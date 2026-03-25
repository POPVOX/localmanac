<?php

namespace App\Services\Chat\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class AnswerQualityJudge implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<int, string>  $evidenceSnippets
     */
    public function __construct(
        private readonly string $question,
        private readonly string $answer,
        private readonly array $evidenceSnippets = [],
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return 'You are an answer quality judge for a civic information assistant. '
            .'Classify the provided answer into exactly one category based on the question and available evidence. '
            .'Categories: '
            .'"useful" — the answer addresses the question with specific, actionable information; '
            .'"no_answer" — the answer explicitly states it cannot find the requested information; '
            .'"refusal" — the answer refuses to engage with the question; '
            .'"vague" — the answer is generic or non-specific without actionable information.';
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'classification' => $schema->string()
                ->enum(['useful', 'no_answer', 'refusal', 'vague'])
                ->required(),
        ];
    }
}
