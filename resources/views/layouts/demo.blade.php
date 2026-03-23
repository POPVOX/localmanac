<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="app-shell-bg text-zinc-900 antialiased dark:text-zinc-100">
        <flux:header container class="app-shell-header">
            <a href="{{ route('home') }}" class="flex items-center" wire:navigate>
                <x-app-logo />
            </a>

            <flux:spacer />

            @auth
                <flux:navbar class="-mb-px">
                    <flux:navbar.item href="{{ route('dashboard') }}" wire:navigate>Dashboard</flux:navbar.item>
                    <flux:navbar.item href="{{ route('demo.calendar') }}" wire:navigate>Calendar</flux:navbar.item>
                </flux:navbar>
            @endauth

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

        <flux:main container>
            {{ $slot }}
        </flux:main>

        @auth
            <livewire:feedback.widget />
        @endauth

        @fluxScripts
    </body>
</html>
