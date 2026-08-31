<div class="admin-page">
    <div class="admin-page-header">
        <div>
            <div class="admin-kicker">{{ __('Calendar ingestion') }}</div>
            <flux:heading size="xl" level="1" class="mt-2 font-serif !text-4xl !font-medium tracking-[-0.03em] text-[#123e32]">{{ $title }}</flux:heading>
            <flux:subheading class="mt-2">{{ __('Configure source details, extraction, and scheduling.') }}</flux:subheading>
        </div>

        <flux:button variant="ghost" :href="route('admin.event-sources.index')" wire:navigate>
            {{ __('Back to event sources') }}
        </flux:button>
    </div>

    <flux:card padding="xl" class="admin-panel space-y-6">
        <form wire:submit.prevent="save" class="space-y-6">
            <div class="grid gap-4 md:grid-cols-2">
                <flux:select wire:model.live="cityId" :label="__('City')" required>
                    <option value="">{{ __('Select a city') }}</option>
                    @foreach ($cities as $city)
                        <option value="{{ $city->id }}">{{ $city->name }}</option>
                    @endforeach
                </flux:select>

                <flux:input
                    wire:model.live="name"
                    :label="__('Name')"
                    type="text"
                    required
                    autofocus
                />
            </div>

            <flux:input
                wire:model.live="sourceUrl"
                :label="__('Source URL')"
                type="url"
                required
            />

            <div class="flex items-center gap-3">
                <flux:switch wire:model.live="isActive" :label="__('Active')" />
                <flux:text variant="subtle">{{ __('Inactive sources will be skipped until re-enabled.') }}</flux:text>
            </div>

            <section class="rounded-xl border border-[#d9d7ce] bg-[#f8f7f2] p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <flux:heading size="sm">{{ __('Test event extraction') }}</flux:heading>
                        <flux:text variant="subtle">{{ __('Run the source without saving to confirm that dated events can be read.') }}</flux:text>
                    </div>
                    <flux:button type="button" variant="subtle" icon="play" wire:click="previewSource" wire:loading.attr="disabled" wire:target="previewSource">
                        <span wire:loading.remove wire:target="previewSource">{{ __('Preview events') }}</span>
                        <span wire:loading wire:target="previewSource">{{ __('Testing…') }}</span>
                    </flux:button>
                </div>

                @if ($previewError)
                    <flux:callout class="mt-4" variant="danger" icon="x-circle" :heading="__('Preview failed')">{{ $previewError }}</flux:callout>
                @elseif ($previewItems !== [])
                    <div class="mt-5 divide-y divide-[#e1dfd7] border-y border-[#e1dfd7]">
                        @foreach ($previewItems as $item)
                            <div class="grid gap-1 py-3 sm:grid-cols-[minmax(0,1fr)_210px]">
                                <div>
                                    <div class="font-medium text-[#18342c]">{{ $item['title'] }}</div>
                                    @if (! empty($item['location']))<div class="text-sm text-[#667970]">{{ $item['location'] }}</div>@endif
                                </div>
                                <div class="text-sm text-[#667970] sm:text-right">{{ $item['starts_at'] ?? '—' }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($previewWarnings !== [])
                    <div class="mt-4 text-sm text-amber-800">{{ implode(' ', $previewWarnings) }}</div>
                @endif
            </section>

            <details class="group rounded-xl border border-[#d9d7ce] bg-white p-5">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 font-semibold text-[#18342c]">
                    <span>{{ __('Advanced') }}</span>
                    <flux:icon icon="chevron-down" class="size-4 transition group-open:rotate-180" />
                </summary>

                <div class="mt-5 space-y-5 border-t border-[#e1dfd7] pt-5">
                    <flux:select wire:model.live="sourceType" :label="__('Source type')" required>
                        @foreach ($types as $typeOption)
                            <option value="{{ $typeOption }}">{{ strtoupper(str_replace('_', ' ', $typeOption)) }}</option>
                        @endforeach
                    </flux:select>

                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <flux:heading size="sm">{{ __('Config (JSON)') }}</flux:heading>
                            <flux:text variant="subtle">{{ __('Source-specific settings. Must be valid JSON.') }}</flux:text>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <flux:button type="button" variant="subtle" wire:click.prevent="applyTemplate('ics')">{{ __('ICS template') }}</flux:button>
                            <flux:button type="button" variant="subtle" wire:click.prevent="applyTemplate('libcal')">{{ __('LibCal template') }}</flux:button>
                            <flux:button type="button" variant="subtle" wire:click.prevent="applyTemplate('visit_wichita')">{{ __('Simpleview template') }}</flux:button>
                            <flux:button type="button" variant="subtle" wire:click.prevent="applyTemplate('html_calendar')">{{ __('HTML calendar template') }}</flux:button>
                        </div>
                    </div>

                    <flux:textarea
                        wire:model.live="config"
                        wire:init="resetConfigField"
                        wire:key="event-source-config-{{ $source?->id ?? 'new' }}"
                        rows="12"
                        placeholder=""
                        class="font-mono text-sm w-full min-h-[240px] rounded-lg border border-zinc-200 bg-zinc-50 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                    />

                    <flux:text variant="subtle">{{ __('Tip: Start with a template, then adapt it only when automatic discovery needs help.') }}</flux:text>
                </div>
            </details>

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ __('Save event source') }}</span>
                    <span wire:loading>{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
