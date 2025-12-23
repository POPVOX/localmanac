<?php

use App\Jobs\EnrichArticle;
use App\Jobs\ExtractPdfBody;
use App\Jobs\ScoreArticleHeuristics;
use App\Models\Article;
use App\Models\ArticleAnalysis;
use App\Models\ArticleBody;
use App\Models\ArticleOpportunity;
use App\Models\City;
use App\Models\Organization;
use App\Models\Scraper;
use App\Services\Analysis\CivicRelevanceCalculator;
use App\Services\Analysis\HeuristicScorer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * @param  array{text: string, exit_code: int, stdout: string, stderr: string}  $fakeResult
 */
function makeTestableExtractPdfBodyForHeuristics(int $articleId, string $pdfUrl, array $fakeResult): ExtractPdfBody
{
    return new class($articleId, $pdfUrl, $fakeResult) extends ExtractPdfBody
    {
        /**
         * @param  array{text: string, exit_code: int, stdout: string, stderr: string}  $fakeResult
         */
        public function __construct(int $articleId, string $pdfUrl, private readonly array $fakeResult)
        {
            parent::__construct($articleId, $pdfUrl);
        }

        /**
         * @return array{text: string, exit_code: int, stdout: string, stderr: string}
         */
        protected function runPdfToText(string $pdfPath): array
        {
            return $this->fakeResult;
        }
    };
}

function makeArticleForAnalysis(string $text): Article
{
    $city = City::create(['name' => 'Dispatch City', 'slug' => 'dispatch-city']);

    $organization = Organization::create([
        'city_id' => $city->id,
        'name' => 'Dispatch Org',
        'slug' => 'dispatch-org',
        'type' => 'government',
    ]);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'organization_id' => $organization->id,
        'name' => 'Dispatch Scraper',
        'slug' => 'dispatch-scraper',
        'type' => 'html',
        'source_url' => 'https://example.com',
        'config' => [],
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Dispatch Test',
        'summary' => 'Dispatch summary',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => $text,
        'extraction_status' => 'success',
        'extracted_at' => now(),
    ]);

    return $article;
}

it('dispatches enrichment after successful pdf extraction', function () {
    Queue::fake();

    Http::fake([
        'https://example.com/file.pdf' => Http::response('PDFDATA', 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $city = City::create(['name' => 'Pdf City', 'slug' => 'pdf-city']);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'PDF',
        'slug' => 'pdf',
        'type' => 'html',
        'source_url' => 'https://example.com',
        'config' => [
            'pdf' => [
                'ocr' => false,
            ],
        ],
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'PDF Item',
        'status' => 'published',
        'content_type' => 'pdf',
        'scraper_id' => $scraper->id,
    ]);

    $job = makeTestableExtractPdfBodyForHeuristics($article->id, 'https://example.com/file.pdf', [
        'text' => 'Public hearing on January 20, 2099.',
        'exit_code' => 0,
        'stdout' => '',
        'stderr' => '',
    ]);

    $job->handle();

    Queue::assertPushedOn('analysis', EnrichArticle::class);
});

it('writes analysis and civic relevance after heuristics scoring', function () {
    $article = makeArticleForAnalysis('Public hearing on January 20, 2099.');

    $job = new ScoreArticleHeuristics($article->id);

    $job->handle(app(HeuristicScorer::class), app(CivicRelevanceCalculator::class));

    $analysis = ArticleAnalysis::first();

    expect($analysis)->not->toBeNull()
        ->and($analysis?->status)->toBe('heuristics_done')
        ->and($analysis?->civic_relevance_score)->not->toBeNull();
});

it('creates opportunities from hearing text with a future date', function () {
    $article = makeArticleForAnalysis('Public hearing on January 20, 2099. Submit comments by January 18, 2099.');

    $job = new ScoreArticleHeuristics($article->id);

    $job->handle(app(HeuristicScorer::class), app(CivicRelevanceCalculator::class));

    $opportunity = ArticleOpportunity::first();

    expect($opportunity)->not->toBeNull()
        ->and($opportunity?->kind)->toBe('meeting')
        ->and($opportunity?->starts_at)->not->toBeNull()
        ->and($opportunity?->source)->toBe('heuristic');
});
