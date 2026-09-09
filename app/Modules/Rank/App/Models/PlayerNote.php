<?php

namespace App\Modules\Rank\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A staff note attached to a player's profile (C5). Panel-owned table -
 * append/delete only, see the migration's own docblock for why there is no
 * edit.
 */
class PlayerNote extends Model
{
    public $timestamps = false;

    protected $table = 'player_notes';

    protected $fillable = [
        'steamid',
        'note',
        'author_steamid',
        'author_name',
    ];

    protected function casts(): array
    {
        return [
            // SteamID64 exceeds JavaScript's safe integer range, so a
            // JSON number silently loses its last digit in the browser
            // and every profile link built from it points at a
            // different account. It is an identifier, never an
            // arithmetic value - keep it a string on the wire.
            'steamid' => 'string',
            'author_steamid' => 'string',
            'created_at' => 'datetime',
        ];
    }
}
