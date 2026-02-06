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
        Schema::create('chat_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('source_url');
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('link_follow_mode')->default('auto');
            $table->unsignedInteger('link_limit')->default(6);
            $table->timestamps();

            $table->unique(['city_id', 'source_url']);
            $table->index(['city_id', 'is_active']);
            $table->index(['city_id', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_sources');
    }
};
