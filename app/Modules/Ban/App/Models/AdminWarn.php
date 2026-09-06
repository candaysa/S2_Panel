<?php

namespace App\Modules\Ban\App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Swiftly admin_warns row (C4). Warns carry no status column – their
 * "active" state is purely expiry based, so the base scopes (which touch
 * `status`) are overridden here.
 */
class AdminWarn extends Punishment
{
    protected $table = 'admin_warns';

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }
}