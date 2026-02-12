<?php

use App\Services\Chat\Agents\ChatCitationAgent;
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

it('disallows additional properties for citation agent items', function () {
    $schema = new JsonSchemaTypeFactory;
    $definition = (new ChatCitationAgent)->schema($schema);
    $citations = $definition['citations']->toArray();

    expect($citations['items']['additionalProperties'] ?? null)->toBeFalse();
});
