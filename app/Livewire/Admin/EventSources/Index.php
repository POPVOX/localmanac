<?php

namespace App\Livewire\Admin\EventSources;

use App\Jobs\RunEventSourceIngestion;
use App\Models\City;
use App\Models\EventIngestionRun;
use App\Models\EventSource;
use App\Services\Ingestion\EventIngestionRunner;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class Index extends Component
{
    use WithPagination;

    public ?int $cityId = null;

    public ?string $type = null;

    public bool $activeOnly = false;

    public string $search = '';

    /**
     * @var list<string>
     */
    public array $types = ['ics', 'rss', 'json_api', 'json', 'html'];

    protected array $queryString = [
        'cityId' => ['except' => null],
        'type' => ['except' => null],
        'activeOnly' => ['except' => false],
        'search' => ['except' => ''],
    ];

    public function updatingCityId(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function updatingActiveOnly(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function toggleActive(int $sourceId): void
    {
        try {
            $source = EventSource::findOrFail($sourceId);

            $source->update([
                'is_active' => ! $source->is_active,
            ]);

            if ($source->is_active) {
                $this->dispatchToast(__('Source enabled'), __('Runs will be included in schedules.'));
            } else {
                $this->dispatchToast(__('Source disabled'), __('Runs will be skipped until re-enabled.'));
            }
        } catch (ModelNotFoundException $exception) {
            $this->dispatchToast(__('Source not found'), __('Refresh the page and try again.'), 'danger');
            report($exception);
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatchToast(__('Update failed'), __('We could not update the source.'), 'danger');
        }
    }

    public function queueRun(int $sourceId): void
    {
        try {
            $source = EventSource::findOrFail($sourceId);

            if (! $source->is_active) {
                $this->dispatchToast(__('Source disabled'), __('Enable it before queuing a run.'), 'danger');

                return;
            }

            if (! in_array($source->source_type, ['ics', 'rss', 'json', 'json_api', 'html'], true)) {
                $this->dispatchToast(__('Unsupported source type'), __('Update the source type and try again.'), 'danger');

                return;
            }

            $hasActiveRun = EventIngestionRun::query()
                ->where('event_source_id', $source->id)
                ->whereIn('status', ['queued', 'running'])
                ->exists();

            if ($hasActiveRun) {
                $this->dispatchToast(__('Already running'), __('A run is already queued or in progress.'), 'warning');

                return;
            }

            $run = app(EventIngestionRunner::class)->createRun($source);

            RunEventSourceIngestion::dispatch($source->id, $run->id);

            $this->dispatchToast(__('Run queued'), __('We will ingest this source in the background.'));
        } catch (ModelNotFoundException $exception) {
            $this->dispatchToast(__('Source not found'), __('Refresh the page and try again.'), 'danger');
            report($exception);
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatchToast(__('Queue failed'), __('We could not queue this run.'), 'danger');
        }
    }

    public function render(): View
    {
        $search = trim($this->search);

        $sources = EventSource::query()
            ->with(['city', 'latestRun'])
            ->when($search !== '', function ($query) use ($search) {
                $query->leftJoin('cities', 'cities.id', '=', 'event_sources.city_id')
                    ->select('event_sources.*')
                    ->where(function ($inner) use ($search) {
                        $inner->where('event_sources.name', 'like', "%{$search}%")
                            ->orWhere('event_sources.source_url', 'like', "%{$search}%")
                            ->orWhere('event_sources.source_type', 'like', "%{$search}%")
                            ->orWhere('cities.name', 'like', "%{$search}%");
                    });
            })
            ->when($this->cityId, fn ($query) => $query->where('city_id', $this->cityId))
            ->when($this->type, fn ($query) => $query->where('source_type', $this->type))
            ->when($this->activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('event_sources.name')
            ->paginate(15);

        $cities = City::query()->orderBy('name')->get();

        return view('livewire.admin.event-sources.index', [
            'sources' => $sources,
            'cities' => $cities,
        ])->layout('layouts.admin', [
            'title' => __('Event Sources'),
        ]);
    }

    private function dispatchToast(string $heading, string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', heading: $heading, message: $message, variant: $variant);
    }
}
