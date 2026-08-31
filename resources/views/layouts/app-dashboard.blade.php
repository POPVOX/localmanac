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
    <body class="app-shell-bg text-zinc-900 antialiased dark:text-zinc-100">
        <flux:header container class="app-shell-header py-4">
            <div class="flex items-center gap-3">
                <a href="{{ $dashboardUrl }}" class="flex items-center" wire:navigate>
                    <x-app-logo />
                </a>

                <flux:badge color="zinc" variant="subtle" class="uppercase tracking-wide">
                    {{ __('Pilot') }}
                </flux:badge>
            </div>

            <flux:spacer />

            @if ($availableCities->isNotEmpty())
                <label class="flex items-center gap-2 text-sm font-medium text-zinc-600">
                    <span class="hidden lg:inline">{{ __('City') }}</span>
                    <select
                        class="max-w-36 rounded-lg border border-zinc-200 bg-white px-2 py-2 text-sm text-zinc-800 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 sm:max-w-52 sm:px-3"
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
                </label>
            @endif

            <flux:navbar class="-mb-px">
                <flux:navbar.item href="{{ $dashboardUrl }}" wire:navigate>{{ __('News') }}</flux:navbar.item>
                <flux:navbar.item href="{{ $calendarUrl }}" wire:navigate>{{ __('Calendar') }}</flux:navbar.item>
            </flux:navbar>

            @guest
                <flux:navbar class="-mb-px">
                    <flux:navbar.item href="{{ route('login') }}" wire:navigate>
                        {{ __('Log in') }}
                    </flux:navbar.item>
                    @if (Route::has('register'))
                        <flux:navbar.item href="{{ route('register') }}" wire:navigate>
                            {{ __('Create account') }}
                        </flux:navbar.item>
                    @endif
                </flux:navbar>
            @endguest

            @auth
                <flux:dropdown position="top" align="start" class="ms-3">
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
                                {{ __('Log Out') }}
                            </flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            @endauth
        </flux:header>

        <flux:main container class="py-8">
            {{ $slot }}
        </flux:main>

        @auth
            <livewire:feedback.widget />
        @endauth

        @fluxScripts
    </body>
</html>
