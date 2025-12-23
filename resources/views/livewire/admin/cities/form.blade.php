<div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
            <flux:subheading>{{ __('Create or update a city and its slug.') }}</flux:subheading>
        </div>

        <flux:button variant="ghost" :href="route('admin.cities.index')" wire:navigate>
            {{ __('Back to cities') }}
        </flux:button>
    </div>

    <flux:card padding="xl" variant="subtle" class="space-y-6">
        <form wire:submit.prevent="save" class="space-y-6">
            <flux:input
                wire:model.live="name"
                :label="__('Name')"
                type="text"
                required
                autofocus
                placeholder="{{ __('Example: Wichita') }}"
            />

            <flux:input
                wire:model.live="slug"
                :label="__('Slug')"
                type="text"
                required
                placeholder=""
                helper="{{ __('Used in URLs and must be unique.') }}"
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ __('Save city') }}</span>
                    <span wire:loading>{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
