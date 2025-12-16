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
        Schema::create('scraper_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scraper_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->string('status')->default('running');
            $table->unsignedInteger('items_found')->default(0);
            $table->unsignedInteger('items_created')->default(0);
            $table->unsignedInteger('items_updated')->default(0);
            $table->json('meta')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['scraper_id', 'started_at']);
            $table->index(['city_id', 'started_at']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scraper_runs');
    }
};
