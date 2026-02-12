<?php

namespace App\Services\Extraction\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class EntityEnrichmentAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Extract entities and issue areas in structured JSON only.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enrichment' => $schema->object()->required(),
            'confidence' => $schema
                ->number()
                ->min(0)
                ->max(1)
                ->required(),
        ];
    }
}
