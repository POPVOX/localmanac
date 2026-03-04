<div class="space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Feedback') }}</flux:heading>
        <flux:subheading>{{ __('Review trial feedback submitted by users across the site.') }}</flux:subheading>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <flux:select wire:model.live="type" :label="__('Filter by type')" placeholder="{{ __('All types') }}">
            <option value="">{{ __('All types') }}</option>
            @foreach ($feedbackTypes as $feedbackType)
                <option value="{{ $feedbackType->value }}">{{ __($feedbackType->label()) }}</option>
            @endforeach
        </flux:select>
    </div>

    <flux:card padding="lg" variant="subtle" class="bg-white dark:bg-zinc-800/35">
        <flux:table :paginate="$feedbackEntries">
            <flux:table.columns sticky>
                <flux:table.column sticky>{{ __('Submitted') }}</flux:table.column>
                <flux:table.column>{{ __('User') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Message') }}</flux:table.column>
                <flux:table.column>{{ __('Page') }}</flux:table.column>
                <flux:table.column>{{ __('City') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($feedbackEntries as $entry)
                    @php
                        $typeColor = match ($entry->type?->value) {
                            'like' => 'green',
                            'dislike' => 'red',
                            'trouble' => 'amber',
                            default => 'blue',
                        };
                    @endphp
                    <flux:table.row :key="$entry->id">
                        <flux:table.cell variant="strong" sticky>
                            <span title="{{ $entry->created_at?->toDateTimeString() }}">
                                {{ $entry->created_at?->diffForHumans() ?? '—' }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($entry->user)
                                <div class="flex flex-col gap-1">
                                    <span>{{ $entry->user->name }}</span>
                                    <flux:text size="sm" variant="subtle">{{ $entry->user->email }}</flux:text>
                                </div>
                            @else
                                <flux:text variant="subtle">{{ __('Deleted user') }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$typeColor" variant="subtle">
                                {{ __($entry->type?->label() ?? 'Unknown') }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ \Illuminate\Support\Str::limit($entry->message, 120) }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-col gap-1">
                                <flux:link href="{{ $entry->page_url }}" target="_blank">
                                    {{ \Illuminate\Support\Str::limit($entry->page_url, 50) }}
                                </flux:link>
                                <flux:text size="sm" variant="subtle">
                                    {{ $entry->route_name ?? __('Unknown route') }}
                                </flux:text>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ $entry->city?->name ?? '—' }}
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <flux:text variant="subtle">{{ __('No feedback found for this filter.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
