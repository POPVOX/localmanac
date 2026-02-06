<div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
            <flux:subheading>{{ __('Add or edit curated sources for the chat assistant.') }}</flux:subheading>
        </div>

        <flux:button variant="ghost" :href="route('admin.chat-sources.index')" wire:navigate>
            {{ __('Back to sources') }}
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
                    wire:model.live="name"
                    :label="__('Name')"
                    type="text"
                    required
                    autofocus
                />
            </div>

            <flux:input
                wire:model.live="sourceUrl"
                :label="__('Source URL')"
                type="url"
                required
            />

            <flux:textarea
                wire:model.live="description"
                :label="__('Description (optional)')"
                rows="4"
            />

            <div class="grid gap-4 md:grid-cols-2">
                <flux:input
                    wire:model.live="tags"
                    :label="__('Tags (comma separated)')"
                    placeholder="{{ __('trash pickup, permits, parks') }}"
                />

                <flux:input
                    wire:model.live="priority"
                    :label="__('Priority')"
                    type="number"
                    min="0"
                />
            </div>

            <div class="flex items-center gap-3">
                <flux:switch wire:model.live="isActive" :label="__('Active')" />
                <flux:text variant="subtle">{{ __('Inactive sources will be skipped for chat answers.') }}</flux:text>
            </div>

            <div class="flex items-center justify-between gap-3">
                <flux:text class="font-medium">{{ __('Advanced') }}</flux:text>
                <flux:button type="button" variant="subtle" wire:click="toggleAdvanced">
                    {{ $showAdvanced ? __('Hide') : __('Show') }}
                </flux:button>
            </div>

            @if ($showAdvanced)
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:select wire:model.live="linkFollowMode" :label="__('Link follow mode')">
                        <option value="auto">{{ __('Auto') }}</option>
                        <option value="none">{{ __('None') }}</option>
                    </flux:select>

                    <flux:input
                        wire:model.live="linkLimit"
                        :label="__('Link limit')"
                        type="number"
                        min="0"
                        max="20"
                    />

                    <flux:select wire:model.live="crawlRenderer" :label="__('Renderer')">
                        <option value="auto">{{ __('Auto (default)') }}</option>
                        <option value="http">{{ __('HTTP only') }}</option>
                        <option value="playwright">{{ __('Playwright only') }}</option>
                    </flux:select>
                </div>
            @endif

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ __('Save source') }}</span>
                    <span wire:loading>{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
