<?php

namespace App\Services\Analysis\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class LlmScoringAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Score civic relevance dimensions and opportunities in structured JSON only.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'dimensions' => $schema->object()->required(),
            'justifications' => $schema->object()->required(),
            'opportunities' => $schema
                ->array()
                ->items($schema->object()),
            'confidence' => $schema
                ->number()
                ->min(0)
                ->max(1)
                ->required(),
        ];
    }
}
