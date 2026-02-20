<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-[radial-gradient(circle_at_top,_#f8fafc,_#eef2f7_55%,_#ffffff_100%)] text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <flux:header container class="bg-white/90 dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-700 backdrop-blur">
            <div class="flex items-center gap-3">
                <flux:brand href="{{ route('dashboard') }}" name="LocAlmanac" wire:navigate>
                    <x-slot name="logo">
                        <x-app-logo-icon class="size-5 text-zinc-900 dark:text-zinc-100" />
                    </x-slot>
                </flux:brand>

                <flux:badge color="zinc" variant="subtle" class="uppercase tracking-wide">
                    {{ __('Pilot') }}
                </flux:badge>
            </div>

            <flux:spacer />

            @auth
                <flux:navbar class="-mb-px">
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

        <flux:main container class="py-8">
            {{ $slot }}
        </flux:main>

        @fluxScripts
    </body>
</html>
