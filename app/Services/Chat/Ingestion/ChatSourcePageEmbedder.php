<?php

namespace App\Services\Chat\Ingestion;

use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Services\Chat\EmbeddingClient;
use App\Services\Chat\VectorFormatter;
use Illuminate\Support\Facades\DB;

class ChatSourcePageEmbedder
{
    public function __construct(
        private readonly Chunker $chunker,
        private readonly EmbeddingClient $embeddingClient,
        private readonly VectorFormatter $vectorFormatter,
    ) {}

    public function embed(ChatSourcePage $page): int
    {
        $content = trim((string) ($page->content_text ?? ''));

        if ($content === '') {
            DB::transaction(
                fn () => ChatSourceChunk::query()->where('chat_source_page_id', $page->id)->delete()
            );

            return 0;
        }

        $chunks = $this->chunker->chunk($content);

        if ($chunks === []) {
            return 0;
        }

        $vectors = config('chat.vector_enabled', true)
            ? $this->embeddingClient->embedOrFail($chunks)
            : [];

        $dimensions = (int) config('chat.embedding_dimensions', 1536);
        $model = $vectors !== [] ? (string) config('chat.embedding_model', 'text-embedding-3-small') : null;
        $now = now();
        $records = [];

        foreach ($chunks as $index => $chunk) {
            $embedding = $vectors[$index] ?? null;
            $embeddingValue = null;

            if (is_array($embedding) && count($embedding) === $dimensions) {
                $formatted = $this->vectorFormatter->toSql($embedding);
                $embeddingValue = DB::connection()->getDriverName() === 'pgsql'
                    ? DB::raw("'".$formatted."'::vector")
                    : $formatted;
            }

            $records[] = [
                'chat_source_page_id' => $page->id,
                'chunk_index' => $index,
                'content' => $chunk,
                'content_length' => mb_strlen($chunk),
                'content_hash' => sha1($chunk),
                'embedding_model' => $model,
                'embedding' => $embeddingValue,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($page, $records): void {
            ChatSourceChunk::query()->where('chat_source_page_id', $page->id)->delete();
            DB::table('chat_source_chunks')->insert($records);
        });

        return count($records);
    }
}
