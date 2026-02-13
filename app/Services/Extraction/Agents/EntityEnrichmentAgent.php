<?php

namespace App\Services\Extraction\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\NumberType;
use Illuminate\JsonSchema\Types\ObjectType;
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
            'enrichment' => $this->enrichmentSchema($schema)->required(),
            'confidence' => $this->confidenceSchema($schema)->required(),
        ];
    }

    private function enrichmentSchema(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'people' => $schema->array()->items(
                $schema->object([
                    'name' => $schema->string()->required(),
                    'role' => $schema->string()->nullable()->required(),
                    'confidence' => $this->confidenceSchema($schema)->required(),
                    'evidence' => $schema->array()->items($this->evidenceSchema($schema))->required(),
                ])->withoutAdditionalProperties()
            )->required(),
            'organizations' => $schema->array()->items(
                $schema->object([
                    'name' => $schema->string()->required(),
                    'type_guess' => $schema
                        ->string()
                        ->enum(['government', 'news_media', 'nonprofit', 'business', 'school', 'other'])
                        ->required(),
                    'confidence' => $this->confidenceSchema($schema)->required(),
                    'evidence' => $schema->array()->items($this->evidenceSchema($schema))->required(),
                ])->withoutAdditionalProperties()
            )->required(),
            'locations' => $schema->array()->items(
                $schema->object([
                    'name' => $schema->string()->required(),
                    'address' => $schema->string()->nullable()->required(),
                    'confidence' => $this->confidenceSchema($schema)->required(),
                    'evidence' => $schema->array()->items($this->evidenceSchema($schema))->required(),
                ])->withoutAdditionalProperties()
            )->required(),
            'keywords' => $schema->array()->items(
                $schema->object([
                    'keyword' => $schema->string()->required(),
                    'confidence' => $this->confidenceSchema($schema)->required(),
                    'evidence' => $schema->array()->items($this->evidenceSchema($schema))->required(),
                ])->withoutAdditionalProperties()
            )->required(),
            'issue_areas' => $schema->array()->items(
                $schema->object([
                    'slug' => $schema->string()->required(),
                    'confidence' => $this->confidenceSchema($schema)->required(),
                    'evidence' => $schema->array()->items($this->evidenceSchema($schema))->required(),
                ])->withoutAdditionalProperties()
            )->required(),
            'confidence' => $this->confidenceSchema($schema)->required(),
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
