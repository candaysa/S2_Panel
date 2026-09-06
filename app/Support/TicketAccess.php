<?php

namespace App\Support;

use App\Models\User;
use App\Modules\Settings\App\Services\SettingService;
use Throwable;

/**
 * Who can see every ticket of a given category (reports, admin applications,
 * ban appeals), shared by the Report and Appeal modules so the two never
 * drift apart.
 *
 * The visibility gate is owner-configurable per category (Settings >
 * Tickets), and by admin GROUP rather than raw flags: an admin who is a
 * member of the group chosen for a category sees every ticket in it, not
 * just their own. Reports and admin applications typically want different
 * groups (a generic-moderation group can triage reports; only a root-level
 * group should see admin applications) - one shared setting for every
 * category made that impossible to express. Deciding a ticket - closing a
 * report, approving or rejecting an appeal - stays fixed at admin.root
 * regardless of this setting: that action unbans a player or closes a
 * dispute, a narrower, higher-stakes capability than reading the queue.
 */
final class TicketAccess
{
    public const CATEGORIES = ['report', 'admin_application', 'ban_appeal'];

    /**
     * True for the owner, or anyone belonging to the admin group configured
     * for this ticket category. Fails closed on any error or unconfigured
     * category - a broken setting or an unreachable flag source must narrow
     * access, never widen it.
     */
    public static function isStaff(User $user, string $ticketType): bool
    {
        if ($user->isOwner()) {
            return true;
        }

        try {
            $group = self::staffGroupFor($ticketType);

            if ($group === null) {
                return false;
            }

            $profile = Flags::for((int) $user->steam_id);

            return $profile !== null && in_array($group, $profile['groups'], true);
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
     * The admin group name configured to staff one ticket category, or null
     * if the category is unknown or nothing has been configured for it yet
     * (Settings > Tickets defaults every category to "owner only").
     */
    public static function staffGroupFor(string $ticketType): ?string
    {
        if (! in_array($ticketType, self::CATEGORIES, true)) {
            return null;
        }

        $value = trim((string) app(SettingService::class)->get(self::settingKey($ticketType), ''));

        return $value === '' ? null : $value;
    }

    public static function settingKey(string $ticketType): string
    {
        return 'ticket_staff_group_'.$ticketType;
    }
}
