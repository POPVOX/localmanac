@props([
    'city' => null,
    'name' => null,
    'slug' => null,
    'compact' => false,
])

@php
    $cityName = $city?->name ?? $name;
    $citySlug = $city?->slug ?? $slug;
@endphp

@if ($cityName && $citySlug)
    <a
        href="{{ route('admin.cities.preview', ['city' => $citySlug]) }}"
        target="_blank"
        rel="noopener noreferrer"
        {{ $attributes->class([
            'group inline-flex items-center gap-1.5 font-medium text-[#285f4d] transition hover:text-[#123e32]',
            'text-xs' => $compact,
            'text-sm' => ! $compact,
        ]) }}
        title="{{ __('Preview :city', ['city' => $cityName]) }}"
    >
        <span>{{ $cityName }}</span>
        <flux:icon icon="arrow-top-right-on-square" class="size-3.5 opacity-55 transition group-hover:opacity-100" />
        <span class="sr-only">{{ __('Preview') }}</span>
    </a>
@else
    <span {{ $attributes->class('text-sm text-[#718078]') }}>{{ $cityName ?: __('Unknown') }}</span>
@endif
