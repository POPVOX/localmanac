@component('layouts.demo')
    <div class="flex flex-col gap-24 py-12">
        <section class="flex items-center justify-center">
            <div class="flex flex-wrap items-center justify-center gap-3 rounded-full border border-sky-200/70 bg-gradient-to-r from-sky-500/90 to-emerald-400/80 px-4 py-2 text-sm text-white shadow-sm dark:border-sky-500/30">
                <flux:text class="text-white">{{ __('Local civic updates and events in one place.') }}</flux:text>
                @guest
                    @if (Route::has('register'))
                        <flux:link href="{{ route('register') }}" wire:navigate class="text-white underline underline-offset-4">
                            {{ __('Create account') }}
                        </flux:link>
                    @endif
                @endguest
            </div>
        </section>

        <section class="relative overflow-hidden rounded-3xl border border-zinc-200/70 bg-gradient-to-br from-white via-sky-50 to-emerald-50 px-6 py-14 shadow-sm dark:border-zinc-800/70 dark:from-zinc-950 dark:via-slate-900 dark:to-emerald-950">
            <div class="pointer-events-none absolute -top-24 left-8 h-64 w-64 rounded-full bg-sky-200/70 blur-3xl dark:bg-sky-500/10"></div>
            <div class="pointer-events-none absolute -bottom-24 right-10 h-72 w-72 rounded-full bg-amber-200/60 blur-3xl dark:bg-amber-500/10"></div>

            <div class="relative flex flex-col items-center gap-6 text-center">
                <div class="inline-flex items-center gap-3 rounded-full border border-white/70 bg-white/80 px-4 py-1.5 text-sm text-slate-600 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-200">
                    <flux:badge color="zinc" variant="subtle">{{ __('Meetings') }}</flux:badge>
                    <flux:badge color="zinc" variant="subtle">{{ __('Notices') }}</flux:badge>
                    <flux:badge color="zinc" variant="subtle">{{ __('Events') }}</flux:badge>
                    <span class="sr-only">{{ __('Key content types') }}</span>
                </div>

                <flux:heading size="xl" level="1" class="max-w-3xl">
                    <span class="bg-gradient-to-r from-slate-900 via-slate-700 to-sky-700 text-transparent bg-clip-text dark:from-white dark:via-slate-100 dark:to-sky-200">
                        {{ __('LocAlmanac') }}
                    </span>
                    {{ __(' keeps local civic updates in one clean view.') }}
                </flux:heading>

                <flux:text class="max-w-2xl">
                    {{ __('See what’s happening, what’s next, and how to participate — with links back to the original source.') }}
                </flux:text>

                <div class="flex flex-wrap items-center justify-center gap-3">
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

                <div class="mt-8 grid w-full max-w-5xl gap-6 lg:grid-cols-[1.2fr,0.8fr]">
                    <flux:card padding="lg" class="bg-white/80 shadow-lg ring-1 ring-zinc-200/60 dark:bg-zinc-900/70 dark:ring-zinc-700/60">
                        <div class="flex items-center justify-between">
                            <flux:heading size="sm" level="2">
                                {{ __('Today in your city') }}
                            </flux:heading>
                            <flux:badge color="zinc" variant="subtle">{{ __('Preview') }}</flux:badge>
                        </div>

                        <div class="mt-4 flex flex-col gap-3">
                            <div class="flex items-start gap-3 rounded-lg bg-white/90 p-3 shadow-sm dark:bg-zinc-900/80">
                                <flux:badge color="sky" variant="subtle">{{ __('Event') }}</flux:badge>
                                <div class="flex flex-col gap-1 text-start">
                                    <flux:text>{{ __('Parks committee meeting') }}</flux:text>
                                    <flux:text variant="subtle">{{ __('7:00 PM • City Hall') }}</flux:text>
                                </div>
                            </div>
                            <div class="flex items-start gap-3 rounded-lg bg-white/90 p-3 shadow-sm dark:bg-zinc-900/80">
                                <flux:badge color="sky" variant="subtle">{{ __('Event') }}</flux:badge>
                                <div class="flex flex-col gap-1 text-start">
                                    <flux:text>{{ __('Neighborhood cleanup') }}</flux:text>
                                    <flux:text variant="subtle">{{ __('Saturday • Riverfront') }}</flux:text>
                                </div>
                            </div>
                            <div class="flex items-start gap-3 rounded-lg bg-white/90 p-3 shadow-sm dark:bg-zinc-900/80">
                                <flux:badge color="amber" variant="subtle">{{ __('Update') }}</flux:badge>
                                <div class="flex flex-col gap-1 text-start">
                                    <flux:text>{{ __('Transit service changes') }}</flux:text>
                                    <flux:text variant="subtle">{{ __('New routes start Monday') }}</flux:text>
                                </div>
                            </div>
                            <div class="flex items-start gap-3 rounded-lg bg-white/90 p-3 shadow-sm dark:bg-zinc-900/80">
                                <flux:badge color="emerald" variant="subtle">{{ __('Notice') }}</flux:badge>
                                <div class="flex flex-col gap-1 text-start">
                                    <flux:text>{{ __('Open comment period') }}</flux:text>
                                    <flux:text variant="subtle">{{ __('Budget priorities this week') }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:card>

                    <div class="flex flex-col gap-4">
                        <flux:card padding="lg" class="bg-white/80 shadow-lg ring-1 ring-zinc-200/60 dark:bg-zinc-900/70 dark:ring-zinc-700/60">
                            <flux:heading size="sm" level="2">{{ __('At a glance') }}</flux:heading>
                            <flux:text variant="subtle" class="mt-2">
                                {{ __('A quick summary of what’s coming up and what requires attention.') }}
                            </flux:text>

                            <div class="mt-4 flex flex-col gap-3">
                                <div class="flex items-center justify-between rounded-lg bg-sky-50/80 p-3 dark:bg-sky-500/10">
                                    <flux:text>{{ __('Upcoming meeting') }}</flux:text>
                                    <flux:badge color="sky" variant="subtle">{{ __('Soon') }}</flux:badge>
                                </div>
                                <div class="flex items-center justify-between rounded-lg bg-emerald-50/80 p-3 dark:bg-emerald-500/10">
                                    <flux:text>{{ __('New notices') }}</flux:text>
                                    <flux:badge color="emerald" variant="subtle">{{ __('—') }}</flux:badge>
                                </div>
                                <div class="flex items-center justify-between rounded-lg bg-amber-50/80 p-3 dark:bg-amber-500/10">
                                    <flux:text>{{ __('Ways to participate') }}</flux:text>
                                    <flux:badge color="amber" variant="subtle">{{ __('—') }}</flux:badge>
                                </div>
                            </div>
                        </flux:card>

                        <div class="grid gap-3 sm:grid-cols-3">
                            <flux:card size="sm" class="flex flex-col gap-1 bg-white/80 text-center dark:bg-zinc-900/70">
                                <flux:text class="font-medium">{{ __('Source links') }}</flux:text>
                                <flux:text variant="subtle">{{ __('When available') }}</flux:text>
                            </flux:card>
                            <flux:card size="sm" class="flex flex-col gap-1 bg-white/80 text-center dark:bg-zinc-900/70">
                                <flux:text class="font-medium">{{ __('Updates') }}</flux:text>
                                <flux:text variant="subtle">{{ __('As sources change') }}</flux:text>
                            </flux:card>
                            <flux:card size="sm" class="flex flex-col gap-1 bg-white/80 text-center dark:bg-zinc-900/70">
                                <flux:text class="font-medium">{{ __('Calendar') }}</flux:text>
                                <flux:text variant="subtle">{{ __('Across sources') }}</flux:text>
                            </flux:card>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="flex flex-col items-center gap-6 text-center">
            <flux:text class="text-sm uppercase tracking-wide text-zinc-500">
                {{ __('Built from public sources') }}
            </flux:text>
            <div class="flex flex-wrap items-center justify-center gap-3">
                <flux:badge color="zinc" variant="subtle">{{ __('City halls') }}</flux:badge>
                <flux:badge color="zinc" variant="subtle">{{ __('Public libraries') }}</flux:badge>
                <flux:badge color="zinc" variant="subtle">{{ __('Transit agencies') }}</flux:badge>
                <flux:badge color="zinc" variant="subtle">{{ __('Community boards') }}</flux:badge>
                <flux:badge color="zinc" variant="subtle">{{ __('Education offices') }}</flux:badge>
            </div>
        </section>

        <section class="flex flex-col gap-8">
            <div class="flex flex-col gap-2 text-center">
                <flux:heading size="lg" level="2">{{ __('Features') }}</flux:heading>
                <flux:text variant="subtle">{{ __('A clean view of local information, pulled into one place.') }}</flux:text>
            </div>

            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <flux:card padding="lg" class="group flex flex-col gap-4 border-zinc-200/70 bg-white/90 transition hover:-translate-y-1 hover:shadow-lg dark:border-zinc-800/70 dark:bg-zinc-900/70">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-200">
                            <flux:icon icon="book-open-text" variant="outline" class="size-4" />
                        </div>
                        <flux:heading size="sm" level="3">{{ __("What's happening") }}</flux:heading>
                    </div>
                    <flux:text variant="subtle">{{ __('A daily view of civic news, meetings, and public notices.') }}</flux:text>
                </flux:card>

                <flux:card padding="lg" class="group flex flex-col gap-4 border-zinc-200/70 bg-white/90 transition hover:-translate-y-1 hover:shadow-lg dark:border-zinc-800/70 dark:bg-zinc-900/70">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200">
                            <flux:icon icon="map-pin" variant="outline" class="size-4" />
                        </div>
                        <flux:heading size="sm" level="3">{{ __('How to participate') }}</flux:heading>
                    </div>
                    <flux:text variant="subtle">{{ __('Clear next steps for hearings, votes, and feedback.') }}</flux:text>
                </flux:card>

                <flux:card padding="lg" class="group flex flex-col gap-4 border-zinc-200/70 bg-white/90 transition hover:-translate-y-1 hover:shadow-lg dark:border-zinc-800/70 dark:bg-zinc-900/70">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200">
                            <flux:icon icon="calendar-days" variant="outline" class="size-4" />
                        </div>
                        <flux:heading size="sm" level="3">{{ __('Events calendar') }}</flux:heading>
                    </div>
                    <flux:text variant="subtle">{{ __('Consolidated listings from trusted local sources.') }}</flux:text>
                </flux:card>

                <flux:card padding="lg" class="group flex flex-col gap-4 border-zinc-200/70 bg-white/90 transition hover:-translate-y-1 hover:shadow-lg dark:border-zinc-800/70 dark:bg-zinc-900/70">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-200">
                            <flux:icon icon="building-office-2" variant="outline" class="size-4" />
                        </div>
                        <flux:heading size="sm" level="3">{{ __('People & organizations') }}</flux:heading>
                    </div>
                    <flux:text variant="subtle">{{ __('Track the groups and agencies shaping decisions.') }}</flux:text>
                </flux:card>

                <flux:card padding="lg" class="group flex flex-col gap-4 border-zinc-200/70 bg-white/90 transition hover:-translate-y-1 hover:shadow-lg dark:border-zinc-800/70 dark:bg-zinc-900/70">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-200">
                            <flux:icon icon="layout-grid" variant="outline" class="size-4" />
                        </div>
                        <flux:heading size="sm" level="3">{{ __('Issues & topics') }}</flux:heading>
                    </div>
                    <flux:text variant="subtle">{{ __('Follow the themes that matter most in your city.') }}</flux:text>
                </flux:card>

                <flux:card padding="lg" class="group flex flex-col gap-4 border-zinc-200/70 bg-white/90 transition hover:-translate-y-1 hover:shadow-lg dark:border-zinc-800/70 dark:bg-zinc-900/70">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-zinc-200 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-100">
                            <flux:icon icon="folder-git-2" variant="outline" class="size-4" />
                        </div>
                        <flux:heading size="sm" level="3">{{ __('Source links & transparency') }}</flux:heading>
                    </div>
                    <flux:text variant="subtle">{{ __('See where information comes from with direct source links.') }}</flux:text>
                </flux:card>
            </div>
        </section>

        <section class="grid gap-10 lg:grid-cols-[0.9fr,1.1fr] lg:items-center">
            <div class="flex flex-col gap-4">
                <flux:heading size="lg" level="2">{{ __('How it works') }}</flux:heading>
                <flux:text variant="subtle">{{ __('A simple flow from public data to daily clarity.') }}</flux:text>

                <div class="flex flex-col gap-4">
                    <flux:card padding="lg" class="flex flex-col gap-3 border-zinc-200/70 bg-white/90 dark:border-zinc-800/70 dark:bg-zinc-900/70">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-200">1</div>
                            <flux:heading size="sm" level="3">{{ __('We ingest public sources') }}</flux:heading>
                        </div>
                        <flux:text variant="subtle">{{ __('Calendars, notices, and updates are collected from configured sources.') }}</flux:text>
                    </flux:card>

                    <flux:card padding="lg" class="flex flex-col gap-3 border-zinc-200/70 bg-white/90 dark:border-zinc-800/70 dark:bg-zinc-900/70">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200">2</div>
                            <flux:heading size="sm" level="3">{{ __('We extract civic context') }}</flux:heading>
                        </div>
                        <flux:text variant="subtle">{{ __('Key details are extracted and organized into a consistent format.') }}</flux:text>
                    </flux:card>

                    <flux:card padding="lg" class="flex flex-col gap-3 border-zinc-200/70 bg-white/90 dark:border-zinc-800/70 dark:bg-zinc-900/70">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200">3</div>
                            <flux:heading size="sm" level="3">{{ __('You get a clean daily view + calendar') }}</flux:heading>
                        </div>
                        <flux:text variant="subtle">{{ __('Browse upcoming events and recent updates in one view.') }}</flux:text>
                    </flux:card>
                </div>
            </div>

            <flux:card padding="lg" class="relative overflow-hidden border-zinc-200/70 bg-white/90 shadow-xl dark:border-zinc-800/70 dark:bg-zinc-900/70">
                <div class="absolute -top-20 right-0 h-48 w-48 rounded-full bg-sky-200/50 blur-3xl dark:bg-sky-500/10"></div>
                <div class="absolute -bottom-24 left-0 h-56 w-56 rounded-full bg-emerald-200/50 blur-3xl dark:bg-emerald-500/10"></div>
                <div class="relative flex flex-col gap-4">
                    <flux:heading size="sm" level="2">{{ __('Daily snapshot preview') }}</flux:heading>
                    <div class="grid gap-3">
                        <div class="flex items-center justify-between rounded-lg bg-white/90 p-3 shadow-sm dark:bg-zinc-900/80">
                            <div class="flex flex-col gap-1">
                                <flux:text>{{ __('Budget hearing') }}</flux:text>
                                <flux:text variant="subtle">{{ __('City council • 6:30 PM') }}</flux:text>
                            </div>
                            <flux:badge color="sky" variant="subtle">{{ __('Today') }}</flux:badge>
                        </div>
                        <div class="flex items-center justify-between rounded-lg bg-white/90 p-3 shadow-sm dark:bg-zinc-900/80">
                            <div class="flex flex-col gap-1">
                                <flux:text>{{ __('New zoning notice') }}</flux:text>
                                <flux:text variant="subtle">{{ __('Open for comments') }}</flux:text>
                            </div>
                            <flux:badge color="amber" variant="subtle">{{ __('Open') }}</flux:badge>
                        </div>
                        <div class="flex items-center justify-between rounded-lg bg-white/90 p-3 shadow-sm dark:bg-zinc-900/80">
                            <div class="flex flex-col gap-1">
                                <flux:text>{{ __('Weekend events') }}</flux:text>
                                <flux:text variant="subtle">{{ __('12 listings updated') }}</flux:text>
                            </div>
                            <flux:badge color="emerald" variant="subtle">{{ __('Updated') }}</flux:badge>
                        </div>
                    </div>
                    <flux:text variant="subtle">{{ __('Linked back to the original source when available.') }}</flux:text>
                </div>
            </flux:card>
        </section>

        <section>
            <flux:card
                padding="lg"
                class="flex flex-col gap-6 border-zinc-200/70 bg-gradient-to-r from-white via-sky-50 to-emerald-50 shadow-lg dark:border-zinc-800/70 dark:from-zinc-950 dark:via-slate-900 dark:to-emerald-950"
            >
                <div class="flex flex-col gap-2">
                    <flux:heading size="md" level="2">
                        {{ __('Get local info in one place.') }}
                    </flux:heading>
                    <flux:text variant="subtle">
                        {{ __('Create an account to follow a city and see what’s coming up.') }}
                    </flux:text>
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
            </flux:card>
        </section>

        <footer class="flex flex-col gap-4 border-t border-zinc-200 pt-8 text-sm dark:border-zinc-800">
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
    </div>
@endcomponent
