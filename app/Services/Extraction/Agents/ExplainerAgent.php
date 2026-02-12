<?php

namespace App\Services\Extraction\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class ExplainerAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Create a concise civic explainer in structured JSON only.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'explainer' => $schema->object()->required(),
        ];
    }
}
