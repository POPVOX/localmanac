<div class="space-y-6 lg:space-y-8">
    {{-- ── Chat + Welcome row ────────────────────────────────────── --}}
    @php
        $hasConversation = $conversationId || count($messages) > 0;
        $upcomingEvents = $upcomingEvents ?? collect();
        $calendarRouteName = $adminPreview && $city
            ? 'admin.cities.calendar'
            : ($city ? 'cities.calendar' : 'demo.calendar');
        $calendarRouteParameters = $city ? ['city' => $city] : [];
        $calendarUrl = route($calendarRouteName, $calendarRouteParameters);
    @endphp

    <section class="public-masthead relative">
        <div class="pointer-events-none absolute -right-16 -top-24 size-72 rounded-full bg-[#dcebe3]/80 blur-3xl"></div>
        <div class="relative grid gap-7 lg:grid-cols-[1fr_auto] lg:items-end">
            <div>
                <div class="editorial-eyebrow">{{ __('Local briefing') }}</div>
                <h1 class="mt-3 font-serif text-4xl font-medium tracking-[-0.035em] text-[#123e32] sm:text-5xl">
                    {{ $city?->name ?? __('Your city') }}@if ($city?->state)<span class="text-[#718078]">, {{ $city->state }}</span>@endif
                </h1>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-[#5d7168] sm:text-base">
                    {{ __('Reporting, public information, and upcoming events gathered for this community.') }}
                </p>
            </div>

            <button
                type="button"
                x-data=""
                x-on:click="$flux.modal('site-feedback').show()"
                class="inline-flex w-fit cursor-pointer items-center gap-2 text-sm font-semibold text-[#1f654f] transition hover:text-[#123e32]"
            >
                <flux:icon icon="chat-bubble-left-ellipsis" class="size-4" />
                {{ __('Share feedback') }}
            </button>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        {{-- Query box (left) --}}
        <div @class([
            'public-panel overflow-hidden',
            'bg-white' => $canUseChat,
            'bg-[#eef0ec]' => ! $canUseChat,
        ])>
            {{-- Header --}}
            <div class="border-b border-[#e4e2da] px-5 py-5 sm:px-6">
                <flux:heading size="lg" level="1">
                    {{ __('Ask LocAlmanac About :city', ['city' => $city?->name ?? __('your city')]) }}
                </flux:heading>
                <flux:subheading class="mt-0.5">
                    {{ $canUseChat
                        ? __('Get answers on local services, meetings, and community updates.')
                        : __('Chat access is available to verified members of this city.') }}
                </flux:subheading>
            </div>

            @if ($canUseChat)
            {{-- Conversation body --}}
            <div
                x-data="{
                    pendingQuestion: '',
                    hasMessages: @js(count($messages) > 0),
                    pageScrollTop: window.scrollY,
                    scrollTimers: [],
                    clearScrollTimers() {
                        this.scrollTimers.forEach((timer) => clearTimeout(timer));
                        this.scrollTimers = [];
                    },
                    scrollToBottom() {
                        this.$nextTick(() => {
                            const container = this.$refs.chatScroll;
                            if (container) {
                                container.scrollTop = container.scrollHeight;
                            }
                        });
                    },
                    scrollToLatestAssistantStart() {
                        this.$nextTick(() => {
                            const container = this.$refs.chatScroll;
                            if (! container) {
                                return;
                            }

                            const assistantMessages = container.querySelectorAll('[data-chat-role=assistant]');
                            const latestAssistant = assistantMessages[assistantMessages.length - 1];

                            if (! latestAssistant) {
                                this.scrollToBottom();

                                return;
                            }

                            const delta = latestAssistant.getBoundingClientRect().top - container.getBoundingClientRect().top;
                            container.scrollTop = Math.max(container.scrollTop + delta - 8, 0);
                            window.scrollTo({ top: this.pageScrollTop, behavior: 'instant' });
                        });
                    },
                    handleSubmit() {
                        this.pageScrollTop = window.scrollY;
                        this.pendingQuestion = ($refs.chatComposer?.querySelector('textarea')?.value ?? '').trim();

                        if (! this.pendingQuestion) {
                            return;
                        }

                        this.hasMessages = true;
                        this.queueScrollToBottom();

                        setTimeout(() => {
                            const input = this.$refs.chatComposer?.querySelector('textarea');

                            if (input) {
                                input.value = '';
                            }
                        }, 0);
                    },
                    queueScrollToBottom() {
                        this.clearScrollTimers();
                        [0, 60, 140, 260, 420].forEach((delay) => {
                            this.scrollTimers.push(setTimeout(() => this.scrollToBottom(), delay));
                        });
                    },
                    queueScrollToLatestAssistantStart() {
                        this.clearScrollTimers();
                        [0, 60, 140, 260, 420].forEach((delay) => {
                            this.scrollTimers.push(setTimeout(() => this.scrollToLatestAssistantStart(), delay));
                        });
                    },
                }"
                x-init="if (hasMessages) { queueScrollToLatestAssistantStart() }"
                class="flex flex-col"
            >

                {{-- Message thread --}}
                <div
                    x-ref="chatScroll"
                    x-show="hasMessages || pendingQuestion"
                    x-cloak
                    class="max-h-[320px] space-y-1 overflow-y-auto px-6 py-5"
                    x-on:chat-updated.window="hasMessages = true; pendingQuestion = ''; queueScrollToLatestAssistantStart()"
                    x-on:chat-reset.window="hasMessages = false; pendingQuestion = ''"
                >
                    @foreach ($messages as $index => $message)
                        @php
                            $messageId = (string) ($message['id'] ?? $message['role'].'-'.$index);
                            $isUser = $message['role'] === 'user';
                            $assistantContent = null;

                            if (! $isUser) {
                                $assistantContent = \Illuminate\Support\Str::markdown((string) ($message['content'] ?? ''), [
                                    'html_input' => 'strip',
                                    'allow_unsafe_links' => false,
                                ]);

                                $assistantContent = preg_replace(
                                    '/<a\s+/i',
                                    '<a target="_blank" rel="noopener noreferrer" ',
                                    $assistantContent ?? ''
                                ) ?? '';
                            }
                        @endphp
                        <div
                            wire:key="chat-message-{{ $messageId }}"
                            data-chat-message-id="{{ $messageId }}"
                            data-chat-role="{{ $message['role'] }}"
                            class="flex w-full {{ $isUser ? 'justify-end' : 'justify-start' }} py-1.5"
                        >
                            <div @class([
                                'max-w-[85%] space-y-2 rounded-2xl px-4 py-3',
                                'bg-emerald-600 text-white' => $isUser,
                                'bg-zinc-100 text-zinc-900' => ! $isUser,
                            ])>
                                @if ($isUser)
                                    <div class="whitespace-pre-wrap text-sm leading-relaxed">{{ $message['content'] }}</div>
                                @else
                                    <div class="max-w-none text-sm leading-relaxed text-zinc-900 [&_p+p]:mt-3 [&_ul]:mt-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ul+p]:mt-3 [&_ol]:mt-2 [&_ol]:list-decimal [&_ol]:pl-5 [&_ol+p]:mt-3 [&_li+li]:mt-1 [&_h1]:mt-3 [&_h1]:text-base [&_h1]:font-semibold [&_h2]:mt-3 [&_h2]:text-sm [&_h2]:font-semibold [&_h3]:mt-2 [&_h3]:text-sm [&_h3]:font-semibold [&_a]:font-semibold [&_a]:text-emerald-600 [&_a]:underline [&_a]:decoration-emerald-600 [&_a]:underline-offset-2 hover:[&_a]:text-emerald-500">
                                        {!! $assistantContent !!}
                                    </div>
                                @endif

                                @if (! empty($message['citations']))
                                    <div @class([
                                        'flex flex-wrap items-center gap-1.5 border-t pt-2',
                                        'border-emerald-500/40' => $isUser,
                                        'border-zinc-200' => ! $isUser,
                                    ])>
                                        @foreach ($message['citations'] as $citationIndex => $citation)
                                            <a
                                                wire:key="chat-citation-{{ $messageId }}-{{ $citationIndex }}"
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
                        x-on:submit="handleSubmit()"
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
            @else
                <div class="bg-[#eef0ec] px-6 py-8" data-testid="chat-locked">
                    <div class="mx-auto max-w-lg space-y-4 text-center">
                        <div class="mx-auto grid size-12 place-items-center rounded-xl bg-white text-[#667970] ring-1 ring-[#d9d7ce]">
                            <flux:icon icon="lock-closed" class="size-5" />
                        </div>
                        <div>
                            <div class="font-semibold text-zinc-700">{{ __('Chat is locked for :city', ['city' => $city?->name ?? __('this city')]) }}</div>
                            <p class="mt-1 text-sm text-zinc-500">
                                {{ __('The news feed and calendar are public. A city access code is required only for the AI assistant.') }}
                            </p>
                        </div>

                        @guest
                            <div class="flex flex-wrap justify-center gap-2">
                                <flux:button :href="route('login')" variant="primary" wire:navigate>
                                    {{ __('Log in') }}
                                </flux:button>
                                @if (Route::has('register'))
                                    <flux:button :href="route('register')" variant="subtle" wire:navigate>
                                        {{ __('Create account with a code') }}
                                    </flux:button>
                                @endif
                            </div>
                        @else
                            @if ($chatAccessConfigured)
                                <form wire:submit.prevent="unlockCityChat" class="mx-auto flex max-w-md flex-col gap-3 sm:flex-row sm:items-start">
                                    <div class="min-w-0 flex-1 text-left">
                                        <flux:input
                                            wire:model="cityAccessCode"
                                            type="text"
                                            autocomplete="off"
                                            :label="__('City access code')"
                                            :placeholder="__('Enter code')"
                                        />
                                    </div>
                                    <flux:button type="submit" variant="primary" class="sm:mt-6" wire:loading.attr="disabled">
                                        {{ __('Unlock chat') }}
                                    </flux:button>
                                </form>
                            @else
                                <p class="text-sm font-medium text-zinc-500">
                                    {{ __('Chat access has not been opened for this city yet.') }}
                                </p>
                            @endif
                        @endguest
                    </div>
                </div>
            @endif
        </div>

        {{-- Welcome box (right) --}}
        <div class="flex flex-col gap-6">
            <div class="public-panel-muted flex flex-1 flex-col justify-between space-y-4 p-5">
                <div class="space-y-4">
                    <div class="editorial-eyebrow">{{ __('About this briefing') }}</div>
                    <flux:heading size="lg">{{ __('Made for :city', ['city' => $city?->name ?? __('your city')]) }}</flux:heading>
                    <p class="text-sm leading-relaxed text-zinc-600">
                        {{ __('Browse the public feed and calendar, then use the local assistant when you need a focused answer.') }}
                    </p>
                    <div class="border-l-2 border-[#1f654f] py-1 pl-3 text-sm text-[#1f654f]">
                        <span class="font-semibold">{{ __('Local coverage:') }}</span>
                        {{ __('Viewing current news and events for :city.', ['city' => $city?->name ?? __('this city')]) }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Stats strip ───────────────────────────────────────────── --}}
    <div class="public-panel metric-strip overflow-hidden">
        @foreach ([
            [__('Location'), $stats['locationLabel']],
            [__('Total articles'), $stats['totalArticles']],
            [__('Added today'), $stats['addedToday']],
            [__('Categories'), $stats['categoryCount']],
        ] as $metric)
            <div class="metric-item">
                <div class="metric-value">{{ $metric[1] }}</div>
                <div class="metric-label">{{ $metric[0] }}</div>
            </div>
        @endforeach
    </div>

    {{-- ── Article search & filters ──────────────────────────────── --}}
    <div class="public-panel p-4 sm:p-5">
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
        <div id="dashboard-feed" class="public-panel space-y-6 p-5 sm:p-7">
            <flux:heading size="lg" class="font-serif !text-2xl !font-medium tracking-[-0.02em] text-[#123e32]">
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
                    <article class="space-y-2.5 py-6 first:pt-0 last:pb-0">
                        <a
                            href="{{ route('articles.show', $article) }}"
                            class="font-serif text-lg font-medium leading-snug tracking-[-0.015em] text-[#18342c] transition hover:text-[#1f654f]"
                        >
                            {{ $article->title ?? __('Untitled') }}
                        </a>
                        <p class="text-sm leading-6 text-[#5d7168]">
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
                    </article>
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
            <div class="public-panel space-y-4 p-5">
                <a
                    href="{{ $calendarUrl }}"
                    wire:navigate
                    data-testid="events-calendar-link"
                    class="group flex items-center gap-2 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
                >
                    <flux:icon icon="calendar-days" class="size-5 text-emerald-600" />
                    <flux:heading size="lg" class="transition group-hover:text-emerald-700">{{ __('Events Calendar') }}</flux:heading>
                    <flux:icon icon="arrow-right" class="ml-auto size-4 text-emerald-600 transition group-hover:translate-x-0.5" />
                </a>

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
                        $eventSourceUrl = $event->publicUrl();
                        $eventUrl = $eventSourceUrl ?: route($calendarRouteName, [
                            ...$calendarRouteParameters,
                            'date' => $eventStart?->toDateString(),
                        ]);
                    @endphp
                    <a
                        href="{{ $eventUrl }}"
                        data-testid="dashboard-event-link-{{ $event->id }}"
                        @if ($eventSourceUrl)
                            target="_blank"
                            rel="noopener noreferrer"
                        @else
                            wire:navigate
                        @endif
                        class="group block rounded-r-lg border-l-2 border-emerald-500 py-2 pl-3 pr-2 transition hover:bg-emerald-50/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
                    >
                        <div class="text-sm font-semibold text-emerald-700 transition group-hover:text-emerald-600">
                            {{ $event->title }}
                        </div>
                        <div class="text-xs text-zinc-500">
                            {{ $eventDateLabel }}@if ($eventTimeRange) &middot; {{ $eventTimeRange }}@endif
                        </div>
                        @if ($event->location_name)
                            <div class="text-xs text-zinc-400">{{ $event->location_name }}</div>
                        @endif
                    </a>
                @empty
                    <flux:text variant="subtle">{{ __('No upcoming events.') }}</flux:text>
                @endforelse

                <a
                    href="{{ $calendarUrl }}"
                    class="inline-flex items-center gap-1 text-sm font-semibold uppercase tracking-wide text-zinc-700 transition hover:text-emerald-600"
                    wire:navigate
                >
                    {{ __('Full Calendar') }}
                </a>
            </div>

            {{-- Browse by Category --}}
            <div class="public-panel space-y-4 p-5">
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
