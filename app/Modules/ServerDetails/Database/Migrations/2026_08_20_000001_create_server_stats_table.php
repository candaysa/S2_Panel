<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per server per sample tick (see SampleServerStats, scheduled
     * every 5 minutes). Not a foreign key to admin_servers: that table lives
     * on the "swiftly" connection (the plugin's own database), same reason
     * server_settings isn't one either - orphaned rows from a since-removed
     * server are harmless and pruned by age regardless.
     */
    public function up(): void
    {
        Schema::create('server_stats', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedSmallInteger('players');
            $table->unsignedSmallInteger('max_players');
            $table->string('map', 64)->nullable();
            $table->timestamp('sampled_at');

            $table->index(['server_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_stats');
    }
};
