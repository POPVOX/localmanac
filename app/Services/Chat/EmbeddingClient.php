<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;
use RuntimeException;
use Throwable;

class EmbeddingClient
{
    /**
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>>
     */
    public function embed(array $inputs): array
    {
        try {
            return $this->embedOrFail($inputs);
        } catch (Throwable $exception) {
            Log::warning('Embedding request failed.', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Generate a complete, dimensionally valid embedding batch or throw.
     *
     * Ingestion uses this strict path so a partial provider response cannot
     * replace a healthy index. Query-time retrieval uses embed(), which keeps
     * its lexical fallback behavior.
     *
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>>
     */
    public function embedOrFail(array $inputs): array
    {
        $inputs = array_values(array_filter(array_map('trim', $inputs), fn (string $value) => $value !== ''));

        if ($inputs === [] || ! config('chat.vector_enabled', true)) {
            return [];
        }

        // UTF-8 bytes are a conservative upper bound for byte-level tokens.
        // Keep headroom below the provider's 300,000-token / 2,048-input limits
        // without assuming English text or adding a network tokenizer call.
        $maxBytes = max(1, min(200000, (int) config('chat.embedding_batch_max_bytes', 200000)));
        $maxInputs = max(1, min(128, (int) config('chat.embedding_batch_max_inputs', 128)));
        $batches = [];
        $batch = [];
        $bytes = 0;

        foreach ($inputs as $input) {
            $length = strlen($input);
            if ($length > 8000 || $length > $maxBytes) {
                throw new RuntimeException('Embedding input exceeds the safe byte limit; split the source into smaller chunks.');
            }

            if ($batch !== [] && ($bytes + $length > $maxBytes || count($batch) >= $maxInputs)) {
                $batches[] = $batch;
                $batch = [];
                $bytes = 0;
            }
            $batch[] = $input;
            $bytes += $length;
        }
        $batches[] = $batch;

        $vectors = [];
        foreach ($batches as $batch) {
            // A failed later batch must throw, never return a partial index.
            array_push($vectors, ...$this->embedBatch($batch));
        }

        return $vectors;
    }

    /** @return array<int, array<int, float>> */
    private function embedBatch(array $inputs): array
    {
        $dimensions = (int) config('chat.embedding_dimensions', 1536);
        $model = (string) config('chat.embedding_model', 'text-embedding-3-small');
        $request = Embeddings::for($inputs)->dimensions($dimensions);

        if ((bool) config('chat.embedding_cache', true)) {
            $cacheSeconds = (int) config('chat.embedding_cache_seconds', 0);
            $request->cache($cacheSeconds > 0 ? $cacheSeconds : null);
        }

        $response = $request->generate(
            provider: $this->providerPreference($model),
        );

        $vectors = array_map(
            fn (array $embedding): array => array_map('floatval', $embedding),
            $response->embeddings
        );

        if (count($vectors) !== count($inputs)) {
            throw new RuntimeException(sprintf(
                'Embedding provider returned %d vector(s) for %d input(s).',
                count($vectors),
                count($inputs),
            ));
        }

        foreach ($vectors as $index => $vector) {
            if (count($vector) !== $dimensions) {
                throw new RuntimeException(sprintf(
                    'Embedding %d has %d dimensions; expected %d.',
                    $index,
                    count($vector),
                    $dimensions,
                ));
            }
        }

        return $vectors;
    }

    /**
     * @return array<int, float>|null
     */
    public function embedQuery(string $input): ?array
    {
        $vectors = $this->embed([$input]);

        return $vectors[0] ?? null;
    }

    /**
     * @return array<string, string|null>|string
     */
    private function providerPreference(string $model): array|string
    {
        $providers = config('chat.embedding_provider_chain');

        if (! is_array($providers) || $providers === []) {
            return [
                (string) config('chat.embedding_provider', 'openai') => $model,
            ];
        }

        $resolved = [];

        foreach (array_values($providers) as $index => $provider) {
            if (! is_string($provider) || trim($provider) === '') {
                continue;
            }

            $resolved[$provider] = $index === 0 ? $model : null;
        }

        if ($resolved === []) {
            return [
                (string) config('chat.embedding_provider', 'openai') => $model,
            ];
        }

        return $resolved;
    }
}
