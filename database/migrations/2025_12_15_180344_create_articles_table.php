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
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scraper_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->string('content_type')->default('unknown');
            $table->text('canonical_url')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();

            $table->index(['city_id', 'status']);
            $table->index(['city_id', 'published_at']);
            $table->unique(['city_id', 'canonical_url']);
            $table->index(['city_id', 'content_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
