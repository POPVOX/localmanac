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
        Schema::create('event_ingestion_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_source_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('items_found')->default(0);
            $table->unsignedInteger('items_written')->default(0);
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['event_source_id', 'started_at']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_ingestion_runs');
    }
};
