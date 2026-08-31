<?php

namespace App\Livewire\Admin\Sources;

use App\Models\ChatSource;
use App\Models\City;
use App\Models\EventSource;
use App\Models\Scraper;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $cityId = null;

    public string $kind = '';

    public bool $activeOnly = false;

    protected array $queryString = [
        'search' => ['except' => ''],
        'cityId' => ['except' => null],
        'kind' => ['except' => ''],
        'activeOnly' => ['except' => false],
    ];

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'cityId', 'kind', 'activeOnly'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $allSources = $this->sourceInventory();
        $scopedSources = $allSources
            ->when($this->cityId, fn (Collection $sources) => $sources->where('city_id', $this->cityId))
            ->values();
        $categoryCounts = [
            'article' => $scopedSources->where('kind', 'article')->count(),
            'event' => $scopedSources->where('kind', 'event')->count(),
            'chat' => $scopedSources->where('kind', 'chat')->count(),
        ];
        $attentionCount = $scopedSources->where('health_status', 'unhealthy')->count();

        $filtered = $scopedSources
            ->when($this->kind !== '', fn (Collection $sources) => $sources->where('kind', $this->kind))
            ->when($this->activeOnly, fn (Collection $sources) => $sources->where('active', true))
            ->when(trim($this->search) !== '', function (Collection $sources): Collection {
                $search = mb_strtolower(trim($this->search));

                return $sources->filter(fn (array $source): bool => str_contains(
                    mb_strtolower(implode(' ', [
                        $source['name'],
                        $source['source_url'],
                        $source['city_name'],
                        $source['label'],
                    ])),
                    $search,
                ));
            })
            ->sortByDesc(fn (array $source) => $source['updated_at']?->getTimestamp() ?? 0)
            ->values();

        $page = $this->getPage();
        $perPage = 20;
        $sources = new LengthAwarePaginator(
            $filtered->forPage($page, $perPage)->values(),
            $filtered->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );

        return view('livewire.admin.sources.index', [
            'sources' => $sources,
            'cities' => City::query()->orderBy('name')->get(),
            'categoryCounts' => $categoryCounts,
            'attentionCount' => $attentionCount,
        ])->layout('layouts.admin', [
            'title' => __('Sources'),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function sourceInventory(): Collection
    {
        $articles = Scraper::query()
            ->with('city')
            ->get()
            ->map(fn (Scraper $source): array => $this->row(
                source: $source,
                kind: 'article',
                label: __('Articles'),
                active: (bool) $source->is_enabled,
                healthStatus: $source->health_status ?? 'unknown',
                healthError: $source->health_error,
                showRoute: route('admin.scrapers.show', $source),
                editRoute: route('admin.scrapers.edit', $source),
            ));

        $events = EventSource::query()
            ->with('city')
            ->get()
            ->map(fn (EventSource $source): array => $this->row(
                source: $source,
                kind: 'event',
                label: __('Events'),
                active: (bool) $source->is_active,
                healthStatus: $source->health_status ?? 'unknown',
                healthError: $source->health_error,
                showRoute: route('admin.event-sources.show', $source),
                editRoute: route('admin.event-sources.edit', $source),
            ));

        $chat = ChatSource::query()
            ->with(['city', 'latestRun'])
            ->get()
            ->map(function (ChatSource $source): array {
                $healthStatus = match ($source->latestRun?->status) {
                    'failed' => 'unhealthy',
                    'success' => 'healthy',
                    default => 'unknown',
                };

                return $this->row(
                    source: $source,
                    kind: 'chat',
                    label: __('Answers'),
                    active: (bool) $source->is_active,
                    healthStatus: $healthStatus,
                    healthError: $source->latestRun?->error_message,
                    showRoute: route('admin.chat-sources.show', $source),
                    editRoute: route('admin.chat-sources.edit', $source),
                );
            });

        return $articles->concat($events)->concat($chat)->values();
    }

    /** @return array<string, mixed> */
    private function row(
        Scraper|EventSource|ChatSource $source,
        string $kind,
        string $label,
        bool $active,
        string $healthStatus,
        ?string $healthError,
        string $showRoute,
        string $editRoute,
    ): array {
        return [
            'key' => $kind.'-'.$source->id,
            'id' => $source->id,
            'kind' => $kind,
            'label' => $label,
            'name' => $source->name,
            'source_url' => (string) $source->source_url,
            'city_id' => $source->city_id,
            'city_name' => $source->city?->name ?? __('Unknown'),
            'city_slug' => $source->city?->slug,
            'active' => $active,
            'health_status' => $healthStatus,
            'health_error' => $healthError,
            'show_route' => $showRoute,
            'edit_route' => $editRoute,
            'updated_at' => $source->updated_at,
        ];
    }
}
