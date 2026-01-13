<?php

namespace App\Livewire\Admin\Events;

use App\Models\City;
use App\Models\Event;
use App\Models\EventSource;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class Index extends Component
{
    use WithPagination;

    public ?int $cityId = null;

    public ?int $sourceId = null;

    public bool $hasUrlOnly = false;

    public ?string $startDate = null;

    public ?string $endDate = null;

    protected array $queryString = [
        'cityId' => ['except' => null],
        'sourceId' => ['except' => null],
        'hasUrlOnly' => ['except' => false],
        'startDate' => ['except' => null],
        'endDate' => ['except' => null],
    ];

    public function mount(): void
    {
        if ($this->cityId === null) {
            $this->cityId = City::query()->orderBy('name')->value('id');
        }

        $timezone = $this->resolveTimezone($this->resolveCity());

        if ($this->startDate === null || trim($this->startDate) === '') {
            $this->startDate = Carbon::now($timezone)->startOfDay()->toDateString();
        }

        if ($this->endDate === null || trim($this->endDate) === '') {
            $this->endDate = Carbon::now($timezone)->addDays(30)->startOfDay()->toDateString();
        }
    }

    public function updatingCityId(): void
    {
        $this->resetPage();
    }

    public function updatingSourceId(): void
    {
        $this->resetPage();
    }

    public function updatingHasUrlOnly(): void
    {
        $this->resetPage();
    }

    public function updatingStartDate(): void
    {
        $this->resetPage();
    }

    public function updatingEndDate(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $city = $this->resolveCity();
        $timezone = $this->resolveTimezone($city);
        [$start, $end] = $this->resolveDateRange($timezone);

        $events = Event::query()
            ->with(['city', 'sourceItems.eventSource'])
            ->whereNotNull('starts_at')
            ->when($this->cityId, fn ($query) => $query->where('city_id', $this->cityId))
            ->when($this->sourceId, function ($query) {
                $query->whereHas('sourceItems', fn ($inner) => $inner->where('event_source_id', $this->sourceId));
            })
            ->when($this->hasUrlOnly, fn ($query) => $query->whereNotNull('event_url')->where('event_url', '!=', ''))
            ->whereBetween('starts_at', [$start, $end])
            ->orderBy('starts_at')
            ->paginate(20);

        $cities = City::query()->orderBy('name')->get();
        $sources = EventSource::query()
            ->when($this->cityId, fn ($query) => $query->where('city_id', $this->cityId))
            ->orderBy('name')
            ->get();

        return view('livewire.admin.events.index', [
            'events' => $events,
            'cities' => $cities,
            'sources' => $sources,
        ])->layout('layouts.admin', [
            'title' => __('Events'),
        ]);
    }

    private function resolveCity(): ?City
    {
        if ($this->cityId) {
            return City::query()->find($this->cityId);
        }

        return City::query()->orderBy('name')->first();
    }

    private function resolveTimezone(?City $city): string
    {
        return $city?->timezone ?? config('app.timezone', 'UTC');
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveDateRange(string $timezone): array
    {
        $start = $this->parseDate($this->startDate, $timezone)->startOfDay();
        $end = $this->parseDate($this->endDate, $timezone)->endOfDay();

        if ($end->lessThan($start)) {
            return [$end, $start];
        }

        return [$start, $end];
    }

    private function parseDate(?string $value, string $timezone): Carbon
    {
        if ($value) {
            try {
                return Carbon::createFromFormat('Y-m-d', $value, $timezone);
            } catch (Throwable) {
            }
        }

        return Carbon::now($timezone);
    }
}
