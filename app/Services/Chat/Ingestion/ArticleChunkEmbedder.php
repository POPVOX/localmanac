<?php

namespace App\Services\Chat\Ingestion;

use App\Models\Article;
use App\Models\ArticleChunk;
use App\Services\Chat\EmbeddingClient;
use App\Services\Chat\VectorFormatter;
use Illuminate\Support\Facades\DB;

class ArticleChunkEmbedder
{
    public function __construct(
        private readonly Chunker $chunker,
        private readonly EmbeddingClient $embeddingClient,
        private readonly VectorFormatter $vectorFormatter,
    ) {}

    public function embed(Article $article): int
    {
        $content = trim((string) ($article->body?->cleaned_text ?? ''));

        if ($content === '') {
            ArticleChunk::query()->where('article_id', $article->id)->delete();

            return 0;
        }

        $chunks = $this->chunker->chunk($content);

        ArticleChunk::query()->where('article_id', $article->id)->delete();

        if ($chunks === []) {
            return 0;
        }

        $vectors = config('chat.vector_enabled', true)
            ? $this->embeddingClient->embed($chunks)
            : [];

        $dimensions = (int) config('chat.embedding_dimensions', 1536);
        $model = $vectors !== [] ? (string) config('chat.embedding_model', 'text-embedding-3-small') : null;
        $now = now();
        $records = [];

        foreach ($chunks as $index => $chunk) {
            $embedding = $vectors[$index] ?? null;
            $embeddingValue = null;

            if (is_array($embedding) && count($embedding) === $dimensions) {
                $embeddingValue = DB::raw("'".$this->vectorFormatter->toSql($embedding)."'::vector");
            }

            $records[] = [
                'article_id' => $article->id,
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

        DB::table('article_chunks')->insert($records);

        return count($records);
    }
}
