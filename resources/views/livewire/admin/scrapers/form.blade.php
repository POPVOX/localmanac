<div class="admin-page">
    <div class="admin-page-header">
        <div>
            <div class="admin-kicker">{{ __('Article ingestion') }}</div>
            <flux:heading size="xl" level="1" class="mt-2 font-serif !text-4xl !font-medium tracking-[-0.03em] text-[#123e32]">{{ $title }}</flux:heading>
            <flux:subheading class="mt-2">{{ __('Configure source details, extraction, and scheduling.') }}</flux:subheading>
        </div>

        <flux:button variant="ghost" :href="route('admin.scrapers.index')" wire:navigate>
            {{ __('Back to scrapers') }}
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

                <flux:select wire:model.live="organizationId" :label="__('Organization')">
                    <option value="">{{ __('Unassigned') }}</option>
                    @foreach ($organizations as $organization)
                        <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <flux:input
                    wire:model.live="name"
                    :label="__('Name')"
                    type="text"
                    required
                    autofocus
                />

                <flux:input
                    wire:model.live="slug"
                    :label="__('Slug')"
                    type="text"
                    required
                    helper="{{ __('Unique per city; used in URLs.') }}"
                />
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <flux:select wire:model.live="type" :label="__('Type')" required>
                    @foreach ($types as $typeOption)
                        <option value="{{ $typeOption }}">{{ strtoupper($typeOption) }}</option>
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

            <div class="grid gap-4 md:grid-cols-3">
                <flux:select wire:model.live="frequency" :label="__('Frequency')" required>
                    @foreach ($frequencies as $frequencyOption)
                        <option value="{{ $frequencyOption }}">{{ ucfirst($frequencyOption) }}</option>
                    @endforeach
                </flux:select>

                @if ($frequency === 'daily')
                    <flux:input
                        wire:model.live="runAt"
                        :label="__('Run time')"
                        type="time"
                        step="60"
                        helper="{{ __('Defaults to :time if left blank.', ['time' => $defaultRunAt]) }}"
                        class="md:col-span-2"
                    />
                @elseif ($frequency === 'weekly')
                    <flux:select wire:model.live="runDayOfWeek" :label="__('Day of week')" required>
                        <option value="">{{ __('Select a day') }}</option>
                        @foreach ($weekdays as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>

                    <flux:input
                        wire:model.live="runAt"
                        :label="__('Run time')"
                        type="time"
                        step="60"
                        helper="{{ __('Defaults to :time if left blank.', ['time' => $defaultRunAt]) }}"
                    />
                @endif
            </div>

            <div class="flex items-center gap-3">
                <flux:switch wire:model.live="isActive" :label="__('Active')" />
                <flux:text variant="subtle">{{ __('Inactive scrapers will be skipped until re-enabled.') }}</flux:text>
            </div>

            <flux:field class="space-y-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div>
                    <flux:heading size="sm">{{ __('Config Assistant') }}</flux:heading>
                    <flux:text variant="subtle">{{ __('Generate config from source content, then preview sample extraction before saving.') }}</flux:text>
                </div>

                @if ($assistantConfigNotice)
                    <flux:callout icon="information-circle" variant="warning" :heading="__('Existing config notice')">
                        <flux:text variant="subtle">{{ $assistantConfigNotice }}</flux:text>
                    </flux:callout>
                @endif

                <div class="grid gap-4 md:grid-cols-2">
                    <flux:select wire:model.live="assistantInputMode" :label="__('Source input mode')">
                        <option value="url">{{ __('Fetch from URL') }}</option>
                        <option value="paste">{{ __('Paste source HTML') }}</option>
                    </flux:select>

                    @if ($assistantFetchRenderer)
                        <flux:field>
                            <flux:label>{{ __('Last fetch renderer') }}</flux:label>
                            <flux:text>{{ strtoupper($assistantFetchRenderer) }}</flux:text>
                        </flux:field>
                    @endif
                </div>

                @if ($assistantInputMode === 'paste')
                    <flux:textarea
                        wire:model.live="assistantSourceHtml"
                        rows="8"
                        :label="__('Paste source HTML')"
                        placeholder="{{ __('Paste page source here') }}"
                        class="font-mono text-xs"
                    />
                @endif

                <div class="flex flex-wrap items-center gap-2">
                    <flux:button type="button" variant="primary" wire:click="generateConfigDraft" wire:loading.attr="disabled" wire:target="generateConfigDraft">
                        <span wire:loading.remove wire:target="generateConfigDraft">{{ __('Generate draft') }}</span>
                        <span wire:loading wire:target="generateConfigDraft">{{ __('Generating...') }}</span>
                    </flux:button>

                    <flux:button
                        type="button"
                        variant="subtle"
                        wire:click="previewGeneratedConfig"
                        wire:loading.attr="disabled"
                        wire:target="previewGeneratedConfig"
                        :disabled="! $assistantHasDraft"
                    >
                        <span wire:loading.remove wire:target="previewGeneratedConfig">{{ __('Preview extraction') }}</span>
                        <span wire:loading wire:target="previewGeneratedConfig">{{ __('Previewing...') }}</span>
                    </flux:button>

                    @if ($assistantHasDraft)
                        <flux:badge color="indigo" variant="subtle">
                            {{ __('Profile: :profile', ['profile' => $assistantDraftProfile ?? 'n/a']) }}
                        </flux:badge>
                    @endif
                    @if ($assistantConfidence !== null)
                        <flux:badge color="zinc" variant="subtle">
                            {{ __('Confidence: :value', ['value' => number_format($assistantConfidence * 100, 0).'%']) }}
                        </flux:badge>
                    @endif
                    @if ($assistantHasDraft)
                        <flux:badge :color="$assistantPreviewValid ? 'green' : 'amber'" variant="subtle">
                            {{ $assistantPreviewValid ? __('Preview ready') : __('Preview required') }}
                        </flux:badge>
                    @endif
                </div>

                @if ($assistantWarnings !== [])
                    <flux:callout icon="information-circle" variant="warning" :heading="__('Draft warnings')">
                        <div class="space-y-1 text-sm">
                            @foreach ($assistantWarnings as $warning)
                                <div>{{ $warning }}</div>
                            @endforeach
                        </div>
                    </flux:callout>
                @endif

                @if ($assistantPreviewError)
                    <flux:callout icon="x-circle" variant="danger" :heading="__('Preview error')">
                        <flux:text variant="subtle">{{ $assistantPreviewError }}</flux:text>
                    </flux:callout>
                @endif

                @if ($assistantPreviewWarnings !== [])
                    <flux:callout icon="information-circle" variant="warning" :heading="__('Preview notes')">
                        <div class="space-y-1 text-sm">
                            @foreach ($assistantPreviewWarnings as $warning)
                                <div>{{ $warning }}</div>
                            @endforeach
                        </div>
                    </flux:callout>
                @endif

                @if ($assistantPreviewItems !== [])
                    <flux:table class="w-full table-fixed">
                        <flux:table.columns>
                            <flux:table.column class="w-[46%]">{{ __('Title') }}</flux:table.column>
                            <flux:table.column class="w-[34%]">{{ __('Source URL') }}</flux:table.column>
                            <flux:table.column class="w-[20%]">{{ __('Published') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($assistantPreviewItems as $item)
                                <flux:table.row>
                                    <flux:table.cell class="max-w-0 align-top">
                                        @if (! empty($item['title']))
                                            <span class="block truncate" title="{{ $item['title'] }}">
                                                {{ \Illuminate\Support\Str::limit($item['title'], 64) }}
                                            </span>
                                        @else
                                            <flux:text variant="subtle">{{ __('—') }}</flux:text>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="max-w-0 align-top">
                                        @if (! empty($item['source_url']))
                                            <flux:link href="{{ $item['source_url'] }}" target="_blank" class="block truncate" title="{{ $item['source_url'] }}">
                                                {{ \Illuminate\Support\Str::of($item['source_url'])->replaceFirst('https://', '')->replaceFirst('http://', '')->limit(44) }}
                                            </flux:link>
                                        @else
                                            <flux:text variant="subtle">{{ __('—') }}</flux:text>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="align-top whitespace-nowrap">
                                        {{ $item['published_at'] ?? '—' }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </flux:field>

            @if ($isSuperAdmin)
                <flux:field class="space-y-2">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <flux:heading size="sm">{{ __('Config (JSON)') }}</flux:heading>
                            <flux:text variant="subtle">{{ __('Raw JSON is available to super admins only.') }}</flux:text>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <flux:button type="button" variant="subtle" wire:click="toggleAdvancedConfig">
                                {{ $showAdvancedConfig ? __('Hide raw JSON') : __('Show raw JSON') }}
                            </flux:button>
                        </div>
                    </div>

                    @if ($showAdvancedConfig)
                        <div class="flex flex-wrap gap-2">
                            <flux:button type="button" variant="subtle" wire:click.prevent="applyTemplate('documenters')">
                                {{ __('Documenters reporting RSS') }}
                            </flux:button>
                            <flux:button type="button" variant="subtle" wire:click.prevent="applyTemplate('generic_listing')">
                                {{ __('Generic listing default') }}
                            </flux:button>
                            <flux:button type="button" variant="subtle" wire:click.prevent="applyTemplate('civicplus_archive_pdf_list')">
                                {{ __('CivicPlus archive PDF list') }}
                            </flux:button>
                        </div>

                        <flux:textarea
                            wire:model.live="config"
                            wire:init="resetConfigField"
                            wire:key="scraper-config-{{ $scraper?->id ?? 'new' }}"
                            rows="12"
                            class="font-mono text-sm w-full min-h-[240px] rounded-lg border border-zinc-200 bg-zinc-50 text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        />
                    @endif
                </flux:field>
            @else
                <flux:text variant="subtle">
                    {{ __('Raw JSON editing is restricted. Use the assistant draft and preview workflow.') }}
                </flux:text>
            @endif

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ __('Save scraper') }}</span>
                    <span wire:loading>{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
