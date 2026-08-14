<?php

namespace App\Modules\Webhook\App\Jobs;

use App\Modules\Webhook\App\Models\Webhook;
use App\Modules\Webhook\App\Services\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Deliver one webhook embed asynchronously (C17). Uses the configured
 * queue (database in production); every attempt is recorded by the
 * service, and a failed attempt throws so the queue retries it.
 */
class WebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public Webhook $webhook,
        public string $event,
        public array $payload,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(WebhookService $service): void
    {
        $result = $service->deliver($this->webhook, $this->event, $this->payload);

        if (! $result['ok']) {
            throw new RuntimeException('webhook_delivery_failed');
        }
    }
}