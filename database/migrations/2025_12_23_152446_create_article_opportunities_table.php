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
        Schema::create('article_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('title')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->string('location')->nullable();
            $table->text('url')->nullable();
            $table->text('notes')->nullable();
            $table->string('source');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->timestamps();

            $table->index('article_id');
            $table->index('kind');
            $table->index('starts_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_opportunities');
    }
};
