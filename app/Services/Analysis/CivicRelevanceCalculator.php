<?php

namespace App\Services\Analysis;

class CivicRelevanceCalculator
{
    /**
     * @return array<string, float>
     */
    public function weights(): array
    {
        return [
            ScoreDimensions::COMPREHENSIBILITY => 0.25,
            ScoreDimensions::ORIENTATION => 0.20,
            ScoreDimensions::AGENCY => 0.20,
            ScoreDimensions::REPRESENTATION => 0.15,
            ScoreDimensions::RELEVANCE => 0.10,
            ScoreDimensions::TIMELINESS => 0.10,
        ];
    }

    /**
     * @param  array<string, float|int|string|null>  $dimensions
     */
    public function compute(array $dimensions): float
    {
        $normalized = $this->finalScores($dimensions);
        $total = 0.0;

        foreach ($this->weights() as $key => $weight) {
            $total += ($normalized[$key] ?? 0.0) * $weight;
        }

        return $this->clamp($total);
    }

    /**
     * @param  array<string, float|int|string|null>  $dimensions
     * @return array<string, float>
     */
    public function finalScores(array $dimensions): array
    {
        $normalized = [];

        foreach (ScoreDimensions::keys() as $key) {
            $value = (float) ($dimensions[$key] ?? 0.0);
            $normalized[$key] = $this->clamp($value);
        }

        return $normalized;
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
