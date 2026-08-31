<?php

namespace App\Services\Admin;

use App\Models\City;
use Illuminate\Database\Eloquent\Builder;

class CityOverviewQuery
{
    /** @return Builder<City> */
    public function build(): Builder
    {
        return City::query()
            ->withCount([
                'organizations',
                'scrapers as article_sources_count',
                'scrapers as active_article_sources_count' => fn (Builder $query) => $query->where('is_enabled', true),
                'scrapers as unhealthy_article_sources_count' => fn (Builder $query) => $query->where('health_status', 'unhealthy'),
                'eventSources as event_sources_count',
                'eventSources as active_event_sources_count' => fn (Builder $query) => $query->where('is_active', true),
                'eventSources as unhealthy_event_sources_count' => fn (Builder $query) => $query->where('health_status', 'unhealthy'),
                'chatSources as chat_sources_count',
                'chatSources as active_chat_sources_count' => fn (Builder $query) => $query->where('is_active', true),
                'articles as recent_articles_count' => fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(7)),
                'events as upcoming_events_count' => fn (Builder $query) => $query
                    ->whereNotNull('starts_at')
                    ->whereBetween('starts_at', [now(), now()->addDays(30)]),
            ])
            ->orderBy('name');
    }
}
