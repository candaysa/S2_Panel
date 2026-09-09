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
 * category made that impossible to express. A category can also be handed
 * to ANY_ADMIN instead of a specific group, for a server that just wants
 * "every admin sees this", regardless of which group they're in - Flags::
 * for() already abstracts over both supported admin plugins (CS2_Admin's
 * admin_admins vs the official swiftlys2-plugins/admins' own table), so
 * this reads correctly under either without knowing which is active.
 * Deciding a ticket - closing a report, approving or rejecting an appeal -
 * stays fixed at admin.root regardless of this setting: that action unbans
 * a player or closes a dispute, a narrower, higher-stakes capability than
 * reading the queue.
 */
final class TicketAccess
{
    public const CATEGORIES = ['report', 'admin_application', 'ban_appeal'];

    /**
     * Sentinel stored in the ticket_staff_group_* setting instead of a real
     * group name - "any admin, in either supported plugin, no matter which
     * group" rather than membership in one specific group. Not a valid
     * group name in either plugin's own schema (both key groups by their
     * own plain display name), so it can never collide with a real one.
     */
    public const ANY_ADMIN = '__any_admin__';

    /**
     * True for the owner, for anyone belonging to the admin group
     * configured for this ticket category, or - when the category is set
     * to ANY_ADMIN - anyone who is an admin at all under whichever plugin
     * is currently active. Fails closed on any error or unconfigured
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

            if ($profile === null) {
                return false;
            }

            return $group === self::ANY_ADMIN || in_array($group, $profile['groups'], true);
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
     * The admin group name configured to staff one ticket category - or
     * ANY_ADMIN, or null if the category is unknown or nothing has been
     * configured for it yet (Settings > Tickets defaults every category to
     * "owner only").
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
