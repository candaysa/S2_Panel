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

        $work = storage_path('app/updates');
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
        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('optimize:clear');
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

        $command = escapeshellcmd($tar).' -xzf '.escapeshellarg($archive).' -C '.escapeshellarg($into).' 2>&1';
        exec($command, $output, $code);

        if ($code !== 0) {
            throw new RuntimeException('extract_failed');
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
