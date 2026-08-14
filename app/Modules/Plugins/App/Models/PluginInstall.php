<?php

namespace App\Modules\Plugins\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One installed third-party plugin (see PluginManager). A row here is the
 * single source of truth for "this plugin exists and is enabled" - the
 * provider class it points at gets registered dynamically at boot
 * (AppServiceProvider::register()) rather than living in bootstrap/
 * providers.php like the panel's own built-in modules do.
 */
class PluginInstall extends Model
{
    protected $table = 'plugin_installs';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'name',
        'version',
        'author',
        'description',
        'provider_class',
        'enabled',
        'installed_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
