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
