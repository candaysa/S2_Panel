<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RCON credentials per server (C11). Panel-owned table – the RCON
     * password is NEVER added to the plugin's admin_servers table; it
     * lives here, encrypted with the application key. server_id maps to
     * Swiftly's admin_servers.id but is intentionally NOT a foreign key
     * (the plugin owns that table).
     */
    public function up(): void
    {
        Schema::create('rcon_settings', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('server_id')->unique();
            $table->text('password');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rcon_settings');
    }
};