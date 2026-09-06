<?php

namespace App\Modules\Settings\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value store for panel settings (C14). Lives on the panel's own
 * connection; plugin databases are never touched by this module.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}