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
}
