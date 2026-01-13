<div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
            <flux:subheading>{{ __('Configure calendar ingestion sources and JSON settings.') }}</flux:subheading>
        </div>

        <flux:button variant="ghost" :href="route('admin.event-sources.index')" wire:navigate>
            {{ __('Back to event sources') }}
        </flux:button>
    </div>

    <flux:card padding="xl" variant="subtle" class="space-y-6">
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

            <div class="grid gap-4 md:grid-cols-3">
                <flux:select wire:model.live="sourceType" :label="__('Type')" required>
                    @foreach ($types as $typeOption)
                        @php
                            $label = strtoupper(str_replace('_', ' ', $typeOption));
                        @endphp
                        <option value="{{ $typeOption }}">{{ $label }}</option>
                    @endforeach
                </flux:select>

                <flux:input
                    wire:model.live="sourceUrl"
                    :label="__('Source URL')"
                    type="url"
                    required
                    class="md:col-span-2"
                />
            </div>

            <div class="flex items-center gap-3">
                <flux:switch wire:model.live="isActive" :label="__('Active')" />
                <flux:text variant="subtle">{{ __('Inactive sources will be skipped until re-enabled.') }}</flux:text>
            </div>

            <flux:field class="space-y-2">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <flux:heading size="sm">{{ __('Config (JSON)') }}</flux:heading>
                        <flux:text variant="subtle">{{ __('Source-specific settings. Must be valid JSON.') }}</flux:text>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <flux:button type="button" variant="subtle" wire:click.prevent="applyTemplate('ics')">
                            {{ __('ICS template') }}
                        </flux:button>
                        <flux:button type="button" variant="subtle" wire:click.prevent="applyTemplate('libcal')">
                            {{ __('LibCal template') }}
                        </flux:button>
                        <flux:button type="button" variant="subtle" wire:click.prevent="applyTemplate('visit_wichita')">
                            {{ __('Visit Wichita template') }}
                        </flux:button>
                        <flux:button type="button" variant="subtle" wire:click.prevent="applyTemplate('html_calendar')">
                            {{ __('HTML calendar template') }}
                        </flux:button>
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

                <flux:text variant="subtle">
                    {{ __('Tip: Start with a template, then adapt it to each calendar provider.') }}
                </flux:text>
            </flux:field>

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ __('Save event source') }}</span>
                    <span wire:loading>{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
