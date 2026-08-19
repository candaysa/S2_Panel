<?php

namespace App\Modules\CheatCheck\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheatScanToken extends Model
{
    // The elevation bootstrap alone needs 2 (the plain fetch, then its
    // UAC-elevated retry) - see CheatCheckService::elevationBootstrap().
    // The extra budget above that is for the same link surviving real
    // friction between "the admin generates it" and "the player actually
    // runs it": a re-paste after a UAC prompt opened behind another
    // window and visibly nothing happened, or one prefetch by a chat
    // client's own link scanner. Once a scan actually resolves,
    // serveScript() rejects any further download regardless of this
    // budget (the scan's own status, not the count, is what closes the
    // token for good) - so this only bounds how much friction the
    // handshake itself can absorb before it succeeds once.
    public const MAX_DOWNLOADS = 5;

    public $timestamps = false;

    protected $table = 'cheat_scan_tokens';

    protected $fillable = [
        'token',
        'scan_id',
        'admin_steamid',
        'admin_name',
        'expires_at',
        'consumed_at',
        'download_count',
        'download_ip',
        'ip_address',
        'created_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
            // SteamID64 exceeds JavaScript's safe integer range, so a
            // JSON number silently loses its last digit in the browser
            // and every profile link built from it points at a
            // different account. It is an identifier, never an
            // arithmetic value - keep it a string on the wire.
            'admin_steamid' => 'string',
            'download_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CheatScan, $this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(CheatScan::class, 'scan_id');
    }

    public function isValid(): bool
    {
        return $this->download_count < self::MAX_DOWNLOADS && $this->expires_at->isFuture();
    }
}
