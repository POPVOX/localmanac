<?php

namespace App\Services\Chat;

class ProceduralQuestionAnalyzer
{
    /**
     * @return array<int, string>
     */
    public function focusTerms(string $question): array
    {
        $broadProceduralTerms = [
            'apply',
            'application',
            'appointment',
            'approval',
            'before',
            'call',
            'city',
            'contact',
            'document',
            'documents',
            'eligibility',
            'fee',
            'fees',
            'form',
            'forms',
            'get',
            'how',
            'inspection',
            'inspections',
            'license',
            'licenses',
            'local',
            'municipal',
            'need',
            'permit',
            'permits',
            'portal',
            'process',
            'processes',
            'qualifications',
            'qualify',
            'register',
            'registration',
            'renew',
            'renewal',
            'request',
            'requested',
            'requirements',
            'required',
            'review',
            'schedule',
            'steps',
            'submit',
            'submitted',
            'what',
            'where',
            'who',
            'wichita',
        ];

        return array_values(array_filter(
            $this->keywordTerms($question),
            fn (string $term): bool => ! in_array($term, $broadProceduralTerms, true)
        ));
    }

    public function isProceduralQuestion(string $question): bool
    {
        $question = mb_strtolower(trim($question));

        if ($question === '') {
            return false;
        }

        if (preg_match('/\b(how do i|how can i|where do i|who do i call|what do i need)\b/i', $question) === 1) {
            return true;
        }

        foreach ($this->processSignals() as $signal) {
            if (str_contains($question, $signal)) {
                return true;
            }
        }

        return false;
    }

    public function requiresStepwiseSupport(string $question): bool
    {
        $question = mb_strtolower(trim($question));

        if ($question === '') {
            return false;
        }

        if (preg_match('/\b(how do i|how can i|where do i|what do i need)\b/i', $question) === 1) {
            return true;
        }

        foreach ([
            'permit',
            'permits',
            'license',
            'licenses',
            'apply',
            'application',
            'submit',
            'inspection',
            'inspections',
            'approval',
            'approved',
            'required',
            'requirements',
            'documents',
            'registration',
            'renewal',
            'appointment',
            'schedule',
            'process',
            'steps',
        ] as $signal) {
            if (str_contains($question, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function processSignals(): array
    {
        return [
            'apply',
            'application',
            'appointment',
            'approval',
            'approved',
            'before you',
            'contact',
            'document',
            'documents',
            'eligibility',
            'fee',
            'fees',
            'form',
            'forms',
            'inspection',
            'inspections',
            'license',
            'licenses',
            'permit',
            'permits',
            'portal',
            'process',
            'processes',
            'register',
            'registration',
            'renew',
            'renewal',
            'request',
            'requested',
            'requirements',
            'required',
            'review',
            'schedule',
            'steps',
            'submit',
            'submitted',
        ];
    }

    public function sharesFocus(string $question, string ...$contexts): bool
    {
        $focusTerms = $this->focusTerms($question);

        if ($focusTerms === []) {
            return false;
        }

        $haystack = mb_strtolower(implode(' ', $contexts));

        foreach ($focusTerms as $term) {
            if (str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function keywordTerms(string $question): array
    {
        $terms = preg_split('/\W+/u', mb_strtolower($question)) ?: [];
        $filtered = array_values(array_filter(
            $terms,
            fn (string $term): bool => (
                mb_strlen($term) >= 3
                || in_array($term, $this->shortKeywordAllowlist(), true)
            ) && ! in_array($term, $this->stopwords(), true)
        ));

        $expanded = [];

        foreach ($filtered as $term) {
            $expanded[] = $term;

            if (mb_strlen($term) > 4 && str_ends_with($term, 's')) {
                $expanded[] = mb_substr($term, 0, -1);
            }
        }

        return array_values(array_unique(array_filter($expanded)));
    }

    /**
     * @return array<int, string>
     */
    private function stopwords(): array
    {
        return [
            'the', 'and', 'for', 'with', 'that', 'this', 'from', 'what', 'when', 'where', 'which', 'who', 'whom',
            'does', 'do', 'did', 'are', 'is', 'was', 'were', 'can', 'could', 'should', 'would', 'will', 'have',
            'has', 'had', 'into', 'onto', 'about', 'your', 'my', 'our', 'their', 'them', 'they', 'you', 'its',
            'a', 'an', 'of', 'to', 'in', 'on', 'at', 'by', 'or', 'if', 'as', 'i', 'get',
            'city', 'local', 'municipal',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function shortKeywordAllowlist(): array
    {
        return ['id', 'am', 'pm'];
    }
}
