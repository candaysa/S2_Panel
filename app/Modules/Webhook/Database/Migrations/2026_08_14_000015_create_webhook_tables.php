<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Panel-owned tables for the Webhook module (C17). The webhook URL is
     * stored encrypted (Discord token is embedded in it) and never
     * returned by the API; webhook_deliveries keeps the send/retry trail.
     */
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191);
            $table->text('url');                        // encrypted (Discord webhook URL pattern)
            $table->json('events');                     // selected event types
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('webhook_id')->nullable()->index();
            $table->string('event', 64);
            $table->string('status', 8);                // "sent" | "failed"
            $table->integer('response_status')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
    }
};