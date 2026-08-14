<?php

namespace App\Modules\Settings\App\Services;

use App\Modules\Settings\App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Settings accessor with a short-lived cache (same pattern as Support\Flags).
 *
 * Unknown keys fall back to config/settings.php defaults, so the panel works
 * before any owner has stored a value.
 */
class SettingService
{
    private const CACHE_TTL = 300;

    public function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = $this->cacheKey($key);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($key) {
            $row = Setting::query()->where('key', $key)->first();

            return $row?->value;
        }) ?? $default ?? config("settings.defaults.{$key}");
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $values = [];

        foreach (array_keys(config('settings.defaults', [])) as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    public function set(string $key, mixed $value, ?int $updatedBy = null): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $updatedBy],
        );

        Cache::forget($this->cacheKey($key));
    }

    /**
     * Drop a stored override so get() falls back to the config default again.
     */
    public function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();

        Cache::forget($this->cacheKey($key));
    }

    private function cacheKey(string $key): string
    {
        return 'settings.'.$key;
    }
}