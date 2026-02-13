<?php

use App\Services\Ingestion\PostgresSequenceSynchronizer;
use Illuminate\Support\Facades\Schema;
use Mockery as M;

uses(Tests\TestCase::class);

afterEach(function () {
    M::close();
});

it('skips when database is not postgresql', function () {
    $this->artisan('db:sync-sequences')
        ->assertExitCode(0);
});

it('syncs discovered public tables with id columns on postgresql', function () {
    config()->set('database.default', 'pgsql');
    config()->set('database.connections.pgsql.driver', 'pgsql');

    $schema = M::mock();
    $schema->shouldReceive('getTableListing')
        ->once()
        ->with('public', false)
        ->andReturn(['users', 'scraper_runs', 'sessions']);

    $schema->shouldReceive('hasColumn')
        ->once()
        ->with('users', 'id')
        ->andReturn(true);

    $schema->shouldReceive('hasColumn')
        ->once()
        ->with('scraper_runs', 'id')
        ->andReturn(true);

    $schema->shouldReceive('hasColumn')
        ->once()
        ->with('sessions', 'id')
        ->andReturn(false);

    Schema::swap($schema);

    $synchronizer = M::mock(PostgresSequenceSynchronizer::class);
    $synchronizer->shouldReceive('syncTables')
        ->once()
        ->with(['users', 'scraper_runs'])
        ->andReturn(true);

    $this->instance(PostgresSequenceSynchronizer::class, $synchronizer);

    $this->artisan('db:sync-sequences')
        ->assertExitCode(0);
});

it('syncs only explicitly specified tables', function () {
    config()->set('database.default', 'pgsql');
    config()->set('database.connections.pgsql.driver', 'pgsql');

    $synchronizer = M::mock(PostgresSequenceSynchronizer::class);
    $synchronizer->shouldReceive('syncTables')
        ->once()
        ->with(['scraper_runs', 'article_analyses'])
        ->andReturn(true);

    $this->instance(PostgresSequenceSynchronizer::class, $synchronizer);

    $this->artisan('db:sync-sequences --table=scraper_runs --table=article_analyses')
        ->assertExitCode(0);
});
