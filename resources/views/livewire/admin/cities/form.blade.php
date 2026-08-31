<div class="admin-page">
    <div class="admin-page-header">
        <div>
            <div class="admin-kicker">{{ __('Jurisdiction settings') }}</div>
            <flux:heading size="xl" level="1" class="mt-2 font-serif !text-4xl !font-medium tracking-[-0.03em] text-[#123e32]">{{ $title }}</flux:heading>
            <flux:subheading class="mt-2">{{ __('Manage public URLs, location details, and member chat access.') }}</flux:subheading>
        </div>

        <flux:button variant="ghost" :href="route('admin.cities.index')" wire:navigate>
            {{ __('Back to cities') }}
        </flux:button>
    </div>

    <flux:card padding="xl" class="admin-panel space-y-6">
        <form wire:submit.prevent="save" class="space-y-6">
            <flux:input
                wire:model.live="name"
                :label="__('Name')"
                type="text"
                required
                autofocus
                placeholder="{{ __('Example: Lawrence') }}"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input
                    wire:model="state"
                    :label="__('State or region')"
                    type="text"
                    placeholder="{{ __('Example: Kansas') }}"
                />

                <flux:input
                    wire:model="country"
                    :label="__('Country code')"
                    type="text"
                    required
                    maxlength="2"
                    placeholder="US"
                />
            </div>

            <flux:input
                wire:model="timezone"
                :label="__('Timezone')"
                type="text"
                required
                placeholder="America/Chicago"
                helper="{{ __('Use an IANA timezone, such as America/Chicago or America/New_York.') }}"
            />

            <section class="rounded-xl border border-[#d9d7ce] bg-[#f8f7f2] p-5">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <flux:heading size="sm">{{ __('Member access codes') }}</flux:heading>
                        <flux:text variant="subtle" class="mt-1">
                            {{ $city
                                ? __('Create named codes for partners and campaigns, then track which code grants each membership.')
                                : __('Create the location first, then add named access codes for partners and campaigns.') }}
                        </flux:text>
                    </div>
                    @if ($city)
                        <flux:button variant="subtle" :href="route('admin.cities.access-codes', $city)" wire:navigate>
                            {{ __('Manage access codes') }}
                        </flux:button>
                    @endif
                </div>
            </section>

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
