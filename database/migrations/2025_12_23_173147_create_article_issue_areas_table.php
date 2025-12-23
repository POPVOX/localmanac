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
        Schema::create('article_issue_areas', function (Blueprint $table) {
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_area_id')->constrained()->cascadeOnDelete();
            $table->decimal('confidence', 4, 3)->default(0.0);
            $table->string('source');
            $table->timestamps();

            $table->unique(['article_id', 'issue_area_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_issue_areas');
    }
};
