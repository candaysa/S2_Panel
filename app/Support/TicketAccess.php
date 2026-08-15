<?php

namespace App\Support;

use App\Models\User;
use App\Modules\Settings\App\Services\SettingService;
use Throwable;

/**
 * Who can see every ticket (reports, admin applications, ban appeals),
 * shared by the Report and Appeal modules so the two never drift apart.
 *
 * The visibility gate is owner-configurable (Settings > Tickets ->
 * ticket_staff_flags): previously each controller hardcoded a check for
 * 'admin.generic', which meant changing who could work tickets required a
 * code change and a deploy. Deciding a ticket - closing a report, approving
 * or rejecting an appeal - stays fixed at admin.root regardless of that
 * setting: that action unbans a player or closes a dispute, which is a
 * narrower, higher-stakes capability than reading the queue and not
 * something this setting is meant to hand out.
 */
final class TicketAccess
{
    /**
     * True for the owner, or anyone holding one of the configured
     * ticket-staff flags. Fails closed on any error - a broken setting or
     * an unreachable flag source must narrow access, never widen it.
     */
    public static function isStaff(User $user): bool
    {
        if ($user->isOwner()) {
            return true;
        }

        try {
            $flags = self::staffFlags();

            return $flags !== [] && Flags::hasAnyFlag((int) $user->steam_id, $flags);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Deciding a ticket (close/approve/reject) always requires admin.root -
     * not owner-configurable, unlike read access above.
     */
    public static function canDecide(User $user): bool
    {
        if ($user->isOwner()) {
            return true;
        }

        try {
            return Flags::hasFlag((int) $user->steam_id, 'admin.root');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    public static function staffFlags(): array
    {
        $raw = (string) app(SettingService::class)->get('ticket_staff_flags', 'admin.generic');

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn (string $f): bool => $f !== ''));
    }
}
