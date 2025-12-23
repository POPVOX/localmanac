<div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Cities') }}</flux:heading>
            <flux:subheading>{{ __('Manage the cities covered by Localmanac.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" :href="route('admin.cities.create')" wire:navigate>
            {{ __('New City') }}
        </flux:button>
    </div>

    <flux:card padding="lg" variant="subtle">
        <flux:table :paginate="$cities">
            <flux:table.columns sticky>
                <flux:table.column sticky>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Slug') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Created') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($cities as $city)
                    <flux:table.row :key="$city->id">
                        <flux:table.cell variant="strong" sticky>{{ $city->name }}</flux:table.cell>
                        <flux:table.cell>{{ $city->slug }}</flux:table.cell>
                        <flux:table.cell align="end">{{ $city->created_at?->format('M j, Y') }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" :href="route('admin.cities.edit', $city)" wire:navigate>
                                {{ __('Edit') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">
                            <flux:text variant="subtle">{{ __('No cities found.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
