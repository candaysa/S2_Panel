<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ban appeal table (C9). Owned by the panel – created by a migration,
     * never by the panel touching plugin tables.
     *
     * A banned player may file one PENDING appeal against their active ban.
     * Staff (admin.root) decide APPROVED/REJECTED; an approved appeal is the
     * signal for admins to lift the ban (the panel never mutates plugin
     * tables, so it cannot unban automatically).
     */
    public function up(): void
    {
        Schema::create('appeals', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('steamid')->index();
            $table->string('name', 64)->nullable();
            $table->bigInteger('ban_id')->nullable()->index();
            $table->text('reason');
            $table->string('status', 16)->default('PENDING');
            $table->bigInteger('decided_by')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appeals');
    }
};