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
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->string('claim_type');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('value_json');
            $table->jsonb('evidence_json')->nullable();
            $table->decimal('confidence', 4, 3)->default(0.0);
            $table->string('source');
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->string('status')->default('proposed');
            $table->string('value_hash')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'claim_type']);
            $table->index(['city_id', 'claim_type']);
            $table->index('status');
            $table->unique(['article_id', 'claim_type', 'subject_type', 'subject_id', 'value_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
