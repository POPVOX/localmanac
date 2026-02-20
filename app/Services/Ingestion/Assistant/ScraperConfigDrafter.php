<?php

namespace App\Services\Ingestion\Assistant;

use Illuminate\Support\Facades\Log;

class ScraperConfigDrafter
{
    public function __construct(
        private readonly ScraperConfigHeuristicGenerator $heuristicGenerator,
        private readonly ScraperConfigAiRefiner $aiRefiner,
    ) {}

    /**
     * @return array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float, mode: string}
     */
    public function draft(string $type, string $sourceUrl, string $html): array
    {
        $heuristic = $this->heuristicGenerator->generate($type, $sourceUrl, $html);
        $result = $heuristic;
        $mode = 'heuristic';

        $shouldRefine = (bool) config('scraper-assistant.ai.refine_enabled', false)
            && $heuristic['confidence'] < (float) config('scraper-assistant.ai.refine_min_confidence', 0.75);

        if ($shouldRefine) {
            $result = $this->aiRefiner->refine($type, $sourceUrl, $html, $heuristic);
            $mode = 'ai_refined';
        }

        Log::info('Scraper assistant generated config draft.', [
            'source_url' => $sourceUrl,
            'type' => $type,
            'mode' => $mode,
            'profile' => $result['profile'],
            'confidence' => $result['confidence'],
            'warning_count' => count($result['warnings']),
        ]);

        return [
            ...$result,
            'mode' => $mode,
        ];
    }
}
