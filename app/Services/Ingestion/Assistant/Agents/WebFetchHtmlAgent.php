<?php

namespace App\Services\Ingestion\Assistant\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class WebFetchHtmlAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function __construct(
        public iterable $tools = [],
    ) {}

    public function instructions(): string
    {
        return 'Fetch a URL and return page HTML only. Do not summarize.';
    }

    public function tools(): iterable
    {
        return $this->tools;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'html' => $schema->string()->required(),
            'final_url' => $schema->string()->nullable()->required(),
            'warnings' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
