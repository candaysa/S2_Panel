<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Panel audit log (C7). Owned by the panel – unlike the Swiftly plugin
     * tables this one is created by a migration and is safe to grow.
     *
     * Every sensitive mutation across modules (admin CRUD, settings, later
     * bans/ranks/skins) records a row here via AuditService. Action names
     * follow "<module>.<verb>" (e.g. "admin.created", "admin.disabled").
     */
    public function up(): void
    {
        Schema::create('panel_logs', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('actor_steamid')->nullable()->index();
            $table->string('actor_name', 64)->nullable();
            $table->string('action', 64)->index();
            $table->string('target_type', 32)->nullable()->index();
            $table->string('target_id', 32)->nullable();
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_logs');
    }
};