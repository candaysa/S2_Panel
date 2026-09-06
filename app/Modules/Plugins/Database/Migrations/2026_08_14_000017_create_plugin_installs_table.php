<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_installs', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('name');
            $table->string('version')->nullable();
            $table->string('author')->nullable();
            $table->text('description')->nullable();
            $table->string('provider_class');
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('installed_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_installs');
    }
};
