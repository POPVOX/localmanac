<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="marketing-shell antialiased">
        @php
            $heroImagePath = null;

            foreach (['images/people.png', 'images/people.jpg', 'images/people.jpeg', 'images/people.webp'] as $candidatePath) {
                if (file_exists(public_path($candidatePath))) {
                    $heroImagePath = $candidatePath;
                    break;
                }
            }
        @endphp

        <div class="marketing-container">
            <header class="marketing-header">
                <a href="{{ route('home') }}" class="inline-flex items-center" wire:navigate>
                    <x-app-logo-icon class="h-11 w-auto sm:h-12" />
                </a>

                <nav class="flex items-center gap-4 sm:gap-6" aria-label="{{ __('Primary navigation') }}">
                    @if ($cities->isNotEmpty())
                        <a href="#cities" class="marketing-nav-link hidden sm:inline-flex">
                            {{ __('Cities') }}
                        </a>
                    @endif

                    @guest
                        @if (Route::has('login'))
                            <a href="{{ route('login') }}" wire:navigate class="marketing-nav-link">
                                {{ __('Log in') }}
                            </a>
                        @endif
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" wire:navigate class="marketing-button-secondary !min-h-10 !px-4">
                                {{ __('Create account') }}
                            </a>
                        @endif
                    @else
                        <a href="{{ route('dashboard') }}" wire:navigate class="marketing-button-secondary !min-h-10 !px-4">
                            {{ __('Go to dashboard') }}
                        </a>
                    @endguest
                </nav>
            </header>

            <main>
                <section class="grid min-h-[calc(100vh-5rem)] items-center gap-10 py-12 lg:grid-cols-12 lg:gap-14 lg:py-16">
                    <div class="lg:col-span-7">
                        <div class="editorial-eyebrow">{{ __('Your community, in context') }}</div>
                        <h1 class="editorial-display mt-6 max-w-[11ch]">
                            {{ __('Know what is happening where you live.') }}
                        </h1>
                        <p class="mt-7 max-w-2xl text-lg leading-8 text-[#496159] sm:text-xl">
                            {{ __('LocAlmanac brings local reporting, public meetings, community events, and civic answers together in one clear daily briefing.') }}
                        </p>

                        <div class="mt-9 flex flex-wrap items-center gap-3">
                            @if ($cities->isNotEmpty())
                                <a href="#cities" class="marketing-button-primary">
                                    {{ __('Choose your city') }}
                                    <flux:icon icon="arrow-down" class="size-4" />
                                </a>
                            @endif
                            <a href="#how-it-works" class="marketing-button-secondary">
                                {{ __('How it works') }}
                            </a>
                        </div>

                        <div class="mt-10 flex flex-wrap gap-x-7 gap-y-3 border-t editorial-rule pt-5 text-sm font-medium text-[#496159]">
                            <span class="inline-flex items-center gap-2">
                                <span class="size-1.5 rounded-full bg-[#1f654f]"></span>
                                {{ __('Public news feeds') }}
                            </span>
                            <span class="inline-flex items-center gap-2">
                                <span class="size-1.5 rounded-full bg-[#1f654f]"></span>
                                {{ __('Local event calendars') }}
                            </span>
                            <span class="inline-flex items-center gap-2">
                                <span class="size-1.5 rounded-full bg-[#1f654f]"></span>
                                {{ __('Source-linked answers') }}
                            </span>
                        </div>
                    </div>

                    <div class="relative lg:col-span-5">
                        <div class="absolute -inset-5 -z-10 rounded-[2rem] bg-[#dfe9e3]"></div>
                        <div class="overflow-hidden rounded-2xl border border-white/70 bg-white shadow-[0_28px_80px_rgba(18,62,50,0.16)]">
                            @if ($heroImagePath)
                                <img
                                    src="{{ asset($heroImagePath) }}"
                                    alt="{{ __('Neighbors gathered at a community event') }}"
                                    class="aspect-[4/5] w-full object-cover object-center sm:aspect-[5/4] lg:aspect-[4/5]"
                                    loading="eager"
                                />
                            @else
                                <div class="aspect-[4/5] w-full bg-[#e7f0eb]"></div>
                            @endif

                            <div class="grid grid-cols-[auto_1fr] items-center gap-4 border-t border-[#d9d7ce] bg-[#fffefb] p-5">
                                <div class="grid size-11 place-items-center rounded-full bg-[#e7f0eb] text-[#1f654f]">
                                    <flux:icon icon="map-pin" class="size-5" />
                                </div>
                                <div>
                                    <div class="text-sm font-semibold text-[#18342c]">
                                        {{ trans_choice(':count community|:count communities', $cities->count(), ['count' => $cities->count()]) }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-[#667970]">
                                        {{ __('Public coverage available now') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </main>
        </div>

        @if ($cities->isNotEmpty())
            <section id="cities" class="scroll-mt-4 bg-[#123e32] py-16 text-white sm:py-20">
                <div class="marketing-container">
                    <div class="grid gap-8 lg:grid-cols-[0.75fr_1.25fr] lg:gap-16">
                        <div>
                            <div class="text-[0.7rem] font-semibold uppercase tracking-[0.22em] text-[#a9cbbb]">
                                {{ __('Local coverage') }}
                            </div>
                            <h2 class="mt-4 max-w-md font-serif text-4xl font-medium leading-tight tracking-[-0.03em] sm:text-5xl">
                                {{ __('Start with your city.') }}
                            </h2>
                            <p class="mt-5 max-w-md text-base leading-7 text-[#c9d8d1]">
                                {{ __('News and calendars are open to everyone. Members can unlock the local assistant with their city access code.') }}
                            </p>
                        </div>

                        <div class="divide-y divide-white/15 border-y border-white/15">
                            @foreach ($cities as $city)
                                <a
                                    href="{{ route('cities.show', $city) }}"
                                    wire:navigate
                                    class="group grid grid-cols-[1fr_auto] items-center gap-5 py-5 transition sm:grid-cols-[1fr_0.55fr_auto] sm:py-6"
                                >
                                    <div>
                                        <div class="font-serif text-2xl font-medium tracking-[-0.02em] text-white transition group-hover:text-[#b9ddca] sm:text-3xl">
                                            {{ $city->name }}
                                        </div>
                                        <div class="mt-1 text-sm text-[#a9bdb4] sm:hidden">
                                            {{ collect([$city->state, $city->country])->filter()->implode(', ') }}
                                        </div>
                                    </div>
                                    <div class="hidden text-sm text-[#a9bdb4] sm:block">
                                        {{ collect([$city->state, $city->country])->filter()->implode(', ') }}
                                    </div>
                                    <span class="grid size-11 place-items-center rounded-full border border-white/25 text-[#b9ddca] transition group-hover:border-[#b9ddca] group-hover:bg-white/10">
                                        <flux:icon icon="arrow-right" class="size-4" />
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </section>
        @endif

        <section class="py-16 sm:py-24">
            <div class="marketing-container">
                <div class="max-w-2xl">
                    <div class="editorial-eyebrow">{{ __('Features') }}</div>
                    <h2 class="editorial-heading mt-4">{{ __('Get local info in one place.') }}</h2>
                    <p class="mt-3 text-base text-[#5d7168]">{{ __('Less noise. More local signal.') }}</p>
                </div>

                <div class="mt-10 grid border-y editorial-rule md:grid-cols-3 md:divide-x md:divide-[#d9d7ce]">
                    @foreach ([
                        ['icon' => 'newspaper', 'title' => __('A readable local feed'), 'body' => __('Reporting and public notices are organized by city, source, and civic topic.')],
                        ['icon' => 'calendar-days', 'title' => __('Events in one calendar'), 'body' => __('Public meetings and community events are gathered from calendars across the city.')],
                        ['icon' => 'chat-bubble-left-right', 'title' => __('Answers with receipts'), 'body' => __('Members can ask local questions and follow the sources behind every useful answer.')],
                    ] as $feature)
                        <article class="border-b editorial-rule py-8 last:border-b-0 md:border-b-0 md:px-8 md:first:pl-0 md:last:pr-0">
                            <div class="grid size-11 place-items-center rounded-xl bg-[#e7f0eb] text-[#1f654f]">
                                <flux:icon :icon="$feature['icon']" class="size-5" />
                            </div>
                            <h3 class="mt-6 text-lg font-semibold tracking-[-0.015em] text-[#18342c]">{{ $feature['title'] }}</h3>
                            <p class="mt-3 text-sm leading-6 text-[#5d7168]">{{ $feature['body'] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section id="how-it-works" class="bg-[#e7e9e3] py-16 sm:py-20">
            <div class="marketing-container">
                <div class="grid gap-10 lg:grid-cols-[0.7fr_1.3fr] lg:gap-16">
                    <div>
                        <div class="editorial-eyebrow">{{ __('How it works') }}</div>
                        <h2 class="editorial-heading mt-4">{{ __('A clearer path from source to resident.') }}</h2>
                    </div>

                    <ol class="divide-y divide-[#cdd1ca] border-y border-[#cdd1ca]">
                        @foreach ([
                            [__('01'), __('Gather'), __('LocAlmanac monitors reporting, public records, meetings, and community calendars.')],
                            [__('02'), __('Organize'), __('Information is cleaned, grouped by jurisdiction, and linked back to its original source.')],
                            [__('03'), __('Understand'), __('Residents browse the briefing or ask focused questions about what matters locally.')],
                        ] as $step)
                            <li class="grid gap-3 py-6 sm:grid-cols-[4rem_8rem_1fr] sm:items-baseline">
                                <span class="text-xs font-semibold tracking-[0.16em] text-[#6b7d74]">{{ $step[0] }}</span>
                                <span class="font-semibold text-[#18342c]">{{ $step[1] }}</span>
                                <span class="text-sm leading-6 text-[#5d7168]">{{ $step[2] }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
        </section>

        <footer class="bg-[#f5f3ed] py-10">
            <div class="marketing-container flex flex-col gap-6 border-t editorial-rule pt-8 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <x-app-logo-icon class="h-9 w-auto" />
                    <p class="mt-3 text-xs text-[#6b7d74]">{{ __('Coverage varies by city and source availability.') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-5 text-sm font-medium text-[#496159]">
                    <span>{{ __('© :year LocAlmanac', ['year' => date('Y')]) }}</span>
                    @guest
                        @if (Route::has('login'))
                            <a href="{{ route('login') }}" wire:navigate class="hover:text-[#1f654f]">{{ __('Log in') }}</a>
                        @endif
                    @else
                        <a href="{{ route('dashboard') }}" wire:navigate class="hover:text-[#1f654f]">{{ __('Go to dashboard') }}</a>
                    @endguest
                </div>
            </div>
        </footer>

        @auth
            <livewire:feedback.widget />
        @endauth

        @fluxScripts
    </body>
</html>
