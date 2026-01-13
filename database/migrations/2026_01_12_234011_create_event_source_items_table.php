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
        Schema::create('event_source_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_source_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->text('source_url')->nullable();
            $table->jsonb('raw_payload')->nullable();
            $table->timestampTz('fetched_at')->nullable();
            $table->timestamps();

            $table->index(['event_source_id', 'fetched_at']);
            $table->index(['event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_source_items');
    }
};
