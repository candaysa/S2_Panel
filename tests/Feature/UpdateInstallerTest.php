<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\App\Services\AuditService;
use App\Modules\Updater\App\Services\UpdateInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Phar;
use PharData;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The in-place updater against a throwaway install directory
 * (panel.update.root), never the checkout the tests run from.
 *
 * The fixture is a 1.0.0 "install" with everything an update must leave
 * alone - .env, storage/, an uploaded logo, a third-party plugin nobody
 * tracks - and a 1.0.1 bundle that adds, changes and drops files, and also
 * carries its own .env/storage/uploads to prove those are never read from a
 * bundle either.
 */
class UpdateInstallerTest extends TestCase
{
    use RefreshDatabase;

    private const ASSET_URL = 'https://github.com/candaysa/S2_Panel/releases/download/v1.0.1/s2panel-1.0.1.tar.gz';

    private string $root;

    private string $scratch;

    private RecordingUpdateInstaller $installer;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(PharData::class) || ! function_exists('gzopen')) {
            $this->markTestSkipped('phar/zlib not available');
        }

        $id = bin2hex(random_bytes(4));
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/s2upd-root-'.$id;
        $this->scratch = str_replace('\\', '/', sys_get_temp_dir()).'/s2upd-src-'.$id;

        config([
            'panel.version' => '1.0.0',
            'panel.update.enabled' => true,
            'panel.update.root' => $this->root,
            'panel.update.maintenance' => false,
            'panel.update.token' => null,
        ]);

        $this->installer = new RecordingUpdateInstaller(app(AuditService::class));
        $this->app->instance(UpdateInstaller::class, $this->installer);
    }

    protected function tearDown(): void
    {
        foreach ([$this->root ?? null, $this->scratch ?? null] as $dir) {
            if ($dir !== null && is_dir($dir)) {
                // git makes its object files read-only, which Windows then
                // refuses to delete.
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    @chmod($file->getPathname(), 0666);
                }

                File::deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------ install

    public function test_install_adds_changes_and_removes_only_what_releases_ship(): void
    {
        $this->makeCurrentInstall(withGit: true);
        $bundle = $this->buildBundle($this->nextRelease());
        $this->fakeDownload($bundle);

        $result = $this->installer->install(self::ASSET_URL, '1.0.1', null, 'sha256:'.hash_file('sha256', $bundle));

        $this->assertSame(['version' => '1.0.1', 'previous' => '1.0.0', 'added' => 3, 'changed' => 4, 'removed' => 4], $result);

        // added / changed
        $this->assertFileContent('app/Added/New.php', 'added');
        $this->assertFileContent('vendor/new/pkg.php', 'new pkg');
        $this->assertFileContent('public/build/assets/app-new.js', 'new js');
        $this->assertFileContent('app/Changed.php', 'new');
        $this->assertFileContent('public/index.php', '<?php // new');
        $this->assertFileContent('vendor/autoload.php', 'new autoload');
        $this->assertFileContent('public/build/manifest.json', '{"new":1}');

        // removed: tracked by git, or build output - and the directories that
        // held nothing else go with them
        $this->assertFileDoesNotExist($this->root.'/app/Dropped.php');
        $this->assertDirectoryDoesNotExist($this->root.'/app/Gone');
        $this->assertDirectoryDoesNotExist($this->root.'/vendor/old');
        $this->assertFileDoesNotExist($this->root.'/public/build/assets/app-old.js');

        // never touched, whatever the bundle carries
        $this->assertFileContent('.env', 'APP_KEY=secret');
        $this->assertFileContent('storage/app/keep.txt', 'user data');
        $this->assertFileContent('public/uploads/logo.png', 'logo');
        $this->assertFileContent('app/Modules/ThirdParty/Plugin.php', 'plugin');
        $this->assertFileContent('notes-local.txt', 'mine');

        $this->assertSame('applied', $this->installer->pending()['stage']);
        $this->assertFalse($this->installer->migrated);
    }

    public function test_finalise_migrates_clears_the_pending_state_and_audits(): void
    {
        $this->makeCurrentInstall(withGit: false);
        $this->fakeDownload($this->buildBundle($this->nextRelease()));
        $this->installer->install(self::ASSET_URL, '1.0.1');

        $result = $this->installer->finalise();

        $this->assertSame(['version' => '1.0.1', 'warnings' => []], $result);
        $this->assertTrue($this->installer->migrated);
        $this->assertNull($this->installer->pending());
        $this->assertDirectoryDoesNotExist($this->root.'/storage/app/updates/1.0.1');
        $this->assertDatabaseHas('panel_logs', ['action' => 'panel.updated', 'target_id' => '1.0.1']);
    }

    public function test_without_git_only_build_output_is_ever_deleted(): void
    {
        $this->makeCurrentInstall(withGit: false);
        $this->fakeDownload($this->buildBundle($this->nextRelease()));

        $result = $this->installer->install(self::ASSET_URL, '1.0.1');

        // No record of what the release shipped, so a stale app file is left
        // rather than guessing - vendor/ and public/build are still owned
        // wholesale by the release.
        $this->assertSame(2, $result['removed']);
        $this->assertFileExists($this->root.'/app/Dropped.php');
        $this->assertDirectoryDoesNotExist($this->root.'/vendor/old');
    }

    public function test_roll_back_restores_the_previous_release_exactly(): void
    {
        $this->makeCurrentInstall(withGit: true);
        $before = $this->snapshot();

        // Wrapped in a top-level directory, the way GitHub packs archives.
        $this->fakeDownload($this->buildBundle($this->nextRelease(), 's2panel-1.0.1'));
        $this->installer->install(self::ASSET_URL, '1.0.1');
        $this->assertNotSame($before, $this->snapshot());

        $this->assertTrue($this->installer->rollBack());

        $this->assertSame($before, $this->snapshot());
        $this->assertDirectoryDoesNotExist($this->root.'/app/Added');
        $this->assertNull($this->installer->pending());
        $this->assertDatabaseHas('panel_logs', ['action' => 'panel.update_rolled_back']);

        // and a second one has nothing left to do
        $this->assertFalse($this->installer->rollBack());
    }

    public function test_a_failed_migration_puts_the_previous_release_back(): void
    {
        $this->makeCurrentInstall(withGit: true);
        $before = $this->snapshot();
        $this->fakeDownload($this->buildBundle($this->nextRelease()));
        $this->installer->install(self::ASSET_URL, '1.0.1');

        $this->installer->failMigrations = true;

        try {
            $this->installer->finalise();
            $this->fail('finalise() should have thrown');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('migration_failed', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertNull($this->installer->pending());
    }

    public function test_an_update_left_pending_blocks_the_next_one(): void
    {
        $this->makeCurrentInstall(withGit: false);
        $this->fakeDownload($this->buildBundle($this->nextRelease()));
        $this->installer->install(self::ASSET_URL, '1.0.1');

        $this->expectExceptionMessage('preflight_failed: no_pending');

        $this->installer->install(self::ASSET_URL, '1.0.1');
    }

    public function test_finalise_refuses_without_an_applied_update(): void
    {
        $this->makeCurrentInstall(withGit: false);

        $this->expectExceptionMessage('nothing_to_finalise');

        $this->installer->finalise();
    }

    #[DataProvider('rejectedBundles')]
    public function test_a_bad_bundle_is_rejected_before_anything_changes(callable $mutate, ?string $digest, string $reason): void
    {
        $this->makeCurrentInstall(withGit: false);
        $before = $this->snapshot();

        $files = $this->nextRelease();
        $mutate($files);
        $this->fakeDownload($this->buildBundle($files));

        try {
            $this->installer->install(self::ASSET_URL, '1.0.1', null, $digest);
            $this->fail('install() should have thrown');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith($reason, $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertNull($this->installer->pending());
        $this->assertDirectoryDoesNotExist($this->root.'/storage/app/updates/1.0.1');
    }

    /**
     * @return array<string, array{0: callable, 1: ?string, 2: string}>
     */
    public static function rejectedBundles(): array
    {
        return [
            'another project' => [function (array &$f): void {
                $f['composer.json'] = '{"name":"someone/else"}';
            }, null, 'bundle_wrong_project'],
            'source tarball without vendor/' => [function (array &$f): void {
                foreach (array_keys($f) as $path) {
                    if (str_starts_with($path, 'vendor/')) {
                        unset($f[$path]);
                    }
                }
            }, null, 'bundle_missing_vendor'],
            'no compiled assets' => [function (array &$f): void {
                unset($f['public/build/manifest.json']);
            }, null, 'bundle_missing_assets'],
            'digest mismatch' => [function (array &$f): void {
            }, 'sha256:'.str_repeat('0', 64), 'download_digest_mismatch'],
        ];
    }

    /**
     * What release.yml actually produces: `tar -czf … -C bundle .` with GNU
     * tar - a "./" root entry, "./"-prefixed names, and names over 100
     * bytes carried in '././@LongLink' entries. PharData, which this used
     * to rely on, cannot extract that archive at all.
     */
    public function test_a_gnu_tar_bundle_as_ci_builds_it_installs(): void
    {
        $this->makeCurrentInstall(withGit: false);

        $long = 'vendor/'.str_repeat('deep/', 25).'File.php';
        $paxNamed = 'app/Pax/'.str_repeat('p', 120).'.php';

        $entries = [$this->tarEntry('./', '', '5', 0755), $this->tarEntry('./app/', '', '5', 0755)];

        foreach ($this->nextRelease() as $rel => $content) {
            $entries[] = $this->tarEntry('./'.$rel, $content, '0', $rel === 'artisan' ? 0755 : 0644);
        }

        $entries[] = $this->tarEntry('././@LongLink', './'.$long."\0", 'L');
        $entries[] = $this->tarEntry(substr('./'.$long, 0, 100), 'long');
        $entries[] = $this->tarEntry('./PaxHeaders/x', $this->paxRecord('path', './'.$paxNamed), 'x');
        $entries[] = $this->tarEntry('./PaxHeaders/ignored-name', 'pax');

        $this->fakeDownload($this->gzipTar($entries));

        $result = $this->installer->install(self::ASSET_URL, '1.0.1');

        $this->assertSame(5, $result['added']);
        $this->assertFileContent($long, 'long');
        $this->assertFileContent($paxNamed, 'pax');
        $this->assertFileContent('app/Changed.php', 'new');
        $this->assertFileContent('.env', 'APP_KEY=secret');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function hostileEntries(): array
    {
        return [
            'parent traversal' => ['./../evil.php', '0', 'archive_rejected_path'],
            'nested traversal' => ['./app/../../evil.php', '0', 'archive_rejected_path'],
            'absolute path' => ['/etc/cron.d/evil', '0', 'archive_rejected_path'],
            'drive letter' => ['C:/evil.php', '0', 'archive_rejected_path'],
            'backslash' => ['app\\..\\..\\evil.php', '0', 'archive_rejected_path'],
            'symlink' => ['./public/evil', '2', 'archive_rejected_link'],
            'hard link' => ['./public/evil', '1', 'archive_rejected_link'],
            'device' => ['./dev', '3', 'archive_rejected_type'],
        ];
    }

    #[DataProvider('hostileEntries')]
    public function test_a_hostile_tar_entry_rejects_the_whole_bundle(string $name, string $type, string $reason): void
    {
        $this->makeCurrentInstall(withGit: false);
        $before = $this->snapshot();

        $entries = [$this->tarEntry('./', '', '5', 0755)];

        foreach ($this->nextRelease() as $rel => $content) {
            $entries[] = $this->tarEntry('./'.$rel, $content);
        }

        $entries[] = $this->tarEntry($name, $type === '0' ? 'pwned' : '', $type, 0644, $type === '0' ? '' : '/etc/passwd');
        $this->fakeDownload($this->gzipTar($entries));

        try {
            $this->installer->install(self::ASSET_URL, '1.0.1');
            $this->fail('install() should have thrown');
        } catch (RuntimeException $e) {
            $this->assertSame($reason, $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertFileDoesNotExist(dirname($this->root).'/evil.php');
        $this->assertDirectoryDoesNotExist($this->root.'/storage/app/updates/1.0.1');
    }

    public function test_a_corrupt_tar_header_is_rejected(): void
    {
        $this->makeCurrentInstall(withGit: false);

        $entry = $this->tarEntry('./artisan', 'x');
        $entry[0] = 'X'; // name changed after the checksum was computed

        $this->fakeDownload($this->gzipTar([$entry]));

        $this->expectExceptionMessage('archive_corrupt');

        $this->installer->install(self::ASSET_URL, '1.0.1');
    }

    public function test_nothing_can_run_while_another_update_request_holds_the_lock(): void
    {
        $this->makeCurrentInstall(withGit: false);
        $this->fakeDownload($this->buildBundle($this->nextRelease()));
        $this->installer->install(self::ASSET_URL, '1.0.1');

        // Another PHP worker still busy with it.
        $lock = fopen($this->root.'/storage/app/update.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $this->assertTrue($this->installer->pending()['running']);

            try {
                $this->installer->rollBack();
                $this->fail('rollBack() should have refused');
            } catch (RuntimeException $e) {
                $this->assertSame('update_in_progress', $e->getMessage());
            }

            $this->actingAs(User::factory()->owner()->create())
                ->postJson('/api/update/rollback')
                ->assertStatus(409)
                ->assertJsonPath('errors.reason.0', 'update_in_progress');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertFalse($this->installer->pending()['running']);
        $this->assertTrue($this->installer->rollBack());
    }

    public function test_a_download_that_is_not_gzip_is_rejected(): void
    {
        $this->makeCurrentInstall(withGit: false);
        Http::fake(['*' => Http::response('<html>rate limited</html>', 200)]);

        $this->expectExceptionMessage('download_not_gzip');

        $this->installer->install(self::ASSET_URL, '1.0.1');
    }

    // ---------------------------------------------------------- HTTP / UI

    public function test_status_reports_no_release_when_github_has_none(): void
    {
        $this->makeCurrentInstall(withGit: false);
        Http::fake(['*api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/update/status?force=1')
            ->assertOk()
            ->assertJsonPath('data.release.reason', 'no_release')
            ->assertJsonPath('data.can_install', false)
            ->assertJsonPath('data.pending', null)
            ->assertJsonPath('data.preflight.ready', true);
    }

    public function test_update_endpoints_are_owner_only(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/update/status')->assertForbidden();
        $this->actingAs($user)->postJson('/api/update/install')->assertForbidden();
        $this->actingAs($user)->postJson('/api/update/rollback')->assertForbidden();
        $this->actingAs($user)->get('/settings/updates')->assertForbidden();
    }

    public function test_rollback_endpoint_says_when_there_is_nothing_to_roll_back(): void
    {
        $this->makeCurrentInstall(withGit: false);

        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/api/update/rollback')
            ->assertStatus(409);
    }

    public function test_the_whole_update_runs_through_the_endpoints(): void
    {
        $this->makeCurrentInstall(withGit: false);
        $bundle = $this->buildBundle($this->nextRelease());

        Http::fake([
            '*api.github.com/*' => Http::response([
                'tag_name' => 'v1.0.1',
                'name' => 'v1.0.1',
                'body' => 'Notes',
                'html_url' => 'https://github.com/candaysa/S2_Panel/releases/tag/v1.0.1',
                'published_at' => '2026-09-11T10:00:00Z',
                'assets' => [[
                    'name' => 's2panel-1.0.1.tar.gz',
                    'size' => filesize($bundle),
                    'browser_download_url' => self::ASSET_URL,
                    'digest' => 'sha256:'.hash_file('sha256', $bundle),
                ]],
            ]),
            '*github.com/*' => Http::response((string) file_get_contents($bundle), 200),
        ]);

        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->getJson('/api/update/status?force=1')
            ->assertOk()
            ->assertJsonPath('data.release.latest', '1.0.1')
            ->assertJsonPath('data.can_install', true);

        $this->actingAs($owner)->postJson('/api/update/install')
            ->assertOk()
            ->assertJsonPath('data.added', 3);

        $this->actingAs($owner)->postJson('/api/update/finalise')
            ->assertOk()
            ->assertJsonPath('data.version', '1.0.1')
            ->assertJsonPath('data.warnings', []);

        $this->assertFileContent('app/Changed.php', 'new');
        $this->assertNull($this->installer->pending());
    }

    public function test_update_endpoints_and_page_stay_reachable_in_maintenance_mode(): void
    {
        // A cache-backed maintenance flag, so a test that dies half way can
        // never leave the real checkout's storage/framework/down behind.
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->makeCurrentInstall(withGit: false);
        Http::fake(['*' => Http::response([], 404)]);

        $this->app->maintenanceMode()->activate([]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/dashboard')->assertStatus(503);
        $this->actingAs($owner)->getJson('/api/update/status')->assertOk();
        $this->actingAs($owner)->get('/settings/updates')->assertOk();
    }

    // ------------------------------------------------------------ fixture

    private function makeCurrentInstall(bool $withGit): void
    {
        $tracked = [
            'artisan' => '#!/usr/bin/env php',
            'composer.json' => '{"name":"candaysa/s2-panel"}',
            'public/index.php' => '<?php // old',
            'bootstrap/app.php' => '<?php // app',
            'app/Keep.php' => 'same',
            'app/Changed.php' => 'old',
            'app/Dropped.php' => 'dropped in 1.0.1',
            'app/Gone/Only.php' => 'dropped in 1.0.1',
        ];

        $this->writeTree($this->root, $tracked + [
            'vendor/autoload.php' => 'old autoload',
            'vendor/old/pkg.php' => 'old pkg',
            'public/build/manifest.json' => '{"old":1}',
            'public/build/assets/app-old.js' => 'old js',
            '.env' => 'APP_KEY=secret',
            'storage/app/keep.txt' => 'user data',
            'public/uploads/logo.png' => 'logo',
            'app/Modules/ThirdParty/Plugin.php' => 'plugin',
            'notes-local.txt' => 'mine',
        ]);

        if (! $withGit) {
            return;
        }

        $this->git(['init', '-q']);
        $this->git(['add', '--', ...array_keys($tracked)]);
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.test', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'v1.0.0']);
    }

    /**
     * @return array<string, string>
     */
    private function nextRelease(): array
    {
        return [
            'artisan' => '#!/usr/bin/env php',
            'composer.json' => '{"name":"candaysa/s2-panel"}',
            'public/index.php' => '<?php // new',
            'bootstrap/app.php' => '<?php // app',
            'app/Keep.php' => 'same',
            'app/Changed.php' => 'new',
            'app/Added/New.php' => 'added',
            'vendor/autoload.php' => 'new autoload',
            'vendor/new/pkg.php' => 'new pkg',
            'public/build/manifest.json' => '{"new":1}',
            'public/build/assets/app-new.js' => 'new js',
            // local state a bundle must never be able to overwrite
            '.env' => 'APP_KEY=attacker',
            'storage/app/keep.txt' => 'overwritten',
            'public/uploads/logo.png' => 'replaced',
        ];
    }

    /**
     * @param  array<string, string>  $files
     */
    private function buildBundle(array $files, ?string $wrapper = null): string
    {
        $id = bin2hex(random_bytes(4));
        $src = $this->scratch.'/src-'.$id;
        $this->writeTree($wrapper === null ? $src : $src.'/'.$wrapper, $files);

        $tar = $this->scratch.'/bundle-'.$id.'.tar';
        $phar = new PharData($tar);
        $phar->buildFromDirectory($src);
        $phar->compress(Phar::GZ);
        unset($phar);

        return $tar.'.gz';
    }

    /**
     * One tar entry in GNU tar's own header layout ("ustar  " magic).
     */
    private function tarEntry(string $name, string $data = '', string $type = '0', int $mode = 0644, string $link = ''): string
    {
        $header = str_pad($name, 100, "\0")
            .sprintf('%07o', $mode)."\0"
            .sprintf('%07o', 0)."\0"
            .sprintf('%07o', 0)."\0"
            .sprintf('%011o', strlen($data))."\0"
            .sprintf('%011o', 0)."\0"
            .'        '
            .$type
            .str_pad($link, 100, "\0")
            ."ustar  \0"
            .str_repeat("\0", 247);

        $sum = array_sum(unpack('C*', $header));
        $header = substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);

        return $header.$data.str_repeat("\0", (512 - strlen($data) % 512) % 512);
    }

    private function paxRecord(string $key, string $value): string
    {
        $body = " {$key}={$value}\n";
        $length = strlen($body) + 1;

        while (strlen($length.$body) !== $length) {
            $length++;
        }

        return $length.$body;
    }

    /**
     * @param  array<int, string>  $entries
     */
    private function gzipTar(array $entries): string
    {
        File::ensureDirectoryExists($this->scratch);
        $path = $this->scratch.'/raw-'.bin2hex(random_bytes(4)).'.tar.gz';
        File::put($path, gzencode(implode('', $entries).str_repeat("\0", 1024)));

        return $path;
    }

    private function fakeDownload(string $bundle): void
    {
        Http::fake(['*' => Http::response((string) file_get_contents($bundle), 200)]);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function writeTree(string $base, array $files): void
    {
        foreach ($files as $rel => $content) {
            File::ensureDirectoryExists(dirname($base.'/'.$rel));
            File::put($base.'/'.$rel, $content);
        }
    }

    /**
     * Every file outside .git and the updater's own work area, with content.
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $rel = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($this->root)), '/');

            if (str_starts_with($rel, '.git/') || str_starts_with($rel, 'storage/app/update')) {
                continue;
            }

            $files[$rel] = (string) file_get_contents($file->getPathname());
        }

        ksort($files);

        return $files;
    }

    private function assertFileContent(string $rel, string $expected): void
    {
        $this->assertFileExists($this->root.'/'.$rel);
        $this->assertSame($expected, file_get_contents($this->root.'/'.$rel), $rel);
    }

    /**
     * @param  array<int, string>  $args
     */
    private function git(array $args): void
    {
        $process = new Process(['git', ...$args], $this->root);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->markTestSkipped('git unavailable: '.$process->getErrorOutput());
        }
    }
}

/**
 * The real installer with the two steps that act on the app running the
 * tests - migrate and optimize:clear - replaced by a flag.
 */
class RecordingUpdateInstaller extends UpdateInstaller
{
    public bool $migrated = false;

    public bool $failMigrations = false;

    protected function runMigrations(): void
    {
        if ($this->failMigrations) {
            throw new RuntimeException('SQLSTATE: table already exists');
        }

        $this->migrated = true;
    }

    protected function clearCaches(): void
    {
    }
}
