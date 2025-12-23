<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\Scraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class ExtractPdfBody implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 2;

    public function __construct(public int $articleId, public string $pdfUrl)
    {
        $this->onQueue('scraping');
    }

    public function handle(): void
    {
        $article = Article::with(['body', 'sources', 'scraper'])->find($this->articleId);

        if (! $article) {
            return;
        }

        $scraperConfig = $this->scraperConfig($article->scraper_id);

        // Enable OCR if either pdf.ocr=true OR pdf.extract=true
        $ocrEnabled =
            (bool) Arr::get($scraperConfig, 'pdf.ocr', false) ||
            (bool) Arr::get($scraperConfig, 'pdf.extract', false);

        // Default OCR pages if not explicitly set
        $maxOcrPages = (int) Arr::get($scraperConfig, 'pdf.max_pages', 5);
        $maxOcrPages = max(1, $maxOcrPages);

        $meta = [];
        $status = 'failed';
        $error = null;
        $rawText = null;
        $cleanedText = null;
        $meta['ocr_attempted'] = false;
        $meta['ocr_pages'] = 0;
        $meta['ocr_length'] = 0;

        $response = $this->httpClient()->get($this->pdfUrl);
        $meta['http_status'] = $response->status();
        $meta['content_type'] = $response->header('Content-Type');
        $meta['bytes'] = strlen((string) $response->body());

        if (! $response->successful()) {
            $error = 'HTTP request failed (status '.$response->status().')';
            $this->persistBody($article, $rawText, $cleanedText, $status, $error, $meta);

            return;
        }

        try {
            $pdfPath = $this->storePdf($response->body());
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
            $this->persistBody($article, $rawText, $cleanedText, $status, $error, $meta);

            return;
        }

        try {
            $meta['pdf_magic_header'] = substr((string) file_get_contents($pdfPath), 0, 4);
        } catch (\Throwable $e) {
            $meta['pdf_magic_header'] = null;
        }

        if (! $this->isPdfResponse($meta['content_type'])) {
            $error = 'Non-PDF response detected';
            $this->persistBody($article, $rawText, $cleanedText, $status, $error, $meta);

            return;
        }

        $result = $this->runPdfToText($pdfPath);
        $meta['pdftotext_exit_code'] = $result['exit_code'];
        $meta['pdftotext_stdout'] = $result['stdout'];
        $meta['pdftotext_stderr'] = $result['stderr'];
        $meta['extracted_text_length'] = mb_strlen($result['text']);
        $meta['pdftotext_meaningful_length'] = $this->meaningfulLength($result['text']);
        $meta['pdftotext_only_control_chars'] = ($meta['extracted_text_length'] > 0) && ($meta['pdftotext_meaningful_length'] === 0);

        if ($result['exit_code'] !== 0) {
            $error = 'pdftotext failed (exit code '.$result['exit_code'].')';
            $this->persistBody($article, $rawText, $cleanedText, $status, $error, $meta);

            return;
        }

        $rawText = $result['text'];
        $meaningfulLength = $meta['pdftotext_meaningful_length'];

        if ($meaningfulLength === 0) {
            if (! $ocrEnabled) {
                $status = 'empty';
                $error = 'Scanned PDF (no text layer); OCR not enabled for this scraper.';
                $this->persistBody($article, $rawText, $cleanedText, $status, $error, $meta);

                return;
            }

            $ocrResult = $this->performOcr($pdfPath, $maxOcrPages);
            $meta = array_merge($meta, $ocrResult['meta']);
            $rawText = $ocrResult['text'];
            $meaningfulOcrLength = $this->meaningfulLength($rawText);
            $meta['ocr_length'] = $meaningfulOcrLength;

            if ($ocrResult['status'] === 'failed') {
                $status = 'failed';
                $error = $ocrResult['error'] ?? 'OCR failed';
                $this->persistBody($article, $rawText, $cleanedText, $status, $error, $meta);

                return;
            }

            if ($meaningfulOcrLength === 0) {
                $status = 'empty';
                $error = 'No extractable text after OCR';
                $this->persistBody($article, $rawText, $cleanedText, $status, $error, $meta);

                return;
            }

            $cleanedText = $this->cleanText($rawText);
            $status = 'ocr_success';

            $this->persistBody($article, $rawText, $cleanedText, $status, $error, $meta);

            if ($article->summary === null && $cleanedText !== '') {
                $article->summary = Str::limit($cleanedText, 200);
                $article->save();
            }

            $this->reindexArticle($article);
            $this->dispatchEnrichment($article);

            return;
        }

        $cleanedText = $this->cleanText($rawText);
        $status = 'success';

        $this->persistBody($article, $rawText, $cleanedText, $status, $error, $meta);

        if ($article->summary === null && $cleanedText !== '') {
            $article->summary = Str::limit($cleanedText, 200);
            $article->save();
        }

        $this->reindexArticle($article);
        $this->dispatchEnrichment($article);
    }

    /**
     * @return array{text: string, exit_code: int, stdout: string, stderr: string}
     */
    protected function runPdfToText(string $pdfPath): array
    {
        $process = new Process(['pdftotext', '-layout', '-nopgbrk', $pdfPath, '-']);
        $process->setTimeout(120);
        $process->run();

        return [
            'text' => $process->getOutput(),
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    protected function cleanText(string $text): string
    {
        $normalized = preg_replace("/\r\n?/", "\n", $text) ?? '';
        $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized) ?? '';

        return trim($normalized);
    }

    protected function storePdf(string $contents): string
    {
        $hash = sha1($this->pdfUrl);
        $path = "pdfs/{$hash}.pdf";

        $stored = Storage::disk('local')->put($path, $contents);

        if (! $stored) {
            throw new RuntimeException('Unable to store PDF contents');
        }

        return Storage::disk('local')->path($path);
    }

    protected function httpClient()
    {
        return Http::timeout(45)
            ->retry(2, 500)
            ->withHeaders(['User-Agent' => 'LocalmanacBot/1.0']);
    }

    private function isPdfResponse(string|array|null $contentType): bool
    {
        if (is_array($contentType)) {
            $contentType = implode(';', $contentType);
        }

        if (! $contentType) {
            return false;
        }

        return str_contains(strtolower($contentType), 'application/pdf');
    }

    private function persistBody(
        Article $article,
        ?string $rawText,
        ?string $cleanedText,
        string $status,
        ?string $error,
        array $meta
    ): void {
        ArticleBody::updateOrCreate(
            ['article_id' => $article->id],
            [
                'raw_text' => $rawText !== '' ? $rawText : null,
                'cleaned_text' => $cleanedText !== '' ? $cleanedText : null,
                'raw_html' => null,
                'lang' => 'en',
                'extracted_at' => now(),
                'extraction_status' => $status,
                'extraction_error' => $error,
                'extraction_meta' => $meta,
            ]
        );
    }

    /**
     * @return array{text: string, status: string, error?: string, meta: array<string, mixed>}
     */
    protected function performOcr(string $pdfPath, int $maxPages): array
    {
        $baseTemp = Storage::disk('local')->path('pdf_tmp');
        if (! File::exists($baseTemp)) {
            File::makeDirectory($baseTemp, 0755, true);
        }

        $tempDir = $baseTemp.'/'.Str::uuid();
        File::makeDirectory($tempDir, 0755, true);

        $meta = [
            'ocr_attempted' => true,
            'ocr_pages' => 0,
            'ocr_length' => 0,
            'pdftoppm_exit_code' => null,
            'tesseract_exit_codes' => [],
            'tesseract_stderr' => [],
            'ocr_page_lengths' => [],
        ];

        $outputPrefix = $tempDir.'/page';

        try {
            $ppmProcess = new Process([
                'pdftoppm',
                '-r',
                '200',
                '-png',
                '-f',
                '1',
                '-l',
                (string) $maxPages,
                $pdfPath,
                $outputPrefix,
            ]);

            $ppmProcess->setTimeout(180);
            $ppmProcess->run();

            $meta['pdftoppm_exit_code'] = $ppmProcess->getExitCode();

            if ($ppmProcess->getExitCode() !== 0) {
                return [
                    'text' => '',
                    'status' => 'failed',
                    'error' => 'pdftoppm failed (exit code '.$ppmProcess->getExitCode().')',
                    'meta' => $meta,
                ];
            }

            $images = glob($outputPrefix.'-*.png') ?: [];
            sort($images, SORT_NATURAL);
            $images = array_slice($images, 0, $maxPages);
            $meta['ocr_pages'] = count($images);

            $texts = [];

            foreach ($images as $imagePath) {
                $tesseract = new Process([
                    'tesseract',
                    $imagePath,
                    'stdout',
                    '-l',
                    'eng',
                ]);

                $tesseract->setTimeout(180);
                $tesseract->run();

                $exit = $tesseract->getExitCode() ?? 1;
                $meta['tesseract_exit_codes'][] = $exit;
                $stderrSnippet = Str::limit(trim($tesseract->getErrorOutput()), 500, '...');
                $meta['tesseract_stderr'][] = $stderrSnippet;

                if ($exit !== 0) {
                    return [
                        'text' => implode("\n\n---- PAGE SEPARATOR ----\n\n", $texts),
                        'status' => 'failed',
                        'error' => 'tesseract failed (exit code '.$exit.')',
                        'meta' => $meta,
                    ];
                }

                $texts[] = trim($tesseract->getOutput());
                $meta['ocr_page_lengths'][] = $this->meaningfulLength(end($texts) ?: '');
            }

            $ocrText = trim(implode("\n\n---- PAGE SEPARATOR ----\n\n", $texts));
            $meta['ocr_length'] = $this->meaningfulLength($ocrText);

            return [
                'text' => $ocrText,
                'status' => 'success',
                'meta' => $meta,
            ];
        } finally {
            if (File::exists($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        }
    }

    private function meaningfulLength(string $text): int
    {
        $stripped = preg_replace('/[\p{C}\s]+/u', '', $text) ?? '';

        return mb_strlen(trim($stripped));
    }

    /**
     * @return array<string, mixed>
     */
    private function scraperConfig(?int $scraperId): array
    {
        if (! $scraperId) {
            return [];
        }

        $scraper = Scraper::find($scraperId);

        return $scraper?->config ?? [];
    }

    private function reindexArticle(Article $article): void
    {
        $article->load(['body', 'sources', 'scraper']);

        $article->searchable();
    }

    private function dispatchEnrichment(Article $article): void
    {
        if (! config('enrichment.enabled', true)) {
            return;
        }

        EnrichArticle::dispatch($article->id);
    }
}
