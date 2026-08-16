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

    /**
     * `status` is not consistently the literal string 'active' in real
     * data - CS2_Admin has apparently used two different conventions over
     * its history, both present in the same table at once: older rows
     * spell it out ('active', 'expired', 'unmuted'), newer ones use a
     * numeric BanStatus/MuteStatus code (confirmed against live data:
     * status=1 rows are 100% permanent and untouched, status=2 rows are
     * 100% correlated with an unban/unmute date being set, status=3 rows
     * are 100% correlated with expires_at already in the past). Checking
     * for 'active' alone against this data undercounted active
     * bans/mutes by roughly 40x - see the 2026-08-15 dashboard fix.
     */
    private const ACTIVE_STATUSES = ['active', '1'];

    /** Manually lifted before expiry - the third bucket CS2_Admin tracks
     *  separately from "ran out on its own" (status='expired'/'3'). */
    private const REMOVED_STATUSES = ['unbanned', 'unmuted', 'ungagged', '2'];

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
            ->whereNull('status')->orWhereIn('status', self::ACTIVE_STATUSES))
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Rows that have been lifted or have already expired.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->whereNotNull('status')->whereNotIn('status', self::ACTIVE_STATUSES)
            ->orWhere(fn (Builder $q): Builder => $q
                ->whereNotNull('expires_at')->where('expires_at', '<=', now())));
    }

    /**
     * A three-state read for display: 'active' (still in force), 'removed'
     * (an admin lifted it early - CS2_Admin tracks this separately from a
     * natural expiry), or 'expired' (ran out, or any status this panel
     * doesn't recognize). Mirrors scopeActive/scopeExpired's status+expiry
     * logic rather than trusting the raw column alone, so a row stuck on
     * status='active' past its own expires_at still reads as expired.
     */
    public function displayStatus(): string
    {
        if ($this->status !== null && in_array((string) $this->status, self::REMOVED_STATUSES, true)) {
            return 'removed';
        }

        $isActiveStatus = $this->status === null || in_array((string) $this->status, self::ACTIVE_STATUSES, true);
        $expired = $this->expires_at !== null && $this->expires_at->lte(now());

        return $isActiveStatus && ! $expired ? 'active' : 'expired';
    }
}