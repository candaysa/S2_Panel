<?php

namespace App\Modules\Install\App\Services;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Creates the panel's own tables in the database the install wizard was
 * given - the same one the CS2 plugins write to.
 *
 * install.sh deliberately makes no database of its own: the one database a
 * CS2 server already has is the one the panel belongs in, and asking for it
 * once, in the wizard, is the only place that knows which it is. That makes
 * this the step that has to cope with whatever is already in there.
 */
class PanelDatabase
{
    /**
     * Migrations recorded in $connection that are not this panel's.
     *
     * The CS2 plugins are C#: none of them keep a Laravel `migrations`
     * table. So one that lists migrations this panel does not ship is
     * another web application's - typically a previous panel installed into
     * the same database, whose `users`/`sessions`/`reports` would collide
     * with ours. Migrating over that does not fail cleanly: it dies part way
     * on the first name clash, or worse, "succeeds" against someone else's
     * half-matching schema. Refusing up front, and saying why, is the only
     * outcome the operator can actually act on.
     *
     * @return array<int, string>
     */
    public function foreignMigrations(string $connection): array
    {
        $table = $this->migrationsTable();

        if (! Schema::connection($connection)->hasTable($table)) {
            return [];
        }

        $recorded = DB::connection($connection)->table($table)->pluck('migration')->all();
        $ours = array_keys($this->migrator()->getMigrationFiles($this->migrationPaths()));

        return array_values(array_diff($recorded, $ours));
    }

    /**
     * Run every panel migration (core and module) against $connection.
     *
     * @throws RuntimeException with the database's own reason when a
     *                          migration fails - a missing CREATE privilege
     *                          or a clashing table name is something the
     *                          operator has to see verbatim to fix
     */
    public function migrate(string $connection): void
    {
        // A migration run over a remote database can outlast the default
        // request budget, and dying half way leaves exactly the partial
        // schema this class exists to avoid.
        @set_time_limit(300);

        try {
            $exit = Artisan::call('migrate', ['--force' => true, '--database' => $connection]);
        } catch (\Throwable $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        if ($exit !== 0) {
            throw new RuntimeException(trim(Artisan::output()) ?: 'migrate exited with code '.$exit);
        }
    }

    private function migrator(): Migrator
    {
        return app('migrator');
    }

    /**
     * database/migrations plus every path a module registered through
     * loadMigrationsFrom() - the same set `php artisan migrate` walks.
     *
     * @return array<int, string>
     */
    private function migrationPaths(): array
    {
        return array_merge([database_path('migrations')], $this->migrator()->paths());
    }

    private function migrationsTable(): string
    {
        $config = config('database.migrations');

        return is_array($config) ? (string) ($config['table'] ?? 'migrations') : (string) ($config ?: 'migrations');
    }
}
