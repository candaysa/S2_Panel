<?php

namespace App\Modules\CheatCheck\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheatScanToken extends Model
{
    public const MAX_DOWNLOADS = 2;

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
            'admin_steamid' => 'integer',
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
