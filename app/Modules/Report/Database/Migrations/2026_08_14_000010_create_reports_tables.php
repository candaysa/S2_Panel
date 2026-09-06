<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Report/ticket tables (C8). Owned by the panel – unlike the Swiftly
     * plugin tables these are created by a migration and safe to grow.
     *
     * report_reason/reporter_steamid are declared up-front alongside
     * ticket_type (report/admin_application), status (open/closed) and
     * resolution (APPROVED/REJECTED), so nothing is missing from the schema
     * a fresh install migrates.
     */
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->string('ticket_type', 32)->default('report');
            $table->string('status', 16)->default('open');
            $table->string('resolution', 16)->nullable();
            $table->bigInteger('reporter_steamid')->index();
            $table->string('reporter_name', 64)->nullable();
            $table->bigInteger('target_steamid')->nullable()->index();
            $table->string('target_name', 64)->nullable();
            $table->text('report_reason');
            $table->integer('server_id')->nullable();
            $table->timestamps();
        });

        Schema::create('report_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->bigInteger('author_steamid')->index();
            $table->string('author_name', 64)->nullable();
            $table->text('message');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_replies');
        Schema::dropIfExists('reports');
    }
};