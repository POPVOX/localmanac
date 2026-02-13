<?php

use App\Jobs\ExtractPdfBody;
use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\City;
use App\Models\Scraper;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;

/**
 * @param  array{text: string, exit_code: int, stdout: string, stderr: string}  $fakeResult
 * @param  array{text: string, status: string, error?: string, meta: array<string, mixed>}|null  $ocrResult
 */
function makeTestableExtractPdfBody(
    int $articleId,
    string $pdfUrl,
    array $fakeResult,
    ?array $ocrResult = null
): ExtractPdfBody {
    return new class($articleId, $pdfUrl, $fakeResult, $ocrResult) extends ExtractPdfBody
    {
        /**
         * @param  array{text: string, exit_code: int, stdout: string, stderr: string}  $fakeResult
         * @param  array{text: string, status: string, error?: string, meta: array<string, mixed>}|null  $ocrResult
         */
        public function __construct(
            int $articleId,
            string $pdfUrl,
            private readonly array $fakeResult,
            private readonly ?array $ocrResult = null
        ) {
            parent::__construct($articleId, $pdfUrl);
        }

        /**
         * @return array{text: string, exit_code: int, stdout: string, stderr: string}
         */
        protected function runPdfToText(string $pdfPath): array
        {
            return $this->fakeResult;
        }

        /**
         * @return array{text: string, status: string, error?: string, meta: array<string, mixed>}
         */
        protected function performOcr(string $pdfPath, int $maxPages): array
        {
            if ($this->ocrResult !== null) {
                return $this->ocrResult;
            }

            return parent::performOcr($pdfPath, $maxPages);
        }
    };
}

/**
 * @param  array<int, string>  $paragraphs
 */
function makeDocxBinary(array $paragraphs): string
{
    $tmpPath = tempnam(sys_get_temp_dir(), 'docx_test_');

    if ($tmpPath === false) {
        throw new RuntimeException('Unable to create DOCX temp file');
    }

    $zip = new ZipArchive;

    $opened = $zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($opened !== true) {
        throw new RuntimeException('Unable to create DOCX archive');
    }

    $contentTypesXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>
XML;

    $relsXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
XML;

    $paragraphXml = '';

    foreach ($paragraphs as $paragraph) {
        $escaped = htmlspecialchars($paragraph, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $paragraphXml .= '<w:p><w:r><w:t xml:space="preserve">'.$escaped.'</w:t></w:r></w:p>';
    }

    $documentXml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        .'<w:body>'.$paragraphXml.'</w:body>'
        .'</w:document>';

    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $relsXml);
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->close();

    $binary = file_get_contents($tmpPath);
    @unlink($tmpPath);

    if (! is_string($binary)) {
        throw new RuntimeException('Unable to read DOCX binary');
    }

    return $binary;
}

it('marks empty extraction when pdftotext returns no text', function () {
    Http::fake([
        'https://example.com/file.pdf' => Http::response('PDFDATA', 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $city = City::create(['name' => 'Wichita', 'slug' => 'wichita']);

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

    $job = makeTestableExtractPdfBody($article->id, 'https://example.com/file.pdf', [
        'text' => '',
        'exit_code' => 0,
        'stdout' => '',
        'stderr' => '',
    ]);

    $job->handle();

    $body = ArticleBody::first();

    expect($body)->not->toBeNull()
        ->and($body?->extraction_status)->toBe('empty')
        ->and($body?->extraction_error)->toContain('OCR not enabled')
        ->and($body?->raw_text)->toBeNull()
        ->and($body?->cleaned_text)->toBeNull()
        ->and($body?->extraction_meta['http_status'])->toBe(200)
        ->and($body?->extraction_meta['content_type'])->toBe('application/pdf')
        ->and($body?->extraction_meta['extracted_text_length'])->toBe(0)
        ->and($body?->extracted_at)->not->toBeNull();
});

it('marks failure when response is not a pdf', function () {
    Http::fake([
        'https://example.com/not-pdf' => Http::response('<html>nope</html>', 200, [
            'Content-Type' => 'text/html',
        ]),
    ]);

    $city = City::create(['name' => 'NonPdf City', 'slug' => 'nonpdf-city']);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'PDF',
        'slug' => 'pdf-2',
        'type' => 'html',
        'source_url' => 'https://example.com',
        'config' => [
            'pdf' => [
                'ocr' => true,
            ],
        ],
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Not PDF',
        'status' => 'published',
        'content_type' => 'pdf',
        'scraper_id' => $scraper->id,
    ]);

    $job = new ExtractPdfBody($article->id, 'https://example.com/not-pdf');

    $job->handle();

    $body = ArticleBody::first();

    expect($body)->not->toBeNull()
        ->and($body?->extraction_status)->toBe('failed')
        ->and($body?->extraction_error)->toBe('Non-PDF response detected')
        ->and($body?->raw_text)->toBeNull()
        ->and($body?->cleaned_text)->toBeNull()
        ->and($body?->extraction_meta['content_type'])->toBe('text/html')
        ->and($body?->extraction_meta['http_status'])->toBe(200);
});

it('extracts docx responses and refreshes article text', function () {
    $docxBinary = makeDocxBinary([
        'Abatement of the property located at 323 N Ash',
        'Remove all scattered trash and debris from the property.',
    ]);

    Http::fake([
        'https://example.com/file.docx' => Http::response($docxBinary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'inline;filename=NNE2025-04890 323 N ASH.docx',
        ]),
    ]);

    config(['scout.driver' => 'fake']);

    $engine = new class extends Engine
    {
        public int $updateCalls = 0;

        public function update(mixed $models): void
        {
            $this->updateCalls++;
        }

        public function delete(mixed $models): void {}

        public function search(Builder $builder): array
        {
            return ['results' => [], 'total' => 0];
        }

        public function paginate(Builder $builder, mixed $perPage, mixed $page): array
        {
            return ['results' => [], 'total' => 0];
        }

        public function mapIds(mixed $results): Collection
        {
            return collect();
        }

        public function map(Builder $builder, mixed $results, mixed $model): EloquentCollection
        {
            return $model->newCollection();
        }

        public function lazyMap(Builder $builder, mixed $results, mixed $model): LazyCollection
        {
            return $this->map($builder, $results, $model)->lazy();
        }

        public function getTotalCount(mixed $results): int
        {
            return $results['total'] ?? 0;
        }

        public function flush(mixed $model): void {}

        public function createIndex(mixed $name, array $options = []): mixed
        {
            return null;
        }

        public function deleteIndex(mixed $name): mixed
        {
            return null;
        }
    };

    $engineManager = app(EngineManager::class);
    $engineManager->forgetDrivers();
    $engineManager->extend('fake', fn () => $engine);

    $city = City::create(['name' => 'Docx City', 'slug' => 'docx-city']);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'DOCX',
        'slug' => 'docx',
        'type' => 'html',
        'source_url' => 'https://example.com',
        'config' => [],
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => '323 N Ash',
        'status' => 'published',
        'content_type' => 'docx',
        'scraper_id' => $scraper->id,
    ]);

    $job = new ExtractPdfBody($article->id, 'https://example.com/file.docx');
    $job->handle();

    $body = ArticleBody::first();
    $article->refresh();

    expect($body)->not->toBeNull()
        ->and($body?->extraction_status)->toBe('success')
        ->and($body?->cleaned_text)->toContain('Abatement of the property located at 323 N Ash')
        ->and($body?->extraction_meta['detected_type'])->toBe('docx')
        ->and($body?->extraction_meta['method'])->toBe('docx_xml')
        ->and($article->title)->toBe('Abatement of the property located at 323 N Ash')
        ->and($article->summary)->toContain('Abatement of the property located at 323 N Ash')
        ->and($engine->updateCalls)->toBeGreaterThanOrEqual(1);
});

it('uses ocr fallback when enabled', function () {
    Http::fake([
        'https://example.com/ocr.pdf' => Http::response('PDF', 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $city = City::create(['name' => 'Ocr City', 'slug' => 'ocr-city']);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'PDF OCR',
        'slug' => 'pdf-ocr',
        'type' => 'html',
        'source_url' => 'https://example.com',
        'config' => [
            'pdf' => [
                'ocr' => true,
                'max_pages' => 3,
            ],
        ],
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'OCR PDF',
        'status' => 'published',
        'content_type' => 'pdf',
        'scraper_id' => $scraper->id,
    ]);

    $job = makeTestableExtractPdfBody($article->id, 'https://example.com/ocr.pdf', [
        'text' => "\f\f",
        'exit_code' => 0,
        'stdout' => '',
        'stderr' => '',
    ], [
        'text' => 'Found text via OCR',
        'status' => 'success',
        'meta' => [
            'ocr_attempted' => true,
            'ocr_pages' => 1,
            'ocr_length' => 18,
            'pdftoppm_exit_code' => 0,
            'tesseract_exit_codes' => [0],
            'tesseract_stderr' => [''],
        ],
    ]);

    $job->handle();

    $body = ArticleBody::first();

    expect($body)->not->toBeNull()
        ->and($body?->extraction_status)->toBe('ocr_success')
        ->and($body?->extraction_error)->toBeNull()
        ->and($body?->raw_text)->toContain('Found text via OCR')
        ->and($body?->cleaned_text)->toBe('Found text via OCR')
        ->and($body?->extraction_meta['ocr_attempted'])->toBeTrue()
        ->and($body?->extraction_meta['ocr_pages'])->toBe(1)
        ->and($body?->extraction_meta['ocr_length'])->toBe(15);
});

it('reindexes the article after a successful extraction', function () {
    Http::fake([
        'https://example.com/text.pdf' => Http::response('PDF', 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    config(['scout.driver' => 'fake']);

    $engine = new class extends Engine
    {
        public int $updateCalls = 0;

        /**
         * @var array<int, array<string, mixed>>
         */
        public array $lastUpdatePayload = [];

        public function update(mixed $models): void
        {
            $this->updateCalls++;
            $this->lastUpdatePayload = $models->map->toSearchableArray()->all();
        }

        public function delete(mixed $models): void {}

        public function search(Builder $builder): array
        {
            return ['results' => [], 'total' => 0];
        }

        public function paginate(Builder $builder, mixed $perPage, mixed $page): array
        {
            return ['results' => [], 'total' => 0];
        }

        public function mapIds(mixed $results): Collection
        {
            return collect();
        }

        public function map(Builder $builder, mixed $results, mixed $model): EloquentCollection
        {
            return $model->newCollection();
        }

        public function lazyMap(Builder $builder, mixed $results, mixed $model): LazyCollection
        {
            return $this->map($builder, $results, $model)->lazy();
        }

        public function getTotalCount(mixed $results): int
        {
            return $results['total'] ?? 0;
        }

        public function flush(mixed $model): void {}

        public function createIndex(mixed $name, array $options = []): mixed
        {
            return null;
        }

        public function deleteIndex(mixed $name): mixed
        {
            return null;
        }
    };
    $engineManager = app(EngineManager::class);
    $engineManager->forgetDrivers();
    $engineManager->extend('fake', fn () => $engine);

    $city = City::create(['name' => 'Index City', 'slug' => 'index-city']);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'PDF Indexer',
        'slug' => 'pdf-indexer',
        'type' => 'pdf',
        'source_url' => 'https://example.com',
        'config' => [],
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Searchable PDF',
        'status' => 'published',
        'content_type' => 'pdf',
        'scraper_id' => $scraper->id,
    ]);

    $job = makeTestableExtractPdfBody($article->id, 'https://example.com/text.pdf', [
        'text' => "Hello world\nSecond line",
        'exit_code' => 0,
        'stdout' => '',
        'stderr' => '',
    ]);

    $job->handle();

    expect($engine->updateCalls)->toBeGreaterThanOrEqual(2)
        ->and($engine->lastUpdatePayload)->toHaveCount(1)
        ->and($engine->lastUpdatePayload[0]['body'])->toContain('Hello world')
        ->and($engine->lastUpdatePayload[0]['extraction_status'])->toBe('success')
        ->and($engine->lastUpdatePayload[0]['source_url'])->toBeNull();
});
