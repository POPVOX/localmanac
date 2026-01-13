<?php

namespace App\Services\Ingestion;

use App\Models\EventIngestionRun;
use App\Models\EventSource;
use App\Services\Ingestion\Fetchers\HtmlCalendarFetcher;
use App\Services\Ingestion\Fetchers\IcsFetcher;
use App\Services\Ingestion\Fetchers\JsonApiFetcher;
use App\Services\Ingestion\Fetchers\RssEventsFetcher;
use InvalidArgumentException;
use Throwable;

class EventIngestionRunner
{
    public function __construct(
        private readonly EventWriter $writer,
        private readonly IcsFetcher $icsFetcher,
        private readonly RssEventsFetcher $rssFetcher,
        private readonly JsonApiFetcher $jsonFetcher,
        private readonly HtmlCalendarFetcher $htmlFetcher,
    ) {}

    public function run(EventSource $source): EventIngestionRun
    {
        $run = $this->createRun($source);

        return $this->runExisting($run);
    }

    public function createRun(EventSource $source): EventIngestionRun
    {
        $this->assertRunnable($source);

        return EventIngestionRun::create([
            'event_source_id' => $source->id,
            'status' => 'queued',
            'items_found' => 0,
            'items_written' => 0,
        ]);
    }

    public function runExisting(EventIngestionRun $run): EventIngestionRun
    {
        $run->loadMissing('eventSource.city');

        $source = $run->eventSource;

        if (! $source) {
            throw new InvalidArgumentException('EventSource is missing for this run');
        }

        $this->assertRunnable($source);

        $run->forceFill([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'error_class' => null,
            'error_message' => null,
        ])->save();

        $itemsFound = 0;
        $itemsWritten = 0;

        try {
            $items = $this->fetchItems($source);
            $itemsFound = count($items);

            foreach ($items as $item) {
                if (trim($item->title) === '' || ! $item->startsAt) {
                    continue;
                }

                $this->writer->write($source, $item);
                $itemsWritten++;
            }

            $run->update([
                'status' => 'success',
                'finished_at' => now(),
                'items_found' => $itemsFound,
                'items_written' => $itemsWritten,
                'error_class' => null,
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'items_found' => $itemsFound,
                'items_written' => $itemsWritten,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);
        }

        $source->forceFill([
            'last_run_at' => now(),
        ])->save();

        return $run->refresh();
    }

    /**
     * @return array<int, EventDTO>
     */
    private function fetchItems(EventSource $source): array
    {
        return match ($source->source_type) {
            'ics' => $this->icsFetcher->fetch($source),
            'rss' => $this->rssFetcher->fetch($source),
            'json', 'json_api' => $this->jsonFetcher->fetch($source),
            'html' => $this->htmlFetcher->fetch($source),
            default => throw new InvalidArgumentException('Unsupported event source type'),
        };
    }

    private function assertRunnable(EventSource $source): void
    {
        if (! $source->is_active) {
            throw new InvalidArgumentException('EventSource is disabled');
        }

        if (! in_array($source->source_type, ['ics', 'rss', 'json', 'json_api', 'html'], true)) {
            throw new InvalidArgumentException('Unsupported event source type');
        }
    }
}
