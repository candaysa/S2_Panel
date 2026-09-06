<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Panel-owned per-server presentation settings.
     *
     * A separate table rather than columns on the plugin's admin_servers:
     * that table belongs to Swiftly, the plugin rewrites rows in it, and the
     * panel's rule everywhere else is to read plugin schema without altering
     * it. Keeping the override here means hiding a server is reversible and
     * survives the plugin re-registering that server.
     */
    public function up(): void
    {
        Schema::create('server_settings', function (Blueprint $table): void {
            $table->id();
            // Not a foreign key: admin_servers lives on a different
            // connection (the plugin database), so the constraint could not
            // be enforced anyway. Orphan rows are harmless and get cleaned up
            // when a server is deleted through the panel.
            $table->unsignedBigInteger('server_id')->unique();
            $table->boolean('hidden')->default(false);
            $table->string('display_name', 128)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_settings');
    }
};
