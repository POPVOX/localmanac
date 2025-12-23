<?php

namespace App\Livewire\Admin\Scrapers;

use App\Jobs\RunScraperRun;
use App\Models\City;
use App\Models\Organization;
use App\Models\Scraper;
use App\Models\ScraperRun;
use App\Services\Ingestion\ScrapeRunner;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class Index extends Component
{
    use WithPagination;

    public ?int $cityId = null;

    public ?int $organizationId = null;

    public ?string $type = null;

    public bool $activeOnly = false;

    /**
     * @var list<string>
     */
    public array $types = ['rss', 'html', 'json'];

    public string $search = '';

    public string $sortField = 'scrapers.updated_at';

    public string $sortDirection = 'desc';

    protected array $queryString = [
        'cityId' => ['except' => null],
        'organizationId' => ['except' => null],
        'type' => ['except' => null],
        'activeOnly' => ['except' => false],
        'search' => ['except' => ''],
        'sortField' => ['except' => 'scrapers.updated_at'],
        'sortDirection' => ['except' => 'desc'],
    ];

    public function updatingCityId(): void
    {
        $this->resetPage();
    }

    public function updatingOrganizationId(): void
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

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function toggleActive(int $scraperId): void
    {
        try {
            $scraper = Scraper::findOrFail($scraperId);

            $scraper->update([
                'is_enabled' => ! $scraper->is_enabled,
            ]);

            $this->dispatchToast($scraper->is_enabled ? __('Scraper enabled') : __('Scraper disabled'));
        } catch (ModelNotFoundException $exception) {
            $this->dispatchToast(__('Scraper not found'), 'danger');
            report($exception);
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatchToast(__('Unable to update scraper'), 'danger');
        }
    }

    public function queueRun(int $scraperId): void
    {
        try {
            $scraper = Scraper::findOrFail($scraperId);

            if (! $scraper->is_enabled || ! in_array($scraper->type, ['rss', 'html'], true)) {
                $this->dispatchToast(__('Enable the scraper before queuing a run.'), 'danger');

                return;
            }

            $hasActiveRun = ScraperRun::query()
                ->where('scraper_id', $scraper->id)
                ->whereIn('status', ['queued', 'running'])
                ->exists();

            if ($hasActiveRun) {
                $this->dispatchToast(__('Already running'), 'warning');

                return;
            }

            $run = app(ScrapeRunner::class)->createRun($scraper);

            RunScraperRun::dispatch($run->id);

            $this->dispatchToast(__('Scrape queued'));
        } catch (ModelNotFoundException $exception) {
            $this->dispatchToast(__('Scraper not found'), 'danger');
            report($exception);
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatchToast(__('Unable to queue scraper'), 'danger');
        }
    }

    public function render(): View
    {
        $search = trim($this->search);

        $scrapers = Scraper::query()
            ->with([
                'city',
                'organization',
                'latestRun',
            ])
            ->leftJoin('organizations', 'organizations.id', '=', 'scrapers.organization_id')
            ->select('scrapers.*', 'organizations.name as organization_name')
            ->when($this->cityId, fn ($query) => $query->where('scrapers.city_id', $this->cityId))
            ->when($this->organizationId, fn ($query) => $query->where('scrapers.organization_id', $this->organizationId))
            ->when($this->type, fn ($query) => $query->where('scrapers.type', $this->type))
            ->when($this->activeOnly, fn ($query) => $query->where('scrapers.is_enabled', true))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('scrapers.name', 'like', "%{$search}%")
                        ->orWhere('scrapers.slug', 'like', "%{$search}%")
                        ->orWhere('scrapers.source_url', 'like', "%{$search}%")
                        ->orWhere('organizations.name', 'like', "%{$search}%");
                });
            })
            ->when($this->sortField, function ($query) {
                $field = $this->sortField;
                $allowed = [
                    'scrapers.id',
                    'organization_name',
                    'scrapers.type',
                    'scrapers.source_url',
                    'scrapers.is_enabled',
                    'scrapers.updated_at',
                ];

                if (! in_array($field, $allowed, true)) {
                    $field = 'scrapers.updated_at';
                }

                $query->orderBy($field, $this->sortDirection);
            }, function ($query) {
                $query->orderByDesc('scrapers.updated_at');
            })
            ->paginate(15);

        $cities = City::query()->orderBy('name')->get();
        $organizations = Organization::query()->orderBy('name')->get();

        return view('livewire.admin.scrapers.index', [
            'scrapers' => $scrapers,
            'cities' => $cities,
            'organizations' => $organizations,
        ])->layout('layouts.admin', [
            'title' => __('Scrapers'),
        ]);
    }

    private function dispatchToast(string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', message: $message, variant: $variant);
    }
}
