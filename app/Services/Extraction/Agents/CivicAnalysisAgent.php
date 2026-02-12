<?php

namespace App\Services\Extraction\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class CivicAnalysisAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Extract civic analysis in structured JSON only.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'analysis' => $schema->object()->required(),
            'process_timeline' => $schema->object()->required(),
            'confidence' => $schema
                ->number()
                ->min(0)
                ->max(1)
                ->required(),
        ];
    }
}
