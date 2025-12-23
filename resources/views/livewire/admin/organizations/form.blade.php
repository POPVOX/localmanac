<div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
            <flux:subheading>{{ __('Create or update an organization attached to a city.') }}</flux:subheading>
        </div>

        <flux:button variant="ghost" :href="route('admin.organizations.index')" wire:navigate>
            {{ __('Back to organizations') }}
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

                <flux:select wire:model.live="type" :label="__('Type')" required>
                    @foreach ($types as $typeOption)
                        <option value="{{ $typeOption }}">{{ ucfirst(str_replace('_', ' ', $typeOption)) }}</option>
                    @endforeach
                </flux:select>
            </div>

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
                helper="{{ __('Unique per city and used in URLs.') }}"
            />

            <flux:input
                wire:model.live="website"
                :label="__('Website')"
                type="url"
                placeholder="https://example.org"
            />

            <flux:textarea
                wire:model.live="description"
                :label="__('Description')"
                placeholder="{{ __('Optional context for the organization') }}"
                rows="4"
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ __('Save organization') }}</span>
                    <span wire:loading>{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
