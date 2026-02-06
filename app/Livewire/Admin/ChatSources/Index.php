<?php

namespace App\Livewire\Admin\ChatSources;

use App\Models\ChatSource;
use App\Models\ChatSourcePage;
use App\Models\City;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class Index extends Component
{
    use WithPagination;

    public ?int $cityId = null;

    public bool $activeOnly = false;

    public string $search = '';

    public string $sortField = 'chat_sources.priority';

    public string $sortDirection = 'desc';

    protected array $queryString = [
        'cityId' => ['except' => null],
        'activeOnly' => ['except' => false],
        'search' => ['except' => ''],
        'sortField' => ['except' => 'chat_sources.priority'],
        'sortDirection' => ['except' => 'desc'],
    ];

    public function updatingCityId(): void
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

    public function toggleActive(int $sourceId): void
    {
        try {
            $source = ChatSource::findOrFail($sourceId);

            $source->update([
                'is_active' => ! $source->is_active,
            ]);

            if ($source->is_active) {
                $this->dispatchToast(__('Source enabled'), __('It will be used for chat answers.'));
            } else {
                $this->dispatchToast(__('Source disabled'), __('It will be skipped until re-enabled.'));
            }
        } catch (ModelNotFoundException $exception) {
            $this->dispatchToast(__('Source not found'), __('Refresh the page and try again.'), 'danger');
            report($exception);
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatchToast(__('Update failed'), __('We could not update the source.'), 'danger');
        }
    }

    public function render(): View
    {
        $search = trim($this->search);

        $sources = ChatSource::query()
            ->with('city')
            ->when($search !== '', function ($query) use ($search) {
                $query->leftJoin('cities', 'cities.id', '=', 'chat_sources.city_id')
                    ->select('chat_sources.*')
                    ->where(function ($inner) use ($search) {
                        $inner->where('chat_sources.name', 'like', "%{$search}%")
                            ->orWhere('chat_sources.source_url', 'like', "%{$search}%")
                            ->orWhere('chat_sources.description', 'like', "%{$search}%")
                            ->orWhere('cities.name', 'like', "%{$search}%");
                    });
            })
            ->when($this->cityId, fn ($query) => $query->where('city_id', $this->cityId))
            ->when($this->activeOnly, fn ($query) => $query->where('is_active', true))
            ->when($this->sortField, function ($query) {
                $allowed = [
                    'chat_sources.name',
                    'chat_sources.priority',
                    'chat_sources.is_active',
                    'chat_sources.updated_at',
                ];

                $field = in_array($this->sortField, $allowed, true)
                    ? $this->sortField
                    : 'chat_sources.priority';

                $query->orderBy($field, $this->sortDirection);
            }, function ($query) {
                $query->orderByDesc('chat_sources.priority');
            })
            ->paginate(15);

        $cities = City::query()->orderBy('name')->get();
        $cutoff = now()->subDay();

        $summaryRow = ChatSourcePage::query()
            ->selectRaw('count(*) as total_pages')
            ->selectRaw('count(*) filter (where fetched_at >= ?) as pages_last_24h', [$cutoff])
            ->selectRaw('count(*) filter (where renderer = ?) as playwright_pages', ['playwright'])
            ->selectRaw('avg(fetch_duration_ms) as avg_fetch_ms')
            ->first();

        $totalPages = (int) ($summaryRow->total_pages ?? 0);
        $playwrightPages = (int) ($summaryRow->playwright_pages ?? 0);

        $summary = [
            'total_pages' => $totalPages,
            'pages_last_24h' => (int) ($summaryRow->pages_last_24h ?? 0),
            'playwright_pages' => $playwrightPages,
            'playwright_rate' => $totalPages > 0 ? round(($playwrightPages / $totalPages) * 100, 1) : 0,
            'avg_fetch_ms' => $summaryRow->avg_fetch_ms ? (int) round((float) $summaryRow->avg_fetch_ms) : null,
        ];

        $slowSources = ChatSource::query()
            ->join('chat_source_pages', 'chat_source_pages.chat_source_id', '=', 'chat_sources.id')
            ->select('chat_sources.id', 'chat_sources.name', 'chat_sources.source_url')
            ->selectRaw('count(*) as page_count')
            ->selectRaw('avg(chat_source_pages.fetch_duration_ms) as avg_fetch_ms')
            ->selectRaw('max(chat_source_pages.fetch_duration_ms) as max_fetch_ms')
            ->selectRaw('max(chat_source_pages.fetched_at) as last_fetched_at')
            ->selectRaw('count(*) filter (where chat_source_pages.renderer = ?) as playwright_pages', ['playwright'])
            ->whereNotNull('chat_source_pages.fetch_duration_ms')
            ->groupBy('chat_sources.id', 'chat_sources.name', 'chat_sources.source_url')
            ->orderByDesc('avg_fetch_ms')
            ->limit(8)
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'source_url' => (string) $row->source_url,
                    'page_count' => (int) $row->page_count,
                    'avg_fetch_ms' => $row->avg_fetch_ms ? (int) round((float) $row->avg_fetch_ms) : null,
                    'max_fetch_ms' => $row->max_fetch_ms ? (int) $row->max_fetch_ms : null,
                    'last_fetched_at' => $row->last_fetched_at ? Carbon::parse($row->last_fetched_at) : null,
                    'playwright_pages' => (int) $row->playwright_pages,
                ];
            });

        return view('livewire.admin.chat-sources.index', [
            'sources' => $sources,
            'cities' => $cities,
            'summary' => $summary,
            'slowSources' => $slowSources,
        ])->layout('layouts.admin', [
            'title' => __('Chat Sources'),
        ]);
    }

    private function dispatchToast(string $heading, string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', heading: $heading, message: $message, variant: $variant);
    }
}
