<div>
    <flux:modal.trigger name="site-feedback">
        <button
            type="button"
            class="fixed bottom-4 right-4 z-40 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-600 text-white shadow-lg shadow-emerald-900/25 transition hover:bg-emerald-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 sm:bottom-6 sm:right-6"
            x-data=""
            x-on:click.prevent="$dispatch('open-modal', 'site-feedback')"
            data-test="feedback-trigger"
            aria-label="{{ __('Share feedback') }}"
        >
            <flux:icon icon="chat-bubble-left-right" variant="solid" class="size-5" />
            <span class="sr-only">{{ __('Share feedback') }}</span>
        </button>
    </flux:modal.trigger>

    <flux:modal name="site-feedback" variant="flyout" position="right" class="w-full sm:max-w-md" data-test="feedback-modal">
        <div class="space-y-6">
            @if ($submitted)
                <div class="space-y-4">
                    <flux:heading size="lg">{{ __('Thanks for your feedback') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Your input helps us improve the trial experience.') }}
                    </flux:subheading>
                    <div class="flex items-center justify-end gap-2">
                        <flux:button type="button" variant="primary" wire:click="submitAnother">
                            {{ __('Submit another response') }}
                        </flux:button>
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Close') }}</flux:button>
                        </flux:modal.close>
                    </div>
                </div>
            @else
                <form wire:submit="submit" class="space-y-6">
                    <div class="space-y-2">
                        <flux:heading size="lg">{{ __('Share feedback') }}</flux:heading>
                        <flux:subheading>
                            {{ __('Tell us what you liked, disliked, or where you ran into trouble.') }}
                        </flux:subheading>
                    </div>

                    @error('feedback')
                        <flux:callout variant="danger" icon="x-circle" :heading="$message" />
                    @enderror

                    <flux:radio.group wire:model="type" :label="__('Feedback type')">
                        @foreach ($feedbackTypes as $feedbackType)
                            <flux:radio :value="$feedbackType->value" :label="__($feedbackType->label())" />
                        @endforeach
                    </flux:radio.group>

                    <flux:textarea
                        wire:model="message"
                        :label="__('Details')"
                        rows="6"
                        required
                        placeholder="{{ __('What happened? What would you like to see improved?') }}"
                    />

                    <div class="flex items-center justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="submit">{{ __('Send feedback') }}</span>
                            <span wire:loading wire:target="submit">{{ __('Sending...') }}</span>
                        </flux:button>
                    </div>
                </form>
            @endif
        </div>
    </flux:modal>
</div>
