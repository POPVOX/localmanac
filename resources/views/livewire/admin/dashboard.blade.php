<div class="space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Admin Dashboard') }}</flux:heading>
        <flux:subheading>{{ __('Overview of coverage, organizations, and ingestion health.') }}</flux:subheading>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['title' => __('Cities'), 'value' => $totalCities, 'trend' => null, 'trendUp' => true],
            ['title' => __('Organizations'), 'value' => $totalOrganizations, 'trend' => null, 'trendUp' => true],
            ['title' => __('Scrapers (active)'), 'value' => "{$activeScrapers} / {$totalScrapers}", 'trend' => null, 'trendUp' => true],
            ['title' => __('Articles (24h)'), 'value' => $hasArticlesTable ? $articlesLast24h : '—', 'trend' => null, 'trendUp' => true],
        ] as $stat)
            <div class="relative flex-1 rounded-lg px-6 py-4 bg-zinc-50 dark:bg-zinc-700 {{ $loop->iteration > 1 ? 'max-md:hidden' : '' }} {{ $loop->iteration > 3 ? 'max-lg:hidden' : '' }}">
                <flux:subheading>{{ $stat['title'] }}</flux:subheading>
                <flux:heading size="xl" class="mb-2">{{ $stat['value'] }}</flux:heading>
                @if ($stat['trend'] !== null)
                    <div class="flex items-center gap-1 font-medium text-sm @if ($stat['trendUp']) text-green-600 dark:text-green-400 @else text-red-500 dark:text-red-400 @endif">
                        <flux:icon :icon="$stat['trendUp'] ? 'arrow-trending-up' : 'arrow-trending-down'" variant="micro" /> {{ $stat['trend'] }}
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <flux:card padding="xl" variant="subtle" class="space-y-4">
            <flux:heading size="lg">{{ __('Article ingestion') }}</flux:heading>
            @if ($hasArticlesTable)
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="relative flex-1 rounded-lg px-6 py-4 bg-zinc-50 dark:bg-zinc-700">
                        <flux:subheading>{{ __('Last 24 hours') }}</flux:subheading>
                        <flux:heading size="xl" class="mb-2">{{ $articlesLast24h }}</flux:heading>
                    </div>
                    <div class="relative flex-1 rounded-lg px-6 py-4 bg-zinc-50 dark:bg-zinc-700">
                        <flux:subheading>{{ __('Last 7 days') }}</flux:subheading>
                        <flux:heading size="xl" class="mb-2">{{ $articlesLast7d }}</flux:heading>
                    </div>
                </div>
            @else
                <flux:text variant="subtle">{{ __('Articles table not available yet. Run migrations to track ingest stats.') }}</flux:text>
            @endif
        </flux:card>

        <flux:card padding="xl" variant="subtle" class="space-y-4">
            <flux:heading size="lg">{{ __('Scraper summary') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="relative flex-1 rounded-lg px-6 py-4 bg-zinc-50 dark:bg-zinc-700">
                    <flux:subheading>{{ __('Active scrapers') }}</flux:subheading>
                    <flux:heading size="xl" class="mb-2">{{ $activeScrapers }}</flux:heading>
                </div>
                <div class="relative flex-1 rounded-lg px-6 py-4 bg-zinc-50 dark:bg-zinc-700">
                    <flux:subheading>{{ __('Total scrapers') }}</flux:subheading>
                    <flux:heading size="xl" class="mb-2">{{ $totalScrapers }}</flux:heading>
                </div>
            </div>
            <flux:text>
                <flux:link :href="route('admin.scrapers.index')" wire:navigate>
                {{ __('Go to scrapers') }}
                </flux:link>
            </flux:text>
        </flux:card>
    </div>

    <flux:card padding="xl" variant="subtle" class="space-y-4">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">{{ __('Recent scraper activity') }}</flux:heading>
            <flux:text>
                <flux:link :href="route('admin.scrapers.index')" wire:navigate>
                    {{ __('View all') }}
                </flux:link>
            </flux:text>
        </div>

        <flux:table>
            <flux:table.columns sticky>
                <flux:table.column sticky>{{ __('Scraper') }}</flux:table.column>
                <flux:table.column>{{ __('City') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Finished at') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($recentRuns as $run)
                    <flux:table.row :key="$run->id">
                        <flux:table.cell variant="strong" sticky>
                            <flux:link :href="route('admin.scrapers.show', $run->scraper)" wire:navigate>
                                {{ $run->scraper->name }}
                            </flux:link>
                        </flux:table.cell>
                        <flux:table.cell>{{ $run->scraper->city?->name }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$run->status === 'success' ? 'green' : ($run->status === 'failed' ? 'red' : 'yellow')">
                                {{ ucfirst($run->status) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ ($run->finished_at ?? $run->started_at)?->toDayDateTimeString() ?? __('Pending') }}
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">
                            <flux:text variant="subtle">{{ __('No scraper activity recorded yet.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
