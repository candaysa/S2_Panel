<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/modules')->assertStatus(401);
    }

    public function test_index_requires_owner(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/modules')
            ->assertStatus(403);
    }

    public function test_index_lists_only_toggleable_modules(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/modules')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.key', 'vip')
            ->assertJsonPath('data.1.key', 'skin')
            ->assertJsonPath('data.2.key', 'rank');
    }

    public function test_index_reflects_env_default_before_any_override(): void
    {
        // MODULE_VIP=true in phpunit.xml, no override row exists yet.
        $this->actingAs(User::factory()->owner()->create())
            ->getJson('/api/modules')
            ->assertOk()
            ->assertJsonPath('data.0.enabled', true);
    }

    public function test_update_requires_owner(): void
    {
        $this->actingAs(User::factory()->create())
            ->putJson('/api/modules/vip', ['enabled' => false])
            ->assertStatus(403);
    }

    public function test_update_persists_override_and_is_reflected_by_the_registry(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->putJson('/api/modules/vip', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->assertDatabaseHas('module_toggles', [
            'module_key' => 'vip',
            'enabled' => false,
            'updated_by' => $owner->id,
        ]);

        $this->assertFalse(app(ModuleRegistry::class)->isEnabled('vip'));

        $this->actingAs($owner)
            ->getJson('/api/modules')
            ->assertOk()
            ->assertJsonPath('data.0.enabled', false);
    }

    public function test_update_can_flip_a_module_back_on(): void
    {
        $registry = app(ModuleRegistry::class);
        $registry->setOverride('skin', false);
        $this->assertFalse($registry->isEnabled('skin'));

        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/modules/skin', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->assertTrue(app(ModuleRegistry::class)->isEnabled('skin'));
    }

    public function test_update_rejects_non_toggleable_key(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/modules/auth', ['enabled' => false])
            ->assertStatus(404);

        $this->assertDatabaseMissing('module_toggles', ['module_key' => 'auth']);
    }

    public function test_update_rejects_missing_enabled_field(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/modules/vip', [])
            ->assertStatus(422);
    }

    public function test_update_rejects_non_boolean_enabled(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->putJson('/api/modules/vip', ['enabled' => 'sure'])
            ->assertStatus(422);
    }

    public function test_module_registry_rejects_setting_an_override_for_a_non_toggleable_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(ModuleRegistry::class)->setOverride('install', false);
    }
}
