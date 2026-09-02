<?php

namespace App\Livewire\Admin;

use App\Models\Article;
use App\Models\ChatSource;
use App\Models\ChatSourceIngestionRun;
use App\Models\City;
use App\Models\CityAccessCode;
use App\Models\CityAccessCodeRedemption;
use App\Models\Event;
use App\Models\EventIngestionRun;
use App\Models\EventSource;
use App\Models\Organization;
use App\Models\Scraper;
use App\Models\ScraperRun;
use App\Services\Admin\CityOverviewQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class Dashboard extends Component
{
    public ?int $cityId = null;

    public int $totalCities = 0;

    public int $totalOrganizations = 0;

    public int $totalScrapers = 0;

    public int $activeScrapers = 0;

    public int $totalEventSources = 0;

    public int $totalChatSources = 0;

    public int $totalSources = 0;

    public int $activeSources = 0;

    public int $unhealthyScrapers = 0;

    public int $unhealthyEventSources = 0;

    public int $articlesLast24h = 0;

    public int $articlesLast7d = 0;

    public int $eventsLast24h = 0;

    public int $eventsLast7d = 0;

    public int $upcomingEvents = 0;

    public int $failedRunsLast24h = 0;

    public int $memberCount = 0;

    public int $newMembersLast7d = 0;

    public int $newMembersLast30d = 0;

    public int $activeAccessCodes = 0;

    public int $attributedMemberCount = 0;

    public int $unattributedMemberCount = 0;

    public ?string $selectedCityName = null;

    public ?string $selectedCitySlug = null;

    public Collection $cities;

    public Collection $citySnapshots;

    public Collection $recentRuns;

    public Collection $recentEventRuns;

    public Collection $recentActivity;

    public Collection $topAccessCodes;

    public Collection $recentMemberGrants;

    public bool $hasArticlesTable = false;

    public bool $hasEventRunsTable = false;

    protected array $queryString = [
        'cityId' => ['except' => null],
    ];

    public function mount(?City $city = null): void
    {
        if ($city?->exists) {
            $this->cityId = $city->getKey();
        }

        $this->loadDashboard();
    }

    public function updatedCityId(): void
    {
        $this->loadDashboard();
    }

    public function render(): View
    {
        return view('livewire.admin.dashboard')
            ->layout('layouts.admin', [
                'title' => $this->selectedCityName
                    ? __(':city overview', ['city' => $this->selectedCityName])
                    : __('Network overview'),
            ]);
    }

    private function loadDashboard(): void
    {
        $this->cities = City::query()->orderBy('name')->get();
        $selectedCity = $this->cityId ? $this->cities->firstWhere('id', $this->cityId) : null;

        if ($this->cityId !== null && $selectedCity === null) {
            $this->cityId = null;
        }

        $this->selectedCityName = $selectedCity?->name;
        $this->selectedCitySlug = $selectedCity?->slug;
        $this->totalCities = $this->cityId ? 1 : $this->cities->count();
        $this->loadUserAnalytics($selectedCity);

        $this->totalOrganizations = $this->scope(Organization::query())->count();
        $this->totalScrapers = $this->scope(Scraper::query())->count();
        $this->activeScrapers = $this->scope(Scraper::query())->where('is_enabled', true)->count();
        $this->totalEventSources = $this->scope(EventSource::query())->count();
        $activeEventSources = $this->scope(EventSource::query())->where('is_active', true)->count();
        $this->totalChatSources = $this->scope(ChatSource::query())->count();
        $activeChatSources = $this->scope(ChatSource::query())->where('is_active', true)->count();
        $this->totalSources = $this->totalScrapers + $this->totalEventSources + $this->totalChatSources;
        $this->activeSources = $this->activeScrapers + $activeEventSources + $activeChatSources;
        $this->unhealthyScrapers = $this->scope(Scraper::query())->where('health_status', 'unhealthy')->count();
        $this->unhealthyEventSources = $this->scope(EventSource::query())->where('health_status', 'unhealthy')->count();

        $this->recentRuns = ScraperRun::query()
            ->with(['scraper.city', 'scraper.organization'])
            ->when($this->cityId, fn (Builder $query) => $query->where('city_id', $this->cityId))
            ->orderByDesc('finished_at')
            ->orderByDesc('started_at')
            ->limit(6)
            ->get();
        $this->recentEventRuns = collect();

        $this->hasArticlesTable = Schema::hasTable('articles');
        if ($this->hasArticlesTable) {
            $articles = $this->scope(Article::query());
            $this->articlesLast24h = (clone $articles)->where('created_at', '>=', now()->subDay())->count();
            $this->articlesLast7d = (clone $articles)->where('created_at', '>=', now()->subDays(7))->count();
        }

        $this->upcomingEvents = $this->scope(Event::query())
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [now(), now()->addDays(30)])
            ->count();

        $this->hasEventRunsTable = Schema::hasTable('event_ingestion_runs');
        if ($this->hasEventRunsTable) {
            $eventRuns = EventIngestionRun::query()
                ->when($this->cityId, fn (Builder $query) => $query->whereHas(
                    'eventSource',
                    fn (Builder $sourceQuery) => $sourceQuery->where('city_id', $this->cityId),
                ));
            $this->eventsLast24h = (int) (clone $eventRuns)
                ->where('finished_at', '>=', now()->subDay())
                ->sum('items_written');
            $this->eventsLast7d = (int) (clone $eventRuns)
                ->where('finished_at', '>=', now()->subDays(7))
                ->sum('items_written');
            $this->recentEventRuns = (clone $eventRuns)
                ->with(['eventSource.city'])
                ->orderByDesc('finished_at')
                ->orderByDesc('started_at')
                ->limit(6)
                ->get();
        }

        $this->failedRunsLast24h = $this->failedRunsLast24h();
        $this->citySnapshots = app(CityOverviewQuery::class)
            ->build()
            ->when($this->cityId, fn (Builder $query) => $query->whereKey($this->cityId))
            ->get();
        $this->recentActivity = $this->buildRecentActivity();
    }

    private function loadUserAnalytics(?City $city): void
    {
        $this->memberCount = 0;
        $this->newMembersLast7d = 0;
        $this->newMembersLast30d = 0;
        $this->activeAccessCodes = 0;
        $this->attributedMemberCount = 0;
        $this->unattributedMemberCount = 0;
        $this->topAccessCodes = collect();
        $this->recentMemberGrants = collect();

        if (! $city) {
            return;
        }

        $memberships = DB::table('city_user')->where('city_id', $city->getKey());
        $this->memberCount = (clone $memberships)->count();
        $this->newMembersLast7d = (clone $memberships)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
        $this->newMembersLast30d = (clone $memberships)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();
        $this->activeAccessCodes = $city->accessCodes()->available()->count();
        $this->attributedMemberCount = $city->accessCodeRedemptions()->count();
        $this->unattributedMemberCount = max(0, $this->memberCount - $this->attributedMemberCount);

        $this->topAccessCodes = CityAccessCode::query()
            ->where('city_id', $city->getKey())
            ->withCount([
                'redemptions',
                'redemptions as recent_redemptions_count' => fn (Builder $query) => $query
                    ->where('redeemed_at', '>=', now()->subDays(30)),
            ])
            ->orderByDesc('redemptions_count')
            ->orderByDesc('last_redeemed_at')
            ->limit(6)
            ->get();

        $this->recentMemberGrants = CityAccessCodeRedemption::query()
            ->where('city_id', $city->getKey())
            ->with(['accessCode', 'user'])
            ->orderByDesc('redeemed_at')
            ->limit(6)
            ->get();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scope(Builder $query): Builder
    {
        return $query->when($this->cityId, fn (Builder $builder) => $builder->where('city_id', $this->cityId));
    }

    private function failedRunsLast24h(): int
    {
        $cutoff = now()->subDay();
        $articleFailures = ScraperRun::query()
            ->when($this->cityId, fn (Builder $query) => $query->where('city_id', $this->cityId))
            ->where('status', 'failed')
            ->where('created_at', '>=', $cutoff)
            ->count();
        $eventFailures = EventIngestionRun::query()
            ->when($this->cityId, fn (Builder $query) => $query->whereHas(
                'eventSource',
                fn (Builder $sourceQuery) => $sourceQuery->where('city_id', $this->cityId),
            ))
            ->where('status', 'failed')
            ->where('created_at', '>=', $cutoff)
            ->count();
        $chatFailures = ChatSourceIngestionRun::query()
            ->when($this->cityId, fn (Builder $query) => $query->whereHas(
                'chatSource',
                fn (Builder $sourceQuery) => $sourceQuery->where('city_id', $this->cityId),
            ))
            ->where('status', 'failed')
            ->where('created_at', '>=', $cutoff)
            ->count();

        return $articleFailures + $eventFailures + $chatFailures;
    }

    private function buildRecentActivity(): Collection
    {
        $articleActivity = $this->recentRuns->toBase()->map(function (ScraperRun $run): array {
            $scraper = $run->scraper;

            return [
                'key' => 'article-'.$run->id,
                'kind' => 'article',
                'source' => $scraper?->name ?? __('Deleted article source'),
                'source_url' => $scraper ? route('admin.scrapers.show', $scraper) : null,
                'city' => $scraper?->city,
                'status' => $run->status,
                'items' => (int) $run->items_created + (int) $run->items_updated,
                'at' => $run->finished_at ?? $run->started_at ?? $run->created_at,
            ];
        });

        $eventActivity = $this->recentEventRuns->toBase()->map(function (EventIngestionRun $run): array {
            $source = $run->eventSource;

            return [
                'key' => 'event-'.$run->id,
                'kind' => 'event',
                'source' => $source?->name ?? __('Deleted event source'),
                'source_url' => $source ? route('admin.event-sources.show', $source) : null,
                'city' => $source?->city,
                'status' => $run->status,
                'items' => (int) $run->items_written,
                'at' => $run->finished_at ?? $run->started_at ?? $run->created_at,
            ];
        });

        $chatActivity = ChatSourceIngestionRun::query()
            ->with(['chatSource.city'])
            ->when($this->cityId, fn (Builder $query) => $query->whereHas(
                'chatSource',
                fn (Builder $sourceQuery) => $sourceQuery->where('city_id', $this->cityId),
            ))
            ->latest('created_at')
            ->limit(6)
            ->get()
            ->toBase()
            ->map(function (ChatSourceIngestionRun $run): array {
                $source = $run->chatSource;

                return [
                    'key' => 'chat-'.$run->id,
                    'kind' => 'chat',
                    'source' => $source?->name ?? __('Deleted answer source'),
                    'source_url' => $source ? route('admin.chat-sources.show', $source) : null,
                    'city' => $source?->city,
                    'status' => $run->status,
                    'items' => (int) $run->pages_changed,
                    'at' => $run->finished_at ?? $run->started_at ?? $run->created_at,
                ];
            });

        return $articleActivity
            ->concat($eventActivity)
            ->concat($chatActivity)
            ->sortByDesc(fn (array $activity) => $activity['at']?->getTimestamp() ?? 0)
            ->take(10)
            ->values();
    }
}
