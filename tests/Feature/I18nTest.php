<?php

namespace Tests\Feature;

use App\Modules\Settings\App\Services\SettingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class I18nTest extends TestCase
{
    use RefreshDatabase;

    private const LOCALES = ['en', 'tr', 'de', 'ru', 'fr', 'it'];

    public function test_locales_endpoint_is_public(): void
    {
        $this->getJson('/api/i18n/locales')
            ->assertOk()
            ->assertJsonPath('data', self::LOCALES);
    }

    public function test_show_returns_full_message_set_for_locale(): void
    {
        $this->getJson('/api/i18n/tr')
            ->assertOk()
            ->assertJsonPath('data.site_name', 'S2 Panel')
            ->assertJsonPath('data.nav.dashboard', 'Panel')
            ->assertJsonPath('data.common.save', 'Kaydet');
    }

    public function test_show_returns_404_for_unsupported_locale(): void
    {
        $this->getJson('/api/i18n/xx')
            ->assertStatus(404)
            ->assertJsonPath('message', 'not_found');
    }

    public function test_show_ignores_urls_longer_than_two_letters(): void
    {
        $this->getJson('/api/i18n/english')->assertStatus(404);
    }

    public function test_set_locale_stores_session_value(): void
    {
        $this->withSession([])
            ->putJson('/api/i18n/locale', ['locale' => 'de'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'de');

        $this->assertSame('de', session('locale'));
    }

    public function test_set_locale_rejects_unsupported_locale(): void
    {
        $this->putJson('/api/i18n/locale', ['locale' => 'zz'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid_input');
    }

    public function test_set_locale_requires_locale_field(): void
    {
        $this->putJson('/api/i18n/locale', [])
            ->assertStatus(422);
    }

    public function test_set_locale_is_public_before_login(): void
    {
        // No authentication guard – the login screen can switch language.
        $this->putJson('/api/i18n/locale', ['locale' => 'fr'])
            ->assertOk();

        $this->assertSame('fr', session('locale'));
    }

    public function test_session_locale_overrides_settings_default(): void
    {
        config()->set('app.locale', 'en');
        app(SettingService::class)->set('default_locale', 'ru');

        $this->withSession(['locale' => 'de'])
            ->getJson('/api/i18n/locales')
            ->assertOk();

        $this->assertSame('de', app()->getLocale());
    }

    public function test_settings_default_locale_is_applied_without_session_choice(): void
    {
        app(SettingService::class)->set('default_locale', 'it');

        $this->getJson('/api/i18n/locales')->assertOk();

        $this->assertSame('it', app()->getLocale());
    }

    public function test_app_config_locale_is_the_fallback(): void
    {
        config()->set('app.locale', 'en');

        $this->getJson('/api/i18n/locales')->assertOk();

        $this->assertSame('en', app()->getLocale());
    }

    /**
     * Every locale file must ship the same keys as the English source, so
     * the SPA can bind `messages.<key>` without locale-specific fallbacks.
     */
    public function test_all_locale_files_have_identical_key_structure(): void
    {
        $langPath = app_path('Modules/I18n/lang');
        $base = $this->flatten($langPath.'/en/messages.php');
        $baseKeys = array_keys($base);

        foreach (['tr', 'de', 'ru', 'fr', 'it'] as $locale) {
            $compare = $this->flatten($langPath.'/'.$locale.'/messages.php');

            $this->assertSame(
                $baseKeys,
                array_keys($compare),
                "Locale [$locale] has a different key structure than [en]."
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function flatten(string $file): array
    {
        $flat = [];
        $data = require $file;

        $walk = function (array $items, string $prefix = '') use (&$walk, &$flat): void {
            foreach ($items as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

                if (is_array($value)) {
                    $walk($value, $path);
                } else {
                    $flat[$path] = $value;
                }
            }
        };

        $walk($data);

        return $flat;
    }
}