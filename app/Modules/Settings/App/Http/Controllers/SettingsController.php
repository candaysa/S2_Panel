<?php

namespace App\Modules\Settings\App\Http\Controllers;

use App\Modules\Settings\App\Services\SettingService;
use App\Support\Api;
use App\Support\PanelBackup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Panel settings (C14). Owner-only: the settings table is a sensitive store
 * and logo/favicon uploads write into the public web root.
 *
 * GET  /api/settings          – all settings
 * PUT  /api/settings          – update whitelisted keys
 * POST /api/settings/logo     – upload logo image
 * POST /api/settings/favicon  – upload favicon image
 * GET  /api/settings/backup   – download a full backup.zip
 */
class SettingsController
{
    public function __construct(private readonly SettingService $settings)
    {
    }

    /**
     * Streams a freshly-built backup.zip (see PanelBackup) and deletes the
     * scratch copy once it's been sent. Contains database credentials and
     * Steam secrets in plain text - the response has no cache headers on
     * purpose, and this route is owner-only (steam.auth + owner.only).
     */
    public function backup(PanelBackup $backup): BinaryFileResponse
    {
        $path = $backup->create();

        return response()->download($path, 'backup.zip')->deleteFileAfterSend(true);
    }

    public function index(): JsonResponse
    {
        return Api::success($this->settings->all());
    }

    public function update(Request $request): JsonResponse
    {
        $whitelist = (array) config('settings.whitelist', []);
        $validator = Validator::make($request->only($whitelist), [
            'site_name' => 'nullable|string|max:120',
            'site_description' => 'nullable|string|max:500',
            'default_locale' => 'nullable|string|in:en,tr,de,ru,fr,it',
            // Validated against the system's own tz database rather than a
            // hand-kept list, so it cannot drift out of date.
            'timezone' => ['nullable', 'string', 'max:64', Rule::in(timezone_identifiers_list())],
            'brand_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $updated = [];

        foreach ($validator->validated() as $key => $value) {
            if ($value === null) {
                continue;
            }

            $this->settings->set($key, $value, Auth::id());
            $updated[] = $key;
        }

        // SetLocale prefers the session locale over this setting, and the
        // install wizard writes one - so without this the owner who set the
        // panel up would change the default and see no difference at all,
        // even after a reload. Their own session follows the new default.
        $localeChanged = false;

        if (($validator->validated()['default_locale'] ?? null) !== null) {
            $locale = (string) $validator->validated()['default_locale'];
            $localeChanged = $request->session()->get('locale') !== $locale;
            $request->session()->put('locale', $locale);
        }

        // The UI is server-rendered, so a new locale needs a fresh render;
        // the client reloads when told the locale actually moved.
        return Api::success(null, ['updated' => $updated, 'locale_changed' => $localeChanged]);
    }

    /**
     * POST /api/settings/brand-color/reset
     *
     * Drops the stored override so SettingService falls back to
     * config/settings.php's factory default again ("refresh"/"reset to
     * default" button in Settings > Appearance).
     */
    public function resetBrandColor(): JsonResponse
    {
        $this->settings->forget('brand_color');

        return Api::success(['brand_color' => $this->settings->get('brand_color')]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        return $this->upload($request, 'logo');
    }

    public function uploadFavicon(Request $request): JsonResponse
    {
        return $this->upload($request, 'favicon');
    }

    private function upload(Request $request, string $kind): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|image|mimes:png,jpg,jpeg,webp,svg,ico|max:2048',
        ]);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $file = $request->file('file');
        $extension = (string) $file->getClientOriginalExtension();

        $this->ensureUploadDir();

        $filename = $kind.'.'.$extension;
        $file->move((string) config('settings.upload_path'), $filename);

        $this->settings->set($kind, 'uploads/'.$filename, Auth::id());

        return Api::success(['path' => 'uploads/'.$filename]);
    }

    private function ensureUploadDir(): void
    {
        $path = (string) config('settings.upload_path');

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}