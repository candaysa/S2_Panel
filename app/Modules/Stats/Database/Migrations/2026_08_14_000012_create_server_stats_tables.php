<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Server sample table (C12). Owned by the panel – the stats:collect
     * command queries each admin_servers row via A2S and appends one row
     * here so the panel keeps its own player-count history (the plugin
     * tables are never mutated).
     */
    public function up(): void
    {
        Schema::create('server_stats', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('server_id')->index();
            $table->integer('players')->default(0);
            $table->integer('max_players')->default(0);
            $table->string('map', 64)->nullable();
            $table->timestamp('recorded_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_stats');
    }
};