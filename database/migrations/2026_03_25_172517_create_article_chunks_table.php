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
        Schema::create('article_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index')->default(0);
            $table->longText('content');
            $table->unsignedInteger('content_length')->default(0);
            $table->string('content_hash', 64);
            $table->string('embedding_model')->nullable();
            $table->timestamps();

            $table->index('article_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE article_chunks ADD COLUMN embedding vector('.((int) config('chat.embedding_dimensions', 1536)).')');
            DB::statement('CREATE INDEX article_chunks_embedding_idx ON article_chunks USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
        } else {
            Schema::table('article_chunks', function (Blueprint $table) {
                $table->text('embedding')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS article_chunks_embedding_idx');
        Schema::dropIfExists('article_chunks');
    }
};
