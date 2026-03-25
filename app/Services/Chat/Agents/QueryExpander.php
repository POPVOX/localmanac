<?php

namespace App\Services\Chat\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class QueryExpander implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        private readonly string $cityName = '',
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $cityContext = $this->cityName !== ''
            ? " Focus on topics relevant to {$this->cityName} and its local government services."
            : '';

        return 'You are a search query expansion assistant for civic information. '
            .'Given a broad user question, generate 2-3 specific search sub-queries '
            .'that would help find relevant civic information. '
            .'Each sub-query should use specific vocabulary likely to appear in government documents, '
            .'such as department names, service types, permit categories, or municipal program names.'
            .$cityContext;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'queries' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
