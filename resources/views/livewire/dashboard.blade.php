<div class="space-y-8">
    <flux:card padding="xl" class="space-y-6 rounded-2xl border border-zinc-200 bg-gradient-to-br from-white via-white to-emerald-50/30 shadow-sm">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="space-y-2">
                <flux:heading size="lg" level="1">
                    {{ __('Ask LocAlmanac About :city', ['city' => $city?->name ?? __('your city')]) }}
                </flux:heading>
                <flux:subheading>
                    {{ __('Get answers on local services, meetings, and community updates.') }}
                </flux:subheading>
            </div>
            <div class="rounded-full border border-emerald-200 bg-emerald-50 px-4 py-2 text-xs font-semibold text-emerald-700">
                {{ __('Powered by verified sources') }}
            </div>
        </div>

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
            class="space-y-4 rounded-2xl border border-zinc-200 bg-white/80 p-4 shadow-sm backdrop-blur"
        >
            <div
                x-ref="chatScroll"
                x-show="hasMessages || pendingQuestion"
                x-cloak
                class="max-h-[360px] space-y-4 overflow-y-auto pr-1"
                x-on:chat-updated.window="hasMessages = true; pendingQuestion = ''; queueScrollToBottom()"
            >
                @foreach ($messages as $message)
                    @php
                        $isUser = $message['role'] === 'user';
                        $bubbleClasses = $isUser
                            ? 'border-sky-200 bg-sky-50 text-zinc-800'
                            : 'border-zinc-200 bg-white text-zinc-900';
                        $bubbleMaxWidth = $isUser ? 'max-w-2xl' : 'max-w-3xl';
                    @endphp
                    <div class="flex w-full {{ $isUser ? 'justify-end' : 'justify-start' }}">
                        <div class="{{ $bubbleMaxWidth }} w-full space-y-2 rounded-2xl border px-4 py-3 shadow-sm {{ $bubbleClasses }}">
                            <flux:badge
                                color="{{ $isUser ? 'sky' : 'emerald' }}"
                                variant="subtle"
                                class="w-fit"
                            >
                                {{ $isUser ? __('You') : __('Assistant') }}
                            </flux:badge>

                            <flux:text class="whitespace-pre-wrap">{{ $message['content'] }}</flux:text>

                            @if (! empty($message['citations']))
                                <div class="border-t border-zinc-100 pt-2">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                    @foreach ($message['citations'] as $citation)
                                            <a
                                                href="{{ $citation['source_url'] }}"
                                                target="_blank"
                                                class="inline-flex items-center gap-1 rounded-md border border-zinc-200 bg-zinc-50 px-2 py-1 text-xs font-medium text-zinc-500 transition hover:border-zinc-300 hover:bg-zinc-100 hover:text-zinc-700"
                                            >
                                                <flux:icon icon="arrow-top-right-on-square" class="size-3" />
                                                <span>{{ \Illuminate\Support\Str::limit($citation['title'], 36) }}</span>
                                            </a>
                                    @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach

                <div wire:loading.block wire:target="ask" class="w-full space-y-3">
                    <div x-show="pendingQuestion" x-cloak class="flex w-full justify-end">
                        <div class="w-full max-w-2xl space-y-2 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 shadow-sm text-zinc-800">
                            <flux:badge color="sky" variant="subtle" class="w-fit">{{ __('You') }}</flux:badge>
                            <flux:text class="whitespace-pre-wrap" x-text="pendingQuestion"></flux:text>
                        </div>
                    </div>

                    <div class="flex w-full justify-start">
                        <div class="w-full max-w-2xl space-y-2 rounded-2xl border border-zinc-200 bg-white px-4 py-3 shadow-sm">
                            <flux:badge color="emerald" variant="subtle" class="w-fit">{{ __('Assistant') }}</flux:badge>
                            <div class="flex items-center gap-3 text-sm text-zinc-500" role="status" aria-live="polite">
                                <div class="flex items-center gap-1">
                                    <span class="h-2 w-2 animate-bounce rounded-full bg-emerald-400 [animation-delay:-0.24s] motion-reduce:animate-none"></span>
                                    <span class="h-2 w-2 animate-bounce rounded-full bg-emerald-400 [animation-delay:-0.12s] motion-reduce:animate-none"></span>
                                    <span class="h-2 w-2 animate-bounce rounded-full bg-emerald-400 motion-reduce:animate-none"></span>
                                </div>
                                <span>{{ __('Thinking…') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div @class([
                'pt-4',
                'border-t border-zinc-200' => count($messages) > 0,
            ])>
                <form
                    wire:submit.prevent="ask"
                    x-on:submit="pendingQuestion = ($refs.chatComposer?.querySelector('textarea')?.value ?? '').trim(); if (pendingQuestion) { hasMessages = true; setTimeout(() => { const input = $refs.chatComposer?.querySelector('textarea'); if (input) { input.value = ''; } }, 0); } queueScrollToBottom();"
                    class="space-y-4"
                >
                    <flux:composer
                        x-ref="chatComposer"
                        wire:model.defer="question"
                        placeholder="{{ __('How much does a garage sale permit cost? How do I report a water leak?') }}"
                        rows="1"
                        max-rows="2"
                        submit="enter"
                        class="border-zinc-200 bg-white shadow-sm [&_textarea]:px-5 [&_textarea]:py-4 [&_textarea]:text-base"
                    >
                        <x-slot name="actionsTrailing" class="flex items-center justify-end gap-2">
                            <flux:button
                                type="submit"
                                size="sm"
                                variant="primary"
                                class="h-10 w-10 rounded-full bg-emerald-600 p-0 text-white shadow-sm hover:bg-emerald-500 inline-grid place-items-center"
                                wire:loading.attr="disabled"
                                aria-label="{{ __('Send question') }}"
                            >
                                <flux:icon icon="paper-airplane" class="size-4" />
                                <span wire:loading class="sr-only">{{ __('Thinking...') }}</span>
                            </flux:button>
                        </x-slot>
                    </flux:composer>

                    <div class="flex flex-wrap gap-2">
                        @php
                            $chipStyles = [
                                'bg-emerald-50 text-emerald-700 border-emerald-200',
                                'bg-sky-50 text-sky-700 border-sky-200',
                                'bg-purple-50 text-purple-700 border-purple-200',
                                'bg-orange-50 text-orange-700 border-orange-200',
                            ];
                        @endphp
                        @foreach ($promptChips as $index => $chip)
                            <button
                                type="button"
                                class="h-9 rounded-full border px-4 text-xs font-semibold transition hover:-translate-y-0.5 hover:shadow-sm {{ $chipStyles[$index % count($chipStyles)] }}"
                                wire:click="applyPrompt(@js($chip))"
                            >
                                {{ $chip }}
                            </button>
                        @endforeach
                    </div>
                </form>
            </div>
        </div>
    </flux:card>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <flux:card padding="lg" class="text-center rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <flux:heading size="xl">{{ $stats['totalArticles'] }}</flux:heading>
            <flux:subheading>{{ __('Total Articles') }}</flux:subheading>
        </flux:card>
        <flux:card padding="lg" class="text-center rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <flux:heading size="xl" class="text-emerald-600">{{ $stats['addedToday'] }}</flux:heading>
            <flux:subheading>{{ __('Added Today') }}</flux:subheading>
        </flux:card>
        <flux:card padding="lg" class="text-center rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <flux:heading size="xl" class="text-blue-600">{{ $stats['categoryCount'] }}</flux:heading>
            <flux:subheading>{{ __('Categories') }}</flux:subheading>
        </flux:card>
        <flux:card padding="lg" class="text-center rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <flux:heading size="xl" class="text-purple-600">{{ $stats['locationLabel'] }}</flux:heading>
            <flux:subheading>{{ __('Your Location') }}</flux:subheading>
        </flux:card>
    </div>

    <flux:card padding="lg" class="rounded-2xl border border-zinc-200 bg-white shadow-sm">
        <div class="grid gap-4">
            <div class="w-full">
                <label for="article-search" class="sr-only">{{ __('Search articles') }}</label>
                <input
                    id="article-search"
                    wire:model.live.debounce.300ms="articleSearch"
                    type="search"
                    placeholder="{{ __('Search articles...') }}"
                    class="h-12 w-full rounded-xl border border-zinc-200 bg-white px-5 text-base text-zinc-700 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-4 focus:ring-emerald-100"
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
                            class="h-10 rounded-full border px-5 text-sm font-semibold transition {{ $activeIssueAreaId ? 'border-zinc-200 bg-zinc-50 text-zinc-700 hover:bg-zinc-100' : 'border-emerald-600 bg-emerald-600 text-white shadow-sm' }}"
                            wire:click="clearIssueArea"
                        >
                            {{ __('All') }}
                        </button>
                        <div class="relative flex-1">
                            <div class="flex w-full gap-2 overflow-x-auto pb-1 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                @foreach ($featuredIssueAreas as $issueArea)
                                    <button
                                        type="button"
                                        class="h-10 shrink-0 rounded-full border px-5 text-sm font-semibold transition {{ $activeIssueAreaId === $issueArea->id ? 'border-emerald-600 bg-emerald-600 text-white shadow-sm' : 'border-zinc-200 bg-white text-zinc-700 hover:bg-zinc-100' }}"
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
                                class="h-10 rounded-full border px-5 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-100"
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
                                <div class="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                    {{ __('All Categories') }}
                                </div>
                                <div class="max-h-64 space-y-2 overflow-y-auto pr-1">
                                    @foreach ($issueAreas as $issueArea)
                                        <button
                                            type="button"
                                            class="flex w-full items-center justify-between rounded-xl border px-3 py-2 text-sm font-semibold transition {{ $activeIssueAreaId === $issueArea->id ? 'border-emerald-600 bg-emerald-600 text-white shadow-sm' : 'border-zinc-200 bg-white text-zinc-700 hover:bg-zinc-100' }}"
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
                                        class="mt-3 w-full rounded-xl border border-zinc-200 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-zinc-600 transition hover:bg-zinc-100"
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
                        @foreach ($promptChips as $chip)
                            <button
                                type="button"
                                class="h-10 rounded-full border px-5 text-sm font-semibold transition {{ strcasecmp(trim($articleSearch), trim($chip)) === 0 ? 'border-emerald-600 bg-emerald-600 text-white shadow-sm' : 'border-zinc-200 bg-zinc-50 text-zinc-700 hover:bg-zinc-100' }}"
                                wire:click="useArticleFilterChip(@js($chip))"
                            >
                                {{ $chip }}
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </flux:card>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <flux:card padding="xl" class="space-y-6 rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">
                    {{ __('Your :city Feed', ['city' => $city?->name ?? __('City')]) }}
                </flux:heading>
            </div>

            <div class="space-y-5">
                @forelse ($articles as $article)
                    @php
                        $articleTime = $article->published_at ?? $article->created_at;
                        $articleTimeLabel = $articleTime?->clone()->tz($timezone)->diffForHumans();
                        $sourceLabel = $article->scraper?->organization?->name
                            ?? parse_url($article->primarySourceUrl() ?? '', PHP_URL_HOST)
                            ?? __('Source');
                        $issueAreaBadges = $article->articleIssueAreas
                            ->map(fn ($item) => $item->issueArea?->name)
                            ->filter()
                            ->take(2);
                        $summary = $article->summary ?: __('No summary available yet.');
                    @endphp
                    <div class="space-y-2 border-b border-zinc-200 pb-5 last:border-b-0 last:pb-0">
                        <flux:heading size="sm" class="text-zinc-900">
                            <a
                                href="{{ route('articles.show', $article) }}"
                                class="transition hover:text-emerald-600"
                            >
                                {{ $article->title ?? __('Untitled') }}
                            </a>
                        </flux:heading>
                        <flux:text class="text-sm text-zinc-600">
                            {{ \Illuminate\Support\Str::limit($summary, 360) }}
                        </flux:text>
                        <div class="flex flex-wrap items-center gap-3 text-xs text-zinc-500">
                            @if ($articleTimeLabel)
                                <span>{{ $articleTimeLabel }}</span>
                            @endif
                            <span>{{ $sourceLabel }}</span>
                            @foreach ($issueAreaBadges as $badge)
                                <flux:badge color="zinc" variant="subtle">{{ $badge }}</flux:badge>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <flux:text variant="subtle">{{ __('No articles yet.') }}</flux:text>
                @endforelse
            </div>
        </flux:card>

        <div class="space-y-6">
            <flux:card padding="lg" class="space-y-4 rounded-2xl border border-zinc-200 bg-white shadow-sm">
                <flux:heading size="lg">{{ __('Welcome to LocAlmanac') }}</flux:heading>
                <flux:text class="text-sm text-zinc-600">
                    {{ __('Your AI-powered local news portal. Ask questions, browse articles, and stay informed about what\'s happening in your community.') }}
                </flux:text>
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    <strong>{{ __('Pilot Status:') }}</strong>
                    {{ __('Live with real Wichita data! Try the AI assistant above.') }}
                </div>
            </flux:card>

            <flux:card padding="lg" class="space-y-4 rounded-2xl border border-zinc-200 bg-white shadow-sm">
                <flux:heading size="lg">{{ __('Browse by Category') }}</flux:heading>
                <div class="space-y-2">
                    @forelse ($issueAreas as $issueArea)
                        <div class="flex items-center gap-2 text-sm text-zinc-700">
                            <span class="text-emerald-600">•</span>
                            <span>{{ $issueArea->name }}</span>
                        </div>
                    @empty
                        <flux:text variant="subtle">{{ __('No categories yet.') }}</flux:text>
                    @endforelse
                </div>
            </flux:card>
        </div>
    </div>
</div>
