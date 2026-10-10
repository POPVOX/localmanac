<?php

namespace App\Services\Ingestion;

use Psr\Http\Message\ResponseInterface;
use Throwable;

class DownloadPolicy
{
    public function isMediaUrl(string $url): bool
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($extension, ['mp4', 'm4v', 'mov', 'webm', 'avi', 'mp3', 'wav', 'm4a', 'ogg', 'zip', 'exe', 'iso'], true);
    }

    public function assertHeaders(ResponseInterface $response): void
    {
        $type = strtolower($response->getHeaderLine('Content-Type'));

        if (str_starts_with($type, 'video/') || str_starts_with($type, 'audio/')) {
            throw new DownloadRejected('Audio and video downloads are not supported by the document crawler.');
        }

        $this->assertSize((float) $response->getHeaderLine('Content-Length'));
    }

    public function assertSize(float $bytes): void
    {
        if ($bytes > max(1, (int) config('ingestion.max_download_bytes', 25 * 1024 * 1024))) {
            throw new DownloadRejected('Download exceeds the configured ingestion size limit.');
        }
    }

    public function options(): array
    {
        return [
            'on_headers' => $this->assertHeaders(...),
            'progress' => function (float $total, float $downloaded): void {
                $this->assertSize(max($total, $downloaded));
            },
        ];
    }

    public function rejectionFrom(Throwable $exception): ?DownloadRejected
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof DownloadRejected) {
                return $cause;
            }
        }

        return null;
    }
}
