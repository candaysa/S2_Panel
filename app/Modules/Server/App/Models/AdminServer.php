<?php

namespace App\Modules\Server\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Swiftly admin_servers row (C10). Read-only model on the "swiftly"
 * connection – the CS2_Admin plugin owns this table and upserts its own
 * rows at startup. server_id is the plugin's IP:PORT identity string;
 * live hostname/map/players come from A2S, never from this table.
 *
 * Visibility is a panel concern, so it is kept out of this table entirely -
 * see ServerSetting and the visible() scope.
 */
class AdminServer extends Model
{
    protected $connection = 'swiftly';

    protected $table = 'admin_servers';

    public $timestamps = false;

    protected $fillable = [
        'server_id',
        'server_ip',
        'server_port',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'server_port' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Servers an ordinary visitor should see.
     *
     * The hidden set lives on the panel connection, so this cannot be a join
     * - it filters on ids read from there. Cached because the public server
     * list and the dashboard both run this on every anonymous page view, and
     * the set changes only when an owner flips a toggle.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNotIn('id', self::hiddenIds());
    }

    /**
     * @return array<int, int>
     */
    public static function hiddenIds(): array
    {
        try {
            return Cache::remember('server.hidden_ids', 300, fn (): array => ServerSetting::query()
                ->where('hidden', true)
                ->pluck('server_id')
                ->all());
        } catch (Throwable) {
            // server_settings may not exist yet (pre-migrate). Showing every
            // server is the safe failure here - hiding one is cosmetic, and
            // an empty list would look like the panel lost its servers.
            return [];
        }
    }

    public static function forgetHiddenIds(): void
    {
        Cache::forget('server.hidden_ids');
    }
}