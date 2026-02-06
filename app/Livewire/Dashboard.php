<?php

namespace App\Livewire;

use App\Models\Article;
use App\Models\City;
use App\Models\IssueArea;
use App\Services\Chat\AskService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Throwable;

class Dashboard extends Component
{
    public string $question = '';

    /**
     * @var array<int, array{role: string, content: string, citations?: array<int, array{title: string, source_url: string, type: string}>}>
     */
    public array $messages = [];

    public string $articleSearch = '';

    public ?int $activeIssueAreaId = null;

    public ?int $cityId = null;

    public function mount(): void
    {
        $this->cityId = request()->integer('city_id') ?: null;
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
            $response = app(AskService::class)->answer(
                question: $question,
                cityId: $city?->id,
                citySlug: null,
            );

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

    public function applyPrompt(string $prompt): void
    {
        $this->question = $prompt;
    }

    public function selectIssueArea(int $issueAreaId): void
    {
        $this->activeIssueAreaId = $issueAreaId;
    }

    public function clearIssueArea(): void
    {
        $this->activeIssueAreaId = null;
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
    }

    public function render(): View
    {
        $city = $this->resolveCity();
        $timezone = $this->resolveTimezone($city);

        $issueAreas = $this->issueAreaQuery($city)
            ->orderBy('name')
            ->get();

        $promptChips = $issueAreas
            ->take(4)
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        if ($promptChips === []) {
            $promptChips = [
                __('Building permits'),
                __('Council meetings'),
                __('Local events'),
                __('Construction updates'),
            ];
        }

        $articleBase = $this->articleQuery($city);

        $totalArticles = (clone $articleBase)->count();
        $articlesAddedToday = $this->countArticlesForDate($articleBase, $timezone);

        $articles = $this->applyArticleFilters($this->articleQuery($city))
            ->with([
                'scraper.organization',
                'sources',
                'articleIssueAreas.issueArea',
            ])
            ->orderByDesc(DB::raw('COALESCE(published_at, created_at)'))
            ->limit(10)
            ->get();

        return view('livewire.dashboard', [
            'city' => $city,
            'timezone' => $timezone,
            'issueAreas' => $issueAreas,
            'promptChips' => $promptChips,
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

    private function applyArticleFilters(Builder $query): Builder
    {
        if ($this->articleSearch !== '') {
            $search = '%'.addcslashes($this->articleSearch, '%_').'%';

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
}
