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

            if ($current !== '') {
                $current .= "\n\n".$paragraph;
            } else {
                $current = $paragraph;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        $chunks = array_values(array_filter($chunks, fn (string $chunk) => mb_strlen($chunk) >= $minChars));

        return $chunks;
    }

    /**
     * @return array<int, string>
     */
    private function splitLongParagraph(string $paragraph, int $maxChars): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph) ?: [];
        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if ($sentence === '') {
                continue;
            }

            if (mb_strlen($sentence) > $maxChars) {
                $chunks[] = mb_substr($sentence, 0, $maxChars);
                $current = '';

                continue;
            }

            if ($current === '') {
                $current = $sentence;

                continue;
            }

            if (mb_strlen($current) + 1 + mb_strlen($sentence) <= $maxChars) {
                $current .= ' '.$sentence;

                continue;
            }

            $chunks[] = $current;
            $current = $sentence;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
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
