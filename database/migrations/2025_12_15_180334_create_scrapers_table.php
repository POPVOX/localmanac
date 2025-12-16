<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scrapers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('type');
            $table->string('source_url')->nullable();
            $table->json('config')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->string('schedule_cron')->nullable();
            $table->timestamps();

            $table->unique(['city_id', 'slug']);
            $table->index(['city_id', 'is_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scrapers');
    }
};
