<?php

namespace App\Services\Chat\Ingestion;

class NavigationPageClassifier
{
    /**
     * Determine if the given content represents a navigation/hub page.
     */
    public function isNavigationPage(string $content): bool
    {
        return $this->hasHighUrlDensity($content) || $this->hasZeroProseSentences($content);
    }

    /**
     * Check if more than 15% of whitespace-delimited words are valid URLs.
     */
    private function hasHighUrlDensity(string $content): bool
    {
        $words = preg_split('/\s+/', trim($content)) ?: [];
        $totalWords = count($words);

        if ($totalWords === 0) {
            return false;
        }

        $urlCount = count(array_filter($words, fn (string $w): bool => filter_var($w, FILTER_VALIDATE_URL) !== false));

        return ($urlCount / $totalWords) > 0.15;
    }

    /**
     * Check if zero sentences contain a subject-verb structure after stripping URLs.
     */
    private function hasZeroProseSentences(string $content): bool
    {
        $stripped = preg_replace('#https?://\S+#', '', $content) ?? $content;
        $sentences = preg_split('/[.!?]+/', $stripped) ?: [];
        $proseSentences = array_filter($sentences, fn (string $s): bool => preg_match('/\b\w+\s+\w+\b/', trim($s)) === 1);

        return count($proseSentences) === 0;
    }
}
