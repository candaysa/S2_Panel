<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Panel-owned tables for the Health module (C16): the health checks
     * log and the owner notification center. Neither touches plugin tables.
     */
    public function up(): void
    {
        Schema::create('health_checks', function (Blueprint $table): void {
            $table->id();
            $table->string('component', 64);            // e.g. "db:swiftly", "rcon:12"
            $table->string('status', 8);                // "ok" | "down"
            $table->string('message', 255)->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['component', 'checked_at']);
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('type', 64);                 // e.g. "health.alert"
            $table->string('title', 191);
            $table->text('body')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('health_checks');
    }
};