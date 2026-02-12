<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;

class EmbeddingClient
{
    /**
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>>
     */
    public function embed(array $inputs): array
    {
        $inputs = array_values(array_filter(array_map('trim', $inputs), fn (string $value) => $value !== ''));

        if ($inputs === []) {
            return [];
        }

        if (! config('chat.vector_enabled', true)) {
            return [];
        }

        $dimensions = (int) config('chat.embedding_dimensions', 1536);
        $model = (string) config('chat.embedding_model', 'text-embedding-3-small');

        try {
            $request = Embeddings::for($inputs)->dimensions($dimensions);

            if ((bool) config('chat.embedding_cache', true)) {
                $cacheSeconds = (int) config('chat.embedding_cache_seconds', 0);
                $request->cache($cacheSeconds > 0 ? $cacheSeconds : null);
            }

            $response = $request->generate(
                provider: $this->providerPreference($model),
            );

            return array_map(
                fn (array $embedding): array => array_map('floatval', $embedding),
                $response->embeddings
            );
        } catch (\Throwable $exception) {
            Log::warning('Embedding request failed.', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
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
