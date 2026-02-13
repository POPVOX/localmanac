<?php

use App\Models\EventIngestionRun;
use App\Models\EventSource;
use App\Services\Ingestion\EventIngestionRunner;
use App\Services\Ingestion\EventWriter;
use App\Services\Ingestion\Fetchers\HtmlCalendarFetcher;
use App\Services\Ingestion\Fetchers\IcsFetcher;
use App\Services\Ingestion\Fetchers\JsonApiFetcher;
use App\Services\Ingestion\Fetchers\RssEventsFetcher;
use App\Services\Ingestion\PostgresSequenceSynchronizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery as M;

uses(Tests\TestCase::class, RefreshDatabase::class);

afterEach(function () {
    M::close();
});

function makePartialEventIngestionRunner(PostgresSequenceSynchronizer $synchronizer): EventIngestionRunner
{
    return M::mock(EventIngestionRunner::class, [
        M::mock(EventWriter::class),
        M::mock(IcsFetcher::class),
        M::mock(RssEventsFetcher::class),
        M::mock(JsonApiFetcher::class),
        M::mock(HtmlCalendarFetcher::class),
        $synchronizer,
    ])->makePartial()->shouldAllowMockingProtectedMethods();
}

it('retries run creation once after recoverable sequence drift is repaired', function () {
    $source = EventSource::factory()->create([
        'source_type' => 'ics',
        'source_url' => 'https://example.com/calendar.ics',
    ]);

    $stored = new EventIngestionRun([
        'event_source_id' => $source->id,
        'status' => 'queued',
        'items_found' => 0,
        'items_written' => 0,
    ]);
    $stored->id = 42;

    $recoverableViolation = new UniqueConstraintViolationException(
        'pgsql',
        'insert into "event_ingestion_runs"',
        [],
        new RuntimeException('duplicate key value violates unique constraint "event_ingestion_runs_pkey"')
    );

    $synchronizer = M::mock(PostgresSequenceSynchronizer::class);
    $synchronizer->shouldReceive('syncTables')
        ->once()
        ->with(['event_ingestion_runs'])
        ->andReturn(true);

    $runner = makePartialEventIngestionRunner($synchronizer);
    $runner->shouldReceive('persistRun')
        ->once()
        ->andThrow($recoverableViolation);
    $runner->shouldReceive('persistRun')
        ->once()
        ->andReturn($stored);

    $result = $runner->createRun($source);

    expect($result)->toBe($stored)
        ->and($result->id)->toBe(42);
});

it('does not retry when run creation unique constraint errors are not recoverable sequence drift', function () {
    $source = EventSource::factory()->create([
        'source_type' => 'ics',
        'source_url' => 'https://example.com/calendar.ics',
    ]);

    $nonRecoverableViolation = new UniqueConstraintViolationException(
        'pgsql',
        'insert into "event_ingestion_runs"',
        [],
        new RuntimeException('duplicate key value violates unique constraint "event_ingestion_runs_event_source_id_status_unique"')
    );

    $synchronizer = M::mock(PostgresSequenceSynchronizer::class);
    $synchronizer->shouldReceive('syncTables')->never();

    $runner = makePartialEventIngestionRunner($synchronizer);
    $runner->shouldReceive('persistRun')
        ->once()
        ->andThrow($nonRecoverableViolation);

    expect(fn () => $runner->createRun($source))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('fails when sequence drift is detected but cannot be repaired', function () {
    $source = EventSource::factory()->create([
        'source_type' => 'ics',
        'source_url' => 'https://example.com/calendar.ics',
    ]);

    $recoverableViolation = new UniqueConstraintViolationException(
        'pgsql',
        'insert into "event_ingestion_runs"',
        [],
        new RuntimeException('duplicate key value violates unique constraint "event_ingestion_runs_pkey"')
    );

    $synchronizer = M::mock(PostgresSequenceSynchronizer::class);
    $synchronizer->shouldReceive('syncTables')
        ->once()
        ->with(['event_ingestion_runs'])
        ->andReturn(false);

    $runner = makePartialEventIngestionRunner($synchronizer);
    $runner->shouldReceive('persistRun')
        ->once()
        ->andThrow($recoverableViolation);

    expect(fn () => $runner->createRun($source))
        ->toThrow(UniqueConstraintViolationException::class);
});
