<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::create('chat_source_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_source_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->text('canonical_url')->nullable();
            $table->string('title')->nullable();
            $table->string('content_type', 50)->nullable();
            $table->string('renderer', 30)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->longText('content_text')->nullable();
            $table->unsignedInteger('content_length')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_source_id', 'url']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_source_pages');
    }
};
