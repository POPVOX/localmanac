@props([
    'keyDetails' => [],
    'whatToWatch' => [],
])

<div
    @class([
        'grid gap-8',
        'md:grid-cols-2' => $keyDetails !== [] && $whatToWatch !== [],
    ])
    data-testid="explainer-lists"
    data-layout="{{ $keyDetails !== [] && $whatToWatch !== [] ? 'split' : 'single' }}"
>
    @if ($keyDetails !== [])
        <section class="flex flex-col gap-3" aria-labelledby="key-details-heading">
            <h2 id="key-details-heading" class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">
                {{ __('Key details') }}
            </h2>
            <ul class="space-y-3" data-testid="key-details-list">
                @foreach ($keyDetails as $detail)
                    <li class="grid grid-cols-[auto_minmax(0,1fr)] gap-3" data-testid="key-detail-item">
                        <span class="mt-2 size-1.5 rounded-full bg-[#6f8f82] dark:bg-emerald-400" aria-hidden="true"></span>
                        <div class="min-w-0 text-sm leading-6 text-zinc-700 dark:text-zinc-200">
                            @if ($detail['label'] && $detail['value'])
                                <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $detail['label'] }}</span>
                                <span>{{ $detail['value'] }}</span>
                            @elseif ($detail['text'])
                                {{ $detail['text'] }}
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($whatToWatch !== [])
        <section class="flex flex-col gap-3" aria-labelledby="what-to-watch-heading">
            <h2 id="what-to-watch-heading" class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">
                {{ __('What to watch next') }}
            </h2>
            <ul class="space-y-3" data-testid="what-to-watch-list">
                @foreach ($whatToWatch as $detail)
                    <li class="grid grid-cols-[auto_minmax(0,1fr)] gap-3" data-testid="what-to-watch-item">
                        <span class="mt-2 size-1.5 rounded-full bg-[#6f8f82] dark:bg-emerald-400" aria-hidden="true"></span>
                        <div class="min-w-0 text-sm leading-6 text-zinc-700 dark:text-zinc-200">
                            @if ($detail['label'] && $detail['value'])
                                <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $detail['label'] }}</span>
                                <span>{{ $detail['value'] }}</span>
                            @elseif ($detail['text'])
                                {{ $detail['text'] }}
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
