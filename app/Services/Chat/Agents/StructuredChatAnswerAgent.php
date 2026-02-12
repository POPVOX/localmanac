<?php

namespace App\Services\Chat\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class StructuredChatAnswerAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function __construct(
        public iterable $tools = [],
    ) {}

    public function instructions(): string
    {
        return 'You are a civic information assistant. Respond only with structured JSON.';
    }

    public function tools(): iterable
    {
        return $this->tools;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'answer' => $schema->string()->required(),
            'citations' => $schema
                ->array()
                ->items(
                    $schema->object([
                        'title' => $schema->string()->required(),
                        'source_url' => $schema->string()->required(),
                        'type' => $schema->string()->required(),
                    ])->withoutAdditionalProperties()
                )
                ->required(),
            'source_mode' => $schema
                ->string()
                ->required(),
            'confidence' => $schema
                ->number()
                ->min(0)
                ->max(1)
                ->required(),
        ];
    }
}
