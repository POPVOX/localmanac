<?php

namespace App\Services\Chat;

use App\Models\ArticleChunk;
use App\Models\ChatSourceChunk;
use App\Services\Chat\Event\EventIntentDetector;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Reranking;
use Throwable;

class ChatSourceRetriever
{
    public function __construct(
        private readonly ChatSourceGuard $chatSourceGuard,
        private readonly EventIntentDetector $eventIntentDetector,
        private readonly ReciprocalRankFusion $reciprocalRankFusion,
        private readonly EvidenceSelector $evidenceSelector,
    ) {}

    /**
     * @param  Collection<int, \App\Models\ChatSource>  $sources
     * @return array{
     *     evidence: array<int, array<string, mixed>>,
     *     meta: array<string, int>
     * }
     */
    public function retrieve(Collection $sources, string $question, ?int $cityId = null): array
    {
        $question = trim($question);

        if ($sources->isEmpty() || $question === '') {
            return [
                'evidence' => [],
                'meta' => [
                    'pages_fetched' => 0,
                    'cache_hits' => 0,
                ],
            ];
        }

        $sourceIds = $sources->pluck('id')->map(fn ($id) => (int) $id)->all();
        $limit = (int) config('chat.retrieval_chunk_limit', 8);
        $proceduralFocusTerms = $this->isProceduralQuestion($question)
            ? $this->proceduralFocusTerms($question)
            : [];

        $focusedRows = $this->proceduralFocusSearch($sourceIds, $proceduralFocusTerms, $limit);
        $vectorRows = $this->vectorSearch($sourceIds, $question, $limit);
        $ftsLimit = max($limit, (int) config('chat.retrieval_fts_limit', $limit));
        $ftsRows = $this->ftsSearch($sourceIds, $question, $ftsLimit);

        if (config('chat.retrieval_v2_enabled', false)) {
            $rows = collect($this->reciprocalRankFusion->fuse([
                'procedural' => $focusedRows,
                'dense' => $vectorRows,
                'lexical' => $ftsRows,
            ], 'chunk_id'));
        } else {
            $rows = collect($focusedRows);

            foreach ($vectorRows as $row) {
                if (! $rows->contains('chunk_id', $row['chunk_id'])) {
                    $rows->push($row);
                }
            }

            foreach ($ftsRows as $row) {
                if (! $rows->contains('chunk_id', $row['chunk_id'])) {
                    $rows->push($row);
                }
            }

            $rows = $rows->sortByDesc('score')->take($limit);
        }

        $rows = $this->expandNeighborChunks($rows);

        if (! config('chat.retrieval_v2_enabled', false)) {
            $rows = $this->sdkRerank($rows, $question);
        }

        $rows = $this->deduplicateRows($rows)
            ->filter(fn (array $row): bool => ! $this->isBlockedRow($row))
            ->take((int) config('chat.retrieval_max_evidence', 24));

        $chunkEvidence = $rows
            ->map(fn (array $row) => $this->mapEvidence($row))
            ->filter(fn (array $item) => $item['snippet'] !== '')
            ->values();

        $articleVectorEvidence = collect();
        $articleFtsEvidence = collect();

        if ($cityId !== null) {
            $articleVectorEvidence = collect($this->articleVectorSearch($cityId, $question, $limit))
                ->filter(fn (array $item) => $item['snippet'] !== '')
                ->values();

            $articleFtsLimit = (int) config('chat.retrieval_fts_limit', 6);
            $articleFtsEvidence = collect($this->articleFtsSearch($cityId, $question, $articleFtsLimit))
                ->filter(fn (array $item) => $item['snippet'] !== '')
                ->values();
        }

        $maxEvidence = (int) config('chat.retrieval_max_evidence', 24);

        if (config('chat.retrieval_v2_enabled', false)) {
            $evidence = collect($this->reciprocalRankFusion->fuse([
                'chat_sources' => $chunkEvidence->all(),
                'article_dense' => $articleVectorEvidence->all(),
                'article_lexical' => $articleFtsEvidence->all(),
            ], 'id'))
                ->unique('id')
                ->pipe(fn (Collection $items): Collection => $this->deduplicateEvidence($items))
                ->pipe(fn (Collection $items): Collection => $this->sdkRerankEvidence($items, $question))
                ->pipe(fn (Collection $items): Collection => $this->evidenceSelector->select($items, $maxEvidence))
                ->all();
        } else {
            $evidence = $chunkEvidence
                ->concat($articleVectorEvidence)
                ->concat($articleFtsEvidence)
                ->unique('id')
                ->pipe(fn (Collection $items): Collection => $this->deduplicateEvidence($items))
                ->sortByDesc('score')
                ->take($maxEvidence)
                ->values()
                ->all();
        }

        $pagesUsed = collect($evidence)
            ->pluck('source_url')
            ->unique()
            ->count();

        return [
            'evidence' => $evidence,
            'meta' => [
                'pages_fetched' => $pagesUsed,
                'cache_hits' => 0,
            ],
        ];
    }

    /**
     * @param  array<int, int>  $sourceIds
     * @param  array<int, string>  $focusTerms
     * @return array<int, array<string, mixed>>
     */
    private function proceduralFocusSearch(array $sourceIds, array $focusTerms, int $limit): array
    {
        if ($sourceIds === [] || $focusTerms === []) {
            return [];
        }

        $query = $this->baseQuery($sourceIds);
        $this->applyProceduralFocusConstraints($query, $focusTerms);

        $rows = $query
            ->limit(max($limit * 2, 8))
            ->get();

        return $rows->map(function ($row) use ($focusTerms) {
            $chunk = (string) $row->content;
            $title = $row->page_title ? (string) $row->page_title : '';
            $url = (string) $row->page_url;
            $context = mb_strtolower($chunk.' '.$title.' '.$url);
            $matches = 0;

            foreach ($focusTerms as $term) {
                if (str_contains($context, $term)) {
                    $matches++;
                }
            }

            $proceduralSignals = 0;

            foreach ($this->proceduralSignals() as $signal) {
                if (str_contains($context, $signal)) {
                    $proceduralSignals++;
                }
            }

            return [
                'chunk_id' => (int) $row->chunk_id,
                'page_id' => (int) $row->page_id,
                'chunk_index' => (int) $row->chunk_index,
                'chunk' => $chunk,
                'page_url' => (string) $row->page_url,
                'canonical_url' => $row->canonical_url ? (string) $row->canonical_url : null,
                'page_title' => $row->page_title ? (string) $row->page_title : null,
                'content_type' => $row->content_type ? (string) $row->content_type : null,
                'source_name' => (string) $row->source_name,
                'page_fetched_at' => $row->page_fetched_at ? (string) $row->page_fetched_at : null,
                'page_updated_at' => $row->page_updated_at ? (string) $row->page_updated_at : null,
                'page_created_at' => $row->page_created_at ? (string) $row->page_created_at : null,
                'score' => max(12, 10 + ($matches * 8) + min($proceduralSignals, 4)),
            ];
        })->all();
    }

    /**
     * @param  array<int, int>  $sourceIds
     * @return array<int, array<string, mixed>>
     */
    private function vectorSearch(array $sourceIds, string $question, int $limit): array
    {
        if (! config('chat.vector_enabled', true) || ! config('chat.interactive_vector_enabled', false)) {
            return [];
        }

        try {
            $query = ChatSourceChunk::query()
                ->whereHas('page.source', fn (EloquentBuilder $b) => $b->whereIn('id', $sourceIds)->where('is_active', true))
                ->whereNotNull('embedding');

            if (! (clone $query)->exists()) {
                return [];
            }

            $results = $query
                ->whereVectorSimilarTo('embedding', $question)
                ->with(['page:id,chat_source_id,url,canonical_url,title,content_type', 'page.source:id,name'])
                ->limit($limit)
                ->get();
        } catch (Throwable $exception) {
            Log::warning('chat.vector_search.failed', [
                'index' => 'chat_sources',
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        return $results->map(fn (ChatSourceChunk $chunk) => $this->mapChunkRow($chunk))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapChunkRow(ChatSourceChunk $chunk): array
    {
        $page = $chunk->page;

        return [
            'chunk_id' => $chunk->id,
            'page_id' => $page?->id,
            'chunk_index' => $chunk->chunk_index,
            'chunk' => (string) $chunk->content,
            'page_url' => (string) $page?->url,
            'canonical_url' => $page?->canonical_url,
            'page_title' => $page?->title,
            'content_type' => $page?->content_type,
            'source_name' => (string) $page?->source?->name,
            'score' => 5,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function articleVectorSearch(int $cityId, string $question, int $limit): array
    {
        if (! config('chat.vector_enabled', true) || ! config('chat.interactive_vector_enabled', false)) {
            return [];
        }

        if (! config('chat.article_chunks_enabled', true)) {
            return [];
        }

        try {
            $query = ArticleChunk::query()
                ->whereHas('article', fn (EloquentBuilder $b) => $b->where('city_id', $cityId)->where('status', 'published'))
                ->whereNotNull('embedding');

            if (! (clone $query)->exists()) {
                return [];
            }

            $results = $query
                ->whereVectorSimilarTo('embedding', $question)
                ->with(['article:id,title,city_id,summary', 'article.sources', 'article.explainer'])
                ->limit($limit)
                ->get();
        } catch (Throwable $exception) {
            Log::warning('chat.vector_search.failed', [
                'index' => 'articles',
                'city_id' => $cityId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        return $results->map(fn (ArticleChunk $chunk) => $this->mapArticleChunkEvidence($chunk))->all();
    }

    /**
     * @return array{id: string, title: string, source_url: string, type: string, snippet: string, score: int}
     */
    private function mapArticleChunkEvidence(ArticleChunk $chunk): array
    {
        $article = $chunk->article;
        $sourceUrl = $article?->sources->first()?->source_url ?? '';

        return [
            'id' => 'article_chunk_'.$chunk->id,
            'title' => (string) ($article?->title ?? 'Article'),
            'source_url' => (string) $sourceUrl,
            'type' => 'html',
            'snippet' => trim((string) $chunk->content),
            'score' => 5,
        ];
    }

    /**
     * Rerank rows using the Laravel AI SDK's Reranking service.
     *
     * On failure, falls back to ordering by original scores.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sdkRerank(Collection $rows, string $question): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        if (! config('chat.reranking_enabled', true)) {
            return $rows->sortByDesc('score')->values();
        }

        try {
            $snippets = $rows->pluck('chunk')->all();

            $response = Reranking::of($snippets)
                ->limit($rows->count())
                ->rerank($question);

            return collect($response->results)
                ->map(function (mixed $result) use ($rows) {
                    $row = $rows->values()->get($result->index);
                    $row['score'] = max(1, (int) ceil($result->score * 10));

                    return $row;
                })
                ->sortByDesc('score')
                ->values();
        } catch (Throwable) {
            return $rows->sortByDesc('score')->values();
        }
    }

    /**
     * Rerank the final cross-index evidence set. Invalid or incomplete provider
     * responses fall back atomically to the RRF order.
     *
     * @param  Collection<int, array<string, mixed>>  $evidence
     * @return Collection<int, array<string, mixed>>
     */
    private function sdkRerankEvidence(Collection $evidence, string $question): Collection
    {
        $evidence = $evidence->values();

        if ($evidence->isEmpty() || ! config('chat.reranking_enabled', true)) {
            return $evidence;
        }

        try {
            $response = Reranking::of($evidence->pluck('snippet')->all())
                ->limit($evidence->count())
                ->rerank($question);
            $results = collect($response->results);

            if ($results->count() !== $evidence->count()) {
                return $evidence;
            }

            $seen = [];
            $reranked = collect();

            foreach ($results as $result) {
                $index = (int) $result->index;

                if (isset($seen[$index]) || $index < 0 || $index >= $evidence->count() || ! is_finite((float) $result->score)) {
                    return $evidence;
                }

                $seen[$index] = true;
                $item = $evidence->get($index);
                $item['score'] = max(1, (int) ceil((float) $result->score * 10));
                $reranked->push($item);
            }

            return $reranked->sortByDesc('score')->values();
        } catch (Throwable) {
            return $evidence;
        }
    }

    /**
     * @param  array<int, int>  $sourceIds
     * @return array<int, array<string, mixed>>
     */
    private function ftsSearch(array $sourceIds, string $question, int $limit): array
    {
        if (! config('chat.fts_enabled', true)) {
            return [];
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return $this->likeSearch($sourceIds, $question, $limit);
        }

        $rows = $this->baseQuery($sourceIds)
            ->whereRaw("chunks.search_vector @@ websearch_to_tsquery('english', ?)", [$question])
            ->selectRaw("ts_rank_cd(chunks.search_vector, websearch_to_tsquery('english', ?)) as rank", [$question])
            ->orderByDesc('rank')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $relaxedQuery = $this->buildRelaxedTsQuery($question);

            if ($relaxedQuery !== null) {
                $rows = $this->baseQuery($sourceIds)
                    ->whereRaw("chunks.search_vector @@ to_tsquery('english', ?)", [$relaxedQuery])
                    ->selectRaw("ts_rank_cd(chunks.search_vector, to_tsquery('english', ?)) as rank", [$relaxedQuery])
                    ->orderByDesc('rank')
                    ->limit($limit)
                    ->get();
            }
        }

        return $rows->map(function ($row) {
            $rank = is_numeric($row->rank ?? null) ? (float) $row->rank : 0.0;
            $score = max(1, (int) ceil($rank * 10));

            return [
                'chunk_id' => (int) $row->chunk_id,
                'page_id' => (int) $row->page_id,
                'chunk_index' => (int) $row->chunk_index,
                'chunk' => (string) $row->content,
                'page_url' => (string) $row->page_url,
                'canonical_url' => $row->canonical_url ? (string) $row->canonical_url : null,
                'page_title' => $row->page_title ? (string) $row->page_title : null,
                'content_type' => $row->content_type ? (string) $row->content_type : null,
                'source_name' => (string) $row->source_name,
                'score' => $score,
            ];
        })->all();
    }

    /**
     * @param  array<int, int>  $sourceIds
     * @return array<int, array<string, mixed>>
     */
    private function likeSearch(array $sourceIds, string $question, int $limit): array
    {
        $terms = $this->keywordTerms($question);

        if ($terms === []) {
            return [];
        }

        $rows = $this->baseQuery($sourceIds)
            ->where(function ($builder) use ($terms) {
                foreach ($terms as $term) {
                    $builder->orWhere('chunks.content', 'like', '%'.$term.'%');
                }
            })
            ->limit($limit)
            ->get();

        return $rows->map(function ($row) use ($terms) {
            $matches = $this->countTermMatches((string) $row->content, $terms);
            $score = max(1, min(10, $matches));

            return [
                'chunk_id' => (int) $row->chunk_id,
                'page_id' => (int) $row->page_id,
                'chunk_index' => (int) $row->chunk_index,
                'chunk' => (string) $row->content,
                'page_url' => (string) $row->page_url,
                'canonical_url' => $row->canonical_url ? (string) $row->canonical_url : null,
                'page_title' => $row->page_title ? (string) $row->page_title : null,
                'content_type' => $row->content_type ? (string) $row->content_type : null,
                'source_name' => (string) $row->source_name,
                'score' => $score,
            ];
        })->all();
    }

    /**
     * @param  array<int, int>  $sourceIds
     */
    private function baseQuery(array $sourceIds): Builder
    {
        return DB::table('chat_source_chunks as chunks')
            ->join('chat_source_pages as pages', 'pages.id', '=', 'chunks.chat_source_page_id')
            ->join('chat_sources as sources', 'sources.id', '=', 'pages.chat_source_id')
            ->whereIn('sources.id', $sourceIds)
            ->where('sources.is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('pages.url')
                    ->orWhere('pages.url', 'not like', '%/cdn-cgi/%');
            })
            ->where(function (Builder $query): void {
                $query->whereNull('pages.canonical_url')
                    ->orWhere('pages.canonical_url', 'not like', '%/cdn-cgi/%');
            })
            ->where(function (Builder $query): void {
                $query->whereNull('pages.title')
                    ->orWhereRaw('lower(pages.title) not in (?, ?, ?)', [
                        'email protection | cloudflare',
                        'attention required! | cloudflare',
                        'just a moment...',
                    ]);
            })
            ->select([
                'chunks.id as chunk_id',
                'chunks.chat_source_page_id as page_id',
                'chunks.chunk_index',
                'chunks.content',
                'pages.url as page_url',
                'pages.canonical_url',
                'pages.title as page_title',
                'pages.content_type',
                'pages.fetched_at as page_fetched_at',
                'pages.updated_at as page_updated_at',
                'pages.created_at as page_created_at',
                'sources.name as source_name',
            ]);
    }

    /**
     * @param  array<int, string>  $focusTerms
     */
    private function applyProceduralFocusConstraints(Builder $query, array $focusTerms): void
    {
        $query->where(function (Builder $builder) use ($focusTerms): void {
            foreach ($focusTerms as $term) {
                $builder->orWhere('chunks.content', 'like', '%'.$term.'%')
                    ->orWhere('pages.title', 'like', '%'.$term.'%')
                    ->orWhere('pages.url', 'like', '%'.$term.'%')
                    ->orWhere('pages.canonical_url', 'like', '%'.$term.'%');
            }
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapEvidence(array $row): array
    {
        $sourceUrl = $row['canonical_url'] ?: $row['page_url'];
        $effectiveScore = min((float) ($row['combined_score'] ?? ($row['score'] ?? 1)), 25.0);

        return [
            'id' => 'chunk_'.$row['chunk_id'],
            'title' => $row['page_title'] ?: $row['source_name'] ?: 'Source',
            'source_url' => $sourceUrl,
            'type' => $row['content_type'] ?: 'html',
            'snippet' => trim($row['chunk'] ?? ''),
            'score' => max(1, (int) ceil($effectiveScore)),
        ];
    }

    /**
     * Search published articles via PostgreSQL full-text search on title, summary, and body.
     *
     * @return array<int, array{id: string, title: string, source_url: string, type: string, snippet: string, score: int}>
     */
    private function articleFtsSearch(int $cityId, string $question, int $limit): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }

        try {
            $rows = $this->articleFtsQuery($cityId, $question, $limit, strict: true);

            if ($rows->isEmpty()) {
                $relaxedQuery = $this->buildRelaxedTsQuery($question);

                if ($relaxedQuery !== null) {
                    $rows = $this->articleFtsQuery($cityId, $relaxedQuery, $limit, strict: false);
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $rows->map(fn (object $row): array => $this->mapArticleEvidence($row))
            ->filter(fn (array $item): bool => $item['snippet'] !== '' && $item['source_url'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, object>
     */
    private function articleFtsQuery(int $cityId, string $question, int $limit, bool $strict): Collection
    {
        $tsFunction = $strict ? 'websearch_to_tsquery' : 'to_tsquery';

        $tsvector = "to_tsvector('english', coalesce(articles.title, '') || ' ' || coalesce(articles.summary, '') || ' ' || coalesce(article_bodies.cleaned_text, ''))";

        return DB::table('articles')
            ->leftJoin('article_bodies', 'article_bodies.article_id', '=', 'articles.id')
            ->leftJoin('article_explainers', 'article_explainers.article_id', '=', 'articles.id')
            ->leftJoin('article_sources', 'article_sources.article_id', '=', 'articles.id')
            ->where('articles.city_id', $cityId)
            ->where('articles.status', 'published')
            ->whereRaw("{$tsvector} @@ {$tsFunction}('english', ?)", [$question])
            ->selectRaw("articles.id, articles.title, articles.summary, article_bodies.cleaned_text, article_explainers.whats_happening, article_explainers.why_it_matters, article_sources.source_url, ts_rank_cd({$tsvector}, {$tsFunction}('english', ?)) as rank", [$question])
            ->orderByDesc('rank')
            ->limit($limit)
            ->get();
    }

    /**
     * Normalize an article row into the Evidence_Item format.
     *
     * @return array{id: string, title: string, source_url: string, type: string, snippet: string, score: int}
     */
    private function mapArticleEvidence(object $article): array
    {
        return [
            'id' => 'article_'.$article->id,
            'title' => (string) ($article->title ?? 'Article'),
            'source_url' => (string) ($article->source_url ?? ''),
            'type' => 'html',
            'snippet' => $this->articleSnippet($article),
            'score' => max(1, (int) ceil((float) ($article->rank ?? 1) * 10)),
        ];
    }

    /**
     * Resolve the best snippet text for an article row.
     *
     * Priority: explainer (whats_happening + why_it_matters), then summary, then cleaned_text truncated.
     */
    private function articleSnippet(object $row): string
    {
        $whatsHappening = trim((string) ($row->whats_happening ?? ''));
        $whyItMatters = trim((string) ($row->why_it_matters ?? ''));

        if ($whatsHappening !== '' || $whyItMatters !== '') {
            return trim($whatsHappening.' '.$whyItMatters);
        }

        $summary = trim((string) ($row->summary ?? ''));

        if ($summary !== '') {
            return $summary;
        }

        $maxChars = (int) config('chat.chunk_max_chars', 1200);

        return mb_substr(trim((string) ($row->cleaned_text ?? '')), 0, $maxChars);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function expandNeighborChunks(Collection $rows): Collection
    {
        $window = (int) config('chat.retrieval_neighbor_window', 1);

        if ($window <= 0 || $rows->isEmpty()) {
            return $rows;
        }

        $expanded = $rows->keyBy('chunk_id');

        foreach ($rows as $row) {
            $pageId = (int) ($row['page_id'] ?? 0);
            $chunkIndex = (int) ($row['chunk_index'] ?? -1);

            if ($pageId <= 0 || $chunkIndex < 0) {
                continue;
            }

            $start = max(0, $chunkIndex - $window);
            $end = $chunkIndex + $window;

            $neighbors = DB::table('chat_source_chunks as chunks')
                ->join('chat_source_pages as pages', 'pages.id', '=', 'chunks.chat_source_page_id')
                ->join('chat_sources as sources', 'sources.id', '=', 'pages.chat_source_id')
                ->where('chunks.chat_source_page_id', $pageId)
                ->whereBetween('chunks.chunk_index', [$start, $end])
                ->where('sources.is_active', true)
                ->select([
                    'chunks.id as chunk_id',
                    'chunks.chat_source_page_id as page_id',
                    'chunks.chunk_index',
                    'chunks.content',
                    'pages.url as page_url',
                    'pages.canonical_url',
                    'pages.title as page_title',
                    'pages.content_type',
                    'pages.fetched_at as page_fetched_at',
                    'pages.updated_at as page_updated_at',
                    'pages.created_at as page_created_at',
                    'sources.name as source_name',
                ])
                ->get()
                ->map(function ($neighbor) use ($row) {
                    return [
                        'chunk_id' => (int) $neighbor->chunk_id,
                        'page_id' => (int) $neighbor->page_id,
                        'chunk_index' => (int) $neighbor->chunk_index,
                        'chunk' => (string) $neighbor->content,
                        'page_url' => (string) $neighbor->page_url,
                        'canonical_url' => $neighbor->canonical_url ? (string) $neighbor->canonical_url : null,
                        'page_title' => $neighbor->page_title ? (string) $neighbor->page_title : null,
                        'content_type' => $neighbor->content_type ? (string) $neighbor->content_type : null,
                        'source_name' => (string) $neighbor->source_name,
                        'page_fetched_at' => $neighbor->page_fetched_at ? (string) $neighbor->page_fetched_at : null,
                        'page_updated_at' => $neighbor->page_updated_at ? (string) $neighbor->page_updated_at : null,
                        'page_created_at' => $neighbor->page_created_at ? (string) $neighbor->page_created_at : null,
                        'score' => (int) ($row['score'] ?? 1),
                    ];
                });

            foreach ($neighbors as $neighbor) {
                $expanded->put($neighbor['chunk_id'], $neighbor);
            }
        }

        return $expanded->values();
    }

    /**
     * @return array<int, string>
     */
    private function keywordTerms(string $question): array
    {
        $terms = preg_split('/\\W+/u', mb_strtolower($question)) ?: [];
        $stopwords = $this->stopwords();
        $shortAllowlist = $this->shortKeywordAllowlist();

        $filtered = array_values(array_filter(
            $terms,
            fn (string $term) => (
                mb_strlen($term) >= 3
                || in_array($term, $shortAllowlist, true)
            ) && ! in_array($term, $stopwords, true)
        ));

        $expanded = [];

        foreach ($filtered as $term) {
            $expanded[] = $term;

            // Basic stemming for common plural forms improves lexical recall/ranking.
            if (mb_strlen($term) > 4 && str_ends_with($term, 's')) {
                $expanded[] = mb_substr($term, 0, -1);
            }
        }

        return array_values(array_unique(array_filter($expanded)));
    }

    private function buildRelaxedTsQuery(string $question): ?string
    {
        $terms = $this->keywordTerms($question);

        if ($terms === []) {
            return null;
        }

        $terms = array_slice($terms, 0, 8);
        $safeTerms = [];

        foreach ($terms as $term) {
            $term = preg_replace('/[^\\p{L}\\p{N}_]+/u', '', $term) ?? '';

            if ($term === '') {
                continue;
            }

            $safeTerms[] = $term;
        }

        if ($safeTerms === []) {
            return null;
        }

        return implode(' | ', $safeTerms);
    }

    /**
     * @param  array<int, string>  $terms
     */
    private function countTermMatches(string $content, array $terms): int
    {
        $content = mb_strtolower($content);
        $matches = 0;

        foreach ($terms as $term) {
            if (str_contains($content, $term)) {
                $matches++;
            }
        }

        return $matches;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function deduplicateRows(Collection $rows): Collection
    {
        return $rows
            ->unique(function (array $row): string {
                $url = mb_strtolower((string) ($row['canonical_url'] ?? $row['page_url'] ?? ''));
                $snippet = preg_replace('/\\s+/u', ' ', mb_strtolower(trim((string) ($row['chunk'] ?? '')))) ?? '';

                return md5($url.'|'.$snippet);
            })
            ->values();
    }

    /**
     * Deduplicate evidence items by source URL and content hash (SHA-1 of normalized snippet).
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function deduplicateEvidence(Collection $items): Collection
    {
        return $items
            ->unique(function (array $item): string {
                $url = mb_strtolower((string) ($item['source_url'] ?? ''));
                $snippet = preg_replace('/\\s+/u', ' ', mb_strtolower(trim((string) ($item['snippet'] ?? '')))) ?? '';

                return $url.'|'.sha1($snippet);
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isBlockedRow(array $row): bool
    {
        return $this->chatSourceGuard->isBlockedPage(
            (string) ($row['page_url'] ?? ''),
            (string) ($row['canonical_url'] ?? ''),
            (string) ($row['page_title'] ?? ''),
            (string) ($row['chunk'] ?? '')
        );
    }

    /**
     * @return array<int, string>
     */
    private function proceduralFocusTerms(string $question): array
    {
        $ignored = [
            'how', 'what', 'when', 'where', 'which', 'who', 'does', 'need', 'want',
            'apply', 'application', 'obtain', 'get', 'renew', 'register', 'file',
            'submit', 'request', 'schedule', 'report', 'permit', 'permits',
            'license', 'licenses', 'process', 'procedure', 'steps', 'step', 'city',
        ];

        return collect($this->keywordTerms($question))
            ->reject(fn (string $term): bool => in_array($term, $ignored, true))
            ->values()
            ->all();
    }

    private function isProceduralQuestion(string $question): bool
    {
        if ($this->eventIntentDetector->isEventIntent($question)) {
            return false;
        }

        $normalized = mb_strtolower(trim($question));

        if ($normalized === '') {
            return false;
        }

        if (preg_match('/\b(how do i|how to|what do i need|where do i apply|where can i apply|steps?|step by step|process|procedure)\b/u', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\b(apply|application|permit|license|renew|register|file|submit|request|obtain)\b/u', $normalized) !== 1) {
            return false;
        }

        if (preg_match('/\b(what new|new permits|rezonings|projects|project|active service alerts?|service alerts?|alerts?|status|summary|summarize|overview|updates?|recently|coming up)\b/u', $normalized) === 1) {
            return false;
        }

        return preg_match('/\b(i|my|me|need|required|requirements?|documents?|fees?|cost|where|when|how|can i|do i|should i|get)\b/u', $normalized) === 1;
    }

    /**
     * @return array<int, string>
     */
    private function proceduralSignals(): array
    {
        return [
            'apply',
            'application',
            'submit',
            'submitted',
            'approval',
            'approved',
            'inspection',
            'inspections',
            'required',
            'requirements',
            'document',
            'documents',
            'portal',
            'review',
            'certificate',
            'fee',
            'fees',
            'bond',
            'before',
            'after',
            'then',
            'next',
            'finally',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function stopwords(): array
    {
        return [
            'the', 'and', 'for', 'with', 'that', 'this', 'from', 'what', 'when', 'where', 'which', 'who', 'whom',
            'does', 'do', 'did', 'are', 'is', 'was', 'were', 'can', 'could', 'should', 'would', 'will', 'have',
            'has', 'had', 'into', 'onto', 'about', 'your', 'my', 'our', 'their', 'them', 'they', 'you', 'its',
            'a', 'an', 'of', 'to', 'in', 'on', 'at', 'by', 'or', 'if', 'as',
            'city', 'local', 'municipal',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function shortKeywordAllowlist(): array
    {
        return ['id', 'am', 'pm'];
    }
}
