<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-[radial-gradient(circle_at_20%_-10%,_#f5efe3_0%,_#f8f5ef_35%,_#f4f2ec_70%,_#eeebe4_100%)] text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <div class="relative overflow-x-clip">
            @php
                $heroImagePath = null;

                foreach (['images/people.png', 'images/people.jpg', 'images/people.jpeg', 'images/people.webp'] as $candidatePath) {
                    if (file_exists(public_path($candidatePath))) {
                        $heroImagePath = $candidatePath;
                        break;
                    }
                }
            @endphp

            <div class="pointer-events-none absolute -left-28 top-0 h-80 w-80 rounded-full bg-emerald-200/30 blur-3xl"></div>
            <div class="pointer-events-none absolute -right-24 top-20 h-72 w-72 rounded-full bg-amber-200/35 blur-3xl"></div>

            <header class="mx-auto flex w-full max-w-7xl items-center justify-between px-6 pt-8 sm:px-8 lg:px-12">
                <a href="{{ route('home') }}" class="inline-flex items-center" wire:navigate>
                    <x-app-logo-icon class="h-16 w-auto" />
                </a>

                <nav class="flex items-center gap-3 text-sm font-semibold text-zinc-700">
                    @guest
                        @if (Route::has('login'))
                            <a
                                href="{{ route('login') }}"
                                wire:navigate
                                class="rounded-full px-4 py-2 transition hover:bg-white/70 hover:text-zinc-900"
                            >
                                {{ __('Log in') }}
                            </a>
                        @endif
                        @if (Route::has('register'))
                            <a
                                href="{{ route('register') }}"
                                wire:navigate
                                class="rounded-full border border-emerald-200 bg-white/80 px-4 py-2 text-emerald-700 shadow-sm transition hover:-translate-y-0.5 hover:shadow"
                            >
                                {{ __('Create account') }}
                            </a>
                        @endif
                    @else
                        <a
                            href="{{ route('dashboard') }}"
                            wire:navigate
                            class="rounded-full border border-emerald-200 bg-white/80 px-4 py-2 text-emerald-700 shadow-sm transition hover:-translate-y-0.5 hover:shadow"
                        >
                            {{ __('Dashboard') }}
                        </a>
                    @endguest
                </nav>
            </header>

            <main class="mx-auto flex w-full max-w-7xl flex-col gap-16 px-6 pb-16 pt-10 sm:px-8 lg:px-12 lg:pt-14">
                <section class="grid gap-8 lg:grid-cols-[1fr_0.95fr] lg:items-center">
                    <div class="flex flex-col gap-6">
                        <flux:text class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-800">
                            {{ __('Built for civic clarity') }}
                        </flux:text>

                        <flux:heading size="xl" level="1" class="max-w-2xl text-balance font-serif text-4xl leading-tight text-green-900 sm:text-5xl">
                            {{ __('LocAlmanac helps your community keep up with what matters locally.') }}
                        </flux:heading>

                        <flux:text class="max-w-xl text-base leading-relaxed text-zinc-700 sm:text-lg">
                            {{ __('Meetings, notices, and local events with clear context on impact, timing, and where to participate.') }}
                        </flux:text>

                        <div class="grid max-w-xl gap-3 sm:grid-cols-3">
                            <div class="rounded-xl border border-zinc-200/70 bg-zinc-50/80 px-3 py-3">
                                <flux:text class="text-sm font-semibold text-zinc-800">{{ __('Local information') }}</flux:text>
                                <flux:text variant="subtle" class="mt-1 text-xs">{{ __('Public resources') }}</flux:text>
                            </div>
                            <div class="rounded-xl border border-zinc-200/70 bg-zinc-50/80 px-3 py-3">
                                <flux:text class="text-sm font-semibold text-zinc-800">{{ __('Local news') }}</flux:text>
                                <flux:text variant="subtle" class="mt-1 text-xs">{{ __('Relevant updates') }}</flux:text>
                            </div>
                            <div class="rounded-xl border border-zinc-200/70 bg-zinc-50/80 px-3 py-3">
                                <flux:text class="text-sm font-semibold text-zinc-800">{{ __('Local data') }}</flux:text>
                                <flux:text variant="subtle" class="mt-1 text-xs">{{ __('Clear context') }}</flux:text>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-3 pt-1">
                            @guest
                                @if (Route::has('register'))
                                    <flux:button href="{{ route('register') }}" variant="primary" wire:navigate>
                                        {{ __('Create account') }}
                                    </flux:button>
                                @endif
                                @if (Route::has('login'))
                                    <flux:link href="{{ route('login') }}" variant="subtle" wire:navigate>
                                        {{ __('Log in') }}
                                    </flux:link>
                                @endif
                            @else
                                <flux:button href="{{ route('dashboard') }}" variant="primary" wire:navigate>
                                    {{ __('Go to dashboard') }}
                                </flux:button>
                                @if (Route::has('profile.edit'))
                                    <flux:link href="{{ route('profile.edit') }}" variant="subtle" wire:navigate>
                                        {{ __('Settings') }}
                                    </flux:link>
                                @endif
                            @endguest
                        </div>

                        <flux:text variant="subtle">{{ __('Coverage varies by city and source availability.') }}</flux:text>
                    </div>

                    <div class="relative">
                        <div class="absolute -inset-3 rounded-[2rem] bg-[linear-gradient(145deg,_rgba(16,185,129,0.20),_rgba(245,158,11,0.16)_55%,_rgba(255,255,255,0.2))] blur-sm"></div>
                        <div class="relative overflow-hidden rounded-[1.75rem] border border-zinc-200 bg-white p-2 shadow-2xl shadow-zinc-400/10">
                            @if ($heroImagePath)
                                <img
                                    src="{{ asset($heroImagePath) }}"
                                    alt="{{ __('People in conversation at a community event') }}"
                                    class="h-[440px] w-full rounded-[1.25rem] object-cover object-center sm:h-[520px]"
                                    loading="eager"
                                />
                            @else
                                <div class="flex h-[440px] w-full items-center justify-center rounded-[1.25rem] bg-zinc-100 text-zinc-500 sm:h-[520px]">
                                    <flux:text variant="subtle">{{ __('Community image unavailable') }}</flux:text>
                                </div>
                            @endif
                            <div class="absolute bottom-6 left-6 right-6 rounded-2xl border border-white/50 bg-white/90 p-4 shadow-lg backdrop-blur">
                                <flux:text class="text-sm font-semibold text-zinc-800">{{ __('Better Information Improves Local Life') }}</flux:text>
                                <flux:text variant="subtle" class="mt-1 text-xs">{{ __('When residents have timely, understandable civic information, participation gets stronger.') }}</flux:text>
                            </div>
                        </div>
                    </div>
                </section>

                <section>
                    <flux:heading size="xl" level="2" class="tracking-tight !mb-0">{{ __('What you get') }}</flux:heading>
                    <flux:text class="mt-2 max-w-3xl text-zinc-700">
                        {{ __('A practical daily civic brief that helps you track change, spot what is coming up, and know where your input matters.') }}
                    </flux:text>

                    <flux:card padding="lg" class="mt-6 rounded-2xl border-zinc-200 bg-white/90 shadow-sm">
                        <flux:heading size="sm" level="3">{{ __('Today in your city') }}</flux:heading>

                        <div class="mt-4 grid gap-3">
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                    <div class="flex items-start gap-3">
                                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 shadow-sm">
                                            <flux:icon icon="newspaper" class="size-7 text-emerald-700" />
                                        </div>
                                        <div class="space-y-1">
                                            <flux:text class="font-medium text-zinc-900">{{ __('New notices and filings') }}</flux:text>
                                            <flux:text variant="subtle">{{ __('Track updates with links back to original public sources.') }}</flux:text>
                                        </div>
                                    </div>
                            </div>

                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                    <div class="flex items-start gap-3">
                                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 shadow-sm">
                                            <flux:icon icon="calendar-days" class="size-7 text-emerald-700" />
                                        </div>
                                        <div class="space-y-1">
                                            <flux:text class="font-medium text-zinc-900">{{ __('Upcoming meetings and agendas') }}</flux:text>
                                            <flux:text variant="subtle">{{ __('See dates, deadlines, and participation opportunities in one timeline.') }}</flux:text>
                                        </div>
                                    </div>
                            </div>

                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                    <div class="flex items-start gap-3">
                                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 shadow-sm">
                                            <flux:icon icon="chat-bubble-left-right" class="size-7 text-emerald-700" />
                                        </div>
                                        <div class="space-y-1">
                                            <flux:text class="font-medium text-zinc-900">{{ __('Ask local questions in plain language') }}</flux:text>
                                            <flux:text variant="subtle">{{ __('Get quick answers about services, policies, and what is happening nearby.') }}</flux:text>
                                        </div>
                                    </div>
                            </div>
                        </div>
                    </flux:card>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <flux:card padding="lg" class="rounded-2xl border-zinc-200 bg-zinc-100/80 shadow-sm">
                            <flux:heading size="sm" level="3">{{ __('LocAlmanac is Your Local Command Center') }}</flux:heading>
                            <ul class="mt-3 space-y-2 text-sm text-zinc-700">
                                <li class="flex items-start gap-2">
                                    <flux:icon icon="check-circle" class="mt-0.5 size-4 shrink-0 text-emerald-700" />
                                    <span>{{ __('Public information resources with smart, personalized access.') }}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <flux:icon icon="check-circle" class="mt-0.5 size-4 shrink-0 text-emerald-700" />
                                    <span>{{ __('AI-enabled updates from local information and meeting proceedings.') }}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <flux:icon icon="check-circle" class="mt-0.5 size-4 shrink-0 text-emerald-700" />
                                    <span>{{ __('Comprehensive event calendar gathered from multiple sources.') }}</span>
                                </li>
                            </ul>
                        </flux:card>

                        <flux:card padding="lg" class="rounded-2xl border-zinc-200 bg-zinc-100/80 shadow-sm">
                            <flux:heading size="sm" level="3">{{ __('You Can Ask LocAlmanac Anything') }}</flux:heading>
                            <ul class="mt-3 space-y-2 text-sm text-zinc-700">
                                <li class="flex items-start gap-2">
                                    <flux:icon icon="sparkles" class="mt-0.5 size-4 shrink-0 text-emerald-700" />
                                    <span>{{ __('Where is my nearest hazardous waste drop-off?') }}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <flux:icon icon="sparkles" class="mt-0.5 size-4 shrink-0 text-emerald-700" />
                                    <span>{{ __('What new construction is planned for my street?') }}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <flux:icon icon="sparkles" class="mt-0.5 size-4 shrink-0 text-emerald-700" />
                                    <span>{{ __('When is the next city council meeting?') }}</span>
                                </li>
                            </ul>
                        </flux:card>
                    </div>
                </section>

                <section>
                    <flux:heading size="xl" level="2" class="tracking-tight !mb-0">{{ __('Features') }}</flux:heading>
                    <flux:text class="mt-2 max-w-3xl text-zinc-700">{{ __('Everything you need to stay connected with your community.') }}</flux:text>

                    <flux:card padding="lg" class="mt-6 rounded-2xl border-zinc-200 bg-white/90 shadow-sm">
                        <flux:heading size="sm" level="3">{{ __('Core capabilities') }}</flux:heading>

                        <div class="mt-4 grid gap-3">
                            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                <flux:text class="font-medium text-zinc-900">{{ __('Public information resources') }}</flux:text>
                                <flux:text variant="subtle" class="mt-1">{{ __('Routine filings, legal notices, transactions, and everyday service answers.') }}</flux:text>
                            </div>
                            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                <flux:text class="font-medium text-zinc-900">{{ __('Updates + Event Calendar') }}</flux:text>
                                <flux:text variant="subtle" class="mt-1">{{ __('Aggregated local updates and AI-gathered events from multiple sources.') }}</flux:text>
                            </div>
                            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                <flux:text class="font-medium text-zinc-900">{{ __('On-Demand local answers') }}</flux:text>
                                <flux:text variant="subtle" class="mt-1">{{ __('Natural-language search and chatbot answers for specific local questions.') }}</flux:text>
                            </div>
                        </div>
                    </flux:card>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <flux:card padding="lg" class="rounded-2xl border-zinc-200 bg-zinc-100/80 shadow-sm">
                            <flux:heading size="sm" level="3">{{ __('Data + context') }}</flux:heading>
                            <ul class="mt-3 space-y-2 text-sm text-zinc-700">
                                <li class="flex items-start gap-2">
                                    <flux:icon icon="check-circle" class="mt-0.5 size-4 shrink-0 text-emerald-700" />
                                    <span>{{ __('Officials, trends, and neighborhood context in one view.') }}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <flux:icon icon="check-circle" class="mt-0.5 size-4 shrink-0 text-emerald-700" />
                                    <span>{{ __('Cross-source summaries that reduce noise and surface relevance.') }}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <flux:icon icon="check-circle" class="mt-0.5 size-4 shrink-0 text-emerald-700" />
                                    <span>{{ __('Information organized around impact, timeline, and participation.') }}</span>
                                </li>
                            </ul>
                        </flux:card>

                        <flux:card padding="lg" class="rounded-2xl border-zinc-200 bg-zinc-100/80 shadow-sm">
                            <flux:heading size="sm" level="3">{{ __('AI integration') }}</flux:heading>
                            <ul class="mt-3 space-y-2 text-sm text-zinc-700">
                                <li class="flex items-start gap-2">
                                    <flux:icon icon="sparkles" class="mt-0.5 size-4 shrink-0 text-emerald-700" />
                                    <span>{{ __('AI curation and summarization to improve speed and clarity.') }}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <flux:icon icon="sparkles" class="mt-0.5 size-4 shrink-0 text-emerald-700" />
                                    <span>{{ __('Built to enhance local journalism and official public communication.') }}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <flux:icon icon="sparkles" class="mt-0.5 size-4 shrink-0 text-emerald-700" />
                                    <span>{{ __('Designed for trustworthy source visibility, not black-box answers.') }}</span>
                                </li>
                            </ul>
                        </flux:card>
                    </div>
                </section>

                <section>
                    <flux:heading size="xl" level="2" class="tracking-tight !mb-0">{{ __('How it works') }}</flux:heading>
                    <flux:text class="mt-2 max-w-3xl text-zinc-700">{{ __('A simple flow from public data to daily clarity.') }}</flux:text>

                    <flux:card padding="lg" class="mt-6 rounded-2xl border-zinc-200 bg-white/90 shadow-sm">
                        <ol class="grid gap-3 text-sm lg:grid-cols-3">
                            <li class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                                <span class="font-semibold text-zinc-800">{{ __('1. Gather') }}</span>
                                <div class="mt-1 text-zinc-600">{{ __('We aggregate local information from government, community, and media sources.') }}</div>
                            </li>
                            <li class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                                <span class="font-semibold text-zinc-800">{{ __('2. Organize') }}</span>
                                <div class="mt-1 text-zinc-600">{{ __('AI summarizes complex information and adds useful civic context.') }}</div>
                            </li>
                            <li class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                                <span class="font-semibold text-zinc-800">{{ __('3. Deliver') }}</span>
                                <div class="mt-1 text-zinc-600">{{ __('You get clear answers, timely updates, and practical participation pathways.') }}</div>
                            </li>
                        </ol>
                    </flux:card>
                </section>

                <section class="rounded-3xl border border-emerald-200/60 bg-[linear-gradient(120deg,_rgba(255,255,255,0.95),_rgba(222,245,233,0.88))] p-6 shadow-sm lg:p-8">
                    <div class="flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
                        <div class="space-y-2">
                            <flux:heading size="md" level="2">{{ __('Get local info in one place.') }}</flux:heading>
                            <flux:text class="text-zinc-700">{{ __('We are building our pilot in Wichita, KS and expanding city by city.') }}</flux:text>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            @guest
                                @if (Route::has('register'))
                                    <flux:button href="{{ route('register') }}" variant="primary" wire:navigate>
                                        {{ __('Create account') }}
                                    </flux:button>
                                @endif
                                @if (Route::has('login'))
                                    <flux:link href="{{ route('login') }}" variant="subtle" wire:navigate>
                                        {{ __('Log in') }}
                                    </flux:link>
                                @endif
                            @else
                                <flux:button href="{{ route('dashboard') }}" variant="primary" wire:navigate>
                                    {{ __('Go to dashboard') }}
                                </flux:button>
                                @if (Route::has('profile.edit'))
                                    <flux:link href="{{ route('profile.edit') }}" variant="subtle" wire:navigate>
                                        {{ __('Settings') }}
                                    </flux:link>
                                @endif
                            @endguest
                        </div>
                    </div>
                </section>

                <footer class="flex flex-col gap-4 border-t border-zinc-200/80 pt-8 text-sm">
                    <flux:text variant="subtle">{{ __('Coverage varies by city and source availability.') }}</flux:text>
                    <div class="flex flex-wrap items-center gap-3">
                        <flux:text variant="subtle">{{ __('© :year LocAlmanac.', ['year' => date('Y')]) }}</flux:text>
                        @guest
                            @if (Route::has('login'))
                                <flux:link href="{{ route('login') }}" variant="subtle" wire:navigate>
                                    {{ __('Log in') }}
                                </flux:link>
                            @endif
                            @if (Route::has('register'))
                                <flux:link href="{{ route('register') }}" variant="subtle" wire:navigate>
                                    {{ __('Create account') }}
                                </flux:link>
                            @endif
                        @else
                            <flux:link href="{{ route('dashboard') }}" variant="subtle" wire:navigate>
                                {{ __('Dashboard') }}
                            </flux:link>
                        @endguest
                    </div>
                </footer>
            </main>
        </div>

        @auth
            <livewire:feedback.widget />
        @endauth

        @fluxScripts
    </body>
</html>
