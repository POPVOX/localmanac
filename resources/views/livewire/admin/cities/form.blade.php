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

            <flux:input
                wire:model="chatAccessCode"
                :label="__('Chat access code')"
                type="text"
                autocomplete="off"
                placeholder="{{ $city?->hasChatAccessCode() ? __('Leave blank to keep the current code') : __('Set an access code') }}"
                helper="{{ __('At least 8 characters. The code is stored securely and cannot be displayed later.') }}"
            />

            @if ($city?->hasChatAccessCode())
                <flux:checkbox
                    wire:model="removeChatAccessCode"
                    :label="__('Remove the current chat access code')"
                    :description="__('Existing users keep their city access; no new users can unlock chat until another code is set.')"
                />
            @endif

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
