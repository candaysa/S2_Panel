<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff notes attached to a player's profile (moderator/player notes).
     * Panel-owned - not a Swiftly plugin table. Append/delete only, no
     * edit: a correction is a new note, so the trail on a player never
     * silently changes shape after the fact.
     */
    public function up(): void
    {
        Schema::create('player_notes', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('steamid')->index();
            $table->text('note');
            $table->bigInteger('author_steamid');
            $table->string('author_name', 64)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_notes');
    }
};
