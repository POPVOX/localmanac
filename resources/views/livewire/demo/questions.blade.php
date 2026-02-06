<div class="space-y-6 py-8">
    <div class="flex flex-col gap-2">
        <flux:heading size="xl" level="1">{{ __('Questions') }}</flux:heading>
        <flux:subheading>
            {{ __('Ask about local services, rules, and programs.') }}
            @if ($city)
                <flux:text variant="subtle" class="inline">{{ __('City:') }} {{ $city->name }}</flux:text>
            @endif
        </flux:subheading>
    </div>

    <flux:card padding="lg" class="space-y-4">
        <div class="space-y-3">
            @forelse ($messages as $message)
                <div class="flex flex-col gap-2">
                    <flux:badge
                        color="{{ $message['role'] === 'user' ? 'sky' : 'emerald' }}"
                        variant="subtle"
                        class="w-fit"
                    >
                        {{ $message['role'] === 'user' ? __('You') : __('Assistant') }}
                    </flux:badge>

                    <flux:text class="whitespace-pre-wrap">{{ $message['content'] }}</flux:text>

                    @if (! empty($message['citations']))
                        <div class="flex flex-wrap gap-2">
                            @foreach ($message['citations'] as $citation)
                                <flux:link href="{{ $citation['source_url'] }}" target="_blank" variant="subtle">
                                    {{ \Illuminate\Support\Str::limit($citation['title'], 48) }}
                                </flux:link>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <flux:text variant="subtle">{{ __('Ask a question to get started.') }}</flux:text>
            @endforelse
        </div>
    </flux:card>

    <flux:card padding="lg">
        <form wire:submit.prevent="ask" class="space-y-4">
            <flux:textarea
                wire:model.live="question"
                :label="__('Your question')"
                rows="3"
                placeholder="{{ __('What day is trash pickup in my neighborhood?') }}"
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ __('Ask') }}</span>
                    <span wire:loading>{{ __('Thinking...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
