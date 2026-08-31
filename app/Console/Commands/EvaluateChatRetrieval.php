<?php

namespace App\Console\Commands;

use App\Models\ChatSource;
use App\Models\City;
use App\Services\Chat\ChatSourceRetriever;
use App\Services\Chat\Evaluation\RetrievalMetrics;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use JsonException;
use Throwable;

class EvaluateChatRetrieval extends Command
{
    /** @var string */
    protected $signature = 'chat:evaluate-retrieval
                            {file : JSON evaluation dataset}
                            {--profile=current : Retrieval profile: current, legacy, or v2}
                            {--k=10 : Number of retrieved source URLs to evaluate}
                            {--json : Emit machine-readable JSON}';

    /** @var string */
    protected $description = 'Evaluate chat retrieval against a labeled, city-scoped question set.';

    public function handle(ChatSourceRetriever $retriever, RetrievalMetrics $metrics): int
    {
        try {
            $dataset = $this->loadDataset((string) $this->argument('file'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $profile = mb_strtolower((string) $this->option('profile'));

        if (! in_array($profile, ['current', 'legacy', 'v2'], true)) {
            $this->error('Profile must be current, legacy, or v2.');

            return self::FAILURE;
        }

        $originalV2 = config('chat.retrieval_v2_enabled', false);

        if ($profile !== 'current') {
            config()->set('chat.retrieval_v2_enabled', $profile === 'v2');
        }

        $k = max(1, (int) $this->option('k'));
        $results = [];
        $evaluationError = null;

        try {
            foreach ($dataset['cases'] as $index => $case) {
                $results[] = $this->evaluateCase($retriever, $metrics, $case, $index, $k);
            }
        } catch (Throwable $exception) {
            $evaluationError = $exception;
        } finally {
            config()->set('chat.retrieval_v2_enabled', $originalV2);
        }

        if ($evaluationError) {
            $this->error($evaluationError->getMessage());

            return self::FAILURE;
        }

        $summary = $this->summarize($results, $profile, $k);

        if ($this->option('json')) {
            $this->line(json_encode([
                'dataset' => $dataset['name'] ?? basename((string) $this->argument('file')),
                'summary' => $summary,
                'cases' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Case', 'City', 'Pass', 'Recall@K', 'Precision@K', 'MRR', 'nDCG@K', 'Sources', 'Latency ms'],
                array_map(fn (array $result): array => [
                    $result['id'],
                    $result['city'],
                    $result['pass'] ? 'yes' : 'no',
                    number_format($result['recall_at_k'], 3),
                    number_format($result['precision_at_k'], 3),
                    number_format($result['reciprocal_rank'], 3),
                    number_format($result['ndcg_at_k'], 3),
                    $result['retrieved_count'],
                    $result['latency_ms'],
                ], $results),
            );

            $this->info(sprintf(
                '%s profile: %d/%d passed; recall %.3f; precision %.3f; MRR %.3f; nDCG %.3f; avg latency %.1f ms.',
                $profile,
                $summary['passed'],
                $summary['cases'],
                $summary['recall_at_k'],
                $summary['precision_at_k'],
                $summary['reciprocal_rank'],
                $summary['ndcg_at_k'],
                $summary['average_latency_ms'],
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return array{name?: string, cases: array<int, array<string, mixed>>}
     *
     * @throws JsonException
     */
    private function loadDataset(string $file): array
    {
        if (! is_file($file) || ! is_readable($file)) {
            throw new JsonException("Evaluation dataset is not readable: {$file}");
        }

        $dataset = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($dataset) || ! isset($dataset['cases']) || ! is_array($dataset['cases']) || $dataset['cases'] === []) {
            throw new JsonException('Evaluation dataset must contain a non-empty cases array.');
        }

        return $dataset;
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function evaluateCase(
        ChatSourceRetriever $retriever,
        RetrievalMetrics $metrics,
        array $case,
        int $index,
        int $k,
    ): array {
        $id = (string) ($case['id'] ?? 'case-'.($index + 1));
        $cityValue = trim((string) ($case['city'] ?? ''));
        $question = trim((string) ($case['question'] ?? ''));

        if ($cityValue === '' || $question === '') {
            throw new JsonException("Evaluation case {$id} requires city and question.");
        }

        $city = City::query()
            ->where(fn (Builder $query) => ctype_digit($cityValue)
                ? $query->whereKey((int) $cityValue)
                : $query->where('slug', $cityValue))
            ->first();

        if (! $city) {
            throw new JsonException("Evaluation case {$id} references unknown city {$cityValue}.");
        }

        $sources = ChatSource::query()
            ->where('city_id', $city->id)
            ->where('is_active', true)
            ->get();
        $startedAt = microtime(true);
        $retrieval = $retriever->retrieve($sources, $question, $city->id);
        $latencyMs = round((microtime(true) - $startedAt) * 1000, 2);
        $retrieved = collect($retrieval['evidence'])
            ->pluck('source_url')
            ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
            ->unique()
            ->take($k)
            ->values()
            ->all();
        $scores = $metrics->evaluate(
            $retrieved,
            $this->stringList($case['required_source_urls'] ?? []),
            $this->stringList($case['any_of_source_urls'] ?? []),
            $this->stringList($case['excluded_source_urls'] ?? []),
            (bool) ($case['expect_no_source'] ?? false),
            $k,
        );

        return array_merge($scores, [
            'id' => $id,
            'city' => $city->slug,
            'question' => $question,
            'retrieved_source_urls' => $retrieved,
            'latency_ms' => $latencyMs,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, float|int|string>
     */
    private function summarize(array $results, string $profile, int $k): array
    {
        $count = count($results);
        $average = fn (string $key): float => $count === 0
            ? 0.0
            : array_sum(array_column($results, $key)) / $count;

        return [
            'profile' => $profile,
            'k' => $k,
            'cases' => $count,
            'passed' => count(array_filter($results, fn (array $result): bool => (bool) $result['pass'])),
            'recall_at_k' => $average('recall_at_k'),
            'precision_at_k' => $average('precision_at_k'),
            'reciprocal_rank' => $average('reciprocal_rank'),
            'ndcg_at_k' => $average('ndcg_at_k'),
            'average_latency_ms' => $average('latency_ms'),
        ];
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
