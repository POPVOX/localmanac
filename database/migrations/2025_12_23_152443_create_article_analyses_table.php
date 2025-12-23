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
        Schema::create('article_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->string('score_version')->default('crf_v1');
            $table->string('status')->default('pending');
            $table->jsonb('heuristic_scores')->nullable();
            $table->jsonb('llm_scores')->nullable();
            $table->jsonb('final_scores')->nullable();
            $table->decimal('civic_relevance_score', 4, 3)->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->timestamp('last_scored_at')->nullable();
            $table->timestamps();

            $table->unique('article_id');
            $table->index('status');
            $table->index('civic_relevance_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_analyses');
    }
};
