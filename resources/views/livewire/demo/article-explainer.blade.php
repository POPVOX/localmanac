@php
    $dimensions = $this->dimensions();
    $analysisScore = $this->civicRelevanceScore();
    $processTimelineItems = $this->processTimelineItems();
    $entitiesByGroup = $this->entitiesByGroup();
    $explainer = $this->explainerContent();
    $whatsHappening = $explainer['whats_happening'];
    $whyItMatters = $explainer['why_it_matters'];
    $keyDetails = $explainer['key_details'];
    $whatToWatch = $explainer['what_to_watch'];
    $bodyText = $article->body?->cleaned_text ?? '';
    $sourceUrl = $article->canonical_url ?? route('articles.source', $article);
    $organizationName = $article->scraper?->organization?->name ?? $article->scraper?->name;
    $publishedAt = $article->published_at ?? $article->created_at;
    $updatedAt = $article->body?->updated_at;
    $participationActions = $this->participationActions();
    $showProcessTimelineSection = collect($processTimelineItems)->contains(
        fn (array $item): bool => ($item['has_date'] ?? false) === true
            || (is_string($item['note'] ?? null) && trim($item['note']) !== '')
    );
@endphp

<div class="mx-auto flex max-w-6xl flex-col gap-10">
    <div class="flex flex-col gap-4">
        <flux:heading size="xl" level="1">
            {{ $article->title ?? __('Untitled article') }}
        </flux:heading>

        <div class="flex flex-wrap items-center gap-3 text-sm text-zinc-600 dark:text-zinc-300">
            @if ($organizationName)
                <flux:badge color="emerald" variant="subtle">
                    {{ $organizationName }}
                </flux:badge>
            @endif

            @if ($publishedAt)
                <span>{{ $publishedAt->format('F j, Y') }}</span>
            @endif

            @if ($updatedAt)
                @if ($publishedAt)
                    <span class="text-zinc-300 dark:text-zinc-600">•</span>
                @endif
                <span>{{ __('Updated :time', ['time' => $updatedAt->diffForHumans()]) }}</span>
            @endif
        </div>
    </div>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <div class="flex flex-col gap-8">
            <flux:card padding="lg" class="flex flex-col gap-8">
                <div class="flex flex-col gap-6">
                    <div class="flex flex-col gap-3">
                        <flux:text class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">
                            {{ __("What's happening") }}
                        </flux:text>
                        <flux:text class="leading-relaxed text-zinc-900 dark:text-zinc-100">
                            {{ $whatsHappening ?? (\Illuminate\Support\Str::limit($bodyText, 600) ?: __('Summary unavailable.')) }}
                        </flux:text>
                    </div>

                    @if ($whyItMatters)
                        <div class="flex flex-col gap-3">
                            <flux:text class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">
                                {{ __('Why it matters') }}
                            </flux:text>
                            <flux:text class="leading-relaxed text-zinc-900 dark:text-zinc-100">
                                {{ $whyItMatters }}
                            </flux:text>
                        </div>
                    @endif
                </div>

                @if ($showProcessTimelineSection)
                    <div class="flex flex-col gap-4">
                        <flux:text class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">
                            {{ __('Where we are in the process') }}
                        </flux:text>

                        <flux:timeline>
                            @foreach ($processTimelineItems as $item)
                                @php
                                    $fluxStatus = match ($item['status']) {
                                        'completed' => 'complete',
                                        'current' => 'current',
                                        default => 'incomplete',
                                    };
                                    $statusIcon = $this->processTimelineStatusIcon($item['status']);
                                @endphp
                                <flux:timeline.item :status="$fluxStatus">
                                    <flux:timeline.indicator :status="$fluxStatus">
                                        @if ($statusIcon)
                                            <flux:icon :icon="$statusIcon" variant="micro" class="size-4" />
                                        @endif
                                    </flux:timeline.indicator>

                                    <flux:timeline.content :status="$fluxStatus">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <flux:text class="font-medium">{{ $item['label'] }}</flux:text>
                                            @if ($item['badge_text'])
                                                <flux:badge variant="subtle">{{ $item['badge_text'] }}</flux:badge>
                                            @endif
                                        </div>
                                        @if ($item['has_date'])
                                            <flux:text variant="subtle">{{ $item['date_label'] }}</flux:text>
                                        @else
                                            <flux:text variant="subtle" class="text-zinc-400 dark:text-zinc-500">
                                                {{ __('Date TBD') }}
                                            </flux:text>
                                        @endif
                                        @if ($item['note'])
                                            <flux:text variant="subtle">{{ $item['note'] }}</flux:text>
                                        @endif
                                    </flux:timeline.content>
                                </flux:timeline.item>
                            @endforeach
                        </flux:timeline>
                    </div>
                @endif

                @if ($keyDetails !== [] || $whatToWatch !== [])
                    <flux:separator />
                    <div class="grid gap-6 md:grid-cols-2">
                        @if ($keyDetails !== [])
                            <div class="flex flex-col gap-3">
                                <flux:text class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">
                                    {{ __('Key details') }}
                                </flux:text>
                                <div class="flex flex-col gap-2 text-sm">
                                    @foreach ($keyDetails as $detail)
                                        <div class="flex flex-col gap-1">
                                            @if ($detail['label'] && $detail['value'])
                                                <flux:text class="text-sm text-zinc-700 dark:text-zinc-200">
                                                    <span class="font-medium">{{ $detail['label'] }}</span>
                                                    <span>{{ $detail['value'] }}</span>
                                                </flux:text>
                                            @elseif ($detail['text'])
                                                <flux:text class="text-sm text-zinc-700 dark:text-zinc-200">{{ $detail['text'] }}</flux:text>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if ($whatToWatch !== [])
                            <div class="flex flex-col gap-3">
                                <flux:text class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">
                                    {{ __('What to watch next') }}
                                </flux:text>
                                <div class="flex flex-col gap-2 text-sm">
                                    @foreach ($whatToWatch as $detail)
                                        <div class="flex flex-col gap-1">
                                            @if ($detail['label'] && $detail['value'])
                                                <flux:text class="text-sm text-zinc-700 dark:text-zinc-200">
                                                    <span class="font-medium">{{ $detail['label'] }}</span>
                                                    <span>{{ $detail['value'] }}</span>
                                                </flux:text>
                                            @elseif ($detail['text'])
                                                <flux:text class="text-sm text-zinc-700 dark:text-zinc-200">{{ $detail['text'] }}</flux:text>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </flux:card>

            <flux:card padding="lg" class="flex flex-col gap-4 bg-zinc-50/70 dark:bg-zinc-900/40">
                <div class="flex flex-col gap-2">
                    <flux:text class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">
                        {{ __('Source Article') }}
                    </flux:text>
                    <flux:text variant="subtle">{{ $article->title ?? __('Untitled article') }}</flux:text>
                </div>

                <div class="flex flex-col gap-2 text-sm">
                    <flux:text>
                        {{ __('Source:') }}
                        <span>{{ $organizationName ?? '--' }}</span>
                    </flux:text>
                    <flux:text variant="subtle">
                        {{ __('Published: :date', ['date' => $publishedAt ? $publishedAt->format('M j, Y') : '--']) }}
                    </flux:text>
                </div>

                <flux:link href="{{ $sourceUrl }}" target="_blank" class="text-sm font-medium">
                    {{ __('Read full source →') }}
                </flux:link>

                <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('AI summary notice')">
                    <flux:text variant="subtle">
                        {{ __('AI summaries can make mistakes. That is why we link to the original source documents. You may want to check them before acting on information you read here.') }}
                    </flux:text>
                </flux:callout>
            </flux:card>

            @can('access-admin')
                <details class="rounded-2xl border border-zinc-200 px-5 py-4 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">
                    <summary class="cursor-pointer text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                        {{ __('About this analysis') }}
                    </summary>
                    <div class="mt-4 flex flex-col gap-3">
                        <div class="flex flex-wrap gap-2">
                            <flux:badge variant="outline">
                                {{ __('Civic relevance: :score', ['score' => $analysisScore !== null ? number_format($analysisScore, 2) : '--']) }}
                            </flux:badge>
                            @foreach ($dimensions as $label => $value)
                                <flux:badge variant="outline">
                                    {{ ucfirst($label) }}: {{ $value !== null ? number_format($value, 2) : '--' }}
                                </flux:badge>
                            @endforeach
                        </div>
                    </div>
                </details>
            @endcan
        </div>

        <div class="flex flex-col gap-6">
            @if ($participationActions !== [])
                <flux:card padding="lg" class="relative flex flex-col gap-5 overflow-hidden border border-zinc-200 bg-white/90 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/70">
                    <span class="pointer-events-none absolute inset-y-0 start-0 w-1.5 bg-gradient-to-b from-emerald-300 via-emerald-400 to-emerald-500 dark:from-emerald-700 dark:via-emerald-600 dark:to-emerald-500"></span>

                    <div class="flex items-center justify-between gap-3 ps-2">
                        <flux:heading size="lg" level="2">
                            {{ __('How to Participate') }}
                        </flux:heading>
                    </div>

                    <div class="flex flex-col gap-5 ps-2">
                        @foreach ($participationActions as $action)
                            <div class="flex gap-4 border-b border-zinc-200/80 pb-5 last:border-b-0 last:pb-0 dark:border-zinc-700/70">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-100/80 text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-900/50 dark:text-emerald-200 dark:ring-emerald-800">
                                    <flux:icon :icon="$action['icon']" variant="micro" class="size-5" />
                                </div>
                                <div class="flex flex-1 flex-col gap-2">
                                    <div class="flex flex-col gap-1">
                                        <flux:heading size="sm" level="3">{{ $action['title'] }}</flux:heading>
                                        @if ($action['subtitle'])
                                            <flux:text variant="subtle" class="text-zinc-600 dark:text-zinc-300">
                                                {{ $action['subtitle'] }}
                                            </flux:text>
                                        @endif
                                    </div>

                                    @if ($action['meta'] !== [])
                                        <div class="flex flex-col gap-1">
                                            @foreach ($action['meta'] as $line)
                                                <flux:text variant="subtle" class="text-zinc-600 dark:text-zinc-300">
                                                    {{ $line }}
                                                </flux:text>
                                            @endforeach
                                        </div>
                                    @endif

                                    <div class="flex flex-wrap items-center gap-3">
                                        @if ($action['cta_url'])
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                :href="$action['cta_url']"
                                                target="_blank"
                                                icon:trailing="arrow-right"
                                                class="text-emerald-700 hover:text-emerald-800 dark:text-emerald-300 dark:hover:text-emerald-200"
                                            >
                                                {{ $action['cta_label'] ?? __('View details') }}
                                            </flux:button>
                                        @endif

                                        @if ($action['badge'])
                                            <flux:badge variant="subtle">{{ $action['badge'] }}</flux:badge>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </flux:card>
            @endif

            <flux:card padding="lg" class="flex flex-col gap-4">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg" level="2">{{ __('People & Organizations') }}</flux:heading>
                </div>

                @if ($entitiesByGroup === [])
                    <flux:text variant="subtle">{{ __('No people or organizations listed yet.') }}</flux:text>
                @else
                    <div class="flex flex-col gap-4">
                        @foreach ($entitiesByGroup as $groupLabel => $entities)
                            <div class="flex flex-col gap-2">
                                <flux:text class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                                    {{ $groupLabel }}
                                </flux:text>
                                <div class="flex flex-col gap-2">
                                    @foreach ($entities as $entity)
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="flex flex-col gap-1">
                                                <flux:text>{{ $entity['name'] }}</flux:text>
                                                @if ($entity['secondary'])
                                                    <flux:text variant="subtle">{{ $entity['secondary'] }}</flux:text>
                                                @endif
                                            </div>
                                            <flux:badge variant="outline">
                                                {{ ucfirst($entity['type']) }}
                                            </flux:badge>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            @if (! $loop->last)
                                <flux:separator />
                            @endif
                        @endforeach
                    </div>
                @endif
            </flux:card>
        </div>
    </div>
</div>
