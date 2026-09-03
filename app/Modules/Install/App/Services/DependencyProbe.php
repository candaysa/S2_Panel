<?php

namespace App\Modules\Install\App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reports which plugin integrations are actually present in a database.
 *
 * A reachable database is not the same as a usable one. The panel reads and
 * writes tables created by the server-side plugins, so pointing it at an
 * empty or wrong schema connects successfully and then fails later, one blank
 * page at a time. This inspects the schema up front and names what is missing
 * while the operator is still on the step that can fix it.
 *
 * Missing integrations are reported, never enforced. Running CS2_Admin without
 * VIPCore is a normal setup, and the matching module can simply stay off.
 */
class DependencyProbe
{
    /**
     * Tables each integration needs, keyed by the module that consumes them.
     *
     * @var array<string, array<int, string>>
     */
    private const REQUIREMENTS = [
        'admin' => ['admin_admins', 'admin_groups', 'admin_servers'],
        'ban' => ['admin_bans', 'admin_mutes', 'admin_gags', 'admin_warns'],
        'rank' => ['lvl_base', 'lvl_base_hits'],
        'skin' => ['wp_player_skins', 'wp_player_knife', 'wp_player_gloves', 'wp_player_agents', 'wp_player_music'],
        'vip' => ['vip_users', 'vip_servers'],
    ];

    /**
     * Same as the CS2_Admin 'admin' entry above, but for the official
     * swiftlys2-plugins/admins schema (see App\Support\AdminPlugin) -
     * substituted in when the install wizard's plugin choice says so.
     */
    private const SWIFTLY_ADMINS_REQUIREMENT = ['admins', 'groups', 'servers'];

    /**
     * Inspect one connection and report each integration's state.
     *
     * @return array<int, array{key: string, satisfied: bool, missing: array<int, string>}>
     */
    public function inspect(string $connection, string $adminPlugin = 'cs2_admin'): array
    {
        $present = $this->tables($connection);

        $requirements = self::REQUIREMENTS;

        if ($adminPlugin === 'swiftly_admins') {
            $requirements['admin'] = self::SWIFTLY_ADMINS_REQUIREMENT;
        }

        $report = [];

        foreach ($requirements as $key => $required) {
            $missing = array_values(array_filter(
                $required,
                fn (string $table): bool => ! in_array(strtolower($table), $present, true),
            ));

            $report[] = [
                'key' => $key,
                'satisfied' => $missing === [],
                'missing' => $missing,
            ];
        }

        return $report;
    }

    /**
     * Lowercased table names in the connection's own schema.
     *
     * Read from information_schema rather than SHOW TABLES so the lookup is a
     * single query, and compared case-insensitively because MySQL's table name
     * casing follows the host filesystem.
     *
     * @return array<int, string>
     */
    private function tables(string $connection): array
    {
        try {
            $rows = DB::connection($connection)
                ->select('SELECT LOWER(table_name) AS name FROM information_schema.tables WHERE table_schema = DATABASE()');

            return array_map(fn (object $row): string => (string) $row->name, $rows);
        } catch (Throwable) {
            // The caller has already established that the connection is
            // healthy; if the schema cannot be read anyway, report everything
            // as missing rather than claiming every integration is present.
            return [];
        }
    }
}
