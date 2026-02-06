<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        if (config('chat.embedding_provider') !== 'openai') {
            return [];
        }

        $apiKey = (string) config('chat.embedding_api_key');

        if ($apiKey === '') {
            Log::warning('Embedding API key missing.');

            return [];
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout((int) config('chat.embedding_timeout', 30))
            ->retry((int) config('chat.embedding_retries', 2), 250)
            ->post(rtrim((string) config('chat.embedding_base_url', 'https://api.openai.com/v1'), '/').'/embeddings', [
                'model' => config('chat.embedding_model', 'text-embedding-3-small'),
                'input' => $inputs,
            ]);

        if (! $response->successful()) {
            Log::warning('Embedding request failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $payload = $response->json();
        $data = $payload['data'] ?? [];

        if (! is_array($data)) {
            return [];
        }

        $vectors = [];
        foreach ($data as $item) {
            if (! is_array($item) || ! isset($item['embedding']) || ! is_array($item['embedding'])) {
                continue;
            }

            $vectors[] = array_map('floatval', $item['embedding']);
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
}
