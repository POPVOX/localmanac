<?php

namespace App\Services\Chat\Ingestion;

class Chunker
{
    /**
     * @return array<int, string>
     */
    public function chunk(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $maxChars = (int) config('chat.chunk_max_chars', 1200);
        $overlap = (int) config('chat.chunk_overlap_chars', 200);
        $minChars = (int) config('chat.chunk_min_chars', 200);

        $paragraphs = preg_split('/\n{2,}/', $text) ?: [];
        $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), fn (string $value) => $value !== ''));

        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            if (mb_strlen($paragraph) > $maxChars) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                $chunks = array_merge($chunks, $this->splitLongParagraph($paragraph, $maxChars));
                $current = '';

                continue;
            }

            if ($current === '') {
                $current = $paragraph;

                continue;
            }

            if (mb_strlen($current) + 2 + mb_strlen($paragraph) <= $maxChars) {
                $current .= "\n\n".$paragraph;

                continue;
            }

            $chunks[] = $current;
            $current = $this->overlapTail($current, $overlap);

            $availableOverlap = max(0, $maxChars - mb_strlen($paragraph) - 2);

            if (mb_strlen($current) > $availableOverlap) {
                $current = $this->overlapTail($current, $availableOverlap);
            }

            if ($current !== '') {
                $current .= "\n\n".$paragraph;
            } else {
                $current = $paragraph;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        $chunks = $this->mergeShortChunks($chunks, $maxChars, $minChars);

        return $chunks;
    }

    /**
     * @return array<int, string>
     */
    private function splitLongParagraph(string $paragraph, int $maxChars): array
    {
        $chunks = [];
        $remaining = trim($paragraph);

        while ($remaining !== '') {
            if (mb_strlen($remaining) <= $maxChars) {
                $chunks[] = $remaining;

                break;
            }

            $window = mb_substr($remaining, 0, $maxChars);
            $breakPosition = $this->findNaturalBreak($window);
            $segmentLength = $breakPosition ?? $maxChars;
            $segment = trim(mb_substr($remaining, 0, $segmentLength));

            if ($segment !== '') {
                $chunks[] = $segment;
            }

            $remaining = ltrim(mb_substr($remaining, max(1, $segmentLength)));
        }

        return $chunks;
    }

    private function findNaturalBreak(string $window): ?int
    {
        $minimumPosition = (int) floor(mb_strlen($window) * 0.6);

        foreach (["\n", '. ', '; ', ', ', ' '] as $delimiter) {
            $position = mb_strrpos($window, $delimiter);

            if ($position !== false && $position >= $minimumPosition) {
                return $position + mb_strlen($delimiter);
            }
        }

        return null;
    }

    /**
     * Keep short tail chunks instead of silently discarding source text. Merge a
     * short chunk into its predecessor when the configured maximum allows it.
     *
     * @param  array<int, string>  $chunks
     * @return array<int, string>
     */
    private function mergeShortChunks(array $chunks, int $maxChars, int $minChars): array
    {
        $merged = [];

        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            $previousIndex = array_key_last($merged);

            if ($previousIndex !== null
                && mb_strlen($chunk) < $minChars
                && mb_strlen($merged[$previousIndex]) + 2 + mb_strlen($chunk) <= $maxChars) {
                $merged[$previousIndex] .= "\n\n".$chunk;

                continue;
            }

            $merged[] = $chunk;
        }

        return array_values($merged);
    }

    private function overlapTail(string $chunk, int $overlap): string
    {
        if ($overlap <= 0) {
            return '';
        }

        $length = mb_strlen($chunk);

        if ($length <= $overlap) {
            return $chunk;
        }

        return mb_substr($chunk, $length - $overlap);
    }
}
