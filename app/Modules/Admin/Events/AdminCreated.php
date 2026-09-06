<?php

namespace App\Modules\Admin\Events;

use App\Modules\Admin\App\Models\AdminAdmin;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an admin row is created (C3). The Webhook module listens for
 * this to notify Discord admins.
 */
class AdminCreated
{
    use Dispatchable;

    public function __construct(public readonly AdminAdmin $admin)
    {
    }
}