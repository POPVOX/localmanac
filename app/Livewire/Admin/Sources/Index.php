<?php

namespace App\Livewire\Admin\Sources;

use App\Jobs\IngestChatSource;
use App\Jobs\RunEventSourceIngestion;
use App\Jobs\RunScraperRun;
use App\Models\ChatSource;
use App\Models\ChatSourceIngestionRun;
use App\Models\City;
use App\Models\EventIngestionRun;
use App\Models\EventSource;
use App\Models\Scraper;
use App\Models\ScraperRun;
use App\Services\Chat\Ingestion\ChatSourceIngestionRunner;
use App\Services\Ingestion\EventIngestionRunner;
use App\Services\Ingestion\ScrapeRunner;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

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

    public function retrySource(string $kind, int $sourceId): void
    {
        $run = null;

        try {
            if ($kind === 'article') {
                $source = Scraper::findOrFail($sourceId);
                $this->expireStaleArticleRuns($source->id);

                if (! $source->is_enabled || ! in_array($source->type, ['rss', 'html'], true)) {
                    $this->dispatchToast(__('Source is paused'), __('Edit or enable the source before retrying.'), 'warning');

                    return;
                }

                if ($source->runs()->freshActive()->exists()) {
                    $this->dispatchToast(__('Already running'), __('A retry is already queued or in progress.'), 'warning');

                    return;
                }

                $run = app(ScrapeRunner::class)->createRun($source);
                RunScraperRun::dispatch($run->id);
            } elseif ($kind === 'event') {
                $source = EventSource::findOrFail($sourceId);
                EventIngestionRun::expireStaleActive();

                if (! $source->is_active) {
                    $this->dispatchToast(__('Source is paused'), __('Edit or enable the source before retrying.'), 'warning');

                    return;
                }

                if (! in_array($source->source_type, ['ics', 'rss', 'json', 'json_api', 'html'], true)) {
                    $this->dispatchToast(__('Source needs editing'), __('Choose a supported source type before retrying.'), 'warning');

                    return;
                }

                if ($source->runs()->freshActive()->exists()) {
                    $this->dispatchToast(__('Already running'), __('A retry is already queued or in progress.'), 'warning');

                    return;
                }

                $run = app(EventIngestionRunner::class)->createRun($source);
                RunEventSourceIngestion::dispatch($source->id, $run->id);
            } elseif ($kind === 'chat') {
                $source = ChatSource::findOrFail($sourceId);
                $this->expireStaleChatRuns($source->id);

                if (! $source->is_active) {
                    $this->dispatchToast(__('Source is paused'), __('Edit or enable the source before retrying.'), 'warning');

                    return;
                }

                if ($source->runs()->freshActive()->exists()) {
                    $this->dispatchToast(__('Already running'), __('A retry is already queued or in progress.'), 'warning');

                    return;
                }

                $run = app(ChatSourceIngestionRunner::class)->createRun($source);
                IngestChatSource::dispatch($source->id, false, $run->id);
            } else {
                $this->dispatchToast(__('Source not found'), __('Refresh the page and try again.'), 'danger');

                return;
            }

            $this->dispatchToast(__('Retry queued'), __('We will test and ingest the source in the background.'));
        } catch (ModelNotFoundException $exception) {
            report($exception);
            $this->dispatchToast(__('Source not found'), __('Refresh the page and try again.'), 'danger');
        } catch (Throwable $exception) {
            if ($run) {
                $failure = [
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error_message' => __('Failed to dispatch retry job: :message', ['message' => $exception->getMessage()]),
                ];

                if ($run instanceof EventIngestionRun || $run instanceof ChatSourceIngestionRun) {
                    $failure['error_class'] = $exception::class;
                }

                $run->update($failure);
            }

            report($exception);
            $this->dispatchToast(__('Retry failed'), __('We could not queue this source.'), 'danger');
        }
    }

    public function deleteSource(string $kind, int $sourceId): void
    {
        try {
            $model = $this->sourceModel($kind);

            if ($model === null) {
                $this->dispatchToast(__('Source not found'), __('Refresh the page and try again.'), 'danger');

                return;
            }

            $source = $model::query()->findOrFail($sourceId);
            $sourceName = $source->name;
            $source->delete();

            $this->resetPage();
            $this->dispatchToast(
                __('Source deleted'),
                __(':name and its run history were removed.', ['name' => $sourceName]),
            );
        } catch (ModelNotFoundException $exception) {
            report($exception);
            $this->dispatchToast(__('Source not found'), __('It may already have been deleted.'), 'warning');
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatchToast(__('Delete failed'), __('We could not delete this source.'), 'danger');
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

    /** @return class-string<Model>|null */
    private function sourceModel(string $kind): ?string
    {
        return match ($kind) {
            'article' => Scraper::class,
            'event' => EventSource::class,
            'chat' => ChatSource::class,
            default => null,
        };
    }

    private function expireStaleArticleRuns(int $sourceId): void
    {
        ScraperRun::query()
            ->where('scraper_id', $sourceId)
            ->staleActive()
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => __('Run timed out before the worker started.'),
                'updated_at' => now(),
            ]);
    }

    private function expireStaleChatRuns(int $sourceId): void
    {
        ChatSourceIngestionRun::query()
            ->where('chat_source_id', $sourceId)
            ->staleActive()
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_class' => null,
                'error_message' => __('Run timed out before the worker started.'),
                'updated_at' => now(),
            ]);
    }

    private function dispatchToast(string $heading, string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', heading: $heading, message: $message, variant: $variant);
    }
}
