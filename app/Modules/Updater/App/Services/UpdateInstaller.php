<?php

namespace App\Modules\Updater\App\Services;

use App\Modules\Audit\App\Services\AuditService;
use FilesystemIterator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Installs a release bundle over the running panel, in place, from the
 * panel itself (Settings > Updates) - no SSH.
 *
 * In place rather than the directory swap this used to do, because a swap
 * needs the web server to create directories next to the install - write
 * access to /var/www - which a correctly set-up server does not grant, so
 * every real install failed the preflight. Everything here happens inside
 * the install directory, which install.sh already hands to www-data.
 *
 * What makes in place safe:
 *  - The whole plan (what gets added, changed, removed) is computed and
 *    every file it would overwrite or delete is copied to a rollback area
 *    BEFORE a single file is touched. Rolling back is "copy those back and
 *    delete what was added" - idempotent, so it also works after a crash
 *    part way through (see the pending-state file and panel:update-rollback).
 *  - Only files the running release itself shipped can be deleted: files git
 *    tracks, plus vendor/ and public/build/ (pure build output). Everything a
 *    release never ships is never touched - .env, storage/, uploaded logos in
 *    public/uploads, and third-party plugins, which the Plugins tab installs
 *    into app/Modules/ right next to the built-in ones. (The old swap carried
 *    over only .env and storage/, so the first update would have silently
 *    deleted every installed plugin and uploaded logo.)
 *  - The panel sits in maintenance mode while files change; the update
 *    endpoints themselves are exempt (bootstrap/app.php) so the owner can
 *    finish or roll back from the same page.
 *
 * What it never does: touch the database beyond `migrate --force` (forward
 * only), or install GitHub's source tarball (see config/panel.php).
 */
class UpdateInstaller
{
    /** Files that must exist in a bundle for it to be a plausible panel. */
    private const REQUIRED = ['artisan', 'composer.json', 'public/index.php', 'bootstrap/app.php'];

    /**
     * Never read from a bundle, never overwritten, never deleted - local
     * state and things no release ships.
     */
    private const PRESERVE = ['.env', 'storage', '.git', 'node_modules', 'public/uploads', 'public/storage', 'public/hot'];

    /** Pure build output: owned wholesale by whichever release is installed. */
    private const BUILD_OUTPUT = ['vendor', 'public/build'];

    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * The install being updated. Configurable only so tests can point it at
     * a throwaway directory instead of the real checkout.
     */
    public function root(): string
    {
        return rtrim((string) (config('panel.update.root') ?: base_path()), '/\\');
    }

    /**
     * Everything that has to be true before an install can be attempted.
     *
     * Reported rather than thrown: the Updates page shows the owner exactly
     * what is missing, so "the button does nothing" is never the experience.
     *
     * @return array{ready: bool, checks: array<int, array{key: string, ok: bool, detail: ?string}>}
     */
    public function preflight(): array
    {
        $root = $this->root();
        $checks = [];

        $unwritable = $this->firstUnwritable($root);
        $checks[] = $this->check('install_writable', $unwritable === null, $unwritable);
        $checks[] = $this->check('storage_writable', is_writable($root.'/storage/app'), $root.'/storage/app');
        $checks[] = $this->check('zlib_available', function_exists('gzopen'), null);

        $free = @disk_free_space($root);
        $needed = 512 * 1024 * 1024;
        $checks[] = $this->check(
            'disk_space',
            $free !== false && $free > $needed,
            $free !== false ? round($free / 1024 / 1024).' MB free' : null,
        );

        $checks[] = $this->check('updates_enabled', (bool) config('panel.update.enabled', true), null);

        $pending = $this->pendingState();
        $checks[] = $this->check('no_pending', $pending === null, $pending['version'] ?? null);

        return [
            'ready' => ! in_array(false, array_column($checks, 'ok'), true),
            'checks' => $checks,
        ];
    }

    /**
     * Download, verify and apply a release bundle.
     *
     * Leaves the panel on the new code and in maintenance mode, with the
     * pending state at "applied"; finalise() - which has to run in a fresh
     * request, see there - migrates and brings it back up.
     *
     * @return array{version: string, previous: string, added: int, changed: int, removed: int}
     *
     * @throws RuntimeException on any failure; nothing has changed yet, or
     *                          everything that had is put back first
     */
    public function install(string $assetUrl, string $version, ?string $tag = null, ?string $digest = null): array
    {
        return $this->exclusively(fn (): array => $this->doInstall($assetUrl, $version, $tag, $digest));
    }

    /**
     * @return array{version: string, previous: string, added: int, changed: int, removed: int}
     */
    private function doInstall(string $assetUrl, string $version, ?string $tag, ?string $digest): array
    {
        $preflight = $this->preflight();

        if (! $preflight['ready']) {
            $failed = implode(', ', array_column(array_filter($preflight['checks'], fn ($c) => ! $c['ok']), 'key'));

            throw new RuntimeException("preflight_failed: {$failed}");
        }

        $root = $this->root();
        $work = $root.'/storage/app/updates/'.preg_replace('/[^A-Za-z0-9._-]/', '_', $version);
        File::deleteDirectory($work);
        File::ensureDirectoryExists($work);

        $archive = $work.'/bundle.tar.gz';
        $staging = $work.'/staging';

        try {
            $this->download($assetUrl, $archive, $digest);
            $bundleRoot = $this->extract($archive, $work, $staging);
            $this->verifyBundle($bundleRoot);
            $plan = $this->plan($root, $bundleRoot);
        } catch (Throwable $e) {
            // Nothing in the install has been touched yet.
            File::deleteDirectory($work);

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }

        File::put($work.'/plan.json', (string) json_encode($plan, JSON_PRETTY_PRINT));

        $state = [
            'stage' => 'backing_up',
            'version' => $version,
            'tag' => $tag,
            'from' => (string) config('panel.version'),
            'work' => $work,
            'bundle' => $bundleRoot,
            'at' => now()->toIso8601String(),
        ];
        $this->writePendingState($state);

        try {
            $this->backup($root, $work.'/rollback', $plan);

            $this->maintenance(true);
            $this->writePendingState(['stage' => 'applying'] + $state);

            $this->apply($root, $bundleRoot, $plan);

            $this->writePendingState(['stage' => 'applied'] + $state);
        } catch (Throwable $e) {
            $this->doRollBack();

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }

        return [
            'version' => $version,
            'previous' => $state['from'],
            'added' => count($plan['added']),
            'changed' => count($plan['changed']),
            'removed' => count($plan['removed']),
        ];
    }

    /**
     * Forward-only migrations and a cache clear against the new code, then
     * out of maintenance.
     *
     * A separate request from install() on purpose: the process that copied
     * the files is still running the previous release's classes - its copy
     * of the framework included - so migrating from there would run new
     * migrations on old code.
     *
     * @return array{version: string, warnings: array<int, string>}
     */
    public function finalise(): array
    {
        return $this->exclusively(fn (): array => $this->doFinalise());
    }

    /**
     * @return array{version: string, warnings: array<int, string>}
     */
    private function doFinalise(): array
    {
        $state = $this->pendingState();

        if ($state === null || ($state['stage'] ?? null) !== 'applied') {
            throw new RuntimeException('nothing_to_finalise');
        }

        try {
            $this->runMigrations();
        } catch (Throwable $e) {
            // New code against the old schema is the one state the panel
            // cannot serve from, so the previous release goes back.
            //
            // Code only: migrations that already committed before the failing
            // one stay applied (Laravel has recorded them), so this ends on
            // the previous release against a schema that may be partly
            // migrated forward. Serviceable as long as releases keep their
            // migrations backward-compatible (expand/contract) - a policy
            // this class cannot enforce.
            $this->doRollBack();

            throw new RuntimeException('migration_failed: '.$e->getMessage(), 0, $e);
        }

        $this->clearCaches();
        $warnings = $this->alignGit($state['tag'] ?? null);

        $this->maintenance(false);
        $this->clearPendingState();
        File::deleteDirectory((string) $state['work']);

        $this->audit->log('panel.updated', 'panel', (string) $state['version'], [
            'from' => $state['from'] ?? null,
            'to' => $state['version'],
            'warnings' => $warnings,
        ]);

        return ['version' => (string) $state['version'], 'warnings' => $warnings];
    }

    /**
     * Put the previous release back - from a failed install, a failed
     * migration, the Updates page's roll-back button after an interrupted
     * update, or `php artisan panel:update-rollback` over SSH when the panel
     * itself cannot be reached.
     *
     * Idempotent: restoring a file that was never overwritten just rewrites
     * the same bytes, and deleting an "added" file that was never copied in
     * is a no-op - so it is safe from any point, including a crash mid-apply.
     *
     * Never while an install or finalise is still running, though: a
     * browser that gave up on a slow request leaves PHP carrying on (see
     * exclusively()), and restoring files underneath it would leave a mix
     * of both releases. That throws update_in_progress instead.
     */
    public function rollBack(): bool
    {
        return $this->exclusively(fn (): bool => $this->doRollBack());
    }

    private function doRollBack(): bool
    {
        $state = $this->pendingState();

        if ($state === null) {
            return false;
        }

        $root = $this->root();
        $work = (string) ($state['work'] ?? '');
        $planFile = $work.'/plan.json';
        $plan = is_file($planFile) ? json_decode((string) File::get($planFile), true) : null;

        if (is_array($plan)) {
            foreach (array_merge($plan['changed'] ?? [], $plan['removed'] ?? []) as $rel) {
                $backup = $work.'/rollback/'.$rel;

                if (is_file($backup)) {
                    File::ensureDirectoryExists(dirname($root.'/'.$rel));
                    copy($backup, $root.'/'.$rel);
                }
            }

            foreach ($plan['added'] ?? [] as $rel) {
                if (is_file($root.'/'.$rel)) {
                    @unlink($root.'/'.$rel);
                    $this->pruneEmptyParents($root, $rel);
                }
            }
        }

        $this->clearCaches();
        $this->maintenance(false);
        $this->clearPendingState();
        File::deleteDirectory($work);

        $this->audit->log('panel.update_rolled_back', 'panel', (string) ($state['from'] ?? ''), [
            'attempted' => $state['version'] ?? null,
            'stage' => $state['stage'] ?? null,
        ]);

        return true;
    }

    /**
     * What the Updates page needs to know about an update in flight, or null.
     *
     * "running" means a request is still working on it right now - the page
     * waits rather than offering buttons that would race it.
     *
     * @return array{stage: string, version: string, from: ?string, at: ?string, running: bool}|null
     */
    public function pending(): ?array
    {
        $state = $this->pendingState();

        return $state === null ? null : [
            'stage' => (string) ($state['stage'] ?? ''),
            'version' => (string) ($state['version'] ?? ''),
            'from' => $state['from'] ?? null,
            'at' => $state['at'] ?? null,
            'running' => $this->running(),
        ];
    }

    /**
     * Separated out (with clearCaches) so a test can make a migration fail
     * without a broken migration file on disk, and without clearing the
     * caches of the app the test itself runs in.
     */
    protected function runMigrations(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    // ---------------------------------------------------------------- plan

    /**
     * added/changed come from the bundle; removed is whatever the running
     * release shipped that the new one no longer does.
     *
     * @return array{added: array<int, string>, changed: array<int, string>, removed: array<int, string>}
     */
    private function plan(string $root, string $bundleRoot): array
    {
        $bundle = $this->files($bundleRoot);
        $added = [];
        $changed = [];

        foreach ($bundle as $rel) {
            $target = $root.'/'.$rel;

            if (! file_exists($target)) {
                $added[] = $rel;
            } elseif (! $this->same($bundleRoot.'/'.$rel, $target)) {
                $changed[] = $rel;
            }
        }

        $inBundle = array_flip($bundle);
        $removed = array_values(array_filter(
            $this->shippedFiles($root),
            fn (string $rel): bool => ! isset($inBundle[$rel]) && is_file($root.'/'.$rel),
        ));

        return ['added' => $added, 'changed' => $changed, 'removed' => $removed];
    }

    /**
     * Files the running release shipped, i.e. the only ones an update may
     * delete: what git tracks, plus build output. Without git there is no
     * record of the rest, so outside build output nothing is deleted - a
     * stale file left behind is harmless next to deleting someone's plugin.
     *
     * @return array<int, string>
     */
    private function shippedFiles(string $root): array
    {
        $shipped = [];

        foreach (self::BUILD_OUTPUT as $dir) {
            if (is_dir($root.'/'.$dir)) {
                foreach ($this->files($root.'/'.$dir, $dir.'/') as $rel) {
                    $shipped[] = $dir.'/'.$rel;
                }
            }
        }

        $git = $this->git();

        if ($git !== null && is_dir($root.'/.git')) {
            $process = new Process([$git, '-C', $root, 'ls-files', '-z']);
            $process->setTimeout(60);
            $process->run();

            if ($process->isSuccessful()) {
                foreach (explode("\0", $process->getOutput()) as $rel) {
                    if ($rel !== '' && ! $this->isPreserved($rel)) {
                        $shipped[] = $rel;
                    }
                }
            }
        }

        return array_values(array_unique($shipped));
    }

    /**
     * Relative paths ('/'-separated) of every regular file under $dir,
     * skipping preserved paths and symlinks - a symlink is never created,
     * followed, replaced or removed by an update.
     *
     * $prefix is where $dir sits relative to the install root, so the
     * preserved-path check is made against the path an update would
     * actually touch (vendor/storage/... is not storage/...).
     *
     * @return array<int, string>
     */
    private function files(string $dir, string $prefix = ''): array
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $file) use ($dir, $prefix): bool {
                    if ($file->isLink()) {
                        return false;
                    }

                    $rel = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($dir)), '/');

                    return ! $this->isPreserved($prefix.$rel);
                },
            ),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($dir)), '/');
            }
        }

        sort($files);

        return $files;
    }

    private function isPreserved(string $rel): bool
    {
        foreach (self::PRESERVE as $path) {
            if ($rel === $path || str_starts_with($rel, $path.'/')) {
                return true;
            }
        }

        return false;
    }

    private function same(string $a, string $b): bool
    {
        return filesize($a) === filesize($b) && hash_file('xxh3', $a) === hash_file('xxh3', $b);
    }

    /**
     * @param  array{added: array<int, string>, changed: array<int, string>, removed: array<int, string>}  $plan
     */
    private function backup(string $root, string $into, array $plan): void
    {
        foreach (array_merge($plan['changed'], $plan['removed']) as $rel) {
            File::ensureDirectoryExists(dirname($into.'/'.$rel));

            if (! copy($root.'/'.$rel, $into.'/'.$rel)) {
                throw new RuntimeException("backup_failed: {$rel}");
            }
        }
    }

    /**
     * @param  array{added: array<int, string>, changed: array<int, string>, removed: array<int, string>}  $plan
     */
    private function apply(string $root, string $bundleRoot, array $plan): void
    {
        foreach (array_merge($plan['added'], $plan['changed']) as $rel) {
            $source = $bundleRoot.'/'.$rel;
            $target = $root.'/'.$rel;
            $temp = $target.'.s2update';

            File::ensureDirectoryExists(dirname($target));

            // Copy beside, then rename over: a request that lands mid-copy
            // sees the old file or the new one, never half of either.
            if (! copy($source, $temp) || ! rename($temp, $target)) {
                @unlink($temp);

                throw new RuntimeException("apply_failed: {$rel}");
            }

            @chmod($target, fileperms($source) & 0777);
        }

        foreach ($plan['removed'] as $rel) {
            if (is_file($root.'/'.$rel) && ! @unlink($root.'/'.$rel)) {
                throw new RuntimeException("apply_failed: {$rel}");
            }

            $this->pruneEmptyParents($root, $rel);
        }
    }

    /**
     * Directories a removal left empty go too, so a module a release dropped
     * does not linger as an empty folder. Stops at the first non-empty one
     * and never climbs above the install root.
     */
    private function pruneEmptyParents(string $root, string $rel): void
    {
        $dir = dirname($rel);

        while ($dir !== '.' && $dir !== '' && ! $this->isPreserved($dir)) {
            $path = $root.'/'.$dir;

            if (! is_dir($path) || (new FilesystemIterator($path))->valid()) {
                return;
            }

            @rmdir($path);
            $dir = dirname($dir);
        }
    }

    // ------------------------------------------------------------ archive

    private function download(string $url, string $target, ?string $digest): void
    {
        $maxBytes = (int) config('panel.update.max_asset_mb', 150) * 1024 * 1024;

        $request = Http::timeout(180)->withHeaders([
            'Accept' => 'application/octet-stream',
            'User-Agent' => 'S2Panel-Updater',
        ]);

        if ($token = config('panel.update.token')) {
            $request = $request->withToken($token);
        }

        $response = $request->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('download_failed');
        }

        $body = $response->body();

        if ($body === '' || ($maxBytes > 0 && strlen($body) > $maxBytes)) {
            throw new RuntimeException('download_rejected');
        }

        if (file_put_contents($target, $body) === false) {
            throw new RuntimeException('download_write_failed');
        }

        // gzip magic - a stray HTML error page would otherwise reach the
        // archive reader.
        if (file_get_contents($target, false, null, 0, 2) !== "\x1f\x8b") {
            throw new RuntimeException('download_not_gzip');
        }

        // GitHub publishes a sha256 for every release asset; when the release
        // API handed one over, the file on disk has to be exactly that file.
        if ($digest !== null && str_starts_with($digest, 'sha256:')
            && ! hash_equals(strtolower(substr($digest, 7)), hash_file('sha256', $target))) {
            throw new RuntimeException('download_digest_mismatch');
        }
    }

    /**
     * Decompress, then read the tar stream with the small reader below -
     * neither the host's tar binary nor PHP's PharData.
     *
     * Not tar(1): the old path parsed `tar -tvzf` output, which differs
     * between GNU tar and bsdtar - on a bsdtar host the listing did not
     * parse and the safety checks it fed were silently skipped.
     *
     * Not PharData either: `tar -C dir .` (how release.yml packs the bundle)
     * starts the archive with a "./" entry, which PharData cannot extract at
     * all, and whose directory iterator skips "." together with everything
     * beneath it - so a check that walked it saw no entries and passed.
     *
     * Reading the stream directly means every single entry is seen and
     * checked before it is written, and only plain files and directories
     * are ever created.
     *
     * The gzip layer is inflated first, counting bytes, so a small download
     * that expands into something enormous is stopped at the size cap
     * instead of filling the disk.
     *
     * @return string the bundle root inside $staging
     */
    private function extract(string $archive, string $work, string $staging): string
    {
        $tarPath = $work.'/bundle.tar';

        try {
            $this->inflate($archive, $tarPath);
            $this->untar($tarPath, $staging);
        } finally {
            @unlink($tarPath);
        }

        return $this->locateRoot($staging);
    }

    private function inflate(string $archive, string $tarPath): void
    {
        $maxExpanded = (int) config('panel.update.max_expanded_mb', 600) * 1024 * 1024;

        $in = @gzopen($archive, 'rb');
        $out = @fopen($tarPath, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException('archive_unreadable');
        }

        $total = 0;

        try {
            while (! gzeof($in)) {
                $chunk = gzread($in, 1048576);

                if ($chunk === false) {
                    throw new RuntimeException('archive_unreadable');
                }

                $total += strlen($chunk);

                if ($maxExpanded > 0 && $total > $maxExpanded) {
                    throw new RuntimeException('archive_rejected_size');
                }

                fwrite($out, $chunk);
            }
        } finally {
            gzclose($in);
            fclose($out);
        }
    }

    /**
     * Extract a tar file into $staging: POSIX ustar, GNU long names ('L')
     * and pax extended headers ('x'), which between them cover what GNU tar
     * and bsdtar write. Anything that is not a regular file or a directory -
     * symlinks, hard links, devices - rejects the whole bundle.
     *
     * Writing as it goes is safe: every path is checked before anything is
     * written for it, and $staging lives in the update's work directory,
     * which install() deletes on any failure.
     */
    private function untar(string $tarPath, string $staging): void
    {
        $maxEntries = (int) config('panel.update.max_entries', 20000);
        $in = @fopen($tarPath, 'rb');

        if ($in === false) {
            throw new RuntimeException('archive_unreadable');
        }

        File::ensureDirectoryExists($staging);
        $count = 0;
        $longName = null;
        $pax = [];

        try {
            while (true) {
                $header = fread($in, 512);

                if ($header === false || strlen($header) !== 512) {
                    throw new RuntimeException('archive_truncated');
                }

                // Two zero blocks end the archive; one is enough to stop.
                if (trim($header, "\0") === '') {
                    break;
                }

                if (++$count > $maxEntries) {
                    throw new RuntimeException('archive_rejected_entry_count');
                }

                $this->verifyTarChecksum($header);

                $type = $header[156];
                $size = isset($pax['size']) ? $this->tarDecimal($pax['size']) : $this->tarOctal(substr($header, 124, 12));

                // Metadata entries describe the entry that follows them.
                if ($type === 'L') {
                    $longName = rtrim($this->readTarData($in, $size), "\0");

                    continue;
                }

                if ($type === 'x') {
                    $pax = $this->parsePax($this->readTarData($in, $size));

                    continue;
                }

                if ($type === 'g') {
                    $this->skipTarData($in, $size);

                    continue;
                }

                $name = $pax['path'] ?? $longName ?? $this->ustarName($header);
                $longName = null;
                $pax = [];
                $rel = $this->safeTarPath($name);

                if ($type === '5') {
                    if ($rel !== '') {
                        File::ensureDirectoryExists($staging.'/'.$rel);
                    }

                    $this->skipTarData($in, $size);

                    continue;
                }

                if ($type === '1' || $type === '2') {
                    throw new RuntimeException('archive_rejected_link');
                }

                if (! in_array($type, ['0', "\0", '7'], true)) {
                    throw new RuntimeException('archive_rejected_type');
                }

                if ($rel === '') {
                    throw new RuntimeException('archive_rejected_path');
                }

                $target = $staging.'/'.$rel;
                File::ensureDirectoryExists(dirname($target));

                if (is_dir($target) || ($out = @fopen($target, 'wb')) === false) {
                    throw new RuntimeException('extract_failed');
                }

                $copied = $size > 0 ? stream_copy_to_stream($in, $out, $size) : 0;
                fclose($out);

                if ($copied !== $size) {
                    throw new RuntimeException('archive_truncated');
                }

                $this->skipTarPadding($in, $size);

                // Only the executable bit is taken from the archive; nothing
                // ends up group/world-writable whatever the bundle says.
                $mode = $this->tarOctal(substr($header, 100, 8));
                @chmod($target, ($mode & 0111) !== 0 ? 0755 : 0644);
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * The entry's path relative to the bundle, '' for the archive root
     * itself ("./"), or a rejection for anything that could land outside
     * $staging.
     */
    private function safeTarPath(string $name): string
    {
        $rel = rtrim($name, '/');

        // `tar -C dir .` names every entry "./..." and adds "./" itself.
        while (str_starts_with($rel, './')) {
            $rel = substr($rel, 2);
        }

        if ($rel === '' || $rel === '.') {
            return '';
        }

        if (
            str_starts_with($rel, '/') || str_contains($rel, "\0") || str_contains($rel, '\\')
            || preg_match('#^[A-Za-z]:#', $rel) === 1
            || array_intersect(explode('/', $rel), ['', '.', '..']) !== []
        ) {
            throw new RuntimeException('archive_rejected_path');
        }

        return $rel;
    }

    private function ustarName(string $header): string
    {
        $name = rtrim(substr($header, 0, 100), "\0");

        // POSIX ustar splits long paths into prefix + name. GNU tar's own
        // format ("ustar  ") uses those bytes for other things, and carries
        // long names in an 'L' entry instead.
        if (substr($header, 257, 6) === "ustar\0") {
            $prefix = rtrim(substr($header, 345, 155), "\0");

            if ($prefix !== '') {
                $name = $prefix.'/'.$name;
            }
        }

        return $name;
    }

    private function verifyTarChecksum(string $header): void
    {
        $stored = $this->tarOctal(substr($header, 148, 8));
        $actual = array_sum(unpack('C*', substr_replace($header, '        ', 148, 8)));

        if ($stored !== $actual) {
            throw new RuntimeException('archive_corrupt');
        }
    }

    private function tarOctal(string $field): int
    {
        // A set high bit is GNU's base-256 encoding, only ever used for
        // sizes no release bundle has.
        if ($field !== '' && (ord($field[0]) & 0x80) !== 0) {
            throw new RuntimeException('archive_rejected_size');
        }

        $digits = trim($field, " \0");

        if ($digits !== '' && preg_match('/^[0-7]+$/', $digits) !== 1) {
            throw new RuntimeException('archive_corrupt');
        }

        return $digits === '' ? 0 : (int) octdec($digits);
    }

    private function tarDecimal(string $value): int
    {
        if (preg_match('/^\d{1,12}$/', $value) !== 1) {
            throw new RuntimeException('archive_corrupt');
        }

        return (int) $value;
    }

    /**
     * pax records are "<length> <key>=<value>\n"; only path and size matter
     * here.
     *
     * @return array<string, string>
     */
    private function parsePax(string $data): array
    {
        $records = [];

        while ($data !== '') {
            $space = strpos($data, ' ');
            $length = $space === false ? 0 : (int) substr($data, 0, $space);

            if ($space === false || $length <= $space + 1 || $length > strlen($data)) {
                throw new RuntimeException('archive_corrupt');
            }

            $record = substr($data, $space + 1, $length - $space - 2);
            $data = substr($data, $length);
            [$key, $value] = array_pad(explode('=', $record, 2), 2, '');

            if ($key === 'path' || $key === 'size') {
                $records[$key] = $value;
            }
        }

        return $records;
    }

    /**
     * Data of a metadata entry (a long name, a pax header) - small by
     * nature, so capped rather than read into memory whatever its size.
     *
     * @param  resource  $in
     */
    private function readTarData($in, int $size): string
    {
        if ($size > 1048576) {
            throw new RuntimeException('archive_corrupt');
        }

        $data = $size > 0 ? (string) fread($in, $size) : '';

        if (strlen($data) !== $size) {
            throw new RuntimeException('archive_truncated');
        }

        $this->skipTarPadding($in, $size);

        return $data;
    }

    /**
     * @param  resource  $in
     */
    private function skipTarData($in, int $size): void
    {
        if ($size > 0 && fseek($in, (int) (ceil($size / 512) * 512), SEEK_CUR) !== 0) {
            throw new RuntimeException('archive_truncated');
        }
    }

    /**
     * @param  resource  $in
     */
    private function skipTarPadding($in, int $size): void
    {
        $padding = (512 - $size % 512) % 512;

        if ($padding > 0 && fseek($in, $padding, SEEK_CUR) !== 0) {
            throw new RuntimeException('archive_truncated');
        }
    }

    /**
     * A bundle may be rooted at the archive top level or inside a single
     * wrapper directory (which is how GitHub packs source archives). Accept
     * both rather than making the release process depend on it.
     */
    private function locateRoot(string $staging): string
    {
        if (File::exists("{$staging}/artisan")) {
            return $staging;
        }

        $entries = array_values(array_filter(
            File::directories($staging),
            fn (string $d): bool => File::exists("{$d}/artisan"),
        ));

        if (count($entries) === 1) {
            return $entries[0];
        }

        throw new RuntimeException('bundle_root_not_found');
    }

    private function verifyBundle(string $root): void
    {
        foreach (self::REQUIRED as $path) {
            if (! File::exists("{$root}/{$path}")) {
                throw new RuntimeException("bundle_incomplete: {$path}");
            }
        }

        // The two things a source tarball lacks. Installing without them
        // leaves a panel that cannot boot and cannot serve a stylesheet, on
        // any host without Composer and Node - which is the common case.
        if (! File::isDirectory("{$root}/vendor")) {
            throw new RuntimeException('bundle_missing_vendor');
        }

        if (! File::exists("{$root}/public/build/manifest.json")) {
            throw new RuntimeException('bundle_missing_assets');
        }

        // Guard against installing a different project entirely.
        $incoming = json_decode((string) File::get("{$root}/composer.json"), true);
        $current = json_decode((string) File::get($this->root().'/composer.json'), true);

        if (($incoming['name'] ?? null) === null || ($incoming['name'] ?? null) !== ($current['name'] ?? null)) {
            throw new RuntimeException('bundle_wrong_project');
        }
    }

    // --------------------------------------------------------------- misc

    /**
     * Point the git checkout at the release just installed, so the next
     * `install.sh` run (which pulls) does not trip over a working tree that
     * silently moved ahead of HEAD.
     *
     * A hard reset rather than a mixed one: the bundle is that tag's checkout
     * plus gitignored build output, so for tracked files this rewrites
     * nothing - but a bundle built without some tracked path (an older
     * workflow excluded tests/) would otherwise leave those deleted in the
     * working tree, and the next pull would refuse to run over them. Reset
     * never removes untracked files, so vendor/, public/build, plugins and
     * uploads are untouched. Best effort: a failure here is reported, never
     * fatal, since the panel itself is fully updated.
     *
     * @return array<int, string> warnings
     */
    private function alignGit(?string $tag): array
    {
        $root = $this->root();

        if ($tag === null || $tag === '' || ! is_dir($root.'/.git')) {
            return [];
        }

        // Also keeps the tag from ever being read as a git option.
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $tag) !== 1) {
            return ['git_align_failed'];
        }

        $git = $this->git();

        if ($git === null) {
            return ['git_align_failed'];
        }

        // No --depth: install.sh clones shallow and that fetch still only
        // pulls what is missing, but on a full clone --depth would quietly
        // turn the owner's repository shallow.
        foreach ([
            [$git, '-C', $root, 'fetch', '--quiet', 'origin', "refs/tags/{$tag}:refs/tags/{$tag}"],
            [$git, '-C', $root, 'reset', '--quiet', '--hard', "refs/tags/{$tag}"],
        ] as $command) {
            $process = new Process($command);
            $process->setTimeout(90);
            $process->run();

            if (! $process->isSuccessful()) {
                return ['git_align_failed'];
            }
        }

        return [];
    }

    private function git(): ?string
    {
        foreach (['/usr/bin/git', '/usr/local/bin/git', '/bin/git'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return (new ExecutableFinder())->find('git');
    }

    /**
     * The first path (relative) the web server cannot write, or null.
     * Everything an update may touch is checked up front - finding a
     * root-owned file half way through the copy is exactly what the
     * rollback exists for, and much better avoided.
     */
    private function firstUnwritable(string $root): ?string
    {
        if (! is_writable($root)) {
            return $root;
        }

        $root = str_replace('\\', '/', $root);
        $iterator = new RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $file) use ($root): bool {
                    if ($file->isLink()) {
                        return false;
                    }

                    return ! $this->isPreserved(ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/'));
                },
            ),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if (! is_writable($file->getPathname())) {
                return ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/');
            }
        }

        return null;
    }

    private function maintenance(bool $down): void
    {
        if (! config('panel.update.maintenance', true)) {
            return;
        }

        try {
            Artisan::call($down ? 'down' : 'up', $down ? ['--retry' => 30] : []);
        } catch (Throwable) {
            // Best effort either way: failing to show the maintenance page is
            // not a reason to stop, and failing to lift it must not mask the
            // error that led here.
        }
    }

    protected function clearCaches(): void
    {
        try {
            Artisan::call('optimize:clear');
        } catch (Throwable) {
            // A stale cache is recoverable by hand; an exception here is not
            // a reason to report a finished update as failed.
        }
    }

    /**
     * One update operation at a time, across PHP workers - a double click,
     * or a roll-back pressed while a timed-out install is still copying.
     *
     * Also keeps the work going when the browser stops listening: a proxy
     * timing the request out (nginx gives up after 60s by default) must not
     * stop PHP half way through replacing files. The page finds out how it
     * ended from the pending state instead.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function exclusively(callable $work): mixed
    {
        @set_time_limit(0);
        ignore_user_abort(true);

        $path = $this->lockPath();
        File::ensureDirectoryExists(dirname($path));
        $handle = @fopen($path, 'c');

        if ($handle === false) {
            throw new RuntimeException('lock_failed');
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new RuntimeException('update_in_progress');
        }

        try {
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function running(): bool
    {
        $handle = is_file($this->lockPath()) ? @fopen($this->lockPath(), 'r') : false;

        if ($handle === false) {
            return false;
        }

        $free = flock($handle, LOCK_SH | LOCK_NB);

        if ($free) {
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        return ! $free;
    }

    private function lockPath(): string
    {
        return $this->root().'/storage/app/update.lock';
    }

    private function pendingStatePath(): string
    {
        return $this->root().'/storage/app/update-pending.json';
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writePendingState(array $state): void
    {
        File::ensureDirectoryExists(dirname($this->pendingStatePath()));
        File::put($this->pendingStatePath(), (string) json_encode($state, JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pendingState(): ?array
    {
        $path = $this->pendingStatePath();

        if (! is_file($path)) {
            return null;
        }

        $state = json_decode((string) File::get($path), true);

        return is_array($state) ? $state : null;
    }

    private function clearPendingState(): void
    {
        File::delete($this->pendingStatePath());
    }

    /**
     * @return array{key: string, ok: bool, detail: ?string}
     */
    private function check(string $key, bool $ok, ?string $detail): array
    {
        return ['key' => $key, 'ok' => $ok, 'detail' => $detail];
    }
}
