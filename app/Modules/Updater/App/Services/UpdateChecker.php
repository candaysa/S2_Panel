<?php

namespace App\Modules\Updater\App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Looks up the newest GitHub release and decides whether it is newer than
 * what is installed.
 *
 * Deliberately read-only and failure-tolerant: an update check that cannot
 * reach GitHub reports "no update", never an error the owner has to dismiss
 * on every page load.
 */
class UpdateChecker
{
    private const CACHE_KEY = 'panel.update.latest';

    public function currentVersion(): string
    {
        return (string) config('panel.version', '0.0.0');
    }

    /**
     * @return array{
     *     available: bool, current: string, latest: ?string, name: ?string,
     *     notes: ?string, published_at: ?string, asset_url: ?string,
     *     asset_name: ?string, asset_size: ?int, html_url: ?string,
     *     reason: ?string
     * }
     */
    public function check(bool $force = false): array
    {
        $base = [
            'available' => false,
            'current' => $this->currentVersion(),
            'latest' => null,
            'name' => null,
            'notes' => null,
            'published_at' => null,
            'asset_url' => null,
            'asset_name' => null,
            'asset_size' => null,
            'html_url' => null,
            'reason' => null,
        ];

        if (! config('panel.update.enabled', true)) {
            return $base + ['reason' => 'updates_disabled'];
        }

        if ($force) {
            Cache::forget(self::CACHE_KEY);
        }

        $release = Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes((int) config('panel.update.check_ttl_minutes', 180)),
            fn (): ?array => $this->fetchLatest(),
        );

        if ($release === null) {
            return array_merge($base, ['reason' => 'lookup_failed']);
        }

        $latest = $this->normalize((string) ($release['tag_name'] ?? ''));

        if ($latest === '') {
            return array_merge($base, ['reason' => 'no_release']);
        }

        $asset = $this->pickAsset($release['assets'] ?? []);

        return array_merge($base, [
            'available' => version_compare($latest, $this->normalize($this->currentVersion()), '>'),
            'latest' => $latest,
            'name' => $release['name'] ?? null,
            'notes' => $release['body'] ?? null,
            'published_at' => $release['published_at'] ?? null,
            'html_url' => $release['html_url'] ?? null,
            'asset_url' => $asset['browser_download_url'] ?? null,
            'asset_name' => $asset['name'] ?? null,
            'asset_size' => isset($asset['size']) ? (int) $asset['size'] : null,
            // A release with no matching bundle can be announced but not
            // installed - the UI needs to say which, rather than offering a
            // button that would break the panel.
            'reason' => $asset === null ? 'no_installable_asset' : null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchLatest(): ?array
    {
        $repo = trim((string) config('panel.update.repository', ''));

        if ($repo === '') {
            return null;
        }

        try {
            $request = Http::timeout(8)->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'S2Panel-Updater',
            ]);

            if ($token = config('panel.update.token')) {
                $request = $request->withToken($token);
            }

            $response = $request->get("https://api.github.com/repos/{$repo}/releases/latest");

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();

            return is_array($body) ? $body : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The one asset that is an installable bundle, or null.
     *
     * @param  array<int, array<string, mixed>>  $assets
     * @return array<string, mixed>|null
     */
    private function pickAsset(array $assets): ?array
    {
        $pattern = (string) config('panel.update.asset_pattern', 's2panel-*.tar.gz');
        $maxBytes = (int) config('panel.update.max_asset_mb', 150) * 1024 * 1024;

        foreach ($assets as $asset) {
            $name = (string) ($asset['name'] ?? '');

            if ($name === '' || ! fnmatch($pattern, $name)) {
                continue;
            }

            if ($maxBytes > 0 && (int) ($asset['size'] ?? 0) > $maxBytes) {
                continue;
            }

            return $asset;
        }

        return null;
    }

    /**
     * "v1.2.3" and "1.2.3" are the same release; tags commonly carry the v.
     */
    private function normalize(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }
}
