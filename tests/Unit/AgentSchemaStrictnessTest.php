<?php

use App\Services\Analysis\Agents\LlmScoringAgent;
use App\Services\Extraction\Agents\CivicAnalysisAgent;
use App\Services\Extraction\Agents\EntityEnrichmentAgent;
use App\Services\Extraction\Agents\ExplainerAgent;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Tests\TestCase;

uses(TestCase::class);

it('uses strict nested objects for civic analysis schema', function () {
    $schema = new JsonSchemaTypeFactory;
    $definition = (new CivicAnalysisAgent)->schema($schema);
    $analysis = $definition['analysis']->toArray();
    $timeline = $definition['process_timeline']->toArray();

    expect($analysis['additionalProperties'] ?? null)->toBeFalse()
        ->and($analysis['properties']['opportunities']['items']['additionalProperties'] ?? null)->toBeFalse()
        ->and($timeline['additionalProperties'] ?? null)->toBeFalse()
        ->and($timeline['properties']['items']['items']['additionalProperties'] ?? null)->toBeFalse();
});

it('uses strict nested objects for entity enrichment schema', function () {
    $schema = new JsonSchemaTypeFactory;
    $definition = (new EntityEnrichmentAgent)->schema($schema);
    $enrichment = $definition['enrichment']->toArray();

    expect($enrichment['additionalProperties'] ?? null)->toBeFalse()
        ->and($enrichment['properties']['people']['items']['additionalProperties'] ?? null)->toBeFalse()
        ->and($enrichment['properties']['organizations']['items']['additionalProperties'] ?? null)->toBeFalse()
        ->and($enrichment['properties']['issue_areas']['items']['additionalProperties'] ?? null)->toBeFalse();
});

it('uses strict nested objects for explainer schema', function () {
    $schema = new JsonSchemaTypeFactory;
    $definition = (new ExplainerAgent)->schema($schema);
    $explainer = $definition['explainer']->toArray();

    expect($explainer['additionalProperties'] ?? null)->toBeFalse()
        ->and($explainer['properties']['evidence']['additionalProperties'] ?? null)->toBeFalse()
        ->and($explainer['properties']['evidence']['properties']['whats_happening']['items']['additionalProperties'] ?? null)->toBeFalse();
});

it('uses strict nested objects for llm scoring schema', function () {
    $schema = new JsonSchemaTypeFactory;
    $definition = (new LlmScoringAgent)->schema($schema);
    $dimensions = $definition['dimensions']->toArray();
    $justifications = $definition['justifications']->toArray();
    $opportunities = $definition['opportunities']->toArray();

    expect($dimensions['additionalProperties'] ?? null)->toBeFalse()
        ->and($justifications['additionalProperties'] ?? null)->toBeFalse()
        ->and($opportunities['items']['additionalProperties'] ?? null)->toBeFalse();
});
