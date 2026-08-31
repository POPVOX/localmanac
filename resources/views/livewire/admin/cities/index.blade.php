<div class="admin-page">
    <div class="admin-page-header">
        <div>
            <div class="admin-kicker">{{ __('Location workspaces') }}</div>
            <flux:heading size="xl" level="1" class="mt-2 font-serif !text-4xl !font-medium tracking-[-0.03em] text-[#123e32]">{{ __('Locations') }}</flux:heading>
            <flux:subheading class="mt-2 max-w-2xl">{{ __('Open a city dashboard, preview the public experience, or manage its local settings and organizations.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button variant="ghost" :href="route('admin.organizations.index')" wire:navigate>{{ __('Organizations') }}</flux:button>
            <flux:button variant="primary" :href="route('admin.cities.create')" icon="plus" wire:navigate>{{ __('Add location') }}</flux:button>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
        @forelse ($cities as $city)
            @php
                $sourceCount = $city->article_sources_count + $city->event_sources_count + $city->chat_sources_count;
                $activeSourceCount = $city->active_article_sources_count + $city->active_event_sources_count + $city->active_chat_sources_count;
                $healthIssues = $city->unhealthy_article_sources_count + $city->unhealthy_event_sources_count;
            @endphp
            <article class="admin-location-card flex flex-col" wire:key="city-{{ $city->id }}">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading size="lg">{{ $city->name }}</flux:heading>
                            @if ($city->state)<span class="text-xs font-semibold uppercase tracking-wide text-[#87968f]">{{ $city->state }}</span>@endif
                        </div>
                        <div class="mt-1 text-xs text-[#718078]">/{{ $city->slug }} · {{ $city->timezone }}</div>
                    </div>
                    <flux:badge :color="$healthIssues > 0 ? 'amber' : 'green'" variant="subtle" size="sm">
                        {{ $healthIssues > 0 ? __('Needs attention') : __('Healthy') }}
                    </flux:badge>
                </div>

                <dl class="mt-5 grid grid-cols-3 divide-x divide-[#e1dfd7] border-y border-[#e1dfd7] py-3">
                    <div class="pr-3"><dt class="text-[0.68rem] font-semibold uppercase tracking-wide text-[#87968f]">{{ __('Sources') }}</dt><dd class="mt-1 text-xl font-semibold text-[#18342c]">{{ $activeSourceCount }}<span class="text-xs font-normal text-[#87968f]"> / {{ $sourceCount }}</span></dd></div>
                    <div class="px-3"><dt class="text-[0.68rem] font-semibold uppercase tracking-wide text-[#87968f]">{{ __('Articles · 7d') }}</dt><dd class="mt-1 text-xl font-semibold text-[#18342c]">{{ $city->recent_articles_count }}</dd></div>
                    <div class="pl-3"><dt class="text-[0.68rem] font-semibold uppercase tracking-wide text-[#87968f]">{{ __('Events · 30d') }}</dt><dd class="mt-1 text-xl font-semibold text-[#18342c]">{{ $city->upcoming_events_count }}</dd></div>
                </dl>

                <div class="mt-4 flex items-center justify-between gap-3 text-xs text-[#718078]">
                    <span>{{ trans_choice(':count organization|:count organizations', $city->organizations_count, ['count' => $city->organizations_count]) }}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full {{ $city->hasChatAccessCode() ? 'bg-emerald-500' : 'bg-zinc-300' }}"></span>{{ $city->hasChatAccessCode() ? __('Chat enabled') : __('No chat code') }}</span>
                </div>

                <div class="mt-5 flex flex-wrap items-center justify-between gap-2 border-t border-[#e1dfd7] pt-4">
                    <flux:button size="sm" variant="subtle" :href="route('admin.dashboard', ['cityId' => $city->id])" wire:navigate>{{ __('Open dashboard') }}</flux:button>
                    <div class="flex gap-1">
                        <flux:button size="sm" variant="ghost" :href="route('admin.cities.preview', $city)" icon="arrow-top-right-on-square" target="_blank" rel="noopener noreferrer">{{ __('Preview') }}</flux:button>
                        <flux:button size="sm" variant="ghost" :href="route('admin.cities.edit', $city)" wire:navigate>{{ __('Settings') }}</flux:button>
                    </div>
                </div>
            </article>
        @empty
            <div class="admin-panel col-span-full p-10 text-center">
                <flux:heading size="lg">{{ __('No locations yet') }}</flux:heading>
                <flux:text variant="subtle" class="mt-2">{{ __('Add the first location to begin building its local feed.') }}</flux:text>
            </div>
        @endforelse
    </div>

    <div>{{ $cities->links() }}</div>
</div>
