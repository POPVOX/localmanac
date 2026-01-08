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
        Schema::create('process_timeline_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('status');
            $table->timestampTz('date')->nullable();
            $table->boolean('has_time')->default(false);
            $table->string('badge_text')->nullable();
            $table->text('note')->nullable();
            $table->jsonb('evidence_json')->nullable();
            $table->string('source')->default('analysis_llm');
            $table->jsonb('source_payload')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['article_id', 'source', 'key']);
            $table->index('article_id');
            $table->index('city_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('process_timeline_items');
    }
};
