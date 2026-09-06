<?php

namespace App\Modules\Admin\Events;

use App\Modules\Admin\App\Models\AdminAdmin;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an admin is disabled (expires_at set to the past). Nothing is
 * ever deleted from the plugin database (project rule); the row stays and
 * Swiftly ignores it once expired.
 */
class AdminDisabled
{
    use Dispatchable;

    public function __construct(public readonly AdminAdmin $admin)
    {
    }
}