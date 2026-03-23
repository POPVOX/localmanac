<?php

namespace App\Services\Extraction\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\NumberType;
use Illuminate\JsonSchema\Types\ObjectType;
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
            'analysis' => $this->analysisSchema($schema)->required(),
            'process_timeline' => $this->processTimelineSchema($schema)->required(),
            'confidence' => $this->confidenceSchema($schema)->required(),
        ];
    }

    private function analysisSchema(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'dimensions' => $schema->object([
                'comprehensibility' => $this->confidenceSchema($schema)->required(),
                'orientation' => $this->confidenceSchema($schema)->required(),
                'representation' => $this->confidenceSchema($schema)->required(),
                'agency' => $this->confidenceSchema($schema)->required(),
                'relevance' => $this->confidenceSchema($schema)->required(),
                'timeliness' => $this->confidenceSchema($schema)->required(),
            ])->withoutAdditionalProperties()->required(),
            'justifications' => $schema->object([
                'comprehensibility' => $schema->string()->required(),
                'orientation' => $schema->string()->required(),
                'representation' => $schema->string()->required(),
                'agency' => $schema->string()->required(),
                'relevance' => $schema->string()->required(),
                'timeliness' => $schema->string()->required(),
            ])->withoutAdditionalProperties()->required(),
            'coverage_scope' => $schema
                ->string()
                ->enum(['local', 'regional', 'statewide', 'national', 'unclear'])
                ->nullable()
                ->required(),
            'local_relevance_score' => $this->confidenceSchema($schema)
                ->nullable()
                ->required(),
            'locality_reason' => $schema->string()->nullable()->required(),
            'opportunities' => $schema->array()->items(
                $schema->object([
                    'type' => $schema
                        ->string()
                        ->enum(['meeting', 'public_comment', 'deadline', 'application', 'other'])
                        ->required(),
                    'date' => $schema->string()->nullable()->required(),
                    'time' => $schema->string()->nullable()->required(),
                    'location' => $schema->string()->nullable()->required(),
                    'url' => $schema->string()->nullable()->required(),
                    'description' => $schema->string()->required(),
                    'evidence' => $schema->array()->items($this->evidenceSchema($schema))->required(),
                ])->withoutAdditionalProperties()
            )->required(),
            'confidence' => $this->confidenceSchema($schema)->required(),
        ])->withoutAdditionalProperties();
    }

    private function processTimelineSchema(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'items' => $schema->array()->items(
                $schema->object([
                    'key' => $schema->string()->required(),
                    'label' => $schema->string()->required(),
                    'date' => $schema->string()->nullable()->required(),
                    'status' => $schema
                        ->string()
                        ->enum(['completed', 'current', 'upcoming', 'unknown'])
                        ->required(),
                    'badge_text' => $schema->string()->nullable()->required(),
                    'note' => $schema->string()->nullable()->required(),
                    'evidence' => $schema->array()->items($this->evidenceSchema($schema))->required(),
                ])->withoutAdditionalProperties()
            )->required(),
            'current_key' => $schema->string()->nullable()->required(),
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

    private function confidenceSchema(JsonSchema $schema): NumberType
    {
        return $schema
            ->number()
            ->min(0)
            ->max(1);
    }
}
