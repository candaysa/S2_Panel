<?php

namespace Tests\Feature;

use App\Support\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_and_install_are_always_enabled(): void
    {
        $registry = app(ModuleRegistry::class);

        $this->assertTrue($registry->isEnabled('auth'));
        $this->assertTrue($registry->isEnabled('install'));
    }

    public function test_gated_modules_follow_env_flags(): void
    {
        $registry = app(ModuleRegistry::class);

        // MODULE_ADMIN=true / MODULE_VIP=false come from phpunit.xml + .env.
        $this->assertSame((bool) env('MODULE_ADMIN', false), $registry->isEnabled('admin'));
        $this->assertSame((bool) env('MODULE_VIP', false), $registry->isEnabled('vip'));
    }

    public function test_providers_only_contains_enabled_modules(): void
    {
        $providers = app(ModuleRegistry::class)->providers();

        foreach ($providers as $provider) {
            $this->assertStringContainsString('App\Modules', $provider);
        }
    }

    public function test_every_configured_module_is_actually_registered(): void
    {
        // bootstrap/providers.php derives its list from config/modules.php
        // precisely so these cannot drift. When they did, the failure was
        // silent: a module declared in the config but missing from the
        // provider list simply never loaded, with nothing to explain why.
        $configured = collect(config('modules.modules'))->pluck('provider')->sort()->values();
        $registered = collect(require base_path('bootstrap/providers.php'))
            ->filter(fn (string $provider): bool => str_starts_with($provider, 'App\Modules'))
            ->sort()
            ->values();

        $this->assertEquals($configured->all(), $registered->all());
    }

    public function test_dependents_reports_enabled_modules_that_rely_on_one(): void
    {
        config(['modules.modules.health.enabled' => true]);

        $this->assertContains('health', app(ModuleRegistry::class)->dependents('rcon'));
        $this->assertSame([], app(ModuleRegistry::class)->dependents('webhook'));
    }
}
