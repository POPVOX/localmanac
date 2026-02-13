<?php

namespace App\Services\Analysis\Agents;

use App\Services\Analysis\ScoreDimensions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\NumberType;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;
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
            'dimensions' => $this->dimensionsSchema($schema)->required(),
            'justifications' => $this->justificationsSchema($schema)->required(),
            'opportunities' => $schema
                ->array()
                ->items($this->opportunitySchema($schema))
                ->required(),
            'confidence' => $this->confidenceSchema($schema)->required(),
        ];
    }

    private function dimensionsSchema(JsonSchema $schema): ObjectType
    {
        return $schema->object($this->dimensionNumberProperties($schema))->withoutAdditionalProperties();
    }

    private function justificationsSchema(JsonSchema $schema): ObjectType
    {
        $properties = [];

        foreach (ScoreDimensions::keys() as $key) {
            $properties[$key] = $schema->string()->required();
        }

        return $schema->object($properties)->withoutAdditionalProperties();
    }

    private function opportunitySchema(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'kind' => $schema
                ->string()
                ->enum(['meeting', 'public_comment', 'deadline', 'application', 'other'])
                ->required(),
            'title' => $schema->string()->nullable()->required(),
            'starts_at' => $schema->string()->nullable()->required(),
            'ends_at' => $schema->string()->nullable()->required(),
            'location' => $schema->string()->nullable()->required(),
            'url' => $schema->string()->nullable()->required(),
            'notes' => $schema->string()->nullable()->required(),
            'confidence' => $this->confidenceSchema($schema)->required(),
        ])->withoutAdditionalProperties();
    }

    /**
     * @return array<string, Type>
     */
    private function dimensionNumberProperties(JsonSchema $schema): array
    {
        $properties = [];

        foreach (ScoreDimensions::keys() as $key) {
            $properties[$key] = $this->confidenceSchema($schema)->required();
        }

        return $properties;
    }

    private function confidenceSchema(JsonSchema $schema): NumberType
    {
        return $schema
            ->number()
            ->min(0)
            ->max(1);
    }
}
