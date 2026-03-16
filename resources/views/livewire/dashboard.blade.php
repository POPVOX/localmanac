<div class="space-y-8">
    {{-- ── Chat + Welcome row ────────────────────────────────────── --}}
    @php
        $hasConversation = $conversationId || count($messages) > 0;
        $upcomingEvents = $upcomingEvents ?? collect();
    @endphp

    <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        {{-- Query box (left) --}}
        <div class="overflow-hidden rounded-3xl border border-zinc-200/60 bg-white shadow-lg">
            {{-- Header --}}
            <div class="px-6 pt-5 pb-4">
                <flux:heading size="lg" level="1">
                    {{ __('Ask LocAlmanac About :city', ['city' => $city?->name ?? __('your city')]) }}
                </flux:heading>
                <flux:subheading class="mt-0.5">
                    {{ __('Get answers on local services, meetings, and community updates.') }}
                </flux:subheading>
            </div>

            {{-- Conversation body --}}
            <div
                x-data="{
                    pendingQuestion: '',
                    hasMessages: @js(count($messages) > 0),
                    scrollTimers: [],
                    scrollToBottom() {
                        this.$nextTick(() => {
                            const container = this.$refs.chatScroll;
                            if (container) {
                                container.scrollTop = container.scrollHeight;
                            }
                        });
                    },
                    queueScrollToBottom() {
                        this.scrollTimers.forEach((timer) => clearTimeout(timer));
                        this.scrollTimers = [];
                        [0, 60, 140, 260, 420].forEach((delay) => {
                            this.scrollTimers.push(setTimeout(() => this.scrollToBottom(), delay));
                        });
                    },
                }"
                x-init="scrollToBottom()"
                class="flex flex-col"
            >

                {{-- Message thread --}}
                <div
                    x-ref="chatScroll"
                    x-show="hasMessages || pendingQuestion"
                    x-cloak
                    class="max-h-[320px] space-y-1 overflow-y-auto px-6 py-5"
                    x-on:chat-updated.window="hasMessages = true; pendingQuestion = ''; queueScrollToBottom()"
                    x-on:chat-reset.window="hasMessages = false; pendingQuestion = ''"
                >
                    @foreach ($messages as $message)
                        @php
                            $isUser = $message['role'] === 'user';
                        @endphp
                        <div class="flex w-full {{ $isUser ? 'justify-end' : 'justify-start' }} py-1.5">
                            <div @class([
                                'max-w-[85%] space-y-2 rounded-2xl px-4 py-3',
                                'bg-emerald-600 text-white' => $isUser,
                                'bg-zinc-100 text-zinc-900' => ! $isUser,
                            ])>
                                <div class="whitespace-pre-wrap text-sm leading-relaxed">{{ $message['content'] }}</div>

                                @if (! empty($message['citations']))
                                    <div @class([
                                        'flex flex-wrap items-center gap-1.5 border-t pt-2',
                                        'border-emerald-500/40' => $isUser,
                                        'border-zinc-200' => ! $isUser,
                                    ])>
                                        @foreach ($message['citations'] as $citation)
                                            <a
                                                href="{{ $citation['source_url'] }}"
                                                target="_blank"
                                                @class([
                                                    'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium transition',
                                                    'bg-emerald-500/30 text-emerald-50 hover:bg-emerald-500/50' => $isUser,
                                                    'bg-white border border-zinc-200 text-zinc-500 hover:text-zinc-700 hover:border-zinc-300' => ! $isUser,
                                                ])
                                            >
                                                <flux:icon icon="arrow-top-right-on-square" class="size-3" />
                                                <span>{{ \Illuminate\Support\Str::limit($citation['title'], 36) }}</span>
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    {{-- Loading / pending state --}}
                    <div wire:loading.block wire:target="ask" class="w-full space-y-1">
                        <div x-show="pendingQuestion" x-cloak class="flex w-full justify-end py-1.5">
                            <div class="max-w-[85%] rounded-2xl bg-emerald-600 px-4 py-3 text-white">
                                <div class="whitespace-pre-wrap text-sm leading-relaxed" x-text="pendingQuestion"></div>
                            </div>
                        </div>

                        <div class="flex w-full justify-start py-1.5">
                            <div class="rounded-2xl bg-zinc-100 px-4 py-3">
                                <div
                                    role="status"
                                    aria-live="polite"
                                    data-testid="assistant-typing-indicator"
                                    class="flex items-center gap-2 text-xs font-medium text-zinc-500"
                                >
                                    <span class="sr-only">{{ __('Assistant is thinking') }}</span>
                                    <span class="inline-flex items-center gap-1">
                                        <span class="size-1.5 rounded-full bg-emerald-500 motion-safe:animate-bounce [animation-delay:0ms]"></span>
                                        <span class="size-1.5 rounded-full bg-emerald-500 motion-safe:animate-bounce [animation-delay:120ms]"></span>
                                        <span class="size-1.5 rounded-full bg-emerald-500 motion-safe:animate-bounce [animation-delay:240ms]"></span>
                                    </span>
                                    <span>{{ __('Thinking…') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Composer --}}
                <div class="border-t border-zinc-100 px-6 pb-5 pt-4">
                    <form
                        wire:submit.prevent="ask"
                        x-on:submit="pendingQuestion = ($refs.chatComposer?.querySelector('textarea')?.value ?? '').trim(); if (pendingQuestion) { hasMessages = true; setTimeout(() => { const input = $refs.chatComposer?.querySelector('textarea'); if (input) { input.value = ''; } }, 0); } queueScrollToBottom();"
                        class="space-y-3"
                    >
                        <flux:composer
                            x-ref="chatComposer"
                            wire:model.defer="question"
                            placeholder="{{ __('Type your question here') }}"
                            rows="2"
                            max-rows="5"
                            submit="enter"
                            class="rounded-xl border-zinc-200 bg-zinc-50 [&_textarea]:px-4 [&_textarea]:py-3 [&_textarea]:text-sm"
                        >
                            <x-slot name="actionsTrailing" class="flex items-center justify-end gap-2">
                                @if ($hasConversation)
                                    <button
                                        type="button"
                                        wire:click="startNewConversation"
                                        data-testid="new-conversation-button"
                                        class="inline-grid size-9 place-items-center rounded-full text-zinc-400 transition hover:bg-zinc-200 hover:text-zinc-600"
                                        aria-label="{{ __('Start a new conversation') }}"
                                        title="{{ __('Start a new conversation') }}"
                                    >
                                        <flux:icon icon="arrow-path-rounded-square" class="size-4" />
                                    </button>
                                @endif

                                <flux:button
                                    type="submit"
                                    size="sm"
                                    variant="primary"
                                    :loading="false"
                                    class="inline-grid size-9 place-items-center rounded-full bg-emerald-600 p-0 text-white hover:bg-emerald-500"
                                    aria-label="{{ __('Send question') }}"
                                >
                                    <flux:icon icon="paper-airplane" class="size-4" />
                                </flux:button>
                            </x-slot>
                        </flux:composer>

                        <div class="flex flex-wrap gap-2">
                            @php
                                $chipColors = [
                                    'text-emerald-700 bg-emerald-50 hover:bg-emerald-100',
                                    'text-sky-700 bg-sky-50 hover:bg-sky-100',
                                    'text-purple-700 bg-purple-50 hover:bg-purple-100',
                                    'text-orange-700 bg-orange-50 hover:bg-orange-100',
                                ];
                            @endphp
                            @foreach ($promptChips as $index => $chip)
                                <button
                                    type="button"
                                    class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition {{ $chipColors[$index % count($chipColors)] }}"
                                    wire:click="applyPrompt(@js($chip['prompt']))"
                                >
                                    {{ $chip['label'] }}
                                </button>
                            @endforeach
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- Welcome box (right) --}}
        <div class="flex flex-col gap-6">
            <div class="flex flex-1 flex-col justify-between space-y-4 rounded-2xl border border-emerald-200/60 bg-emerald-50/30 p-5 shadow-sm">
                <div class="space-y-4">
                    <flux:heading size="lg">{{ __('Welcome to LocAlmanac') }}</flux:heading>
                    <p class="text-sm leading-relaxed text-zinc-600">
                        {{ __('Your AI-powered local information and news portal. Ask questions, browse articles, and stay informed about what\'s happening in your community.') }}
                    </p>
                    <div class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                        <span class="font-semibold">{{ __('Pilot Status:') }}</span>
                        {{ __('Live with real Wichita data! Try the AI assistant above.') }}
                    </div>
                </div>
                <div class="pt-2">
                    <button
                        type="button"
                        x-data=""
                        x-on:click="$flux.modal('site-feedback').show()"
                        class="inline-flex cursor-pointer items-center gap-1.5 text-sm font-medium text-emerald-700 transition hover:text-emerald-600"
                    >
                        <flux:icon icon="chat-bubble-left-ellipsis" class="size-4" />
                        {{ __('Let us know what you think') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Stats strip ───────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <div class="flex items-center gap-3 rounded-2xl border border-zinc-200/60 bg-white px-5 py-4 shadow-sm">
            <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-purple-50 text-purple-600">
                <flux:icon icon="map-pin" class="size-5" />
            </div>
            <div class="min-w-0">
                <div class="truncate text-base font-semibold text-purple-600">{{ $stats['locationLabel'] }}</div>
                <div class="text-xs text-zinc-500">{{ __('Your Location') }}</div>
            </div>
        </div>
        <div class="flex items-center gap-3 rounded-2xl border border-zinc-200/60 bg-white px-5 py-4 shadow-sm">
            <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-zinc-100 text-zinc-600">
                <flux:icon icon="document-text" class="size-5" />
            </div>
            <div>
                <div class="text-base font-semibold text-zinc-800">{{ $stats['totalArticles'] }}</div>
                <div class="text-xs text-zinc-500">{{ __('Total Articles') }}</div>
            </div>
        </div>
        <div class="flex items-center gap-3 rounded-2xl border border-zinc-200/60 bg-white px-5 py-4 shadow-sm">
            <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-600">
                <flux:icon icon="plus-circle" class="size-5" />
            </div>
            <div>
                <div class="text-base font-semibold text-emerald-600">{{ $stats['addedToday'] }}</div>
                <div class="text-xs text-zinc-500">{{ __('Added Today') }}</div>
            </div>
        </div>
        <div class="flex items-center gap-3 rounded-2xl border border-zinc-200/60 bg-white px-5 py-4 shadow-sm">
            <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-sky-50 text-sky-600">
                <flux:icon icon="tag" class="size-5" />
            </div>
            <div>
                <div class="text-base font-semibold text-sky-600">{{ $stats['categoryCount'] }}</div>
                <div class="text-xs text-zinc-500">{{ __('Categories') }}</div>
            </div>
        </div>
    </div>

    {{-- ── Article search & filters ──────────────────────────────── --}}
    <div class="rounded-2xl border border-zinc-200/60 bg-white p-5 shadow-sm">
        <div class="grid gap-4">
            <div class="w-full">
                <label for="article-search" class="sr-only">{{ __('Search articles') }}</label>
                <input
                    id="article-search"
                    wire:model.live.debounce.300ms="articleSearch"
                    type="search"
                    placeholder="{{ __('Search articles…') }}"
                    class="h-11 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-4 text-sm text-zinc-700 transition focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
                />
            </div>

            <div class="space-y-3">
                @if ($issueAreas->isNotEmpty())
                    @php
                        $featuredIssueAreas = $issueAreas->take(8);
                        $overflowIssueAreas = $issueAreas->slice(8);
                    @endphp
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="h-9 rounded-full px-4 text-sm font-medium transition {{ $activeIssueAreaId ? 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200' : 'bg-emerald-600 text-white shadow-sm' }}"
                            wire:click="clearIssueArea"
                        >
                            {{ __('All') }}
                        </button>
                        <div class="relative flex-1">
                            <div class="flex w-full gap-2 overflow-x-auto pb-1 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                @foreach ($featuredIssueAreas as $issueArea)
                                    <button
                                        type="button"
                                        class="h-9 shrink-0 rounded-full px-4 text-sm font-medium transition {{ $activeIssueAreaId === $issueArea->id ? 'bg-emerald-600 text-white shadow-sm' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200' }}"
                                        wire:click="selectIssueArea({{ $issueArea->id }})"
                                    >
                                        {{ $issueArea->name }}
                                    </button>
                                @endforeach
                            </div>
                            <div class="pointer-events-none absolute inset-y-0 right-0 w-12 bg-gradient-to-l from-white via-white/80 to-transparent"></div>
                        </div>
                        <div x-data="{ open: false }" class="relative">
                            <button
                                type="button"
                                class="h-9 rounded-full bg-zinc-100 px-4 text-sm font-medium text-zinc-700 transition hover:bg-zinc-200"
                                x-on:click="open = !open"
                            >
                                {{ __('More') }}
                            </button>
                            <div
                                x-cloak
                                x-show="open"
                                x-transition.opacity
                                x-on:click.away="open = false"
                                class="absolute right-0 top-12 z-10 w-[320px] rounded-2xl border border-zinc-200 bg-white p-4 shadow-xl"
                            >
                                <div class="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-400">
                                    {{ __('All Categories') }}
                                </div>
                                <div class="max-h-64 space-y-1.5 overflow-y-auto pr-1">
                                    @foreach ($issueAreas as $issueArea)
                                        <button
                                            type="button"
                                            class="flex w-full items-center rounded-lg px-3 py-2 text-sm font-medium transition {{ $activeIssueAreaId === $issueArea->id ? 'bg-emerald-600 text-white' : 'text-zinc-700 hover:bg-zinc-100' }}"
                                            wire:click="selectIssueArea({{ $issueArea->id }})"
                                            x-on:click="open = false"
                                        >
                                            <span class="truncate">{{ $issueArea->name }}</span>
                                        </button>
                                    @endforeach
                                </div>
                                @if ($activeIssueAreaId)
                                    <button
                                        type="button"
                                        class="mt-3 w-full rounded-lg px-3 py-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 transition hover:bg-zinc-100"
                                        wire:click="clearIssueArea"
                                        x-on:click="open = false"
                                    >
                                        {{ __('Clear category') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @else
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($articleFallbackChips as $chip)
                            <button
                                type="button"
                                class="h-9 rounded-full px-4 text-sm font-medium transition {{ strcasecmp(trim($articleSearch), trim($chip)) === 0 ? 'bg-emerald-600 text-white shadow-sm' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200' }}"
                                wire:click="useArticleFilterChip(@js($chip))"
                            >
                                {{ $chip }}
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Feed + sidebar ────────────────────────────────────────── --}}
    <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <div id="dashboard-feed" class="space-y-6 rounded-2xl border border-zinc-200/60 bg-white p-6 shadow-sm">
            <flux:heading size="lg">
                {{ __('Your :city Feed', ['city' => $city?->name ?? __('City')]) }}
            </flux:heading>

            <div class="divide-y divide-zinc-100">
                @forelse ($articles as $article)
                    @php
                        $articleTime = $article->published_at ?? $article->created_at;
                        $articleDisplayTime = $articleTime?->clone()->tz($timezone);
                        $articleTimeLabel = $articleDisplayTime?->format('M j, Y');
                        $sourceLabel = $article->scraper?->organization?->name
                            ?? parse_url($article->primarySourceUrl() ?? '', PHP_URL_HOST)
                            ?? __('Source');
                        $issueAreaBadges = $article->articleIssueAreas
                            ->map(fn ($item) => $item->issueArea?->name)
                            ->filter()
                            ->take(2);
                        $summary = $article->summary ?: __('No summary available yet.');
                    @endphp
                    <div class="space-y-2 py-5 first:pt-0 last:pb-0">
                        <a
                            href="{{ route('articles.show', $article) }}"
                            class="text-sm font-semibold text-zinc-900 transition hover:text-emerald-600"
                        >
                            {{ $article->title ?? __('Untitled') }}
                        </a>
                        <p class="text-sm leading-relaxed text-zinc-600">
                            {{ \Illuminate\Support\Str::limit($summary, 360) }}
                        </p>
                        <div class="flex flex-wrap items-center gap-3 text-xs text-zinc-400">
                            @if ($articleTimeLabel)
                                <span>{{ $articleTimeLabel }}</span>
                            @endif
                            <span>{{ $sourceLabel }}</span>
                            @foreach ($issueAreaBadges as $badge)
                                <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-zinc-500">{{ $badge }}</span>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <flux:text variant="subtle">{{ __('No articles yet.') }}</flux:text>
                @endforelse
            </div>

            @if ($articles->hasPages())
                <div class="pt-2">
                    <flux:pagination :paginator="$articles" scroll-to="#dashboard-feed" />
                </div>
            @endif
        </div>

        <div class="space-y-6">
            {{-- Calendar highlights --}}
            <div class="space-y-4 rounded-2xl border border-zinc-200/60 bg-white p-5 shadow-sm">
                <div class="flex items-center gap-2">
                    <flux:icon icon="calendar-days" class="size-5 text-emerald-600" />
                    <flux:heading size="lg">{{ __('Events Calendar') }}</flux:heading>
                </div>

                @forelse ($upcomingEvents as $event)
                    @php
                        $eventStart = $event->starts_at?->clone()->tz($timezone);
                        $eventEnd = $event->ends_at?->clone()->tz($timezone);
                        $eventDateLabel = $eventStart?->format('M j');
                        $eventTimeRange = null;
                        if (! $event->all_day && $eventStart && $eventEnd) {
                            $eventTimeRange = $eventStart->format('g:i A') . ' - ' . $eventEnd->format('g:i A');
                        } elseif (! $event->all_day && $eventStart) {
                            $eventTimeRange = $eventStart->format('g:i A');
                        }
                    @endphp
                    <div class="border-l-2 border-emerald-500 py-1 pl-3">
                        <div class="text-sm font-semibold text-emerald-700">
                            @if ($event->event_url)
                                <a
                                    href="{{ $event->event_url }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="transition hover:text-emerald-600"
                                >
                                    {{ $event->title }}
                                </a>
                            @else
                                {{ $event->title }}
                            @endif
                        </div>
                        <div class="text-xs text-zinc-500">
                            {{ $eventDateLabel }}@if ($eventTimeRange) &middot; {{ $eventTimeRange }}@endif
                        </div>
                        @if ($event->location_name)
                            <div class="text-xs text-zinc-400">{{ $event->location_name }}</div>
                        @endif
                    </div>
                @empty
                    <flux:text variant="subtle">{{ __('No upcoming events.') }}</flux:text>
                @endforelse

                <a
                    href="{{ route('demo.calendar') }}"
                    class="inline-flex items-center gap-1 text-sm font-semibold uppercase tracking-wide text-zinc-700 transition hover:text-emerald-600"
                    wire:navigate
                >
                    {{ __('Full Calendar') }}
                </a>
            </div>

            {{-- Browse by Category --}}
            <div class="space-y-4 rounded-2xl border border-zinc-200/60 bg-white p-5 shadow-sm">
                <flux:heading size="lg">{{ __('Browse by Category') }}</flux:heading>
                <p class="text-sm text-zinc-500">
                    {{ __('Select a category to filter the article feed.') }}
                </p>
                <div class="space-y-1">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm font-medium transition {{ $activeIssueAreaId === null ? 'bg-emerald-600 text-white' : 'text-zinc-700 hover:bg-zinc-100' }}"
                        wire:click="clearIssueArea"
                        aria-pressed="{{ $activeIssueAreaId === null ? 'true' : 'false' }}"
                    >
                        <span class="size-1.5 rounded-full {{ $activeIssueAreaId === null ? 'bg-white' : 'bg-emerald-500' }}"></span>
                        <span>{{ __('All categories') }}</span>
                    </button>

                    @forelse ($issueAreas as $issueArea)
                        <button
                            type="button"
                            class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm font-medium transition {{ $activeIssueAreaId === $issueArea->id ? 'bg-emerald-600 text-white' : 'text-zinc-700 hover:bg-zinc-100' }}"
                            wire:click="selectIssueArea({{ $issueArea->id }})"
                            aria-pressed="{{ $activeIssueAreaId === $issueArea->id ? 'true' : 'false' }}"
                        >
                            <span class="size-1.5 rounded-full {{ $activeIssueAreaId === $issueArea->id ? 'bg-white' : 'bg-emerald-500' }}"></span>
                            <span>{{ $issueArea->name }}</span>
                        </button>
                    @empty
                        <flux:text variant="subtle">{{ __('No categories yet.') }}</flux:text>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
