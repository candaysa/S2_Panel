<?php

namespace App\Modules\Auth\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a Steam account registers with the panel for the first time.
 * The Webhook module listens to this for the "user.registered" event.
 */
class UserRegistered
{
    use Dispatchable;

    public function __construct(public User $user)
    {
    }
}