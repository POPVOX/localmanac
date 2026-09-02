<div class="admin-page">
    <div class="admin-page-header">
        <div>
            <div class="admin-kicker">{{ $selectedCityName ? __('Location dashboard') : __('Network analytics') }}</div>
            <flux:heading size="xl" level="1" class="mt-2 font-serif !text-4xl !font-medium tracking-[-0.03em] text-[#123e32]">
                {{ $selectedCityName ? __(':city overview', ['city' => $selectedCityName]) : __('All locations') }}
            </flux:heading>
            <flux:subheading class="mt-2 max-w-2xl">
                {{ $selectedCityName
                    ? __('Audience growth, publishing, source health, and recent activity for this location.')
                    : __('Compare coverage and operations across the LocAlmanac network, then focus on any city.') }}
            </flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($selectedCitySlug)
                <flux:button variant="ghost" :href="route('admin.cities.preview', ['city' => $selectedCitySlug])" icon="arrow-top-right-on-square" target="_blank" rel="noopener noreferrer">
                    {{ __('Preview city') }}
                </flux:button>
            @endif
            <flux:button variant="primary" :href="route('admin.sources.create', array_filter(['cityId' => $cityId]))" icon="plus" wire:navigate>{{ __('Add source') }}</flux:button>
        </div>
    </div>

    <section class="admin-scopebar lg:hidden" aria-label="{{ __('Location scope') }}">
        <div>
            <div class="admin-kicker">{{ __('Showing') }}</div>
            <div class="mt-1 text-sm font-semibold text-[#18342c]">{{ $selectedCityName ?: __('All locations combined') }}</div>
        </div>
        <form method="GET" action="{{ route('admin.dashboard') }}" class="w-full sm:w-72">
            <flux:select name="cityId" :label="__('Filter dashboard by location')" onchange="this.form.submit()">
                <option value="">{{ __('All locations') }}</option>
                @foreach ($cities as $city)
                    <option value="{{ $city->id }}" @selected($cityId === $city->id)>{{ $city->name }}{{ $city->state ? ', '.$city->state : '' }}</option>
                @endforeach
            </flux:select>
        </form>
    </section>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('Key metrics') }}">
        @foreach ([
            ['title' => $selectedCityName ? __('Organizations') : __('Locations'), 'value' => $selectedCityName ? $totalOrganizations : $totalCities, 'detail' => $selectedCityName ? __('in this location') : __('active workspaces'), 'icon' => $selectedCityName ? 'building-office-2' : 'map-pin'],
            ['title' => __('Active sources'), 'value' => $activeSources, 'detail' => __('of :total configured', ['total' => $totalSources]), 'icon' => 'signal'],
            ['title' => __('Articles captured'), 'value' => $hasArticlesTable ? $articlesLast7d : '—', 'detail' => __('last 7 days'), 'icon' => 'newspaper'],
            ['title' => __('Upcoming events'), 'value' => $upcomingEvents, 'detail' => __('next 30 days'), 'icon' => 'calendar-days'],
        ] as $stat)
            <div class="admin-stat !py-4">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <div class="admin-kicker">{{ $stat['title'] }}</div>
                        <div class="mt-2 text-3xl font-semibold tracking-[-0.04em] text-[#18342c]">{{ number_format((int) $stat['value']) }}</div>
                        <div class="mt-1 text-xs text-[#718078]">{{ $stat['detail'] }}</div>
                    </div>
                    <div class="grid size-10 place-items-center rounded-xl bg-[#e7f0eb] text-[#1f654f]"><flux:icon :icon="$stat['icon']" class="size-5" /></div>
                </div>
            </div>
        @endforeach
    </section>

    @php
        $unhealthySources = $unhealthyScrapers + $unhealthyEventSources;
    @endphp
    <section class="admin-panel grid divide-y divide-[#e1dfd7] sm:grid-cols-2 sm:divide-x sm:divide-y-0 xl:grid-cols-4" aria-label="{{ __('Operations pulse') }}">
        @foreach ([
            ['label' => __('Source attention'), 'value' => $unhealthySources, 'detail' => $unhealthySources > 0 ? __('needs review') : __('all clear'), 'tone' => $unhealthySources > 0 ? 'text-amber-700' : 'text-[#1f654f]'],
            ['label' => __('Failed runs'), 'value' => $failedRunsLast24h, 'detail' => __('last 24 hours'), 'tone' => $failedRunsLast24h > 0 ? 'text-rose-700' : 'text-[#18342c]'],
            ['label' => __('Articles today'), 'value' => $hasArticlesTable ? $articlesLast24h : 0, 'detail' => __('new records'), 'tone' => 'text-[#18342c]'],
            ['label' => __('Event ingestion'), 'value' => $hasEventRunsTable ? $eventsLast7d : 0, 'detail' => __('last 7 days'), 'tone' => 'text-[#18342c]'],
        ] as $pulse)
            <div class="px-5 py-4">
                <div class="text-xs font-medium text-[#718078]">{{ $pulse['label'] }}</div>
                <div class="mt-1 flex items-baseline gap-2">
                    <span class="text-xl font-semibold {{ $pulse['tone'] }}">{{ number_format($pulse['value']) }}</span>
                    <span class="text-xs text-[#87968f]">{{ $pulse['detail'] }}</span>
                </div>
            </div>
        @endforeach
    </section>

    <section aria-labelledby="location-overview-heading">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <div class="admin-kicker">{{ __('Location overview') }}</div>
                <flux:heading id="location-overview-heading" size="lg" class="mt-1">{{ $selectedCityName ? __('This city at a glance') : __('Compare every city') }}</flux:heading>
            </div>
            <flux:link :href="route('admin.cities.index')" class="font-semibold" wire:navigate>{{ __('Manage locations') }} →</flux:link>
        </div>

        <div class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
            @forelse ($citySnapshots as $city)
                @php
                    $sourceCount = $city->article_sources_count + $city->event_sources_count + $city->chat_sources_count;
                    $activeSourceCount = $city->active_article_sources_count + $city->active_event_sources_count + $city->active_chat_sources_count;
                    $healthIssues = $city->unhealthy_article_sources_count + $city->unhealthy_event_sources_count;
                @endphp
                <article class="admin-location-card">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <flux:heading size="lg">{{ $city->name }}</flux:heading>
                                @if ($city->state)<span class="text-xs font-semibold uppercase tracking-wide text-[#87968f]">{{ $city->state }}</span>@endif
                            </div>
                            <div class="mt-1 text-xs text-[#718078]">{{ trans_choice(':count organization|:count organizations', $city->organizations_count, ['count' => $city->organizations_count]) }}</div>
                        </div>
                        <flux:badge :color="$healthIssues > 0 ? 'amber' : 'green'" variant="subtle" size="sm">
                            {{ $healthIssues > 0 ? trans_choice(':count issue|:count issues', $healthIssues, ['count' => $healthIssues]) : __('Healthy') }}
                        </flux:badge>
                    </div>

                    <dl class="mt-5 grid grid-cols-2 divide-x divide-y divide-[#e1dfd7] border-y border-[#e1dfd7] sm:grid-cols-4 sm:divide-y-0">
                        <div class="px-3 py-3 first:pl-0"><dt class="text-[0.68rem] font-semibold uppercase tracking-wide text-[#87968f]">{{ __('Members') }}</dt><dd class="mt-1 text-xl font-semibold text-[#18342c]">{{ $city->members_count }}<span class="block text-[0.68rem] font-normal text-[#87968f]">{{ __('+:count · 30d', ['count' => $city->new_members_last_30d_count]) }}</span></dd></div>
                        <div class="px-3 py-3"><dt class="text-[0.68rem] font-semibold uppercase tracking-wide text-[#87968f]">{{ __('Sources') }}</dt><dd class="mt-1 text-xl font-semibold text-[#18342c]">{{ $activeSourceCount }}<span class="text-xs font-normal text-[#87968f]"> / {{ $sourceCount }}</span></dd></div>
                        <div class="px-3 py-3"><dt class="text-[0.68rem] font-semibold uppercase tracking-wide text-[#87968f]">{{ __('Articles · 7d') }}</dt><dd class="mt-1 text-xl font-semibold text-[#18342c]">{{ $city->recent_articles_count }}</dd></div>
                        <div class="px-3 py-3 last:pr-0"><dt class="text-[0.68rem] font-semibold uppercase tracking-wide text-[#87968f]">{{ __('Events · 30d') }}</dt><dd class="mt-1 text-xl font-semibold text-[#18342c]">{{ $city->upcoming_events_count }}</dd></div>
                    </dl>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                        <flux:button
                            size="sm"
                            variant="subtle"
                            :href="$selectedCityName ? route('admin.sources.index', ['cityId' => $city->id]) : route('admin.cities.analytics', $city)"
                            wire:navigate
                        >{{ $selectedCityName ? __('Manage sources') : __('Open dashboard') }}</flux:button>
                        <flux:button size="sm" variant="ghost" :href="route('admin.cities.preview', $city)" icon="arrow-top-right-on-square" target="_blank" rel="noopener noreferrer">{{ __('Preview') }}</flux:button>
                    </div>
                </article>
            @empty
                <div class="admin-panel col-span-full p-8 text-center"><flux:text variant="subtle">{{ __('No locations are configured yet.') }}</flux:text></div>
            @endforelse
        </div>
    </section>

    @if ($selectedCityName)
        @php
            $attributionRate = $memberCount > 0
                ? (int) round(($attributedMemberCount / $memberCount) * 100)
                : 0;
        @endphp
        <section id="user-analytics" class="space-y-4 scroll-mt-6" aria-labelledby="user-analytics-heading">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <div class="admin-kicker">{{ __('Audience') }}</div>
                    <flux:heading id="user-analytics-heading" size="lg" class="mt-1">{{ __('User analytics') }}</flux:heading>
                    <flux:text variant="subtle" class="mt-1">{{ __('Understand membership growth and which partners or campaigns are bringing people into :city.', ['city' => $selectedCityName]) }}</flux:text>
                </div>
                <flux:button size="sm" variant="ghost" :href="route('admin.cities.access-codes', ['city' => $selectedCitySlug])" wire:navigate>{{ __('Manage access codes') }}</flux:button>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="admin-stat !py-4">
                    <div class="admin-kicker">{{ __('Members') }}</div>
                    <div class="mt-2 text-3xl font-semibold tracking-[-0.04em] text-[#18342c]">{{ number_format($memberCount) }}</div>
                    <div class="mt-1 text-xs text-[#718078]">{{ __('with access to this city') }}</div>
                </div>
                <div class="admin-stat !py-4">
                    <div class="admin-kicker">{{ __('New members') }}</div>
                    <div class="mt-2 text-3xl font-semibold tracking-[-0.04em] text-[#18342c]">{{ number_format($newMembersLast7d) }}</div>
                    <div class="mt-1 text-xs text-[#718078]">{{ __('last 7 days · :count in 30 days', ['count' => number_format($newMembersLast30d)]) }}</div>
                </div>
                <div class="admin-stat !py-4">
                    <div class="admin-kicker">{{ __('Active codes') }}</div>
                    <div class="mt-2 text-3xl font-semibold tracking-[-0.04em] text-[#18342c]">{{ number_format($activeAccessCodes) }}</div>
                    <div class="mt-1 text-xs text-[#718078]">{{ __('available to partners and campaigns') }}</div>
                </div>
                <div class="admin-stat !py-4">
                    <div class="admin-kicker">{{ __('Attributed') }}</div>
                    <div class="mt-2 text-3xl font-semibold tracking-[-0.04em] text-[#18342c]">{{ $attributionRate }}%</div>
                    <div class="mt-1 text-xs text-[#718078]">{{ trans_choice(':count tracked member|:count tracked members', $attributedMemberCount, ['count' => number_format($attributedMemberCount)]) }} · {{ trans_choice(':count other|:count others', $unattributedMemberCount, ['count' => number_format($unattributedMemberCount)]) }}</div>
                </div>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                <div class="admin-panel overflow-hidden">
                    <div class="border-b border-[#e1dfd7] px-5 py-4">
                        <flux:heading size="sm">{{ __('Campaign performance') }}</flux:heading>
                        <flux:text variant="subtle" class="mt-1">{{ __('Members attributed to each access code.') }}</flux:text>
                    </div>
                    <div class="overflow-x-auto px-3 pb-3">
                        <flux:table>
                            <flux:table.columns sticky>
                                <flux:table.column sticky>{{ __('Campaign or partner') }}</flux:table.column>
                                <flux:table.column align="end">{{ __('30d') }}</flux:table.column>
                                <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @forelse ($topAccessCodes as $code)
                                    <flux:table.row :key="$code->id">
                                        <flux:table.cell variant="strong" sticky>
                                            <div class="flex flex-col gap-1">
                                                <span>{{ $code->label }}</span>
                                                <flux:text size="sm" variant="subtle">{{ $code->last_redeemed_at?->tz($citySnapshots->first()?->timezone ?? config('app.timezone', 'UTC'))->diffForHumans() ?? __('Never used') }}</flux:text>
                                            </div>
                                        </flux:table.cell>
                                        <flux:table.cell align="end">{{ number_format($code->recent_redemptions_count) }}</flux:table.cell>
                                        <flux:table.cell align="end">{{ number_format($code->redemptions_count) }}</flux:table.cell>
                                    </flux:table.row>
                                @empty
                                    <flux:table.row><flux:table.cell colspan="3"><flux:text variant="subtle">{{ __('No access codes have been created for this city yet.') }}</flux:text></flux:table.cell></flux:table.row>
                                @endforelse
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </div>

                <div class="admin-panel overflow-hidden">
                    <div class="border-b border-[#e1dfd7] px-5 py-4">
                        <flux:heading size="sm">{{ __('Recent member grants') }}</flux:heading>
                        <flux:text variant="subtle" class="mt-1">{{ __('The latest people who unlocked this city with a tracked code.') }}</flux:text>
                    </div>
                    <div class="divide-y divide-[#e1dfd7]">
                        @forelse ($recentMemberGrants as $grant)
                            <div class="flex items-center justify-between gap-4 px-5 py-3" wire:key="member-grant-{{ $grant->id }}">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-[#18342c]">{{ $grant->user?->name ?? __('Deleted user') }}</div>
                                    <div class="truncate text-xs text-[#718078]">{{ $grant->user?->email ?? __('Account removed') }}</div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div class="text-xs font-medium text-[#4f675d]">{{ $grant->accessCode?->label ?? __('Deleted code') }}</div>
                                    <div class="mt-1 text-xs text-[#87968f]">{{ $grant->redeemed_at?->tz($citySnapshots->first()?->timezone ?? config('app.timezone', 'UTC'))->diffForHumans() ?? '—' }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="px-5 py-8 text-center"><flux:text variant="subtle">{{ __('No tracked access grants have been recorded yet.') }}</flux:text></div>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>

        <section class="admin-panel flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between" aria-label="{{ __('Location shortcuts') }}">
            <div>
                <div class="admin-kicker">{{ __('Manage :city', ['city' => $selectedCityName]) }}</div>
                <div class="mt-1 text-sm text-[#667970]">{{ __('Jump directly to this location’s records without resetting the filter.') }}</div>
            </div>
            <div class="flex flex-wrap gap-2">
                <flux:button size="sm" variant="ghost" href="#user-analytics">{{ __('User analytics') }}</flux:button>
                <flux:button size="sm" variant="ghost" :href="route('admin.organizations.index', ['cityId' => $cityId])" wire:navigate>{{ __('Organizations') }}</flux:button>
                <flux:button size="sm" variant="ghost" :href="route('admin.sources.index', ['cityId' => $cityId])" wire:navigate>{{ __('Sources') }}</flux:button>
                <flux:button size="sm" variant="ghost" :href="route('admin.events.index', ['cityId' => $cityId])" wire:navigate>{{ __('Events') }}</flux:button>
            </div>
        </section>
    @endif

    <section class="admin-panel overflow-hidden" aria-labelledby="recent-activity-heading">
        <div class="flex items-center justify-between gap-4 border-b border-[#e1dfd7] px-5 py-4 sm:px-6">
            <div>
                <div class="admin-kicker">{{ __('Recent activity') }}</div>
                <flux:heading id="recent-activity-heading" size="lg" class="mt-1">{{ __('Latest source runs') }}</flux:heading>
            </div>
            <flux:link :href="route('admin.sources.index', array_filter(['cityId' => $cityId]))" class="font-semibold" wire:navigate>{{ __('View sources') }} →</flux:link>
        </div>

        <div class="overflow-x-auto px-3 pb-3 sm:px-5">
            <flux:table>
                <flux:table.columns sticky>
                    <flux:table.column sticky>{{ __('Source') }}</flux:table.column>
                    <flux:table.column>{{ __('Purpose') }}</flux:table.column>
                    <flux:table.column>{{ __('Location · preview') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Changed') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('When') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($recentActivity as $activity)
                        <flux:table.row :key="$activity['key']">
                            <flux:table.cell variant="strong" sticky>
                                @if ($activity['source_url'])
                                    <flux:link :href="$activity['source_url']" wire:navigate>{{ $activity['source'] }}</flux:link>
                                @else
                                    {{ $activity['source'] }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="capitalize">{{ $activity['kind'] === 'chat' ? __('answers') : __($activity['kind']) }}</flux:table.cell>
                            <flux:table.cell><x-admin.city-preview-link :city="$activity['city']" compact /></flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$activity['status'] === 'success' ? 'green' : ($activity['status'] === 'failed' ? 'red' : 'amber')" variant="subtle">{{ ucfirst($activity['status']) }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($activity['items']) }}</flux:table.cell>
                            <flux:table.cell align="end"><span title="{{ $activity['at']?->toDateTimeString() }}">{{ $activity['at']?->diffForHumans() ?? __('Pending') }}</span></flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="6"><flux:text variant="subtle">{{ __('No source activity has been recorded for this view yet.') }}</flux:text></flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </section>
</div>
