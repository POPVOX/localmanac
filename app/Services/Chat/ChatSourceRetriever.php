<?php

namespace App\Services\Chat;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChatSourceRetriever
{
    public function __construct(
        private readonly EmbeddingClient $embeddingClient,
        private readonly VectorFormatter $vectorFormatter,
        private readonly ChatSourceGuard $chatSourceGuard,
    ) {}

    /**
     * @param  Collection<int, \App\Models\ChatSource>  $sources
     * @return array{
     *     evidence: array<int, array<string, mixed>>,
     *     meta: array<string, int>
     * }
     */
    public function retrieve(Collection $sources, string $question): array
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

        $rows = $this->rerankRows($rows, $question)->take($limit);
        $rows = $this->expandNeighborChunks($rows);
        $rows = $this->rerankRows($rows, $question);
        $rows = $this->deduplicateRows($rows)
            ->pipe(fn (Collection $items): Collection => $this->filterSeverelyMismatchedProceduralRows($items, $question))
            ->filter(fn (array $row): bool => ! $this->isBlockedRow($row))
            ->pipe(fn (Collection $items): Collection => $this->promoteDistinctPagesForAggregationQueries($items, $question))
            ->take((int) config('chat.retrieval_max_evidence', 24));

        $evidence = $rows
            ->map(fn (array $row) => $this->mapEvidence($row))
            ->filter(fn (array $item) => $item['snippet'] !== '')
            ->values()
            ->all();

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
        if (! config('chat.vector_enabled', true)) {
            return [];
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }

        $vector = $this->embeddingClient->embedQuery($question);

        if (! $vector) {
            return [];
        }

        $vectorSql = $this->vectorFormatter->toSql($vector);

        $rows = $this->baseQuery($sourceIds)
            ->whereNotNull('chunks.embedding')
            ->selectRaw('chunks.embedding <=> ?::vector as distance', [$vectorSql])
            ->orderBy('distance')
            ->limit($limit)
            ->get();

        return $rows->map(function ($row) {
            $distance = is_numeric($row->distance ?? null) ? (float) $row->distance : null;
            $similarity = $distance !== null ? max(0.0, 1 - $distance) : 0.0;
            $score = max(1, (int) ceil($similarity * 10));

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
    private function rerankRows(Collection $rows, string $question): Collection
    {
        $terms = $this->keywordTerms($question);
        $isProceduralQuestion = $this->isProceduralQuestion($question);
        $proceduralFocusTerms = $this->proceduralFocusTerms($question);
        $aggregationWindowDays = $this->aggregationRecencyWindowDays($question);

        if ($terms === [] || $rows->isEmpty()) {
            return $rows;
        }

        return $rows
            ->map(function (array $row) use ($terms, $question, $isProceduralQuestion, $proceduralFocusTerms, $aggregationWindowDays) {
                $chunk = (string) ($row['chunk'] ?? '');
                $title = (string) ($row['page_title'] ?? $row['source_name'] ?? '');
                $url = (string) ($row['page_url'] ?? '');

                $chunkMatches = $this->countTermMatches($chunk, $terms);
                $titleMatches = $this->countTermMatches($title, $terms);
                $urlMatches = $this->countTermMatches($url, $terms);
                $phraseMatches = $this->countPhraseMatches($terms, $chunk);

                // Prioritize evidence text over page title/URL to avoid generic page-level mismatches.
                $boostScore = ($chunkMatches * 3.0) + ($titleMatches * 0.5) + ($urlMatches * 0.25) + ($phraseMatches * 2.0);

                // Extra boost when domain words appear in key operational phrasing.
                if ($this->hasOperationalSignal($question, $chunk)) {
                    $boostScore += 3;
                }

                if ($isProceduralQuestion) {
                    $boostScore += $this->proceduralBoostScore($question, $terms, $row);
                    $boostScore += $this->proceduralFocusBoostScore($proceduralFocusTerms, $row);
                    $boostScore -= $this->proceduralFocusMismatchPenalty($proceduralFocusTerms, $row);
                    $boostScore -= $this->proceduralIntentMismatchPenalty($question, $proceduralFocusTerms, $row);
                    $boostScore -= $this->genericPagePenalty($row);
                } else {
                    $boostScore -= $this->genericPagePenalty($row) * 0.4;
                }

                if ($aggregationWindowDays !== null) {
                    $boostScore += $this->aggregationRecencyBoostScore($row, $aggregationWindowDays);
                    $boostScore -= $this->aggregationGenericPagePenalty($row, $aggregationWindowDays);
                }

                $baseScore = $this->normalizedRetrievalScore($row);
                $row['combined_score'] = $baseScore + $boostScore;
                $row['score'] = max(1, (int) ceil($row['combined_score']));

                return $row;
            })
            ->sortByDesc('combined_score')
            ->values();
    }

    /**
     * @param  array<int, string>  $focusTerms
     * @param  array<string, mixed>  $row
     */
    private function proceduralFocusBoostScore(array $focusTerms, array $row): float
    {
        if ($focusTerms === []) {
            return 0.0;
        }

        $chunk = mb_strtolower((string) ($row['chunk'] ?? ''));
        $title = mb_strtolower((string) ($row['page_title'] ?? ''));
        $sourceName = mb_strtolower((string) ($row['source_name'] ?? ''));
        $url = mb_strtolower((string) ($row['page_url'] ?? ''));
        $context = $title.' '.$sourceName.' '.$url;

        $chunkMatches = $this->countTermMatches($chunk, $focusTerms);
        $contextMatches = $this->countTermMatches($context, $focusTerms);

        return ($chunkMatches * 8.0) + ($contextMatches * 12.0);
    }

    /**
     * @param  array<int, string>  $focusTerms
     * @param  array<string, mixed>  $row
     */
    private function proceduralFocusMismatchPenalty(array $focusTerms, array $row): float
    {
        if ($focusTerms === []) {
            return 0.0;
        }

        $chunk = mb_strtolower((string) ($row['chunk'] ?? ''));
        $title = mb_strtolower((string) ($row['page_title'] ?? ''));
        $sourceName = mb_strtolower((string) ($row['source_name'] ?? ''));
        $url = mb_strtolower((string) ($row['page_url'] ?? ''));
        $haystack = $chunk.' '.$title.' '.$sourceName.' '.$url;

        foreach ($focusTerms as $term) {
            if (str_contains($haystack, $term)) {
                return 0.0;
            }
        }

        return 10.0;
    }

    /**
     * @param  array<int, string>  $focusTerms
     * @param  array<string, mixed>  $row
     */
    private function proceduralIntentMismatchPenalty(string $question, array $focusTerms, array $row): float
    {
        if (! $this->questionRequiresProceduralSteps($question) || $focusTerms === []) {
            return 0.0;
        }

        $chunk = mb_strtolower((string) ($row['chunk'] ?? ''));
        $title = mb_strtolower((string) ($row['page_title'] ?? ''));
        $sourceName = mb_strtolower((string) ($row['source_name'] ?? ''));
        $url = mb_strtolower((string) ($row['page_url'] ?? ''));
        $context = $title.' '.$sourceName.' '.$url;
        $focusInChunk = $this->countTermMatches($chunk, $focusTerms) > 0;
        $focusInContext = $this->countTermMatches($context, $focusTerms) > 0;
        $processSignals = $this->countProceduralProcessSignals($chunk.' '.$context);
        $penalty = 0.0;

        if ($focusInChunk && ! $focusInContext) {
            $penalty += 18.0;
        }

        if ($processSignals === 0) {
            $penalty += 18.0;
        } elseif ($processSignals === 1) {
            $penalty += 8.0;
        }

        return $penalty;
    }

    /**
     * @param  array<int, string>  $focusTerms
     * @param  array<string, mixed>  $row
     */
    private function isSeverelyProceduralMismatch(string $question, array $focusTerms, array $row): bool
    {
        if (! $this->questionRequiresProceduralSteps($question)) {
            return false;
        }

        $chunk = mb_strtolower((string) ($row['chunk'] ?? ''));
        $title = mb_strtolower((string) ($row['page_title'] ?? ''));
        $sourceName = mb_strtolower((string) ($row['source_name'] ?? ''));
        $url = mb_strtolower((string) ($row['page_url'] ?? ''));
        $context = $title.' '.$sourceName.' '.$url;
        $focusInChunk = $this->countTermMatches($chunk, $focusTerms) > 0;
        $focusInContext = $this->countTermMatches($context, $focusTerms) > 0;
        $processSignals = $this->countProceduralProcessSignals($chunk.' '.$context);

        return $focusInChunk && ! $focusInContext && $processSignals === 0;
    }

    /**
     * @param  array<int, string>  $terms
     */
    private function countPhraseMatches(array $terms, string $content): int
    {
        if (count($terms) < 2 || trim($content) === '') {
            return 0;
        }

        $content = mb_strtolower($content);
        $matches = 0;

        for ($index = 0; $index < count($terms) - 1; $index++) {
            $phrase = $terms[$index].' '.$terms[$index + 1];

            if (str_contains($content, $phrase)) {
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
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function filterSeverelyMismatchedProceduralRows(Collection $rows, string $question): Collection
    {
        $focusTerms = $this->proceduralFocusTerms($question);

        if (! $this->questionRequiresProceduralSteps($question) || $focusTerms === [] || $rows->isEmpty()) {
            return $rows;
        }

        $filtered = $rows->reject(fn (array $row): bool => $this->isSeverelyProceduralMismatch($question, $focusTerms, $row));

        return $filtered->isNotEmpty() ? $filtered->values() : $rows;
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

    private function hasOperationalSignal(string $question, string $chunk): bool
    {
        $q = mb_strtolower($question);
        $text = mb_strtolower($chunk);

        $timeWords = ['hour', 'hours', 'schedule', 'open', 'close', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $feeWords = ['fee', 'fees', 'cost', 'costs', 'rate', 'rates', 'price', 'prices', '$', 'per hour', 'per day', 'per ton'];

        $questionAsksTime = collect($timeWords)->contains(fn (string $word) => str_contains($q, $word));
        $questionAsksFees = collect($feeWords)->contains(fn (string $word) => str_contains($q, $word));

        if ($questionAsksTime && collect($timeWords)->contains(fn (string $word) => str_contains($text, $word))) {
            return true;
        }

        if ($questionAsksFees && collect($feeWords)->contains(fn (string $word) => str_contains($text, $word))) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<int, string>  $terms
     * @param  array<string, mixed>  $row
     */
    private function proceduralBoostScore(string $question, array $terms, array $row): float
    {
        $chunk = mb_strtolower((string) ($row['chunk'] ?? ''));
        $title = mb_strtolower((string) ($row['page_title'] ?? ''));
        $sourceName = mb_strtolower((string) ($row['source_name'] ?? ''));
        $url = mb_strtolower((string) ($row['page_url'] ?? ''));
        $question = mb_strtolower($question);
        $boost = 0.0;

        $titleMatches = $this->countTermMatches($title.' '.$sourceName, $terms);
        $urlMatches = $this->countTermMatches($url, $terms);
        $boost += ($titleMatches * 2.5) + ($urlMatches * 1.5);

        foreach ($this->proceduralSignals() as $signal) {
            if (str_contains($chunk, $signal)) {
                $boost += 1.25;
            }

            if (str_contains($title, $signal) || str_contains($sourceName, $signal)) {
                $boost += 1.0;
            }
        }

        foreach ($this->proceduralPhrases() as $phrase) {
            if (str_contains($chunk, $phrase)) {
                $boost += 2.0;
            }
        }

        if ($this->countPhraseMatches($terms, $title.' '.$sourceName) > 0) {
            $boost += 3.0;
        }

        if ($this->questionAndChunkShareProceduralFocus($question, $chunk, $title.' '.$sourceName.' '.$url)) {
            $boost += 4.0;
        }

        return $boost;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function normalizedRetrievalScore(array $row): float
    {
        $score = (float) ($row['score'] ?? 1.0);

        return max(1.0, min($score, 12.0));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function genericPagePenalty(array $row): float
    {
        $title = mb_strtolower((string) ($row['page_title'] ?? ''));
        $sourceName = mb_strtolower((string) ($row['source_name'] ?? ''));
        $chunk = mb_strtolower((string) ($row['chunk'] ?? ''));
        $haystack = $title.' '.$sourceName.' '.$chunk;
        $penalty = 0.0;

        foreach ([
            'frequently asked questions',
            'faq',
            'quick links',
            'city hall',
            'departments',
            'services',
            'residents',
            'visitors',
            'archive center',
            'news flash',
            'boards and committees',
            'all content',
            'facebook',
            'twitter',
            'pinterest',
            'linkedin',
            'city government',
        ] as $signal) {
            if (str_contains($haystack, $signal)) {
                $penalty += 4.0;
            }
        }

        if (str_contains($haystack, '/faq') || str_contains($haystack, '/government')) {
            $penalty += 5.0;
        }

        return $penalty;
    }

    private function questionRequiresProceduralSteps(string $question): bool
    {
        return false;
    }

    private function countProceduralProcessSignals(string $content): int
    {
        $content = mb_strtolower($content);
        $matches = 0;

        foreach ($this->proceduralProcessSignals() as $signal) {
            if (str_contains($content, $signal)) {
                $matches++;
            }
        }

        return $matches;
    }

    private function questionAndChunkShareProceduralFocus(string $question, string $chunk, string $context): bool
    {
        return false;
    }

    /**
     * @return array<int, string>
     */
    private function proceduralFocusTerms(string $question): array
    {
        return [];
    }

    private function isProceduralQuestion(string $question): bool
    {
        return false;
    }

    /**
     * @return array<int, string>
     */
    private function proceduralSignals(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    private function proceduralPhrases(): array
    {
        return [
            'step 1',
            'step 2',
            'first,',
            'then,',
            'before you apply',
            'before submitting',
            'you must',
            'you will need',
            'submit the application',
            'apply online',
            'permit portal',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function proceduralProcessSignals(): array
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
            'contractor',
            'review',
            'certificate',
            'office',
            'department',
            'bond',
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

    private function aggregationRecencyWindowDays(string $question): ?int
    {
        $normalized = mb_strtolower($question);

        if (! $this->isAggregationRecencyQuery($normalized)) {
            return null;
        }

        if (preg_match('/\b(?:last|past|previous)\s+(\d{1,2})\s+days?\b/u', $normalized, $matches) === 1) {
            return max(1, (int) ($matches[1] ?? 0));
        }

        if (preg_match('/\b(?:last|past|previous)\s+(\d{1,2})\s+weeks?\b/u', $normalized, $matches) === 1) {
            return max(1, (int) ($matches[1] ?? 0)) * 7;
        }

        if (preg_match('/\b(?:this|last|past|previous)\s+week\b/u', $normalized) === 1) {
            return 7;
        }

        if (preg_match('/\b(?:this|last|past|previous)\s+month\b/u', $normalized) === 1) {
            return 30;
        }

        if (preg_match('/\b(?:today|latest|recent|recently)\b/u', $normalized) === 1) {
            return 7;
        }

        return 7;
    }

    private function isAggregationRecencyQuery(string $question): bool
    {
        return preg_match('/\b(what(?:\'s| is)? new|newest|latest|recent|recently|updates?|announcements?|alerts?|service alerts?|filed|approved|status changes?|changed|change|released)\b/u', $question) === 1;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function aggregationRecencyBoostScore(array $row, int $windowDays): float
    {
        $boost = 0.0;
        $updateSignalCount = $this->aggregationUpdateSignalCount($row);
        $mentionedDate = $this->rowMentionedDate($row);
        $pageTimestamp = $this->rowPageTimestamp($row);

        if ($mentionedDate instanceof CarbonImmutable) {
            if ($this->isWithinAggregationWindow($mentionedDate, $windowDays)) {
                $daysOld = max(0, CarbonImmutable::now()->diffInDays($mentionedDate, false) * -1);
                $boost += 14.0 - min((float) $daysOld * 2.5, 10.0);
            } elseif ($mentionedDate->lessThan(CarbonImmutable::now()->subDays($windowDays))) {
                $boost -= min(8.0, max(2.0, (float) $mentionedDate->diffInDays(CarbonImmutable::now()->subDays($windowDays))));
            }
        } elseif ($pageTimestamp instanceof CarbonImmutable && $this->isWithinAggregationWindow($pageTimestamp, $windowDays)) {
            $boost += $updateSignalCount > 0 ? 4.0 : 1.0;
        }

        if ($updateSignalCount > 0) {
            $boost += min(6.0, $updateSignalCount * 2.0);
        }

        if ($this->rowHasTimestampSignal($row)) {
            $boost += 2.5;
        }

        return $boost;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function aggregationGenericPagePenalty(array $row, int $windowDays): float
    {
        if (! $this->isAggregationGenericPage($row)) {
            return 0.0;
        }

        if ($this->rowHasExplicitRecentUpdateEvidence($row, $windowDays)) {
            return 4.0;
        }

        if ($this->aggregationUpdateSignalCount($row) > 0) {
            return 8.0;
        }

        return 14.0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isAggregationGenericPage(array $row): bool
    {
        $title = mb_strtolower((string) ($row['page_title'] ?? ''));
        $sourceName = mb_strtolower((string) ($row['source_name'] ?? ''));
        $url = mb_strtolower((string) ($row['page_url'] ?? ''));
        $haystack = $title.' '.$sourceName.' '.$url;

        foreach ([
            'current projects',
            'apply for',
            'licenses & permits',
            'licences & permits',
            'licenses and permits',
            'licences and permits',
        ] as $signal) {
            if (str_contains($haystack, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowHasExplicitRecentUpdateEvidence(array $row, int $windowDays): bool
    {
        $mentionedDate = $this->rowMentionedDate($row);

        if ($mentionedDate instanceof CarbonImmutable && $this->isWithinAggregationWindow($mentionedDate, $windowDays)) {
            return true;
        }

        $pageTimestamp = $this->rowPageTimestamp($row);

        return $pageTimestamp instanceof CarbonImmutable
            && $this->isWithinAggregationWindow($pageTimestamp, $windowDays)
            && $this->aggregationUpdateSignalCount($row) > 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function aggregationUpdateSignalCount(array $row): int
    {
        $chunk = mb_strtolower((string) ($row['chunk'] ?? ''));
        $title = mb_strtolower((string) ($row['page_title'] ?? ''));
        $sourceName = mb_strtolower((string) ($row['source_name'] ?? ''));
        $haystack = $chunk.' '.$title.' '.$sourceName;
        $matches = 0;

        foreach ([
            'announcement',
            'announced',
            'update',
            'updated',
            'posted',
            'published',
            'release',
            'released',
            'service alert',
            'alert',
            'notice',
            'bulletin',
            'filed',
            'approved',
            'approval',
            'agenda',
            'minutes',
        ] as $signal) {
            if (str_contains($haystack, $signal)) {
                $matches++;
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowHasTimestampSignal(array $row): bool
    {
        $text = (string) ($row['page_title'] ?? '').' '.(string) ($row['chunk'] ?? '');

        return preg_match('/\b(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\.?\s+\d{1,2},\s+\d{4}\b/i', $text) === 1
            || preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $text) === 1
            || preg_match('/\b\d{1,2}\/\d{1,2}\/\d{4}\b/', $text) === 1;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowMentionedDate(array $row): ?CarbonImmutable
    {
        $text = trim((string) ($row['page_title'] ?? '').' '.(string) ($row['chunk'] ?? ''));

        if ($text === '') {
            return null;
        }

        foreach ([
            '/\b(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\.?\s+\d{1,2},\s+\d{4}(?:\s+\d{1,2}:\d{2}(?:\s?[AP]M)?)?/i',
            '/\b\d{4}-\d{2}-\d{2}\b/',
            '/\b\d{1,2}\/\d{1,2}\/\d{4}\b/',
        ] as $pattern) {
            if (preg_match($pattern, $text, $matches) !== 1) {
                continue;
            }

            $candidate = trim((string) ($matches[0] ?? ''));

            if ($candidate === '') {
                continue;
            }

            try {
                return CarbonImmutable::parse($candidate, config('app.timezone'));
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowPageTimestamp(array $row): ?CarbonImmutable
    {
        foreach (['page_updated_at', 'page_fetched_at', 'page_created_at'] as $key) {
            $value = $row[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                return CarbonImmutable::parse($value);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function isWithinAggregationWindow(CarbonImmutable $timestamp, int $windowDays): bool
    {
        $now = CarbonImmutable::now();

        return $timestamp->betweenIncluded($now->subDays($windowDays), $now->addDay());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function promoteDistinctPagesForAggregationQueries(Collection $rows, string $question): Collection
    {
        if ($this->aggregationRecencyWindowDays($question) === null || $rows->count() < 2) {
            return $rows;
        }

        $leaders = $rows
            ->groupBy(fn (array $row): string => $this->rowPageKey($row))
            ->map(function (Collection $group): array {
                /** @var array<string, mixed> $row */
                $row = $group->sortByDesc('combined_score')->first();

                return $row;
            })
            ->sortByDesc('combined_score')
            ->values();

        $leaderChunkIds = $leaders->pluck('chunk_id')->all();

        return $leaders
            ->concat(
                $rows->reject(fn (array $row): bool => in_array($row['chunk_id'] ?? null, $leaderChunkIds, true))->values()
            )
            ->values();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowPageKey(array $row): string
    {
        $url = (string) ($row['canonical_url'] ?? $row['page_url'] ?? '');

        if ($url !== '') {
            return mb_strtolower($url);
        }

        return 'page:'.(string) ($row['page_id'] ?? '0');
    }
}
