<?php

namespace App\Services\Chat\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class ChatCitationAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Select only valid source citations from the supplied context and return structured JSON.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
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
            'confidence' => $schema
                ->number()
                ->min(0)
                ->max(1)
                ->required(),
        ];
    }
}
