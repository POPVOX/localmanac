<?php

use App\Services\Chat\Agents\StructuredChatAnswerAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('disallows additional properties for structured chat citation items', function () {
    $schema = new JsonSchemaTypeFactory;
    $definition = (new StructuredChatAnswerAgent)->schema($schema);
    $citations = $definition['citations']->toArray();

    expect($citations['items']['additionalProperties'] ?? null)->toBeFalse();
});

it('requires the documented top-level structured chat fields', function () {
    $schema = new JsonSchemaTypeFactory;
    $definition = (new StructuredChatAnswerAgent)->schema($schema);

    expect(array_keys($definition))->toBe(['answer', 'citations', 'source_mode', 'confidence']);
});
