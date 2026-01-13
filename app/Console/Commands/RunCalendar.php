<?php

namespace App\Console\Commands;

use App\Jobs\RunEventSourceIngestion;
use App\Models\EventSource;
use Illuminate\Console\Command;

class RunCalendar extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calendar:run {--source_id=} {--city_id=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch calendar ingestion jobs';

    public function handle(): int
    {
        $sourceId = $this->option('source_id');
        $cityId = $this->option('city_id');

        $query = EventSource::query()->where('is_active', true);

        if ($sourceId) {
            $query->where('id', (int) $sourceId);
        }

        if ($cityId) {
            $query->where('city_id', (int) $cityId);
        }

        $sources = $query->get();

        if ($sources->isEmpty()) {
            $this->error('No matching event sources found.');

            return self::FAILURE;
        }

        foreach ($sources as $source) {
            RunEventSourceIngestion::dispatch($source->id);
        }

        $this->info("Dispatched {$sources->count()} calendar source(s).");

        return self::SUCCESS;
    }
}
