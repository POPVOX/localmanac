<div class="admin-page">
    <div class="admin-page-header">
        <div>
            <div class="admin-kicker">{{ __('Jurisdictions') }}</div>
            <flux:heading size="xl" level="1" class="mt-2 font-serif !text-4xl !font-medium tracking-[-0.03em] text-[#123e32]">{{ __('Cities') }}</flux:heading>
            <flux:subheading class="mt-2">{{ __('Manage public city pages and member chat access.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" :href="route('admin.cities.create')" icon="plus" wire:navigate>
            {{ __('New City') }}
        </flux:button>
    </div>

    <flux:card padding="lg" class="admin-panel overflow-hidden">
        <flux:table :paginate="$cities">
            <flux:table.columns sticky>
                <flux:table.column sticky>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Slug') }}</flux:table.column>
                <flux:table.column>{{ __('Chat') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Created') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($cities as $city)
                    <flux:table.row :key="$city->id">
                        <flux:table.cell variant="strong" sticky>{{ $city->name }}</flux:table.cell>
                        <flux:table.cell>{{ $city->slug }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$city->hasChatAccessCode() ? 'green' : 'zinc'" size="sm">
                                {{ $city->hasChatAccessCode() ? __('Code set') : __('No code') }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ $city->created_at?->format('M j, Y') }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-1">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    :href="route('admin.cities.preview', $city)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {{ __('Preview') }}
                                </flux:button>
                                <flux:button size="sm" variant="ghost" :href="route('admin.cities.edit', $city)" wire:navigate>
                                    {{ __('Edit') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <flux:text variant="subtle">{{ __('No cities found.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
