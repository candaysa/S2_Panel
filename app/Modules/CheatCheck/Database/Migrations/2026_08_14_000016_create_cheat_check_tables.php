<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cheat check tables (C18). Panel-owned data.
     *
     * status distinguishes evidence from heuristics on purpose: only
     * conclusive findings produce "cheat", heuristic-only findings land on
     * "suspicious" so a player is never auto-flagged on a hunch alone.
     */
    public function up(): void
    {
        Schema::create('cheat_scans', function (Blueprint $table): void {
            $table->id();
            $table->string('player_name', 128);
            $table->string('steam_link', 512);
            $table->string('discord_id', 128)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('finding_count')->default(0);
            $table->unsignedInteger('risk_score')->default(0);
            $table->unsignedInteger('high_count')->default(0);
            $table->unsignedInteger('medium_count')->default(0);
            $table->decimal('scan_duration', 8, 1)->nullable();
            $table->string('scan_coverage', 20)->nullable();
            $table->boolean('is_partial')->default(false);
            $table->boolean('was_elevated')->default(false);
            $table->json('findings')->nullable();
            $table->string('computer_name', 128)->nullable();
            $table->string('scan_username', 128)->nullable();
            $table->longText('raw_log')->nullable();
            $table->bigInteger('admin_steamid')->index();
            $table->string('admin_name', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('risk_score');
            $table->index('created_at');
        });

        Schema::create('cheat_scan_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('scan_id')->constrained('cheat_scans')->cascadeOnDelete();
            $table->bigInteger('admin_steamid')->index();
            $table->string('admin_name', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            // At most two downloads: the normal run uses one, the elevation
            // bootstrap re-fetches the same URL once after UAC because a
            // script started via "irm | iex" cannot recover its own source.
            $table->unsignedTinyInteger('download_count')->default(0);
            $table->string('download_ip', 45)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheat_scan_tokens');
        Schema::dropIfExists('cheat_scans');
    }
};
