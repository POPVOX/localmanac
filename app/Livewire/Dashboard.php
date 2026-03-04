<?php

namespace App\Livewire;

use App\Models\Article;
use App\Models\City;
use App\Models\IssueArea;
use App\Services\Chat\AskService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class Dashboard extends Component
{
    use WithPagination;

    private const ARTICLE_RESULT_LIMIT = 10;

    private const ARTICLE_SEARCH_CANDIDATE_LIMIT = 250;

    private const ARTICLE_PAGE_NAME = 'articles-page';

    public string $question = '';

    /**
     * @var array<int, array{role: string, content: string, citations?: array<int, array{title: string, source_url: string, type: string}>}>
     */
    public array $messages = [];

    public string $articleSearch = '';

    public ?int $activeIssueAreaId = null;

    public ?int $cityId = null;

    public ?string $conversationId = null;

    public function mount(): void
    {
        $this->cityId = request()->integer('city_id') ?: null;

        if ($this->memoryEnabled()) {
            $this->conversationId = session()->get($this->conversationSessionKey());
        }
    }

    public function ask(): void
    {
        $question = trim($this->question);

        if ($question === '') {
            return;
        }

        $this->messages[] = [
            'role' => 'user',
            'content' => $question,
        ];

        try {
            $city = $this->resolveCity();
            $response = [];

            if (auth()->user()) {
                $response = app(AskService::class)->answerStreamingForUser(
                    question: $question,
                    citySelector: $city?->id,
                    user: auth()->user(),
                    conversationId: $this->memoryEnabled() ? $this->conversationId : null,
                    onDelta: static fn (string $delta): null => null,
                );

                if ($this->memoryEnabled()) {
                    $this->conversationId = is_string($response['conversation_id'] ?? null)
                        ? $response['conversation_id']
                        : null;

                    if ($this->conversationId) {
                        session()->put($this->conversationSessionKey(), $this->conversationId);
                    }
                }
            } else {
                $response = app(AskService::class)->answer(
                    question: $question,
                    cityId: $city?->id,
                    citySlug: null,
                );
            }

            $this->messages[] = [
                'role' => 'assistant',
                'content' => (string) ($response['answer'] ?? ''),
                'citations' => $response['citations'] ?? [],
            ];
        } catch (Throwable $exception) {
            report($exception);

            $this->messages[] = [
                'role' => 'assistant',
                'content' => __('Sorry, something went wrong while answering that.'),
            ];
        }

        $this->question = '';
        $this->dispatch('chat-updated');
    }

    public function startNewConversation(): void
    {
        $this->conversationId = null;
        session()->forget($this->conversationSessionKey());
        $this->messages = [];
        $this->dispatch('chat-updated');
    }

    public function applyPrompt(string $prompt): void
    {
        $this->question = $prompt;
    }

    public function selectIssueArea(int $issueAreaId): void
    {
        $this->activeIssueAreaId = $issueAreaId;
        $this->resetArticlePage();
    }

    public function clearIssueArea(): void
    {
        $this->activeIssueAreaId = null;
        $this->resetArticlePage();
    }

    public function updatedActiveIssueAreaId(mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->activeIssueAreaId = null;

            return;
        }

        $this->activeIssueAreaId = (int) $value;
    }

    public function useArticleFilterChip(string $chip): void
    {
        $this->activeIssueAreaId = null;
        $this->articleSearch = trim($chip);
        $this->resetArticlePage();
    }

    public function updatingArticleSearch(): void
    {
        $this->resetArticlePage();
    }

    public function updatingCityId(): void
    {
        $this->resetArticlePage();
    }

    public function render(): View
    {
        $city = $this->resolveCity();
        $timezone = $this->resolveTimezone($city);

        $issueAreas = $this->issueAreaQuery($city)
            ->orderBy('name')
            ->get();

        $promptChips = $this->chatPromptChips($city);
        $articleFallbackChips = $this->articleFallbackChips();

        $articleBase = $this->articleQuery($city);

        $totalArticles = (clone $articleBase)->count();
        $articlesAddedToday = $this->countArticlesForDate($articleBase, $timezone);

        $articles = $this->dashboardArticles($city);

        return view('livewire.dashboard', [
            'city' => $city,
            'timezone' => $timezone,
            'issueAreas' => $issueAreas,
            'promptChips' => $promptChips,
            'articleFallbackChips' => $articleFallbackChips,
            'articles' => $articles,
            'stats' => [
                'totalArticles' => $totalArticles,
                'addedToday' => $articlesAddedToday,
                'categoryCount' => $issueAreas->count(),
                'locationLabel' => $city?->name ?? '—',
            ],
        ])->layout('layouts.app-dashboard');
    }

    private function resolveCity(): ?City
    {
        if ($this->cityId) {
            return City::query()->find($this->cityId);
        }

        return City::query()
            ->where('slug', 'wichita')
            ->first()
            ?? City::query()->first();
    }

    private function memoryEnabled(): bool
    {
        return (bool) config('chat.memory_enabled', true) && auth()->check();
    }

    private function conversationSessionKey(): string
    {
        return (string) config('chat.memory_session_key', 'chat.conversation_id');
    }

    private function resolveTimezone(?City $city): string
    {
        return $city?->timezone ?? config('app.timezone', 'UTC');
    }

    private function articleQuery(?City $city): Builder
    {
        $query = Article::query();

        if ($city?->id) {
            $query->where('city_id', $city->id);
        }

        return $query;
    }

    private function issueAreaQuery(?City $city): Builder
    {
        $query = IssueArea::query();

        if ($city?->id) {
            $query->where('city_id', $city->id);
        }

        return $query;
    }

    private function dashboardArticles(?City $city): LengthAwarePaginator
    {
        $search = trim($this->articleSearch);

        if ($search === '') {
            return $this->recentArticles($city);
        }

        return $this->searchArticles($city, $search);
    }

    private function recentArticles(?City $city): LengthAwarePaginator
    {
        return $this->applyArticleFilters($this->articleQuery($city))
            ->with($this->articleRelations())
            ->orderByDesc(DB::raw('COALESCE(published_at, created_at)'))
            ->paginate(self::ARTICLE_RESULT_LIMIT, ['*'], self::ARTICLE_PAGE_NAME);
    }

    private function searchArticles(?City $city, string $search): LengthAwarePaginator
    {
        try {
            $searchQuery = Article::search($search);

            if ($city?->id) {
                $searchQuery->where('city_id', $city->id);
            }

            $orderedIds = collect($searchQuery
                ->take(self::ARTICLE_SEARCH_CANDIDATE_LIMIT)
                ->keys())
                ->map(fn (int|string $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->values();

            if ($orderedIds->isEmpty()) {
                return $this->emptyArticlePaginator();
            }

            $query = Article::query()
                ->whereIn('id', $orderedIds->all());

            if ($city?->id) {
                $query->where('city_id', $city->id);
            }

            if ($this->activeIssueAreaId) {
                $query->whereHas('articleIssueAreas', function (Builder $builder): void {
                    $builder->where('issue_area_id', $this->activeIssueAreaId);
                });
            }

            $matchedIds = $query
                ->pluck('id')
                ->map(fn (int|string $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0);

            $matchedIdSet = $matchedIds->flip();
            $filteredOrderedIds = $orderedIds
                ->filter(fn (int $id): bool => $matchedIdSet->has($id))
                ->values();

            $total = $filteredOrderedIds->count();

            if ($total === 0) {
                return $this->emptyArticlePaginator();
            }

            $currentPage = max(1, (int) $this->getPage(self::ARTICLE_PAGE_NAME));
            $lastPage = max(1, (int) ceil($total / self::ARTICLE_RESULT_LIMIT));
            $currentPage = min($currentPage, $lastPage);
            $offset = ($currentPage - 1) * self::ARTICLE_RESULT_LIMIT;

            $pageIds = $filteredOrderedIds
                ->slice($offset, self::ARTICLE_RESULT_LIMIT)
                ->values();

            if ($pageIds->isEmpty()) {
                return $this->emptyArticlePaginator();
            }

            $matched = Article::query()
                ->whereIn('id', $pageIds->all())
                ->with($this->articleRelations())
                ->get()
                ->keyBy('id');

            $ordered = $pageIds
                ->map(fn (int $id): ?Article => $matched->get($id))
                ->filter()
                ->values();

            return new LengthAwarePaginator(
                new EloquentCollection($ordered->all()),
                $total,
                self::ARTICLE_RESULT_LIMIT,
                $currentPage,
                [
                    'path' => LengthAwarePaginator::resolveCurrentPath(),
                    'pageName' => self::ARTICLE_PAGE_NAME,
                ],
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->sqlLikeFallbackArticles($city);
        }
    }

    private function sqlLikeFallbackArticles(?City $city): LengthAwarePaginator
    {
        return $this->applyArticleFilters($this->articleQuery($city))
            ->with($this->articleRelations())
            ->orderByDesc(DB::raw('COALESCE(published_at, created_at)'))
            ->paginate(self::ARTICLE_RESULT_LIMIT, ['*'], self::ARTICLE_PAGE_NAME);
    }

    private function applyArticleFilters(Builder $query): Builder
    {
        $searchTerm = trim($this->articleSearch);

        if ($searchTerm !== '') {
            $search = '%'.addcslashes($searchTerm, '%_').'%';

            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('title', 'like', $search)
                    ->orWhere('summary', 'like', $search);
            });
        }

        if ($this->activeIssueAreaId) {
            $query->whereHas('articleIssueAreas', function (Builder $builder): void {
                $builder->where('issue_area_id', $this->activeIssueAreaId);
            });
        }

        return $query;
    }

    private function countArticlesForDate(Builder $baseQuery, string $timezone): int
    {
        $today = Carbon::now($timezone)->toDateString();

        return (clone $baseQuery)
            ->where(function (Builder $query) use ($today): void {
                $query->whereDate('published_at', $today)
                    ->orWhere(function (Builder $nested) use ($today): void {
                        $nested->whereNull('published_at')
                            ->whereDate('created_at', $today);
                    });
            })
            ->count();
    }

    /**
     * @return array<int, string>
     */
    private function articleRelations(): array
    {
        return [
            'scraper.organization',
            'sources',
            'articleIssueAreas.issueArea',
        ];
    }

    private function emptyArticlePaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            new EloquentCollection,
            0,
            self::ARTICLE_RESULT_LIMIT,
            1,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => self::ARTICLE_PAGE_NAME,
            ],
        );
    }

    private function resetArticlePage(): void
    {
        $this->resetPage(self::ARTICLE_PAGE_NAME);
    }

    /**
     * @return array<int, array{label: string, prompt: string}>
     */
    private function chatPromptChips(?City $city): array
    {
        $cityName = $city?->name ?? __('your city');

        return [
            [
                'label' => __('What changed this week?'),
                'prompt' => __('Summarize the most important local updates in :city from the last 7 days. Focus on decisions, projects, and deadlines, and include citations.', ['city' => $cityName]),
            ],
            [
                'label' => __('Upcoming meetings'),
                'prompt' => __('What city council, board, and public meetings are coming up in :city in the next 14 days? Include dates, times, and where to find the agenda.', ['city' => $cityName]),
            ],
            [
                'label' => __('New permits & projects'),
                'prompt' => __('What new permits, rezonings, or major development projects were recently filed or approved in :city? Include status and key locations.', ['city' => $cityName]),
            ],
            [
                'label' => __('Service alerts'),
                'prompt' => __('What active service alerts or disruptions should residents in :city know about right now? Focus on roads, utilities, water, trash, and public services.', ['city' => $cityName]),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function articleFallbackChips(): array
    {
        return [
            __('Building permits'),
            __('Council meetings'),
            __('Local events'),
            __('Construction updates'),
        ];
    }
}
