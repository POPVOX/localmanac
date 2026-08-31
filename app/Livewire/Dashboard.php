<?php

namespace App\Livewire;

use App\Models\Article;
use App\Models\City;
use App\Models\Event;
use App\Models\IssueArea;
use App\Models\User;
use App\Services\Chat\AskService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class Dashboard extends Component
{
    use WithPagination;

    private const ARTICLE_RESULT_LIMIT = 10;

    private const ARTICLE_SEARCH_CANDIDATE_LIMIT = 250;

    private const ARTICLE_PAGE_NAME = 'articles-page';

    private const NATIONAL_STORY_LOCAL_RELEVANCE_MIN = 0.6;

    public string $question = '';

    /**
     * @var array<int, array{
     *     id: string,
     *     role: string,
     *     content: string,
     *     citations: array<int, array{title: string, source_url: string, type: string}>
     * }>
     */
    public array $messages = [];

    public string $articleSearch = '';

    public ?int $activeIssueAreaId = null;

    public ?int $cityId = null;

    public bool $adminPreview = false;

    public ?string $conversationId = null;

    public string $cityAccessCode = '';

    public bool $chatAccessGranted = false;

    public function mount(?City $city = null): void
    {
        $this->cityId = $city?->id ?? (request()->integer('city_id') ?: null);
        $this->adminPreview = $city !== null && request()->routeIs('admin.cities.preview');
        $this->conversationId = $this->storedConversationId($this->resolveCity());
    }

    public function ask(): void
    {
        $question = trim($this->question);

        if ($question === '') {
            return;
        }

        $city = $this->resolveCity();
        $user = auth()->user();

        if (! $city || ! $user instanceof User || ! $user->hasVerifiedEmail() || ! $user->canAccessCity($city)) {
            $this->addError('question', __('Enter the access code for this city to use chat.'));

            return;
        }

        $this->appendMessage('user', $question);
        $assistantMessageId = $this->appendMessage('assistant');

        try {
            $response = app(AskService::class)->answerStreamingForUser(
                question: $question,
                citySelector: $city?->id,
                user: $user,
                conversationId: $this->storedConversationId($city),
                onDelta: function (string $delta) use ($assistantMessageId): null {
                    $this->appendAssistantDelta($assistantMessageId, $delta);

                    return null;
                },
            );

            $this->conversationId = is_string($response['conversation_id'] ?? null)
                ? $response['conversation_id']
                : null;

            $this->storeConversationId($this->conversationId, $city);

            $this->replaceMessage(
                messageId: $assistantMessageId,
                content: $this->resolvedAssistantContent($assistantMessageId, $response),
                citations: $this->normalizedCitations($response['citations'] ?? []),
            );
        } catch (Throwable $exception) {
            report($exception);

            $this->replaceMessage(
                messageId: $assistantMessageId,
                content: __('Sorry, something went wrong while answering that.'),
            );
        }

        $this->question = '';
        $this->dispatch('chat-updated');
    }

    public function startNewConversation(): void
    {
        $this->conversationId = null;
        $this->storeConversationId(null, $this->resolveCity());
        $this->messages = [];
        $this->dispatch('chat-reset');
    }

    public function applyPrompt(string $prompt): void
    {
        if (! $this->canUseChat($this->resolveCity())) {
            return;
        }

        $this->question = $prompt;
    }

    public function unlockCityChat(): void
    {
        $user = auth()->user();
        $city = $this->resolveCity();

        if (! $user instanceof User || ! $city) {
            $this->addError('cityAccessCode', __('Sign in before entering a city access code.'));

            return;
        }

        if (! $user->hasVerifiedEmail()) {
            $this->addError('cityAccessCode', __('Verify your email address before unlocking chat.'));

            return;
        }

        if ($user->isSuperAdmin() || $user->canAccessCity($city)) {
            $this->chatAccessGranted = true;

            return;
        }

        $this->validate([
            'cityAccessCode' => ['required', 'string', 'max:100'],
        ]);

        $rateLimitKey = 'city-chat-access:'.$user->getKey().':'.$city->getKey();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $this->addError(
                'cityAccessCode',
                __('Too many attempts. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($rateLimitKey),
                ]),
            );

            return;
        }

        if (! $city->matchesChatAccessCode($this->cityAccessCode)) {
            RateLimiter::hit($rateLimitKey, 60);
            $this->addError('cityAccessCode', __('That access code is not valid for :city.', ['city' => $city->name]));

            return;
        }

        $user->cities()->syncWithoutDetaching([$city->getKey()]);
        RateLimiter::clear($rateLimitKey);
        $this->reset('cityAccessCode');
        $this->resetErrorBag('cityAccessCode');
        $this->chatAccessGranted = true;
        $this->dispatch('chat-access-granted');
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
        $availableCities = City::query()
            ->orderBy('name')
            ->orderBy('state')
            ->get();

        $issueAreas = $this->issueAreaQuery($city)
            ->orderBy('name')
            ->get();

        $promptChips = $this->chatPromptChips($city);
        $articleFallbackChips = $this->articleFallbackChips();

        $articleBase = $this->articleQuery($city);
        $visibleFeedBase = $this->applyRecentFeedFilters($this->articleQuery($city));

        $totalArticles = (clone $articleBase)->count();
        $articlesAddedToday = $this->countArticlesAddedToday($visibleFeedBase, $timezone);

        $articles = $this->dashboardArticles($city);

        return view('livewire.dashboard', [
            'city' => $city,
            'timezone' => $timezone,
            'issueAreas' => $issueAreas,
            'promptChips' => $promptChips,
            'articleFallbackChips' => $articleFallbackChips,
            'articles' => $articles,
            'upcomingEvents' => $this->upcomingEvents($city, $timezone),
            'canUseChat' => $this->canUseChat($city),
            'chatAccessConfigured' => $city?->hasChatAccessCode() ?? false,
            'stats' => [
                'totalArticles' => $totalArticles,
                'addedToday' => $articlesAddedToday,
                'categoryCount' => $issueAreas->count(),
                'locationLabel' => $city?->name ?? '—',
            ],
        ])->layout('layouts.app-dashboard', [
            'city' => $city,
            'adminPreview' => $this->adminPreview,
            'availableCities' => $availableCities,
            'currentSurface' => 'dashboard',
        ]);
    }

    private function appendMessage(string $role, string $content = '', array $citations = []): string
    {
        $messageId = (string) Str::uuid();

        $this->messages[] = [
            'id' => $messageId,
            'role' => $role,
            'content' => $content,
            'citations' => $this->normalizedCitations($citations),
        ];

        return $messageId;
    }

    private function appendAssistantDelta(string $messageId, string $delta): void
    {
        if ($delta === '') {
            return;
        }

        $messageIndex = $this->messageIndex($messageId);

        if ($messageIndex === null) {
            return;
        }

        $message = $this->messages[$messageIndex];

        $this->messages[$messageIndex] = [
            'id' => $message['id'],
            'role' => $message['role'],
            'content' => (string) ($message['content'] ?? '').$delta,
            'citations' => $message['citations'] ?? [],
        ];
    }

    private function replaceMessage(string $messageId, string $content, array $citations = []): void
    {
        $messageIndex = $this->messageIndex($messageId);

        if ($messageIndex === null) {
            return;
        }

        $message = $this->messages[$messageIndex];

        $this->messages[$messageIndex] = [
            'id' => $message['id'],
            'role' => $message['role'],
            'content' => $content,
            'citations' => $this->normalizedCitations($citations),
        ];
    }

    private function messageIndex(string $messageId): ?int
    {
        foreach ($this->messages as $index => $message) {
            if (($message['id'] ?? null) === $messageId) {
                return $index;
            }
        }

        return null;
    }

    private function resolvedAssistantContent(string $messageId, array $response): string
    {
        $answer = trim((string) ($response['answer'] ?? ''));

        if ($answer !== '') {
            return $answer;
        }

        $messageIndex = $this->messageIndex($messageId);

        if ($messageIndex !== null) {
            $streamedContent = trim((string) ($this->messages[$messageIndex]['content'] ?? ''));

            if ($streamedContent !== '') {
                return $streamedContent;
            }
        }

        return __('Sorry, I could not find an answer for that question.');
    }

    /**
     * @param  array<int, mixed>  $citations
     * @return array<int, array{title: string, source_url: string, type: string}>
     */
    private function normalizedCitations(array $citations): array
    {
        return collect($citations)
            ->map(function (mixed $citation): ?array {
                if (! is_array($citation)) {
                    return null;
                }

                $title = trim((string) ($citation['title'] ?? ''));
                $sourceUrl = trim((string) ($citation['source_url'] ?? ''));

                if ($title === '' || $sourceUrl === '') {
                    return null;
                }

                return [
                    'title' => $title,
                    'source_url' => $sourceUrl,
                    'type' => trim((string) ($citation['type'] ?? 'html')) ?: 'html',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function resolveCity(): ?City
    {
        if ($this->cityId) {
            return City::query()->find($this->cityId);
        }

        $user = auth()->user();

        if ($user instanceof User && ! $user->isSuperAdmin()) {
            $authorizedCity = $user->cities()
                ->orderBy('name')
                ->first();

            if ($authorizedCity) {
                return $authorizedCity;
            }
        }

        return City::query()->orderBy('name')->first();
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
        return $this->applyRecentFeedFilters($this->applyArticleFilters($this->articleQuery($city)))
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

    private function applyRecentFeedFilters(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder->whereDoesntHave('analysis')
                ->orWhereHas('analysis', function (Builder $analysisQuery): void {
                    $analysisQuery->whereNull('coverage_scope')
                        ->orWhere('coverage_scope', '!=', 'national')
                        ->orWhereNull('local_relevance_score')
                        ->orWhere('local_relevance_score', '>=', self::NATIONAL_STORY_LOCAL_RELEVANCE_MIN);
                });
        });
    }

    private function countArticlesAddedToday(Builder $baseQuery, string $timezone): int
    {
        $windowStartUtc = Carbon::now($timezone)->startOfDay()->setTimezone('UTC');
        $windowEndUtc = Carbon::now($timezone)->endOfDay()->setTimezone('UTC');

        return (clone $baseQuery)
            ->whereBetween('created_at', [$windowStartUtc, $windowEndUtc])
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
                'label' => __("What's new this week?"),
                'prompt' => __('Summarize the most important local updates in :city from the last 7 days. Focus on decisions, projects, and deadlines, and include citations.', ['city' => $cityName]),
            ],
            [
                'label' => __('Upcoming meetings'),
                'prompt' => __('What city council, board, and public meetings are coming up in :city in the next 14 days? Include dates, times, and where to find the agenda.', ['city' => $cityName]),
            ],
            [
                'label' => __('How do I...?'),
                'prompt' => __('How do I apply for a building permit in :city? What documents do I need and where do I submit the application?', ['city' => $cityName]),
            ],
            [
                'label' => __('Service alerts'),
                'prompt' => __('What active service alerts or disruptions should residents in :city know about right now? Focus on roads, utilities, water, trash, and public services.', ['city' => $cityName]),
            ],
        ];
    }

    private function storedConversationId(?City $city): ?string
    {
        if (! auth()->check() || ! $city || ! (bool) config('chat.memory_enabled', true)) {
            return null;
        }

        $conversationId = session($this->conversationSessionKey($city));

        return is_string($conversationId) && trim($conversationId) !== ''
            ? $conversationId
            : null;
    }

    private function storeConversationId(?string $conversationId, ?City $city): void
    {
        if (! auth()->check() || ! $city || ! (bool) config('chat.memory_enabled', true)) {
            return;
        }

        $sessionKey = $this->conversationSessionKey($city);

        if ($conversationId === null || trim($conversationId) === '') {
            session()->forget($sessionKey);

            return;
        }

        session()->put($sessionKey, $conversationId);
    }

    private function conversationSessionKey(City $city): string
    {
        return (string) config('chat.memory_session_key', 'chat.conversation_id').'.city.'.$city->getKey();
    }

    private function canUseChat(?City $city): bool
    {
        $user = auth()->user();

        return $city !== null
            && $user instanceof User
            && $user->hasVerifiedEmail()
            && $user->canAccessCity($city);
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

    /**
     * @return Collection<int, Event>
     */
    private function upcomingEvents(?City $city, string $timezone): Collection
    {
        if (! $city) {
            return collect();
        }

        $windowStartUtc = Carbon::now($timezone)->startOfDay()->setTimezone('UTC');
        $windowEndUtc = Carbon::now($timezone)->addDays(7)->endOfDay()->setTimezone('UTC');

        return Event::query()
            ->where('city_id', $city->id)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', $windowStartUtc)
            ->where('starts_at', '<=', $windowEndUtc)
            ->orderBy('starts_at')
            ->limit(5)
            ->get();
    }
}
