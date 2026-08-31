<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="marketing-shell text-zinc-900 antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-7 p-5 sm:p-8">
            <a href="{{ route('home') }}" class="flex items-center" wire:navigate>
                <x-app-logo-icon class="h-11 w-auto" />
                <span class="sr-only">{{ config('app.name', 'LocAlmanac') }}</span>
            </a>
            <div class="auth-panel">
                <div class="flex flex-col gap-6">
                    {{ $slot }}
                </div>
            </div>
            <p class="text-center text-xs text-[#6b7d74]">{{ __('Local information, clearly connected to its sources.') }}</p>
        </div>
        @fluxScripts
    </body>
</html>
