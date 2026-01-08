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
        Schema::create('article_explainers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->text('whats_happening')->nullable();
            $table->text('why_it_matters')->nullable();
            $table->jsonb('key_details')->nullable();
            $table->jsonb('what_to_watch')->nullable();
            $table->jsonb('evidence_json')->nullable();
            $table->string('source')->default('analysis_llm');
            $table->jsonb('source_payload')->nullable();
            $table->timestamps();

            $table->unique('article_id');
            $table->index('city_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_explainers');
    }
};
