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
        Schema::create('chat_source_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_source_page_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->longText('content');
            $table->unsignedInteger('content_length');
            $table->string('content_hash', 64)->nullable();
            $table->string('embedding_model')->nullable();
            $table->timestamps();

            $table->unique(['chat_source_page_id', 'chunk_index']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE chat_source_chunks ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('english', content)) STORED");
            DB::statement('ALTER TABLE chat_source_chunks ADD COLUMN embedding vector('.((int) config('chat.embedding_dimensions', 1536)).')');
            DB::statement('CREATE INDEX chat_source_chunks_search_vector_idx ON chat_source_chunks USING GIN (search_vector)');
            DB::statement('CREATE INDEX chat_source_chunks_embedding_idx ON chat_source_chunks USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
        } else {
            Schema::table('chat_source_chunks', function (Blueprint $table) {
                $table->text('search_vector')->nullable();
                $table->text('embedding')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS chat_source_chunks_embedding_idx');
        DB::statement('DROP INDEX IF EXISTS chat_source_chunks_search_vector_idx');
        Schema::dropIfExists('chat_source_chunks');
    }
};
