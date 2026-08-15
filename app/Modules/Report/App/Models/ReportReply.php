<?php

namespace App\Modules\Report\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reply inside a report ticket (C8). Panel-owned table.
 */
class ReportReply extends Model
{
    protected $table = 'report_replies';

    public $timestamps = false;

    protected $fillable = [
        'report_id',
        'author_steamid',
        'author_name',
        'message',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            // SteamID64 exceeds JavaScript's safe integer range, so a
            // JSON number silently loses its last digit in the browser
            // and every profile link built from it points at a
            // different account. It is an identifier, never an
            // arithmetic value - keep it a string on the wire.
            'author_steamid' => 'string',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }
}