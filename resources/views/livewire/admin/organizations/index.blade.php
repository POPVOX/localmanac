<div class="admin-page">
    <div class="admin-page-header">
        <div>
            <div class="admin-kicker">{{ __('Directory') }}</div>
            <flux:heading size="xl" level="1" class="mt-2 font-serif !text-4xl !font-medium tracking-[-0.03em] text-[#123e32]">{{ __('Organizations') }}</flux:heading>
            <flux:subheading class="mt-2">{{ __('Manage the publishers and civic organizations behind each source.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" :href="route('admin.organizations.create')" icon="plus" wire:navigate>
            {{ __('New Organization') }}
        </flux:button>
    </div>

    <div class="admin-filter-panel grid gap-4 md:grid-cols-3">
        <flux:select wire:model.live="cityId" :label="__('Filter by city')" placeholder="{{ __('All cities') }}">
            <option value="">{{ __('All cities') }}</option>
            @foreach ($cities as $city)
                <option value="{{ $city->id }}">{{ $city->name }}</option>
            @endforeach
        </flux:select>
    </div>

    <flux:card padding="lg" class="admin-panel overflow-hidden">
        <flux:table :paginate="$organizations">
            <flux:table.columns sticky>
                <flux:table.column sticky>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Website') }}</flux:table.column>
                <flux:table.column>{{ __('City') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($organizations as $organization)
                    <flux:table.row :key="$organization->id">
                        <flux:table.cell variant="strong" sticky>{{ $organization->name }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="blue" variant="subtle" class="capitalize">
                                {{ str_replace('_', ' ', $organization->type) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($organization->website)
                                <flux:link href="{{ $organization->website }}" target="_blank">
                                    {{ \Illuminate\Support\Str::limit($organization->website, 40) }}
                                </flux:link>
                            @else
                                <flux:text variant="subtle">{{ __('—') }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $organization->city?->name }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" :href="route('admin.organizations.edit', $organization)" wire:navigate>
                                {{ __('Edit') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <flux:text variant="subtle">{{ __('No organizations found for this filter.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
