<?php

namespace Tests\Feature;

use App\Modules\Settings\App\Models\Setting;
use App\Modules\Settings\App\Services\SettingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Never write into the real public web root during tests.
        config()->set('settings.upload_path', storage_path('framework/testing/uploads'));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/settings')->assertStatus(401);
    }

    public function test_index_requires_owner(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/settings')
            ->assertStatus(403);
    }

    public function test_index_returns_defaults_before_any_value_is_stored(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.site_name', 'S2 Panel')
            ->assertJsonPath('data.default_locale', 'en')
            ->assertJsonPath('data.timezone', 'UTC');
    }

    public function test_update_persists_whitelisted_key(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/settings', ['site_name' => 'My Community'])
            ->assertOk()
            ->assertJsonPath('meta.updated', ['site_name']);

        $this->assertSame('My Community', app(SettingService::class)->get('site_name'));
        $this->assertDatabaseHas('settings', ['key' => 'site_name', 'updated_by' => 1]);
    }

    public function test_update_ignores_keys_outside_the_whitelist(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/settings', ['logo' => 'sneaky-path', 'site_name' => 'Safe'])
            ->assertOk();

        $this->assertDatabaseMissing('settings', ['key' => 'logo']);
        $this->assertSame('Safe', app(SettingService::class)->get('site_name'));
    }

    public function test_update_rejects_invalid_locale(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/settings', ['default_locale' => 'xx'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'validation_failed');
    }

    public function test_update_rejects_oversized_site_name(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/settings', ['site_name' => str_repeat('a', 121)])
            ->assertStatus(422);
    }

    public function test_upload_logo_moves_file_and_records_path(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/api/settings/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk()
            ->assertJsonPath('data.path', 'uploads/logo.png');

        $this->assertFileExists(storage_path('framework/testing/uploads/logo.png'));
        $this->assertSame('uploads/logo.png', app(SettingService::class)->get('logo'));
    }

    public function test_upload_favicon_moves_file_and_records_path(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/api/settings/favicon', [
                'file' => UploadedFile::fake()->image('favicon.png'),
            ])
            ->assertOk();

        $this->assertFileExists(storage_path('framework/testing/uploads/favicon.png'));
    }

    public function test_upload_rejects_non_image(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/api/settings/logo', [
                'file' => UploadedFile::fake()->create('evil.txt', 10),
            ])
            ->assertStatus(422);
    }

    public function test_upload_requires_owner(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/settings/logo', ['file' => UploadedFile::fake()->image('logo.png')])
            ->assertStatus(403);
    }

    public function test_update_persists_brand_color(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/settings', ['brand_color' => '#ff0055'])
            ->assertOk()
            ->assertJsonPath('meta.updated', ['brand_color']);

        $this->assertSame('#ff0055', app(SettingService::class)->get('brand_color'));
    }

    public function test_update_rejects_malformed_brand_color(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/settings', ['brand_color' => 'not-a-color'])
            ->assertStatus(422);
    }

    public function test_brand_color_reset_restores_factory_default(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->putJson('/api/settings', ['brand_color' => '#ff0055'])->assertOk();
        $this->assertSame('#ff0055', app(SettingService::class)->get('brand_color'));

        $this->actingAs($owner)
            ->postJson('/api/settings/brand-color/reset')
            ->assertOk()
            ->assertJsonPath('data.brand_color', '#00ffe3');

        $this->assertSame('#00ffe3', app(SettingService::class)->get('brand_color'));
        $this->assertDatabaseMissing('settings', ['key' => 'brand_color']);
    }

    public function test_brand_color_reset_requires_owner(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/settings/brand-color/reset')
            ->assertStatus(403);
    }

    public function test_setting_service_falls_back_to_config_default(): void
    {
        $this->assertSame('en', app(SettingService::class)->get('default_locale'));
        $this->assertNull(app(SettingService::class)->get('missing_key'));
    }

    public function test_setting_model_stores_string_and_json_values(): void
    {
        Setting::query()->create(['key' => 'site_name', 'value' => 'Store', 'updated_by' => null]);
        Setting::query()->create(['key' => 'meta', 'value' => ['a' => 1], 'updated_by' => null]);

        $this->assertSame('Store', Setting::query()->where('key', 'site_name')->first()->value);
        $this->assertSame(['a' => 1], Setting::query()->where('key', 'meta')->first()->value);
    }
}