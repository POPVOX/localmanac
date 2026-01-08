<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <flux:header container class="bg-zinc-50 dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-700">
            <flux:brand href="{{ route('home') }}" name="LocAlmanac" wire:navigate>
                <x-slot name="logo">
                    <x-app-logo-icon class="size-5 text-zinc-900 dark:text-zinc-100" />
                </x-slot>
            </flux:brand>

            <flux:spacer />

            <flux:navbar class="-mb-px">
                <flux:navbar.item href="#">Search</flux:navbar.item>
                <flux:navbar.item href="#">Issues</flux:navbar.item>
                <flux:navbar.item href="#">Calendar</flux:navbar.item>
                <flux:navbar.item href="#">Questions</flux:navbar.item>
            </flux:navbar>

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

        @fluxScripts
    </body>
</html>
