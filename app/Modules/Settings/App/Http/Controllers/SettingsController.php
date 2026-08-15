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
 * GET  /api/settings              – all settings
 * PUT  /api/settings              – update whitelisted keys
 * POST /api/settings/logo         – upload logo image
 * POST /api/settings/favicon      – upload favicon image
 * PUT  /api/settings/theme        – set the full color palette override
 * POST /api/settings/theme/reset  – drop it, back to app.css factory colors
 * GET  /api/settings/backup       – download a full backup.zip
 */
class SettingsController
{
    /**
     * Every token an owner may override, matching the custom properties
     * app.css defines under @theme (dark) and :root[data-theme='light'].
     * brand_color is deliberately not here - it already has its own
     * setting/reset endpoint and color-mix() derivation.
     */
    private const THEME_TOKENS = [
        'surface', 'surface_raised', 'canvas', 'line', 'line_soft', 'ink', 'ink_muted', 'ink_faint',
    ];

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
            // One admin group name per ticket category - see TicketAccess.
            // Not validated against the live group list here: Settings has
            // no dependency on the Admin module, and an owner clearing a
            // renamed/deleted group's name back out is exactly the "narrow
            // access" failure mode TicketAccess::isStaff() already handles
            // (an unknown group name simply matches no one).
            'ticket_staff_group_report' => ['nullable', 'string', 'max:100'],
            'ticket_staff_group_admin_application' => ['nullable', 'string', 'max:100'],
            'ticket_staff_group_ban_appeal' => ['nullable', 'string', 'max:100'],
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

    /**
     * PUT /api/settings/theme
     *
     * Body: { dark: {token: hex, ...}, light: {token: hex, ...} }, both
     * optional and every token within them optional - only the tokens
     * actually present are stored, so a page that only ever edited "ink"
     * cannot accidentally blank out colors it never touched.
     */
    public function updateTheme(Request $request): JsonResponse
    {
        $rules = [];

        foreach (['dark', 'light'] as $mode) {
            foreach (self::THEME_TOKENS as $token) {
                $rules["{$mode}.{$token}"] = ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
            }
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return Api::error(Api::MSG_VALIDATION_FAILED, $validator->errors()->toArray(), 422);
        }

        $current = (array) $this->settings->get('theme_colors', []);

        foreach (['dark', 'light'] as $mode) {
            $submitted = (array) $request->input($mode, []);

            foreach (self::THEME_TOKENS as $token) {
                if (! array_key_exists($token, $submitted)) {
                    continue;
                }

                $value = $submitted[$token];

                if ($value === null || $value === '') {
                    unset($current[$mode][$token]);
                } elseif (preg_match('/^#[0-9a-fA-F]{6}$/', (string) $value) === 1) {
                    $current[$mode][$token] = $value;
                }
            }
        }

        $this->settings->set('theme_colors', $current, Auth::id());

        return Api::success($current);
    }

    /**
     * POST /api/settings/theme/reset
     *
     * Drops every override at once, back to app.css's factory palette -
     * same "start over" convention as resetBrandColor() below.
     */
    public function resetTheme(): JsonResponse
    {
        $this->settings->forget('theme_colors');

        return Api::success(['theme_colors' => $this->settings->get('theme_colors', [])]);
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