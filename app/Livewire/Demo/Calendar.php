<?php

namespace App\Livewire\Demo;

use App\Models\City;
use App\Models\Event;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;
use Throwable;

class Calendar extends Component
{
    public string $selectedDate = '';

    public ?int $cityId = null;

    public bool $adminPreview = false;

    public function mount(?City $city = null): void
    {
        $this->cityId = $city?->id ?? $this->resolveCityId();
        $this->adminPreview = $city !== null && request()->routeIs('admin.cities.calendar');

        $timezone = $this->resolveTimezone($this->resolveCity());
        $this->selectedDate = $this->resolveSelectedDate(request()->query('date'), $timezone)->toDateString();
    }

    public function updatedSelectedDate(mixed $value): void
    {
        if ($value !== null && ! is_string($value)) {
            return;
        }

        $timezone = $this->resolveTimezone($this->resolveCity());
        $date = $this->resolveSelectedDate($value, $timezone)->toDateString();
        $this->selectedDate = $date;

        $this->redirectRoute($this->calendarRouteName(), $this->buildRouteParameters($date));
    }

    public function render(): View
    {
        $city = $this->resolveCity();
        $timezone = $this->resolveTimezone($city);
        $selectedDate = $this->resolveSelectedDate($this->selectedDate, $timezone);
        [$allDayEvents, $timedEventGroups] = $this->groupEventsForDate($city, $selectedDate, $timezone);
        $previousDate = $selectedDate->copy()->subDay()->toDateString();
        $nextDate = $selectedDate->copy()->addDay()->toDateString();
        $todayDate = Carbon::now($timezone)->toDateString();

        return view('livewire.demo.calendar', [
            'city' => $city,
            'selectedDate' => $selectedDate,
            'selectedDateLabel' => $selectedDate->format('F j, Y'),
            'previousDateUrl' => route($this->calendarRouteName(), $this->buildRouteParameters($previousDate)),
            'nextDateUrl' => route($this->calendarRouteName(), $this->buildRouteParameters($nextDate)),
            'todayDateUrl' => route($this->calendarRouteName(), $this->buildRouteParameters($todayDate)),
            'allDayEvents' => $allDayEvents,
            'timedEventGroups' => $timedEventGroups,
            'timezone' => $timezone,
        ])->layout('layouts.app-dashboard', [
            'city' => $city,
            'adminPreview' => $this->adminPreview,
        ]);
    }

    /**
     * @return array{0: Collection<int, Event>, 1: Collection<string, Collection<int, Event>>}
     */
    private function groupEventsForDate(?City $city, Carbon $selectedDate, string $timezone): array
    {
        if (! $city) {
            return [collect(), collect()];
        }

        $dayStart = $selectedDate->copy()->startOfDay()->setTimezone('UTC');
        $dayEnd = $selectedDate->copy()->endOfDay()->setTimezone('UTC');

        $events = Event::query()
            ->where('city_id', $city->id)
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [
                $dayStart,
                $dayEnd,
            ])
            ->orderBy('starts_at')
            ->with('sourceItems.eventSource')
            ->get();

        $allDayEvents = $events->filter(fn (Event $event) => $this->shouldDisplayAsAllDay($event, $timezone));
        $timedEvents = $events->reject(fn (Event $event) => $this->shouldDisplayAsAllDay($event, $timezone));

        $groupedTimedEvents = $timedEvents
            ->groupBy(fn (Event $event) => $event->starts_at?->copy()->setTimezone($timezone)->format('H:i') ?? 'tbd')
            ->sortKeys();

        return [$allDayEvents, $groupedTimedEvents];
    }

    private function shouldDisplayAsAllDay(Event $event, string $timezone): bool
    {
        if ($event->all_day) {
            return true;
        }

        $startsAt = $event->starts_at?->copy()->setTimezone($timezone);
        $endsAt = $event->ends_at?->copy()->setTimezone($timezone);

        if (! $startsAt || ! $endsAt) {
            return false;
        }

        return $startsAt->isSameDay($endsAt)
            && $startsAt->equalTo($startsAt->copy()->startOfDay())
            && $endsAt->greaterThanOrEqualTo($startsAt->copy()->endOfDay()->subMinute());
    }

    private function resolveCity(): ?City
    {
        if ($this->cityId) {
            return City::query()->find($this->cityId);
        }

        return City::query()
            ->where('slug', 'wichita')
            ->first()
            ?? City::query()->first();
    }

    private function resolveCityId(): ?int
    {
        $cityId = request()->integer('city_id');

        return $cityId > 0 ? $cityId : null;
    }

    private function resolveTimezone(?City $city): string
    {
        return $city?->timezone ?? config('app.timezone', 'UTC');
    }

    private function resolveSelectedDate(?string $value, string $timezone): Carbon
    {
        if ($value) {
            try {
                return Carbon::createFromFormat('Y-m-d', $value, $timezone)->startOfDay();
            } catch (Throwable) {
            }
        }

        return Carbon::now($timezone)->startOfDay();
    }

    /**
     * @return array{date: string, city?: City, city_id?: int}
     */
    private function buildRouteParameters(string $date): array
    {
        $parameters = ['date' => $date];

        if ($this->adminPreview) {
            $city = $this->resolveCity();

            if ($city) {
                $parameters['city'] = $city;
            }

            return $parameters;
        }

        if ($this->cityId) {
            $parameters['city_id'] = $this->cityId;
        }

        return $parameters;
    }

    private function calendarRouteName(): string
    {
        return $this->adminPreview ? 'admin.cities.calendar' : 'demo.calendar';
    }
}
