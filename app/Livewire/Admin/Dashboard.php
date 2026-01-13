<?php

namespace App\Livewire\Admin;

use App\Models\Article;
use App\Models\City;
use App\Models\EventIngestionRun;
use App\Models\Organization;
use App\Models\Scraper;
use App\Models\ScraperRun;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class Dashboard extends Component
{
    public int $totalCities = 0;

    public int $totalOrganizations = 0;

    public int $totalScrapers = 0;

    public int $activeScrapers = 0;

    public int $articlesLast24h = 0;

    public int $articlesLast7d = 0;

    public int $eventsLast24h = 0;

    public int $eventsLast7d = 0;

    public Collection $recentRuns;

    public Collection $recentEventRuns;

    public bool $hasArticlesTable = false;

    public bool $hasEventRunsTable = false;

    public function mount(): void
    {
        $this->totalCities = City::count();
        $this->totalOrganizations = Organization::count();
        $this->totalScrapers = Scraper::count();
        $this->activeScrapers = Scraper::where('is_enabled', true)->count();
        $this->recentRuns = ScraperRun::with(['scraper.city', 'scraper.organization'])
            ->orderByDesc('finished_at')
            ->orderByDesc('started_at')
            ->limit(5)
            ->get();
        $this->recentEventRuns = collect();

        if (Schema::hasTable('articles')) {
            $this->hasArticlesTable = true;
            $this->articlesLast24h = Article::where('created_at', '>=', now()->subDay())->count();
            $this->articlesLast7d = Article::where('created_at', '>=', now()->subDays(7))->count();
        }

        if (Schema::hasTable('event_ingestion_runs')) {
            $this->hasEventRunsTable = true;
            $this->eventsLast24h = (int) EventIngestionRun::where('finished_at', '>=', now()->subDay())
                ->sum('items_written');
            $this->eventsLast7d = (int) EventIngestionRun::where('finished_at', '>=', now()->subDays(7))
                ->sum('items_written');
            $this->recentEventRuns = EventIngestionRun::with(['eventSource.city'])
                ->orderByDesc('finished_at')
                ->orderByDesc('started_at')
                ->limit(5)
                ->get();
        }
    }

    public function render(): View
    {
        return view('livewire.admin.dashboard')
            ->layout('layouts.admin', [
                'title' => __('Dashboard'),
            ]);
    }
}
