<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    @php
        $isAdminPreview = ($adminPreview ?? false) && isset($city);
        $dashboardUrl = $isAdminPreview
            ? route('admin.cities.preview', $city)
            : (isset($city) && $city ? route('cities.show', $city) : route('home'));
        $calendarUrl = $isAdminPreview
            ? route('admin.cities.calendar', $city)
            : (isset($city) && $city ? route('cities.calendar', $city) : route('demo.calendar'));
        $availableCities = $availableCities ?? collect();
        $currentSurface = $currentSurface ?? 'dashboard';
    @endphp
    <head>
        @include('partials.head')
    </head>
    <body class="app-shell-bg antialiased">
        <header class="app-shell-header sticky top-0 z-30">
            <div class="mx-auto flex min-h-20 w-full max-w-[82rem] flex-wrap items-center gap-x-5 gap-y-3 px-4 py-3 sm:px-7 lg:flex-nowrap lg:px-10">
                <a href="{{ route('home') }}" class="shrink-0" wire:navigate aria-label="{{ __('LocAlmanac home') }}">
                    <x-app-logo />
                </a>

                <div class="order-3 flex w-full items-center gap-1 border-t border-[#dedcd4] pt-2 lg:order-none lg:w-auto lg:border-0 lg:pt-0">
                    <a
                        href="{{ $dashboardUrl }}"
                        wire:navigate
                        @class([
                            'relative px-3 py-2 text-sm font-semibold transition-colors',
                            'text-[#18342c] after:absolute after:inset-x-3 after:-bottom-[9px] after:h-0.5 after:bg-[#1f654f] lg:after:-bottom-[1.8rem]' => $currentSurface === 'dashboard',
                            'text-[#667970] hover:text-[#1f654f]' => $currentSurface !== 'dashboard',
                        ])
                    >
                        {{ __('News') }}
                    </a>
                    <a
                        href="{{ $calendarUrl }}"
                        wire:navigate
                        @class([
                            'relative px-3 py-2 text-sm font-semibold transition-colors',
                            'text-[#18342c] after:absolute after:inset-x-3 after:-bottom-[9px] after:h-0.5 after:bg-[#1f654f] lg:after:-bottom-[1.8rem]' => $currentSurface === 'calendar',
                            'text-[#667970] hover:text-[#1f654f]' => $currentSurface !== 'calendar',
                        ])
                    >
                        {{ __('Calendar') }}
                    </a>
                </div>

                <div class="ml-auto flex min-w-0 items-center gap-2 sm:gap-3">
                    @if ($availableCities->isNotEmpty())
                        <label class="relative min-w-0">
                            <span class="sr-only">{{ __('Choose a city') }}</span>
                            <select
                                class="h-10 max-w-40 appearance-none rounded-xl border border-[#d9d7ce] bg-[#fffefb] py-2 pl-3 pr-8 text-sm font-semibold text-[#18342c] outline-none transition focus:border-[#1f654f] focus:ring-2 focus:ring-[#1f654f]/15 sm:max-w-52"
                                aria-label="{{ __('Choose a city') }}"
                                x-data
                                x-on:change="window.location.href = $event.target.value"
                            >
                                @foreach ($availableCities as $availableCity)
                                    @php
                                        $cityUrl = $isAdminPreview
                                            ? route($currentSurface === 'calendar' ? 'admin.cities.calendar' : 'admin.cities.preview', $availableCity)
                                            : route($currentSurface === 'calendar' ? 'cities.calendar' : 'cities.show', $availableCity);
                                    @endphp
                                    <option value="{{ $cityUrl }}" @selected(isset($city) && $city?->is($availableCity))>
                                        {{ $availableCity->name }}@if ($availableCity->state), {{ $availableCity->state }}@endif
                                    </option>
                                @endforeach
                            </select>
                            <flux:icon icon="chevron-down" class="pointer-events-none absolute right-2.5 top-1/2 size-4 -translate-y-1/2 text-[#667970]" />
                        </label>
                    @endif

                    @guest
                        <a href="{{ route('login') }}" wire:navigate class="hidden text-sm font-semibold text-[#496159] transition hover:text-[#1f654f] sm:inline-flex">
                            {{ __('Log in') }}
                        </a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" wire:navigate class="inline-flex h-10 items-center rounded-xl bg-[#123e32] px-3.5 text-sm font-semibold text-white transition hover:bg-[#1f654f]">
                                {{ __('Join') }}
                            </a>
                        @endif
                    @else
                        <flux:dropdown position="bottom" align="end">
                            <flux:profile
                                :name="auth()->user()->name"
                                :initials="auth()->user()->initials()"
                                icon:trailing="chevron-down"
                            />

                            <flux:menu>
                                @if (Route::has('profile.edit'))
                                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                                        {{ __('Settings') }}
                                    </flux:menu.item>
                                @endif

                                @can('access-admin')
                                    <flux:menu.item :href="route('admin.dashboard')" icon="shield-check" wire:navigate>
                                        {{ __('Admin Dashboard') }}
                                    </flux:menu.item>
                                @endcan

                                <flux:menu.separator />

                                <form method="POST" action="{{ route('logout') }}" class="w-full">
                                    @csrf
                                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                                        {{ __('Log out') }}
                                    </flux:menu.item>
                                </form>
                            </flux:menu>
                        </flux:dropdown>
                    @endguest
                </div>
            </div>
        </header>

        @if ($isAdminPreview)
            <div class="border-b border-[#c5d4cc] bg-[#e7f0eb] px-4 py-2 text-center text-xs font-semibold text-[#1f654f]">
                {{ __('Admin preview · This is how :city appears to visitors.', ['city' => $city->name]) }}
            </div>
        @endif

        <main class="public-main">
            {{ $slot }}
        </main>

        @auth
            <livewire:feedback.widget />
        @endauth

        @fluxScripts
    </body>
</html>
