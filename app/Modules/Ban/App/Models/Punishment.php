<?php

namespace App\Modules\Ban\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for the four Swiftly punishment tables (C4). Read-only models on the
 * "swiftly" connection – the plugin owns these tables; the panel only
 * queries them. Subclasses set the concrete table name.
 */
abstract class Punishment extends Model
{
    protected $connection = 'swiftly';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            // SteamID64 exceeds JavaScript's safe integer range, so a
            // JSON number silently loses its last digit in the browser
            // and every profile link built from it points at a
            // different account. It is an identifier, never an
            // arithmetic value - keep it a string on the wire.
            'steamid' => 'string',
            'admin_steamid' => 'string',
            'is_global' => 'boolean',
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
            'unban_date' => 'datetime',
        ];
    }

    /**
     * Rows that are currently in force: status says active (or has no
     * status column) and the expiry is null or in the future.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->whereNull('status')->orWhere('status', 'active'))
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Rows that have been lifted or have already expired.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->whereNotNull('status')->where('status', '!=', 'active')
            ->orWhere(fn (Builder $q): Builder => $q
                ->whereNotNull('expires_at')->where('expires_at', '<=', now())));
    }
}