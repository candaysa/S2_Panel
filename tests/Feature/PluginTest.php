<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * Third-party plugin install/uninstall (see PluginManager). Builds real
 * .zip fixtures on the fly and exercises the whole HTTP flow - proving the
 * extraction/validation/autoload-registration mechanics actually work,
 * not just that the right DB row gets written.
 */
class PluginTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> Module directories created during a test, cleaned up in tearDown. */
    private array $createdModuleDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->createdModuleDirs as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $files  relative path within the zip => file contents
     */
    private function buildZip(array $files): UploadedFile
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'plugin_test_').'.zip';

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        // $test = true bypasses the is_uploaded_file() check so a real,
        // locally-built zip can stand in for a browser upload.
        return new UploadedFile($zipPath, 'plugin.zip', 'application/zip', null, true);
    }

    /**
     * A fresh, never-reused key per call: PHP cannot "unload" a class once
     * it's been declared, so re-using the same plugin key (-> the same
     * class name) across multiple test methods within one PHPUnit process
     * would make later tests silently see an earlier test's already-loaded
     * class instead of exercising their own fixture - most dangerously for
     * the "provider class is missing" test, where a stale class from an
     * earlier test would make class_exists() wrongly return true. A unique
     * key per call sidesteps the whole problem.
     */
    private function uniqueKey(string $prefix = 'sampleplug'): string
    {
        return $prefix.'_'.substr(bin2hex(random_bytes(4)), 0, 8);
    }

    /**
     * @param  array<string, mixed>  $manifestOverrides
     */
    private function validPluginZip(?string $key = null, array $manifestOverrides = []): UploadedFile
    {
        $key ??= $this->uniqueKey();
        $studly = \Illuminate\Support\Str::studly($key);

        $manifest = array_merge([
            'key' => $key,
            'name' => 'Sample Plugin',
            'version' => '1.0.0',
            'author' => 'Test Author',
            'description' => 'A minimal plugin used only by PluginTest.',
        ], $manifestOverrides);

        // Only track directories this helper itself creates. A
        // rejected-key test (overriding key to a real built-in module
        // like "vip") must never end up here - app/Modules/Vip is real
        // source code, not something this test is allowed to delete.
        if ($manifest['key'] === $key) {
            $this->createdModuleDirs[] = app_path("Modules/{$studly}");
        }

        return $this->buildZip([
            'plugin.json' => json_encode($manifest),
            "{$studly}ServiceProvider.php" => $this->providerSource($studly, $key),
            'Routes/api.php' => $this->routesSource($key),
        ]);
    }

    private function providerSource(string $studly, string $key): string
    {
        $template = <<<'PHP'
            <?php

            namespace App\Modules\__STUDLY__;

            use App\Support\ModuleServiceProvider;

            class __STUDLY__ServiceProvider extends ModuleServiceProvider
            {
                public function moduleKey(): string
                {
                    return '__KEY__';
                }

                protected function registerModule(): void
                {
                }

                protected function bootModule(): void
                {
                }
            }
            PHP;

        return str_replace(['__STUDLY__', '__KEY__'], [$studly, $key], $template);
    }

    private function routesSource(string $key): string
    {
        $template = <<<'PHP'
            <?php

            use Illuminate\Support\Facades\Route;

            Route::get('api/__KEY__/hello', function () {
                return response()->json(['message' => 'hello from a plugin']);
            })->name('__KEY__.hello');
            PHP;

        return str_replace('__KEY__', $key, $template);
    }

    public function test_index_requires_owner(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/plugins')
            ->assertStatus(403);
    }

    public function test_page_renders_for_the_owner(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get('/plugins')
            ->assertOk();
    }

    public function test_store_requires_owner(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/plugins', ['file' => $this->validPluginZip()])
            ->assertStatus(403);
    }

    public function test_store_installs_a_valid_plugin(): void
    {
        $owner = User::factory()->owner()->create();
        $key = $this->uniqueKey();
        $studly = \Illuminate\Support\Str::studly($key);

        $this->actingAs($owner)
            ->postJson('/api/plugins', ['file' => $this->validPluginZip($key)])
            ->assertOk()
            ->assertJsonPath('data.key', $key)
            ->assertJsonPath('data.name', 'Sample Plugin')
            ->assertJsonPath('data.enabled', true);

        $this->assertDatabaseHas('plugin_installs', [
            'key' => $key,
            'provider_class' => "App\\Modules\\{$studly}\\{$studly}ServiceProvider",
            'installed_by' => $owner->id,
        ]);

        $this->assertTrue(File::isDirectory(app_path("Modules/{$studly}")));
        $this->assertTrue(class_exists("App\\Modules\\{$studly}\\{$studly}ServiceProvider"));
    }

    public function test_store_rejects_missing_manifest(): void
    {
        $key = $this->uniqueKey();
        $studly = \Illuminate\Support\Str::studly($key);
        $this->createdModuleDirs[] = app_path("Modules/{$studly}");

        $zip = $this->buildZip(["{$studly}ServiceProvider.php" => $this->providerSource($studly, $key)]);

        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/api/plugins', ['file' => $zip])
            ->assertStatus(422)
            ->assertJsonPath('message', 'manifest_missing');
    }

    public function test_store_rejects_a_key_that_collides_with_a_built_in_module(): void
    {
        // "vip" always exists as a real built-in module key - reusing it
        // here is exactly the point of this test. validPluginZip() only
        // tracks a cleanup dir when the override key matches the generated
        // one, so app/Modules/Vip (real source) is never touched.
        $zip = $this->validPluginZip($this->uniqueKey(), ['key' => 'vip']);

        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/api/plugins', ['file' => $zip])
            ->assertStatus(422)
            ->assertJsonPath('message', 'plugin_key_taken');
    }

    public function test_store_rejects_a_duplicate_key_on_second_install(): void
    {
        $owner = User::factory()->owner()->create();
        $key = $this->uniqueKey();

        $this->actingAs($owner)->postJson('/api/plugins', ['file' => $this->validPluginZip($key)])->assertOk();

        $this->actingAs($owner)
            ->postJson('/api/plugins', ['file' => $this->validPluginZip($key)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'plugin_key_taken');
    }

    public function test_store_rejects_a_zip_with_a_path_traversal_entry(): void
    {
        $key = $this->uniqueKey();

        $zip = $this->buildZip([
            'plugin.json' => json_encode(['key' => $key, 'name' => 'Sample']),
            '../evil.php' => '<?php echo "pwned"; ?>',
        ]);

        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/api/plugins', ['file' => $zip])
            ->assertStatus(422)
            ->assertJsonPath('message', 'unsafe_zip_entry');

        // Rejected before extraction ever starts - nothing gets installed.
        $this->assertDatabaseMissing('plugin_installs', ['key' => $key]);
        $this->assertFalse(File::isDirectory(app_path('Modules/'.\Illuminate\Support\Str::studly($key))));
    }

    public function test_store_rejects_when_the_provider_class_is_missing(): void
    {
        $key = $this->uniqueKey();
        $studly = \Illuminate\Support\Str::studly($key);
        $this->createdModuleDirs[] = app_path("Modules/{$studly}");

        $zip = $this->buildZip([
            'plugin.json' => json_encode(['key' => $key, 'name' => 'Sample']),
        ]);

        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/api/plugins', ['file' => $zip])
            ->assertStatus(422)
            ->assertJsonPath('message', 'provider_class_not_found');

        // Failed installs must never leave a half-installed directory behind.
        $this->assertFalse(File::isDirectory(app_path("Modules/{$studly}")));
    }

    public function test_update_toggles_enabled(): void
    {
        $owner = User::factory()->owner()->create();
        $key = $this->uniqueKey();
        $this->actingAs($owner)->postJson('/api/plugins', ['file' => $this->validPluginZip($key)])->assertOk();

        $this->actingAs($owner)
            ->putJson("/api/plugins/{$key}", ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->assertDatabaseHas('plugin_installs', ['key' => $key, 'enabled' => false]);
    }

    public function test_destroy_uninstalls_and_deletes_its_files(): void
    {
        $owner = User::factory()->owner()->create();
        $key = $this->uniqueKey();
        $studly = \Illuminate\Support\Str::studly($key);
        $this->actingAs($owner)->postJson('/api/plugins', ['file' => $this->validPluginZip($key)])->assertOk();

        $this->assertTrue(File::isDirectory(app_path("Modules/{$studly}")));

        $this->actingAs($owner)
            ->deleteJson("/api/plugins/{$key}")
            ->assertOk()
            ->assertJsonPath('data.uninstalled', true);

        $this->assertDatabaseMissing('plugin_installs', ['key' => $key]);
        $this->assertFalse(File::isDirectory(app_path("Modules/{$studly}")));
    }

    public function test_destroy_requires_owner(): void
    {
        $this->actingAs(User::factory()->create())
            ->deleteJson('/api/plugins/'.$this->uniqueKey())
            ->assertStatus(403);
    }

    /**
     * End-to-end proof that installing a plugin actually wires its routes
     * up - not just that the install endpoint writes the right files/DB
     * row. PluginManager::install() registers the freshly-installed
     * provider on the current, already-booted application immediately
     * (see its docblock) - the same "register a provider on an already-
     * booted app" mechanism AppServiceProvider::registerInstalledPlugins()
     * relies on for every request after this one. A plain, unauthenticated
     * route was deliberately used in the fixture so this test proves
     * routing alone, without conflating it with auth/flag middleware.
     */
    public function test_an_installed_plugins_routes_are_reachable_immediately(): void
    {
        $key = $this->uniqueKey();

        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/api/plugins', ['file' => $this->validPluginZip($key)])
            ->assertOk();

        $this->getJson("/api/{$key}/hello")
            ->assertOk()
            ->assertJsonPath('message', 'hello from a plugin');
    }
}
