<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Appeal\App\Events\AppealCreated;
use App\Modules\Appeal\App\Events\AppealDecided;
use App\Modules\Appeal\App\Models\Appeal;
use App\Modules\Auth\Events\UserRegistered;
use App\Modules\Health\App\Events\HealthAlert;
use App\Modules\Rcon\App\Events\RconActionPerformed;
use App\Modules\Report\App\Events\ReportCreated;
use App\Modules\Report\App\Events\ReportReplied;
use App\Modules\Report\App\Models\Report;
use App\Modules\Report\App\Models\ReportReply;
use App\Modules\Webhook\App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Discord webhook notifications (C17). Management is owner-only, the URL
 * is encrypted at rest and never returned, delivery is asynchronous via
 * the queue (sync in tests) and every attempt lands in webhook_deliveries.
 */
class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const DISCORD_URL = 'https://discord.com/api/webhooks/123456789012345678/abcdefGHIJKLMNOP';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_page_renders_for_the_owner(): void
    {
        $this->actingAs($this->createOwner())
            ->get('/webhooks')
            ->assertOk();
    }

    private function createOwner(int $steamid64 = 76561197960512640): User
    {
        return User::factory()->create([
            'steam_id' => (string) $steamid64,
            'name' => 'Owner',
            'is_owner' => true,
        ]);
    }

    private function addWebhook(array $overrides = []): Webhook
    {
        return Webhook::query()->create(array_merge([
            'name' => 'Main',
            'url' => self::DISCORD_URL,
            'events' => ['health.alert', 'report.created'],
            'enabled' => true,
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/webhooks')->assertStatus(401);
    }

    public function test_index_requires_owner(): void
    {
        $staff = User::factory()->create(['steam_id' => '76561197960512640']);

        $this->actingAs($staff)
            ->getJson('/api/webhooks')
            ->assertStatus(403);
    }

    public function test_store_creates_webhook_with_encrypted_url(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner)
            ->postJson('/api/webhooks', [
                'name' => 'Ops alerts',
                'url' => self::DISCORD_URL,
                'events' => ['health.alert'],
                'enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ops alerts')
            ->assertJsonPath('data.events.0', 'health.alert')
            ->assertJsonPath('data.enabled', true);

        $row = Webhook::query()->first();
        $this->assertNotNull($row);

        // getRawOriginal() returns the raw DB value – still encrypted at rest
        // (the model accessor would already decrypt it).
        $rawUrl = $row->getRawOriginal('url');
        $this->assertNotSame(self::DISCORD_URL, $rawUrl, 'url must be encrypted at rest');
        $this->assertSame(self::DISCORD_URL, Crypt::decryptString($rawUrl));

        // The raw URL must never leak into the response.
        $this->actingAs($owner)
            ->getJson('/api/webhooks')
            ->assertOk()
            ->assertJsonMissing(['url' => self::DISCORD_URL])
            ->assertJsonPath('data.0.url_hint', '•••'.substr(self::DISCORD_URL, -12));
    }

    public function test_store_validates_input(): void
    {
        $owner = $this->createOwner();

        $base = ['name' => 'Ops', 'url' => self::DISCORD_URL, 'events' => ['health.alert']];

        $this->actingAs($owner)->postJson('/api/webhooks', array_merge($base, ['name' => '']))->assertStatus(422);
        $this->actingAs($owner)->postJson('/api/webhooks', array_merge($base, ['url' => 'https://evil.example/hook']))->assertStatus(422);
        $this->actingAs($owner)->postJson('/api/webhooks', array_merge($base, ['url' => 'not-a-url']))->assertStatus(422);
        $this->actingAs($owner)->postJson('/api/webhooks', array_merge($base, ['events' => []]))->assertStatus(422);
        $this->actingAs($owner)->postJson('/api/webhooks', array_merge($base, ['events' => ['unknown.event']]))->assertStatus(422);
    }

    public function test_update_changes_fields_and_keeps_url_when_omitted(): void
    {
        $owner = $this->createOwner();
        $webhook = $this->addWebhook();

        $this->actingAs($owner)
            ->putJson("/api/webhooks/{$webhook->id}", [
                'name' => 'Renamed',
                'events' => ['appeal.created', 'appeal.decided'],
                'enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.enabled', false);

        $webhook->refresh();
        $this->assertSame(self::DISCORD_URL, Crypt::decryptString($webhook->getRawOriginal('url')), 'omitted url must be preserved');
        $this->assertSame(['appeal.created', 'appeal.decided'], $webhook->events);
    }

    public function test_update_unknown_returns_404(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner)
            ->putJson('/api/webhooks/999', ['name' => 'x'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'not_found');
    }

    public function test_destroy_deletes_webhook(): void
    {
        $owner = $this->createOwner();
        $webhook = $this->addWebhook();

        $this->actingAs($owner)
            ->deleteJson("/api/webhooks/{$webhook->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $webhook->id);

        $this->assertDatabaseMissing('webhooks', ['id' => $webhook->id]);
    }

    public function test_destroy_unknown_returns_404(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner)
            ->deleteJson('/api/webhooks/999')
            ->assertStatus(404);
    }

    public function test_test_send_posts_embed_and_records_delivery(): void
    {
        Http::fake([
            self::DISCORD_URL => Http::response(null, 204),
        ]);

        $owner = $this->createOwner();
        $webhook = $this->addWebhook();

        $this->actingAs($owner)
            ->postJson("/api/webhooks/{$webhook->id}/test")
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.response_status', 204);

        Http::assertSent(function ($request) use ($webhook): bool {
            $payload = $request->data();

            return $request->url() === $webhook->url
                && $payload['username'] === 'S2 Panel'
                && $payload['embeds'][0]['title'] === 'Test webhook'
                && isset($payload['embeds'][0]['color']);
        });

        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_id' => $webhook->id,
            'event' => 'test',
            'status' => 'sent',
            'response_status' => 204,
        ]);
    }

    public function test_test_send_failure_records_failed_delivery(): void
    {
        Http::fake([
            self::DISCORD_URL => Http::response(null, 500),
        ]);

        $owner = $this->createOwner();
        $webhook = $this->addWebhook();

        $this->actingAs($owner)
            ->postJson("/api/webhooks/{$webhook->id}/test")
            ->assertStatus(502)
            ->assertJsonPath('message', 'webhook_delivery_failed');

        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_id' => $webhook->id,
            'status' => 'failed',
            'response_status' => 500,
        ]);
    }

    public function test_test_send_unknown_returns_404(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner)
            ->postJson('/api/webhooks/999/test')
            ->assertStatus(404);
    }

    public function test_health_alert_event_fans_out_to_subscribed_webhook(): void
    {
        Http::fake();
        $webhook = $this->addWebhook(['events' => ['health.alert']]);

        HealthAlert::dispatch('db:swiftly', 'down', 'database connection failed');

        Http::assertSent(function ($request) use ($webhook): bool {
            $payload = $request->data();

            return $request->url() === $webhook->url
                && $payload['embeds'][0]['title'] === 'Health alert'
                && $payload['embeds'][0]['color'] === 0xE74C3C
                && $payload['embeds'][0]['fields'][0]['name'] === 'Component'
                && $payload['embeds'][0]['fields'][0]['value'] === 'db:swiftly';
        });

        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_id' => $webhook->id,
            'event' => 'health.alert',
            'status' => 'sent',
        ]);
    }

    public function test_event_not_subscribed_does_not_deliver(): void
    {
        Http::fake();
        $this->addWebhook(['events' => ['report.created']]);

        HealthAlert::dispatch('db:ranks', 'down', null);

        Http::assertNothingSent();
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_disabled_webhook_receives_nothing(): void
    {
        Http::fake();
        $this->addWebhook(['events' => ['health.alert'], 'enabled' => false]);

        HealthAlert::dispatch('db:ranks', 'down', null);

        Http::assertNothingSent();
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_user_registered_event_delivers(): void
    {
        Http::fake();
        $webhook = $this->addWebhook(['events' => ['user.registered']]);
        $user = User::factory()->create(['steam_id' => '76561197960512640', 'name' => 'Newbie']);

        UserRegistered::dispatch($user);

        Http::assertSent(fn ($request): bool => $request->url() === $webhook->url);

        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_id' => $webhook->id,
            'event' => 'user.registered',
            'status' => 'sent',
        ]);
    }

    public function test_rcon_action_event_delivers_as_admin_action(): void
    {
        Http::fake();
        $webhook = $this->addWebhook(['events' => ['admin.action']]);

        RconActionPerformed::dispatch(5, 'ban', 'STEAM_1:0:123', '1440', true);

        Http::assertSent(function ($request) use ($webhook): bool {
            $payload = $request->data();

            return $request->url() === $webhook->url
                && $payload['embeds'][0]['title'] === 'Admin action performed'
                && $payload['embeds'][0]['fields'][0]['name'] === 'Action'
                && $payload['embeds'][0]['fields'][0]['value'] === 'BAN';
        });

        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_id' => $webhook->id,
            'event' => 'admin.action',
            'status' => 'sent',
        ]);
    }

    public function test_report_and_appeal_events_deliver(): void
    {
        Http::fake();
        $webhook = $this->addWebhook(['events' => ['report.created', 'report.replied', 'appeal.created', 'appeal.decided']]);

        $report = Report::query()->create([
            'ticket_type' => 'report',
            'status' => 'open',
            'reporter_steamid' => 76561197960512640,
            'reporter_name' => 'Reporter',
            'target_steamid' => 76561197960512641,
            'target_name' => 'Target',
            'report_reason' => 'wallhack',
        ]);
        $reply = ReportReply::query()->create([
            'report_id' => $report->id,
            'author_steamid' => 76561197960512640,
            'author_name' => 'Staff',
            'message' => 'noted',
        ]);
        $appeal = Appeal::query()->create([
            'steamid' => 76561197960512640,
            'name' => 'Banned User',
            'ban_id' => 1,
            'reason' => 'appealing',
            'status' => 'PENDING',
        ]);

        ReportCreated::dispatch($report);
        ReportReplied::dispatch($report, $reply);
        AppealCreated::dispatch($appeal);
        AppealDecided::dispatch($appeal);

        $this->assertDatabaseHas('webhook_deliveries', ['event' => 'report.created', 'status' => 'sent']);
        $this->assertDatabaseHas('webhook_deliveries', ['event' => 'report.replied', 'status' => 'sent']);
        $this->assertDatabaseHas('webhook_deliveries', ['event' => 'appeal.created', 'status' => 'sent']);
        $this->assertDatabaseHas('webhook_deliveries', ['event' => 'appeal.decided', 'status' => 'sent']);
    }

    public function test_embed_uses_event_specific_color_and_username(): void
    {
        Http::fake();
        $webhook = $this->addWebhook(['events' => ['appeal.decided']]);
        $appeal = Appeal::query()->create([
            'steamid' => 76561197960512640,
            'name' => 'Banned User',
            'ban_id' => 1,
            'reason' => 'appealing',
            'status' => 'APPROVED',
        ]);

        AppealDecided::dispatch($appeal);

        Http::assertSent(function ($request) use ($webhook): bool {
            $payload = $request->data();

            return $request->url() === $webhook->url
                && $payload['username'] === 'S2 Panel'
                && $payload['embeds'][0]['color'] === 0x8E44AD
                && $payload['embeds'][0]['fields'][2]['name'] === 'Decision'
                && $payload['embeds'][0]['fields'][2]['value'] === 'APPROVED';
        });
    }
}
