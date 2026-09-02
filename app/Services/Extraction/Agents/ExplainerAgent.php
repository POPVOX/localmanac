<?php

namespace App\Services\Extraction\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class ExplainerAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Create a decision-useful civic explainer that prioritizes concrete actions, named subjects, and source-supported stakes over meeting logistics or generic boilerplate. Use only supplied facts and return structured JSON only.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'explainer' => $this->explainerSchema($schema)->required(),
        ];
    }

    private function explainerSchema(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'headline' => $schema->string()->nullable()->required(),
            'whats_happening' => $schema->string()->nullable()->required(),
            'why_it_matters' => $schema->string()->nullable()->required(),
            'key_details' => $schema->array()->items($schema->string())->nullable()->required(),
            'what_to_watch' => $schema->array()->items($schema->string())->nullable()->required(),
            'evidence' => $schema->object([
                'whats_happening' => $schema->array()->items($this->evidenceSchema($schema))->required(),
                'why_it_matters' => $schema->array()->items($this->evidenceSchema($schema))->required(),
            ])->withoutAdditionalProperties()->nullable()->required(),
        ])->withoutAdditionalProperties();
    }

    private function evidenceSchema(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'quote' => $schema->string()->required(),
            'start' => $schema->integer()->nullable()->required(),
            'end' => $schema->integer()->nullable()->required(),
        ])->withoutAdditionalProperties();
    }
}
