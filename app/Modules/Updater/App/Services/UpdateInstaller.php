<?php

namespace App\Modules\Updater\App\Services;

use App\Modules\Audit\App\Services\AuditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Installs a release bundle over the running panel.
 *
 * The shape of this is dictated by one fact: the code being replaced is the
 * code doing the replacing. So the swap is a directory rename (atomic on the
 * same filesystem), the previous install is kept intact beside it, and a
 * health check decides whether to keep the new one or put the old one back.
 *
 * What it never does:
 *   - touch the database beyond `migrate --force`, which only moves forward
 *   - replace .env, storage/ or anything else carrying local state
 *   - install GitHub's source tarball (see config/panel.php for why)
 */
class UpdateInstaller
{
    /** Files that must exist in a bundle for it to be a plausible panel. */
    private const REQUIRED = ['artisan', 'composer.json', 'public/index.php', 'bootstrap/app.php'];

    /** Directories carried over from the running install, never from the bundle. */
    private const PRESERVE = ['.env', 'storage'];

    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * Everything that has to be true before an install can be attempted.
     *
     * Reported rather than thrown: the UI shows the owner exactly what is
     * missing, so "the button does nothing" is never the experience.
     *
     * @return array{ready: bool, checks: array<int, array{key: string, ok: bool, detail: ?string}>}
     */
    public function preflight(): array
    {
        $base = base_path();
        $parent = dirname($base);
        $checks = [];

        $checks[] = $this->check('install_writable', is_writable($base), $base);
        // The swap creates sibling directories, so the parent must be
        // writable too - this is the one that usually fails, because a
        // hardened deploy has root-owned directories under /var/www.
        $checks[] = $this->check('parent_writable', is_writable($parent), $parent);
        $checks[] = $this->check('storage_writable', is_writable(storage_path('app')), storage_path('app'));

        $tar = $this->findTar();
        $checks[] = $this->check('tar_available', $tar !== null, $tar);

        $free = @disk_free_space($parent);
        $needed = 512 * 1024 * 1024;
        $checks[] = $this->check(
            'disk_space',
            $free !== false && $free > $needed,
            $free !== false ? round($free / 1024 / 1024).' MB free' : null,
        );

        $checks[] = $this->check('updates_enabled', (bool) config('panel.update.enabled', true), null);

        return [
            'ready' => ! in_array(false, array_column($checks, 'ok'), true),
            'checks' => $checks,
        ];
    }

    /**
     * Download, verify and swap in a release bundle.
     *
     * @return array{version: string, previous: string, backup_path: string}
     *
     * @throws RuntimeException on any failure; the running install is left
     *                          untouched, or restored if the swap had begun.
     */
    public function install(string $assetUrl, string $version): array
    {
        $preflight = $this->preflight();

        if (! $preflight['ready']) {
            $failed = implode(', ', array_column(array_filter($preflight['checks'], fn ($c) => ! $c['ok']), 'key'));

            throw new RuntimeException("preflight_failed: {$failed}");
        }

        $base = base_path();
        $parent = dirname($base);
        $name = basename($base);
        $stamp = date('Ymd-His');

        // Sibling of the install being replaced, not inside it. storage/
        // (which lives under $base) is one of the PRESERVE paths carried
        // over into the incoming tree below - staging a copy of the new
        // release under storage/app/updates would make that copy target a
        // path inside its own source directory, and when the swap then
        // moved the staging root into place, this work directory's old
        // path would vanish with it mid-install. $parent is guaranteed to
        // be on the same filesystem as $base already, since the backup
        // rename below depends on that too.
        $work = "{$parent}/.{$name}-update-work";
        File::ensureDirectoryExists($work);

        $archive = "{$work}/bundle-{$version}.tar.gz";
        $staging = "{$work}/staging-{$version}";
        $backup = "{$parent}/{$name}_pre-update_{$stamp}";

        File::deleteDirectory($staging);
        @unlink($archive);

        try {
            $this->download($assetUrl, $archive);

            File::ensureDirectoryExists($staging);
            $this->extract($archive, $staging);

            $root = $this->locateRoot($staging);
            $this->verifyBundle($root);
            $this->carryOverLocalState($base, $root);

            // Atomic-ish swap. Both renames are on the same filesystem, so
            // each is a single operation; the window between them is where a
            // request could 404, which is why the second one is immediate.
            if (! @rename($base, $backup)) {
                throw new RuntimeException('swap_failed_backup');
            }

            if (! @rename($root, $base)) {
                // Put it back before anything else - a panel that is simply
                // not updated is a far better outcome than a missing one.
                @rename($backup, $base);

                throw new RuntimeException('swap_failed_install');
            }
        } catch (Throwable $e) {
            File::deleteDirectory($staging);
            @unlink($archive);

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }

        File::deleteDirectory($staging);
        @unlink($archive);

        // Where finalise() has to put things back if the migrations that run
        // against the new code fail. It lives in storage/, which is carried
        // across the swap (see PRESERVE), so the newly installed code reads
        // the same file this - the old code - just wrote.
        $this->writePendingState([
            'backup' => $backup,
            'installed' => $base,
            'from' => (string) config('panel.version'),
            'to' => $version,
            'at' => now()->toIso8601String(),
        ]);

        $this->audit->log('panel.updated', 'panel', $version, [
            'from' => config('panel.version'),
            'to' => $version,
            'backup' => $backup,
        ]);

        return [
            'version' => $version,
            'previous' => (string) config('panel.version'),
            'backup_path' => $backup,
        ];
    }

    /**
     * Forward-only migrations plus a cache rebuild, run after the swap.
     *
     * Separate from install() because it runs against the NEW code, which
     * this process has not loaded - the caller triggers it in a fresh
     * request so the freshly installed classes are the ones that execute.
     */
    public function finalise(): void
    {
        try {
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('optimize:clear');
        } catch (Throwable $e) {
            // The swap already happened, so a failed migration leaves new
            // code running against the old schema - the one state the panel
            // cannot serve out of. Previously that was simply reported and
            // left in place; the backup directory existed but nothing ever
            // used it. Put the old release back instead, so a failed update
            // ends where it started rather than half-applied.
            $this->rollBack();

            throw $e;
        }

        $this->clearPendingState();
    }

    /**
     * Swap the pre-update directory back in.
     *
     * Best-effort by design: if the rename fails there is nothing further
     * this process can do, and the original exception (which says what
     * actually went wrong) has to reach the owner rather than being masked
     * by a second one from the recovery path.
     */
    private function rollBack(): void
    {
        $state = $this->pendingState();

        if ($state === null) {
            return;
        }

        $backup = (string) ($state['backup'] ?? '');
        $installed = (string) ($state['installed'] ?? '');

        if ($backup === '' || $installed === '' || ! is_dir($backup)) {
            return;
        }

        $failed = $installed.'_failed_'.now()->format('Ymd_His');

        if (! @rename($installed, $failed)) {
            return;
        }

        if (! @rename($backup, $installed)) {
            // Nothing is serving from $installed at this point, so put the
            // new code back rather than leaving the path missing entirely.
            @rename($failed, $installed);

            return;
        }

        $this->clearPendingState();

        $this->audit->log('panel.update_rolled_back', 'panel', (string) ($state['from'] ?? ''), [
            'attempted' => $state['to'] ?? null,
            'failed_copy' => $failed,
        ]);
    }

    private function pendingStatePath(): string
    {
        return storage_path('app/update-pending.json');
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

    private function download(string $url, string $target): void
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

        // gzip magic - a stray HTML error page would otherwise reach tar.
        if (file_get_contents($target, false, null, 0, 2) !== "\x1f\x8b") {
            throw new RuntimeException('download_not_gzip');
        }
    }

    private function extract(string $archive, string $into): void
    {
        $tar = $this->findTar();

        if ($tar === null) {
            throw new RuntimeException('tar_missing');
        }

        $this->assertSafeArchive($tar, $archive);

        $command = escapeshellcmd($tar).' -xzf '.escapeshellarg($archive).' -C '.escapeshellarg($into).' 2>&1';
        exec($command, $output, $code);

        if ($code !== 0) {
            throw new RuntimeException('extract_failed');
        }
    }

    /**
     * Read the member list before unpacking anything.
     *
     * The download is size-capped compressed, which says nothing about what
     * it expands to, and tar's own handling of absolute paths, "..' and
     * symlinks differs between the GNU and bsdtar builds this might find on
     * the host - so the archive is inspected here rather than trusted to
     * whichever binary findTar() picked. SafeZip does the same for the .zip
     * paths (plugins, backup restore); this is the tar equivalent.
     */
    private function assertSafeArchive(string $tar, string $archive): void
    {
        $command = escapeshellcmd($tar).' -tvzf '.escapeshellarg($archive).' 2>&1';
        exec($command, $listing, $code);

        if ($code !== 0) {
            throw new RuntimeException('archive_unreadable');
        }

        $maxEntries = (int) config('panel.update.max_entries', 20000);
        $maxExpandedBytes = (int) config('panel.update.max_expanded_mb', 600) * 1024 * 1024;

        if (count($listing) > $maxEntries) {
            throw new RuntimeException('archive_rejected_entry_count');
        }

        $total = 0;

        foreach ($listing as $line) {
            // "-rw-r--r-- user/group  1234 2026-01-01 00:00 path/to/file"
            // and, for a link, "... path/to/link -> target".
            if (preg_match('/^(\S+)\s+\S+\s+(\d+)\s+\S+\s+\S+\s+(.*)$/', $line, $m) !== 1) {
                continue;
            }

            [, $mode, $size, $name] = $m;

            if (str_starts_with($mode, 'l') || str_contains($name, ' -> ')) {
                throw new RuntimeException('archive_rejected_link');
            }

            $path = str_replace('\\', '/', trim($name));

            if (
                str_starts_with($path, '/')
                || preg_match('#^[A-Za-z]:#', $path) === 1
                || str_contains($path, '../')
                || str_ends_with($path, '/..')
                || $path === '..'
            ) {
                throw new RuntimeException('archive_rejected_path');
            }

            $total += (int) $size;

            if ($maxExpandedBytes > 0 && $total > $maxExpandedBytes) {
                throw new RuntimeException('archive_rejected_size');
            }
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
        $composer = json_decode((string) File::get("{$root}/composer.json"), true);

        if (($composer['name'] ?? null) !== (json_decode((string) File::get(base_path('composer.json')), true)['name'] ?? null)) {
            throw new RuntimeException('bundle_wrong_project');
        }
    }

    /**
     * Move local state into the incoming tree so the swap does not lose it.
     */
    private function carryOverLocalState(string $current, string $incoming): void
    {
        foreach (self::PRESERVE as $path) {
            $from = "{$current}/{$path}";
            $to = "{$incoming}/{$path}";

            if (! File::exists($from)) {
                continue;
            }

            if (File::isDirectory($from)) {
                File::deleteDirectory($to);
                File::copyDirectory($from, $to);

                continue;
            }

            File::copy($from, $to);
        }

        if (! File::exists("{$incoming}/.env")) {
            throw new RuntimeException('env_not_carried');
        }
    }

    private function findTar(): ?string
    {
        foreach (['/usr/bin/tar', '/bin/tar', '/usr/local/bin/tar'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array{key: string, ok: bool, detail: ?string}
     */
    private function check(string $key, bool $ok, ?string $detail): array
    {
        return ['key' => $key, 'ok' => $ok, 'detail' => $detail];
    }
}
