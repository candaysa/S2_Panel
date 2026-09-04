<?php

namespace App\Modules\I18n\App\Http\Controllers;

use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;

/**
 * Internationalization (C15). The panel ships eight fixed locales (en is
 * the default); API error envelopes stay fixed English (Support\Api), these
 * messages serve the UI layer (SPA / blade).
 *
 * The single source of truth for which locales exist - the install
 * wizard's validation, Settings' language dropdown and CatalogService's own
 * locale map (Skin module) all read this list rather than repeating it, so
 * adding a language only ever means dropping in lang/{locale}/messages.php
 * and adding it here.
 *
 * GET /api/i18n/locales      – supported locales
 * GET /api/i18n/{locale}     – full message set for a locale
 * PUT /api/i18n/locale       – set session locale
 */
class I18nController
{
    /**
     * @return array<int, string>
     */
    public static function locales(): array
    {
        return ['en', 'tr', 'de', 'ru', 'fr', 'it', 'hu', 'pl'];
    }

    public function index(): JsonResponse
    {
        return Api::success(self::locales());
    }

    public function show(string $locale): JsonResponse
    {
        if (! in_array($locale, self::locales(), true)) {
            return Api::notFound();
        }

        // 'i18n' namespace is registered by the module provider.
        return Api::success(Lang::get('i18n::messages', [], $locale));
    }

    public function setLocale(Request $request): JsonResponse
    {
        $locale = (string) $request->input('locale', '');

        if (! in_array($locale, self::locales(), true)) {
            return Api::error(Api::MSG_INVALID_INPUT, ['locale' => 'unsupported_locale'], 422);
        }

        $request->session()->put('locale', $locale);

        return Api::success(['locale' => $locale]);
    }
}