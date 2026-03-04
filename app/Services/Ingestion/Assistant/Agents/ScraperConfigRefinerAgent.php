<?php

namespace App\Services\Ingestion\Assistant\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class ScraperConfigRefinerAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function __construct(
        public iterable $tools = [],
    ) {}

    public function instructions(): string
    {
        return 'You generate scraper ingestion config JSON for a Laravel app. Return only structured data.';
    }

    public function tools(): iterable
    {
        return $this->tools;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'profile' => $schema
                ->string()
                ->enum(['rss', 'wichitadocumenters', 'generic_listing', 'wichita_archive_pdf_list'])
                ->required(),
            'config' => $schema
                ->object([
                    'feed_url' => $schema->string()->nullable()->required(),
                    'lang' => $schema->string()->nullable()->required(),
                    'max_items' => $schema->integer()->nullable()->required(),
                    'best_effort' => $schema->boolean()->nullable()->required(),
                    'list' => $schema->object([
                        'link_selector' => $schema->string()->nullable()->required(),
                        'link_attr' => $schema->string()->nullable()->required(),
                        'max_links' => $schema->integer()->nullable()->required(),
                        'max_pages' => $schema->integer()->nullable()->required(),
                        'pagination_selector' => $schema->string()->nullable()->required(),
                        'pagination_attr' => $schema->string()->nullable()->required(),
                        'href_contains' => $schema->string()->nullable()->required(),
                    ])->withoutAdditionalProperties()->nullable()->required(),
                    'article' => $schema->object([
                        'content_selector' => $schema->string()->nullable()->required(),
                        'remove_selectors' => $schema->array()->items($schema->string())->nullable()->required(),
                    ])->withoutAdditionalProperties()->nullable()->required(),
                    'fetch' => $schema->object([
                        'renderer' => $schema->string()->nullable()->required(),
                        'playwright' => $schema->object([
                            'timeout_ms' => $schema->integer()->nullable()->required(),
                            'wait_selector' => $schema->string()->nullable()->required(),
                            'user_agent' => $schema->string()->nullable()->required(),
                            'storage_state_path' => $schema->string()->nullable()->required(),
                            'refresh_on_blocked' => $schema->boolean()->nullable()->required(),
                            'refresh_attempts' => $schema->integer()->nullable()->required(),
                            'auto_scroll' => $schema->boolean()->nullable()->required(),
                            'max_scroll_steps' => $schema->integer()->nullable()->required(),
                            'scroll_pause_ms' => $schema->integer()->nullable()->required(),
                            'proxy' => $schema->object([
                                'server' => $schema->string()->nullable()->required(),
                                'username' => $schema->string()->nullable()->required(),
                                'password' => $schema->string()->nullable()->required(),
                                'bypass' => $schema->string()->nullable()->required(),
                            ])->withoutAdditionalProperties()->nullable()->required(),
                        ])->withoutAdditionalProperties()->nullable()->required(),
                    ])->withoutAdditionalProperties()->nullable()->required(),
                    'pdf' => $schema->object([
                        'extract' => $schema->boolean()->nullable()->required(),
                    ])->withoutAdditionalProperties()->nullable()->required(),
                ])
                ->withoutAdditionalProperties()
                ->required(),
            'warnings' => $schema->array()->items($schema->string())->required(),
            'confidence' => $schema
                ->number()
                ->min(0)
                ->max(1)
                ->required(),
        ];
    }
}
