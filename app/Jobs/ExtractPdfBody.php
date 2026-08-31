<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\Scraper;
use App\Services\Articles\ArticleTextService;
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
        $this->onConnection('redis')->onQueue('scraping');
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
        $meta['content_disposition'] = $response->header('Content-Disposition');
        $meta['bytes'] = strlen((string) $response->body());

        if (! $response->successful()) {
            $error = 'HTTP request failed (status '.$response->status().')';
            $this->persistBody($article, $rawText, $cleanedText, $status, $error, $meta);

            return;
        }

        $binary = (string) $response->body();
        $filename = $this->filenameFromContentDisposition($meta['content_disposition']);
        $detectedType = $this->detectDocumentType(
            $meta['content_type'],
            $meta['content_disposition'],
            $binary,
            $this->pdfUrl
        );

        $meta['filename'] = $filename;
        $meta['detected_type'] = $detectedType;

        try {
            $storedPath = $this->storeDocument($binary, $this->storageExtension($detectedType));
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
            $this->persistBody($article, $rawText, $cleanedText, $status, $error, $meta);

            return;
        }

        try {
            $meta['pdf_magic_header'] = substr((string) file_get_contents($storedPath), 0, 4);
        } catch (\Throwable $e) {
            $meta['pdf_magic_header'] = null;
        }

        if ($detectedType === 'docx') {
            $this->handleDocxExtraction($article, $storedPath, $meta);

            return;
        }

        if ($detectedType !== 'pdf') {
            $error = 'Non-PDF response detected';
            $this->persistBody($article, $rawText, $cleanedText, $status, $error, $meta);

            return;
        }

        $result = $this->runPdfToText($storedPath);
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
                $meta['method'] = 'pdftotext';
                $this->persistBody($article, $rawText, $cleanedText, $status, $error, $meta);

                return;
            }

            $ocrResult = $this->performOcr($storedPath, $maxOcrPages);
            $meta = array_merge($meta, $ocrResult['meta']);
            $rawText = $ocrResult['text'];
            $meaningfulOcrLength = $this->meaningfulLength($rawText);
            $meta['ocr_length'] = $meaningfulOcrLength;

            if ($ocrResult['status'] === 'failed') {
                $status = 'failed';
                $error = $ocrResult['error'] ?? 'OCR failed';
                $meta['method'] = 'ocr';
                $this->persistBody($article, $rawText, $cleanedText, $status, $error, $meta);

                return;
            }

            if ($meaningfulOcrLength === 0) {
                $status = 'empty';
                $error = 'No extractable text after OCR';
                $meta['method'] = 'ocr';
                $this->persistBody($article, $rawText, $cleanedText, $status, $error, $meta);

                return;
            }

            $cleanedText = $this->cleanText($rawText);
            $status = 'ocr_success';
            $meta['method'] = 'ocr';

            $this->persistAndProjectExtraction($article, $rawText, $cleanedText, $status, $error, $meta);

            return;
        }

        $cleanedText = $this->cleanText($rawText);
        $status = 'success';
        $meta['method'] = 'pdftotext';

        $this->persistAndProjectExtraction($article, $rawText, $cleanedText, $status, $error, $meta);
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

    protected function storeDocument(string $contents, string $extension = 'pdf'): string
    {
        $extension = trim($extension) !== '' ? strtolower(trim($extension)) : 'bin';
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension) ?: 'bin';
        $hash = sha1($this->pdfUrl);
        $path = "pdfs/{$hash}.{$extension}";

        $stored = Storage::disk('local')->put($path, $contents);

        if (! $stored) {
            throw new RuntimeException('Unable to store downloaded document');
        }

        return Storage::disk('local')->path($path);
    }

    protected function httpClient()
    {
        return Http::timeout(45)
            ->retry(2, 500)
            ->withHeaders(['User-Agent' => 'LocalmanacBot/1.0']);
    }

    private function handleDocxExtraction(Article $article, string $docxPath, array $meta): void
    {
        try {
            $rawText = $this->extractDocxText($docxPath);
        } catch (RuntimeException $exception) {
            $meta['method'] = 'docx_xml';
            $this->persistBody($article, null, null, 'failed', $exception->getMessage(), $meta);

            return;
        }

        $meta['method'] = 'docx_xml';
        $meta['extracted_text_length'] = mb_strlen($rawText);
        $meta['docx_meaningful_length'] = $this->meaningfulLength($rawText);

        if ($meta['docx_meaningful_length'] === 0) {
            $this->persistBody($article, $rawText, null, 'empty', 'No extractable text in DOCX', $meta);

            return;
        }

        $cleanedText = $this->cleanText($rawText);
        $this->persistAndProjectExtraction($article, $rawText, $cleanedText, 'success', null, $meta);
    }

    private function persistAndProjectExtraction(
        Article $article,
        ?string $rawText,
        ?string $cleanedText,
        string $status,
        ?string $error,
        array $meta
    ): void {
        $this->persistBody($article, $rawText, $cleanedText, $status, $error, $meta);

        if ($cleanedText !== null && trim($cleanedText) !== '') {
            app(ArticleTextService::class)->refresh($article, cleanedText: $cleanedText);
        }

        $this->reindexArticle($article);
        $this->dispatchEnrichment($article);
    }

    private function detectDocumentType(
        string|array|null $contentType,
        string|array|null $contentDisposition,
        string $body,
        string $url
    ): string {
        $contentTypeValue = mb_strtolower($this->headerValue($contentType));
        $contentDispositionValue = mb_strtolower($this->headerValue($contentDisposition));
        $magicHeader = strtolower(bin2hex(substr($body, 0, 4)));
        $urlLower = mb_strtolower($url);

        if (str_contains($contentTypeValue, 'application/pdf') || $magicHeader === '25504446') {
            return 'pdf';
        }

        if (
            str_contains($contentTypeValue, 'wordprocessingml.document')
            || str_contains($contentTypeValue, 'application/msword')
            || str_contains($contentDispositionValue, '.docx')
            || str_contains($contentDispositionValue, '.doc')
            || preg_match('/\.docx?($|\?)/', $urlLower) === 1
        ) {
            return 'docx';
        }

        if ($magicHeader === '504b0304') {
            return 'docx';
        }

        return 'unknown';
    }

    private function storageExtension(string $detectedType): string
    {
        return match ($detectedType) {
            'pdf' => 'pdf',
            'docx' => 'docx',
            default => 'bin',
        };
    }

    private function filenameFromContentDisposition(string|array|null $contentDisposition): ?string
    {
        $value = $this->headerValue($contentDisposition);

        if ($value === '') {
            return null;
        }

        if (preg_match('/filename\*?=(?:UTF-8\'\')?\"?([^\";]+)\"?/i', $value, $matches) !== 1) {
            return null;
        }

        $filename = trim($matches[1]);
        $filename = rawurldecode($filename);

        return $filename !== '' ? $filename : null;
    }

    private function headerValue(string|array|null $value): string
    {
        if (is_array($value)) {
            return trim(implode(';', $value));
        }

        if (is_string($value)) {
            return trim($value);
        }

        return '';
    }

    private function extractDocxText(string $docxPath): string
    {
        $zip = new \ZipArchive;
        $opened = $zip->open($docxPath);

        if ($opened !== true) {
            throw new RuntimeException('Unable to open DOCX file');
        }

        try {
            $documentXml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }

        if (! is_string($documentXml) || trim($documentXml) === '') {
            throw new RuntimeException('DOCX missing word/document.xml');
        }

        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($documentXml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('Unable to parse DOCX XML');
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $paragraphs = [];
        $nodes = $xpath->query('//w:body//w:p');

        if ($nodes === false) {
            return '';
        }

        foreach ($nodes as $paragraphNode) {
            $texts = $xpath->query('.//w:t', $paragraphNode);

            if ($texts === false) {
                continue;
            }

            $line = '';

            foreach ($texts as $textNode) {
                $line .= $textNode->nodeValue;
            }

            $line = $this->cleanText($line);

            if ($line !== '') {
                $paragraphs[] = $line;
            }
        }

        return trim(implode("\n\n", $paragraphs));
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
