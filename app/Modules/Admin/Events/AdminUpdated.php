<?php

namespace App\Modules\Admin\Events;

use App\Modules\Admin\App\Models\AdminAdmin;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an admin row is updated (C3).
 */
class AdminUpdated
{
    use Dispatchable;

    public function __construct(public readonly AdminAdmin $admin)
    {
    }
}