<?php

namespace App\Modules\Health\App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A health component flipped to "down" (C16). The Webhook module (C17)
 * listens for this event to push a Discord notification.
 */
class HealthAlert
{
    use Dispatchable;

    public function __construct(
        public readonly string $component,
        public readonly string $status,
        public readonly ?string $message = null,
    ) {
    }
}