<?php

use App\Jobs\EnrichArticle;
use App\Jobs\ExtractPdfBody;
use App\Models\Article;
use App\Models\City;
use App\Models\Scraper;
use App\Services\Ingestion\ArticleWriter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh');
});

/**
 * @param  array{text: string, exit_code: int, stdout: string, stderr: string}  $fakeResult
 */
function makeTestableExtractPdfBodyForEnrichment(int $articleId, string $pdfUrl, array $fakeResult): ExtractPdfBody
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

it('dispatches enrichment when article writer saves cleaned text', function () {
    Queue::fake();

    $city = City::create([
        'name' => 'Dispatch City',
        'slug' => 'dispatch-city',
    ]);

    $writer = new ArticleWriter;
    $writer->write([
        'city_id' => $city->id,
        'title' => 'Dispatch Article',
        'summary' => 'Summary',
        'content_type' => 'html',
        'status' => 'published',
        'source' => [
            'source_url' => 'https://example.com/article',
        ],
        'body' => [
            'cleaned_text' => 'The city council held a meeting.',
        ],
    ]);

    Queue::assertPushedOn('analysis', EnrichArticle::class);
});

it('dispatches enrichment after pdf extraction', function () {
    Queue::fake();

    Http::fake([
        'https://example.com/file.pdf' => Http::response('PDFDATA', 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $city = City::create([
        'name' => 'Pdf City',
        'slug' => 'pdf-city',
    ]);

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

    $job = makeTestableExtractPdfBodyForEnrichment($article->id, 'https://example.com/file.pdf', [
        'text' => 'Public hearing on January 20, 2099.',
        'exit_code' => 0,
        'stdout' => '',
        'stderr' => '',
    ]);

    $job->handle();

    Queue::assertPushedOn('analysis', EnrichArticle::class);
});
