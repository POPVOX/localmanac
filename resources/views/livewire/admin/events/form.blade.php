<div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
            <flux:subheading>{{ __('Edit event details and timing for the selected city.') }}</flux:subheading>
        </div>

        <flux:button variant="ghost" :href="route('admin.events.index')" wire:navigate>
            {{ __('Back to events') }}
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
                    wire:model.live="title"
                    :label="__('Title')"
                    type="text"
                    required
                    autofocus
                />
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <flux:input
                    wire:model.live="startsAt"
                    :label="__('Starts at')"
                    type="datetime-local"
                    required
                />

                <flux:input
                    wire:model.live="endsAt"
                    :label="__('Ends at')"
                    type="datetime-local"
                />
            </div>

            <flux:text variant="subtle">
                {{ __('Times are shown in :timezone.', ['timezone' => $timezone]) }}
            </flux:text>

            <div class="flex items-center gap-3">
                <flux:switch wire:model.live="allDay" :label="__('All day')" />
                <flux:text variant="subtle">{{ __('Mark for all-day events.') }}</flux:text>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <flux:input
                    wire:model.live="locationName"
                    :label="__('Location name')"
                    type="text"
                />

                <flux:input
                    wire:model.live="locationAddress"
                    :label="__('Location address')"
                    type="text"
                />
            </div>

            <flux:input
                wire:model.live="eventUrl"
                :label="__('Event URL')"
                type="url"
            />

            <flux:textarea
                wire:model.live="description"
                :label="__('Description')"
                rows="6"
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ __('Save event') }}</span>
                    <span wire:loading>{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
