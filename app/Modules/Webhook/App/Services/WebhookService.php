<?php

namespace App\Modules\Webhook\App\Services;

use App\Modules\Webhook\App\Jobs\WebhookJob;
use App\Modules\Webhook\App\Models\Webhook;
use App\Modules\Webhook\App\Models\WebhookDelivery;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Discord webhook delivery (C17).
 *
 * dispatch() fans an event out to every enabled webhook that subscribed
 * to it (asynchronous – one queued job per webhook). deliver() renders
 * the Discord embed and records every attempt into webhook_deliveries.
 *
 * The webhook URL stays out of every API response: clients see only a
 * masked hint of the stored (encrypted) URL.
 */
class WebhookService
{
    /**
     * Queue the event to every subscribing, enabled webhook.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $event, array $payload): void
    {
        foreach (Webhook::query()->where('enabled', true)->get() as $webhook) {
            if (! $webhook->hasEvent($event)) {
                continue;
            }

            WebhookJob::dispatch($webhook, $event, $payload);
        }
    }

    /**
     * Send one embed synchronously and record the attempt.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, response_status: ?int, error: ?string}
     */
    public function deliver(Webhook $webhook, string $event, array $payload): array
    {
        try {
            $response = Http::timeout((int) config('webhook.timeout', 5))
                ->post($webhook->url, $this->embed($event, $payload));

            $ok = $response->successful();
            $responseStatus = $response->status();
            $error = $ok ? null : ('http_'.$response->status());
        } catch (Throwable $e) {
            $ok = false;
            $responseStatus = null;
            $error = $e->getMessage();
        }

        WebhookDelivery::query()->create([
            'webhook_id' => $webhook->id,
            'event' => $event,
            'status' => $ok ? 'sent' : 'failed',
            'response_status' => $responseStatus,
            'error' => $error,
            'attempted_at' => now(),
        ]);

        return [
            'ok' => $ok,
            'response_status' => $responseStatus,
            'error' => $error,
        ];
    }

    /**
     * Check, create or update a webhook.
     */
    public function save(?Webhook $webhook, array $data): Webhook
    {
        if ($webhook === null) {
            return Webhook::query()->create($data);
        }

        $webhook->update($data);

        return $webhook;
    }

    /**
     * Verify the webhook by posting a test embed (skip on failure).
     *
     * @return array{ok: bool, response_status: ?int, error: ?string}
     */
    public function test(Webhook $webhook): array
    {
        return $this->deliver($webhook, 'test', [
            'title' => 'Test webhook',
            'description' => 'This is a test message from S2 Panel.',
            'fields' => [],
        ]);
    }

    /**
     * Build the Discord webhook payload (username + one embed).
     *
     * @param  array<string, mixed>  $payload
     * @return array{username: string, embeds: list<array<string, mixed>>}
     */
    public function embed(string $event, array $payload): array
    {
        // Config keys contain dots (health.alert, appeal.decided, ...), so
        // read the events map directly instead of using dot-notation lookup.
        $events = (array) config('webhook.events', []);

        $defaults = (array) ($events[$event] ?? [
            'title' => ucfirst($event),
            'color' => 0x5865F2,
        ]);

        $fields = [];

        foreach ((array) ($payload['fields'] ?? []) as $field) {
            if (is_array($field) && isset($field['name'], $field['value'])) {
                $fields[] = [
                    'name' => (string) $field['name'],
                    'value' => (string) $field['value'],
                    'inline' => (bool) ($field['inline'] ?? false),
                ];
            }
        }

        $embed = [
            'title' => (string) ($payload['title'] ?? $defaults['title']),
            'description' => isset($payload['description']) ? (string) $payload['description'] : null,
            'color' => (int) ($defaults['color'] ?? 0x5865F2),
            'timestamp' => now()->toIso8601String(),
        ];

        if ($fields !== []) {
            $embed['fields'] = $fields;
        }

        return [
            'username' => (string) config('webhook.embed.username', 'S2 Panel'),
            'embeds' => [$embed],
        ];
    }
}