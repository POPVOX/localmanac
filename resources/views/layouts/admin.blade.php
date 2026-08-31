<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="admin-shell min-h-screen antialiased">
        @php
            $adminCities = \App\Models\City::query()->orderBy('name')->get(['id', 'name', 'slug', 'state']);
            $adminCityScopeId = request()->integer('cityId') ?: null;
            $adminCityScope = $adminCityScopeId ? $adminCities->firstWhere('id', $adminCityScopeId) : null;
            $adminScopeQuery = $adminCityScope ? ['cityId' => $adminCityScope->id] : [];
        @endphp

        <flux:sidebar sticky stashable class="admin-sidebar border-e">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('admin.dashboard') }}" class="admin-brand-block" wire:navigate>
                <x-app-logo />
                <span class="mt-2 block text-[0.65rem] font-semibold uppercase tracking-[0.18em] text-[#667970]">
                    {{ __('Admin workspace') }}
                </span>
            </a>

            <form method="GET" action="{{ route('admin.dashboard') }}" class="mb-5 rounded-xl border border-[#cfd7d1] bg-white/65 p-3">
                <label for="admin-location-scope" class="block text-[0.65rem] font-semibold uppercase tracking-[0.16em] text-[#667970]">{{ __('Location scope') }}</label>
                <select
                    id="admin-location-scope"
                    name="cityId"
                    class="mt-2 block h-9 w-full rounded-lg border border-[#cfd5d0] bg-white px-2.5 text-sm font-semibold text-[#18342c]"
                    onchange="this.form.submit()"
                >
                    <option value="">{{ __('All locations') }}</option>
                    @foreach ($adminCities as $adminCity)
                        <option value="{{ $adminCity->id }}" @selected($adminCityScope?->id === $adminCity->id)>{{ $adminCity->name }}{{ $adminCity->state ? ', '.$adminCity->state : '' }}</option>
                    @endforeach
                </select>
                @if ($adminCityScope)
                    <a href="{{ route('admin.cities.preview', $adminCityScope) }}" target="_blank" rel="noopener noreferrer" class="mt-2 flex items-center justify-between text-xs font-semibold text-[#285f4d] hover:text-[#123e32]">
                        <span>{{ __('Preview public page') }}</span>
                        <flux:icon icon="arrow-top-right-on-square" class="size-3.5" />
                    </a>
                @endif
            </form>

            <flux:navlist variant="outline" class="space-y-5">
                <flux:navlist.group :heading="__('Workspace')" class="grid">
                    <flux:navlist.item icon="layout-grid" :href="route('admin.dashboard', $adminScopeQuery)" :current="request()->routeIs('admin.dashboard')" wire:navigate>
                        {{ __('Overview') }}
                    </flux:navlist.item>
                    <flux:navlist.item icon="map-pin" :href="route('admin.cities.index')" :current="request()->routeIs('admin.cities.*') || request()->routeIs('admin.organizations.*')" wire:navigate>
                        {{ __('Locations') }}
                    </flux:navlist.item>
                    <flux:navlist.item icon="signal" :href="route('admin.sources.index', $adminScopeQuery)" :current="request()->routeIs('admin.sources.*') || request()->routeIs('admin.scrapers.*') || request()->routeIs('admin.event-sources.*') || request()->routeIs('admin.chat-sources.*')" wire:navigate>
                        {{ __('Sources') }}
                    </flux:navlist.item>
                </flux:navlist.group>

                <flux:navlist.group :heading="__('Review')" class="grid">
                    <flux:navlist.item icon="calendar-days" :href="route('admin.events.index', $adminScopeQuery)" :current="request()->routeIs('admin.events.*')" wire:navigate>
                        {{ __('Events') }}
                    </flux:navlist.item>
                    <flux:navlist.item icon="chat-bubble-left-right" :href="route('admin.feedback.index', $adminScopeQuery)" :current="request()->routeIs('admin.feedback.*')" wire:navigate>
                        {{ __('Feedback') }}
                    </flux:navlist.item>
                </flux:navlist.group>
            </flux:navlist>

            <flux:button class="mt-5 w-full" variant="primary" :href="route('admin.sources.create', $adminScopeQuery)" icon="plus" wire:navigate>{{ __('Add source') }}</flux:button>

            <flux:spacer />

            <flux:dropdown class="hidden lg:block" position="bottom" align="start">
                <flux:profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    icon:trailing="chevrons-up-down"
                    data-test="sidebar-menu-button"
                />

                <flux:menu class="w-[220px]">
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" data-test="logout-button">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <flux:header class="app-shell-header lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <span class="text-xs font-semibold uppercase tracking-[0.16em] text-[#667970]">{{ __('Admin') }}</span>

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" data-test="logout-button">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        <flux:main class="admin-main">
            {{ $slot }}
        </flux:main>

        @persist('toast')
            <flux:toast />
        @endpersist

        @fluxScripts

        <script>
            (function () {
                window.showFluxToast = window.showFluxToast ?? ((rawDetail) => {
                    const detail = rawDetail ?? {};
                    const message = detail.message ?? detail.text;

                    if (! message) {
                        return;
                    }

                    const options = {
                        heading: detail.heading ?? null,
                        variant: detail.variant ?? detail.type ?? 'success',
                        duration: detail.duration ?? 5000,
                        position: detail.position ?? null,
                    };

                    if (window.Flux?.toast) {
                        window.Flux.toast(message, {
                            heading: options.heading ?? undefined,
                            variant: options.variant ?? undefined,
                            duration: options.duration,
                            position: options.position ?? undefined,
                        });

                        return;
                    }

                    document.dispatchEvent(new CustomEvent('toast-show', {
                        detail: {
                            slots: {
                                text: message,
                                ...(options.heading ? { heading: options.heading } : {}),
                            },
                            dataset: {
                                ...(options.variant ? { variant: options.variant } : {}),
                                ...(options.position ? { position: options.position } : {}),
                            },
                            duration: options.duration,
                        },
                    }));
                });

                if (! window.__fluxToastListenerRegistered) {
                    window.addEventListener('toast', (event) => {
                        window.showFluxToast(event.detail);
                    });

                    window.__fluxToastListenerRegistered = true;
                }

                const flashToast = @json(session()->pull('toast'));

                if (! flashToast) {
                    return;
                }

                document.addEventListener('livewire:navigated', () => {
                    window.showFluxToast(flashToast);
                }, { once: true });
            })();
        </script>
    </body>
</html>
