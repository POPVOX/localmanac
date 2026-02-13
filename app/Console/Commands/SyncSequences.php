<?php

namespace App\Console\Commands;

use App\Services\Ingestion\PostgresSequenceSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SyncSequences extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:sync-sequences {--table=* : Limit sync to one or more tables (repeat option)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync PostgreSQL id sequences to each table max(id)';

    /**
     * Execute the console command.
     */
    public function handle(PostgresSequenceSynchronizer $sequenceSynchronizer): int
    {
        $driver = (string) config('database.connections.'.config('database.default').'.driver', '');

        if ($driver !== 'pgsql') {
            $this->warn("Skipping sequence sync: database driver '{$driver}' is not PostgreSQL.");

            return self::SUCCESS;
        }

        $tables = $this->resolveTables();

        if ($tables->isEmpty()) {
            $this->warn('No public tables with an id column were found.');

            return self::SUCCESS;
        }

        $synchronized = $sequenceSynchronizer->syncTables($tables->all());

        $this->info(sprintf('Processed %d table(s).', $tables->count()));

        if ($synchronized) {
            $this->info('Sequence synchronization completed.');
        } else {
            $this->warn('No matching PostgreSQL id sequences were synchronized.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, string>
     */
    private function resolveTables(): Collection
    {
        $specifiedTables = collect($this->option('table'))
            ->filter(fn (mixed $table): bool => is_string($table) && trim($table) !== '')
            ->map(fn (mixed $table): string => trim((string) $table))
            ->values();

        if ($specifiedTables->isNotEmpty()) {
            return $specifiedTables->unique()->values();
        }

        return collect(Schema::getTableListing('public', false))
            ->filter(fn (mixed $table): bool => is_string($table) && Schema::hasColumn($table, 'id'))
            ->map(fn (mixed $table): string => (string) $table)
            ->values();
    }
}
