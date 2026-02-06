<?php

namespace App\Services\Chat;

use RuntimeException;
use Symfony\Component\Process\Process;

class PdfTextExtractor
{
    public function extract(string $binary): string
    {
        $path = $this->storeTempPdf($binary);

        try {
            $result = $this->runPdfToText($path);
        } finally {
            @unlink($path);
        }

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('pdftotext failed (exit code '.$result['exit_code'].')');
        }

        return trim($result['text']);
    }

    /**
     * @return array{text: string, exit_code: int, stdout: string, stderr: string}
     */
    private function runPdfToText(string $pdfPath): array
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

    private function storeTempPdf(string $binary): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'chat_pdf_');

        if ($temp === false) {
            throw new RuntimeException('Unable to create temp file for PDF extraction.');
        }

        file_put_contents($temp, $binary);

        return $temp;
    }
}
