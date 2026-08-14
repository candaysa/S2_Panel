<?php

namespace App\Modules\Rcon\App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A player action was executed through RCON (C11) – kick, ban or slay.
 * The Webhook module (C17) listens for it as the "admin.action" event.
 */
class RconActionPerformed
{
    use Dispatchable;

    public function __construct(
        public readonly int $serverId,
        public readonly string $action,   // kick | ban | slay
        public readonly string $target,
        public readonly ?string $detail,  // reason or duration
        public readonly bool $ok,
    ) {
    }
}