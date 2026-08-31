<div class="admin-page">
    <div class="admin-page-header">
        <div>
            <div class="admin-kicker">{{ __('Source onboarding') }}</div>
            <flux:heading size="xl" level="1" class="mt-2 font-serif !text-4xl !font-medium tracking-[-0.03em] text-[#123e32]">
                {{ __('Add a source') }}
            </flux:heading>
            <flux:subheading class="mt-2 max-w-2xl">
                {{ __('Paste a page, feed, or calendar URL. LocAlmanac will find the best endpoint, determine what it contains, and test it before anything is saved.') }}
            </flux:subheading>
        </div>

        <flux:button variant="ghost" :href="route('admin.dashboard')" wire:navigate>{{ __('Cancel') }}</flux:button>
    </div>

    <ol class="grid max-w-3xl grid-cols-3 gap-2" aria-label="{{ __('Source setup progress') }}">
        @foreach ([1 => __('Give us a URL'), 2 => __('Review and test'), 3 => __('Ready to ingest')] as $number => $label)
            <li class="border-t-2 pt-3 {{ $step >= $number ? 'border-[#1f654f] text-[#18342c]' : 'border-[#d9d7ce] text-[#7a8882]' }}">
                <div class="text-[0.68rem] font-semibold uppercase tracking-[0.16em]">{{ __('Step :number', ['number' => $number]) }}</div>
                <div class="mt-1 text-sm font-medium">{{ $label }}</div>
            </li>
        @endforeach
    </ol>

    @if ($step === 1)
        <form wire:submit.prevent="analyze" class="admin-panel max-w-3xl space-y-6 p-6 sm:p-8">
            <div>
                <flux:heading size="lg">{{ __('What should LocAlmanac follow?') }}</flux:heading>
                <flux:text variant="subtle" class="mt-2">{{ __('Use the public page you would give a person. We will look for feeds, APIs, calendars, and repeated content automatically.') }}</flux:text>
            </div>

            <flux:input
                wire:model="sourceUrl"
                :label="__('Source URL')"
                type="url"
                placeholder="https://example.gov/news-or-events"
                required
                autofocus
            />

            <div class="grid gap-4 md:grid-cols-2">
                <flux:select wire:model="cityId" :label="__('City')" required>
                    <option value="">{{ __('Select a city') }}</option>
                    @foreach ($cities as $city)
                        <option value="{{ $city->id }}">{{ $city->name }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="organizationId" :label="__('Organization (optional)')">
                    <option value="">{{ __('Detect from source') }}</option>
                    @foreach ($organizations as $organization)
                        <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                    @endforeach
                </flux:select>
            </div>

            @if ($discoveryError)
                <flux:callout variant="danger" icon="x-circle" :heading="__('We could not analyze that URL')">
                    {{ $discoveryError }}
                </flux:callout>
            @endif

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" icon="sparkles" wire:loading.attr="disabled" wire:target="analyze">
                    <span wire:loading.remove wire:target="analyze">{{ __('Analyze source') }}</span>
                    <span wire:loading wire:target="analyze">{{ __('Finding the best endpoint…') }}</span>
                </flux:button>
            </div>
        </form>
    @else
        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
            <div class="space-y-5">
                <section class="admin-panel p-6 sm:p-8" aria-labelledby="detected-source">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex items-start gap-4">
                            <div class="grid size-11 shrink-0 place-items-center rounded-xl bg-[#e7f0eb] text-[#1f654f]">
                                <flux:icon :icon="$discoveredKind === 'event' ? 'calendar-days' : 'newspaper'" class="size-5" />
                            </div>
                            <div>
                                <div class="admin-kicker">{{ __('Detected source') }}</div>
                                <flux:heading id="detected-source" size="lg" class="mt-1">
                                    {{ $discoveredKind === 'event' ? __('Events and calendar listings') : __('News and civic articles') }}
                                </flux:heading>
                                <flux:text variant="subtle" class="mt-2 break-all">{{ $discoveredUrl }}</flux:text>
                            </div>
                        </div>

                        <flux:badge :color="$previewValid ? 'green' : 'amber'" variant="subtle" :icon="$previewValid ? 'check-circle' : 'exclamation-triangle'">
                            {{ $previewValid ? __('Verified') : __('Needs attention') }}
                        </flux:badge>
                    </div>

                    @if ($reasons !== [])
                        <ul class="mt-6 space-y-2 border-t border-[#e1dfd7] pt-5 text-sm leading-6 text-[#4d6259]">
                            @foreach ($reasons as $reason)
                                <li class="flex gap-2"><flux:icon icon="check" class="mt-1 size-4 shrink-0 text-[#1f654f]" /> <span>{{ $reason }}</span></li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($warnings !== [])
                        <flux:callout class="mt-5" variant="warning" icon="information-circle" :heading="__('Discovery notes')">
                            <div class="space-y-1 text-sm">
                                @foreach ($warnings as $warning)<div>{{ $warning }}</div>@endforeach
                            </div>
                        </flux:callout>
                    @endif
                </section>

                <section class="admin-panel overflow-hidden" aria-labelledby="preview-heading">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#e1dfd7] px-6 py-5">
                        <div>
                            <div class="admin-kicker">{{ __('Live preview') }}</div>
                            <flux:heading id="preview-heading" size="lg" class="mt-1">
                                {{ $discoveredKind === 'event' ? __('Upcoming events found') : __('Recent articles found') }}
                            </flux:heading>
                        </div>
                        <flux:button type="button" variant="subtle" icon="arrow-path" wire:click="preview" wire:loading.attr="disabled" wire:target="preview">
                            <span wire:loading.remove wire:target="preview">{{ __('Test again') }}</span>
                            <span wire:loading wire:target="preview">{{ __('Testing…') }}</span>
                        </flux:button>
                    </div>

                    @if ($previewError)
                        <div class="p-6">
                            <flux:callout variant="danger" icon="x-circle" :heading="__('Preview failed')">{{ $previewError }}</flux:callout>
                        </div>
                    @elseif ($previewItems !== [])
                        <div class="divide-y divide-[#e8e5dc]">
                            @foreach ($previewItems as $item)
                                <article class="grid gap-2 px-6 py-4 sm:grid-cols-[minmax(0,1fr)_190px] sm:items-start">
                                    <div class="min-w-0">
                                        <div class="font-medium text-[#18342c]">{{ $item['title'] ?? __('Untitled item') }}</div>
                                        @if (! empty($item['location']))
                                            <div class="mt-1 text-sm text-[#667970]">{{ $item['location'] }}</div>
                                        @elseif (! empty($item['summary']))
                                            <div class="mt-1 line-clamp-2 text-sm text-[#667970]">{{ $item['summary'] }}</div>
                                        @endif
                                    </div>
                                    <div class="text-sm text-[#667970] sm:text-right">
                                        {{ $discoveredKind === 'event' ? ($item['starts_at'] ?? '—') : ($item['published_at'] ?? '—') }}
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @else
                        <flux:text variant="subtle" class="block p-6">{{ __('No preview items are available yet.') }}</flux:text>
                    @endif

                    @if ($previewWarnings !== [])
                        <div class="border-t border-[#e1dfd7] px-6 py-4 text-sm text-amber-800">
                            {{ implode(' ', $previewWarnings) }}
                        </div>
                    @endif
                </section>

                <details class="admin-panel group p-6">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold text-[#18342c]">
                        <span>{{ __('Advanced') }}</span>
                        <flux:icon icon="chevron-down" class="size-4 transition group-open:rotate-180" />
                    </summary>
                    <div class="mt-6 space-y-5 border-t border-[#e1dfd7] pt-6">
                        <div class="grid gap-4 md:grid-cols-2">
                            <flux:select wire:model.live="discoveredKind" :label="__('Content destination')">
                                <option value="article">{{ __('Articles') }}</option>
                                <option value="event">{{ __('Events') }}</option>
                            </flux:select>
                            <flux:select wire:model.live="discoveredType" :label="__('Source type')">
                                @foreach ($discoveredKind === 'event' ? ['ics', 'rss', 'json_api', 'html'] : ['rss', 'html'] as $type)
                                    <option value="{{ $type }}">{{ strtoupper(str_replace('_', ' ', $type)) }}</option>
                                @endforeach
                            </flux:select>
                        </div>
                        <flux:input wire:model.live="discoveredUrl" :label="__('Detected endpoint')" type="url" />
                        <flux:textarea wire:model.live="rawConfig" :label="__('Extraction config (JSON)')" rows="14" class="font-mono text-xs" />
                        <flux:text variant="subtle">{{ __('Changing advanced settings requires another successful test.') }}</flux:text>
                    </div>
                </details>
            </div>

            <aside class="admin-panel h-fit space-y-5 p-6 xl:sticky xl:top-24">
                <div>
                    <div class="admin-kicker">{{ __('Source details') }}</div>
                    <flux:heading size="lg" class="mt-1">{{ __('Ready to add') }}</flux:heading>
                </div>

                <flux:input wire:model="name" :label="__('Name')" required />
                <flux:select wire:model="frequency" :label="__('Check for updates')">
                    <option value="hourly">{{ __('Hourly') }}</option>
                    <option value="daily">{{ __('Daily') }}</option>
                    <option value="weekly">{{ __('Weekly') }}</option>
                </flux:select>
                <div class="flex items-center gap-3">
                    <flux:switch wire:model="isActive" :label="__('Active immediately')" />
                </div>

                <div class="border-t border-[#e1dfd7] pt-5">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-[#667970]">{{ __('Detection confidence') }}</span>
                        <span class="font-semibold text-[#18342c]">{{ $confidence !== null ? number_format($confidence * 100). '%' : '—' }}</span>
                    </div>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-[#e7e5de]">
                        <div class="h-full rounded-full bg-[#1f654f]" style="width: {{ $confidence !== null ? max(4, $confidence * 100) : 0 }}%"></div>
                    </div>
                </div>

                <div class="grid gap-2">
                    <flux:button type="button" variant="primary" wire:click="save" wire:loading.attr="disabled" :disabled="! $previewValid">
                        <span wire:loading.remove wire:target="save">{{ __('Add source') }}</span>
                        <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                    </flux:button>
                    <flux:button type="button" variant="ghost" wire:click="startOver">{{ __('Start over') }}</flux:button>
                </div>
            </aside>
        </div>
    @endif
</div>
