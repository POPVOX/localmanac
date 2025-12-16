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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->enum('type', ['government', 'news_media', 'nonprofit', 'business', 'school', 'other']);
            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->smallInteger('credibility_score')->default(5);
            $table->timestamps();

            $table->unique(['city_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
