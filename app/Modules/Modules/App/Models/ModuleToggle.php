<?php

namespace App\Modules\Modules\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persisted on/off override for a module (see ModuleRegistry). A row here
 * always wins over the module's MODULE_* env default; no row means "use
 * the env default" - toggling never requires an owner to touch .env.
 */
class ModuleToggle extends Model
{
    protected $primaryKey = 'module_key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['module_key', 'enabled', 'updated_by'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
