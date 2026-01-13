<?php

namespace App\Livewire\Admin\Events;

use App\Models\City;
use App\Models\Event;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;
use Throwable;

class Form extends Component
{
    public ?Event $event = null;

    public ?int $cityId = null;

    public string $title = '';

    public ?string $startsAt = null;

    public ?string $endsAt = null;

    public bool $allDay = false;

    public string $locationName = '';

    public string $locationAddress = '';

    public string $description = '';

    public ?string $rawDescription = null;

    public string $eventUrl = '';

    public function mount(?Event $event = null): void
    {
        $this->event = $event?->exists ? $event : null;

        if ($this->event?->exists) {
            $timezone = $this->resolveTimezone($this->event->city);
            $this->cityId = $this->event->city_id;
            $this->title = $this->event->title ?? '';
            $this->startsAt = $this->formatDateTime($this->event->starts_at, $timezone);
            $this->endsAt = $this->formatDateTime($this->event->ends_at, $timezone);
            $this->allDay = (bool) $this->event->all_day;
            $this->locationName = $this->event->location_name ?? '';
            $this->locationAddress = $this->event->location_address ?? '';
            $this->rawDescription = $this->event->description;
            $this->description = $this->sanitizeDescription($this->rawDescription);
            $this->eventUrl = $this->event->event_url ?? '';
        } else {
            $this->cityId = City::query()->orderBy('name')->value('id');
        }
    }

    public function save(): RedirectResponse|Redirector|null
    {
        try {
            $payload = $this->validate($this->rules());

            $city = City::query()->findOrFail((int) $payload['cityId']);
            $timezone = $this->resolveTimezone($city);
            $startsAt = $this->parseDateTime($payload['startsAt'] ?? null, $timezone, 'startsAt');
            $endsAt = $this->parseDateTime($payload['endsAt'] ?? null, $timezone, 'endsAt');

            $payload['city_id'] = $city->id;
            $payload['starts_at'] = $startsAt?->shiftTimezone('UTC');
            $payload['ends_at'] = $endsAt?->shiftTimezone('UTC');
            $payload['all_day'] = (bool) $payload['allDay'];
            $payload['location_name'] = $this->nullIfBlank($payload['locationName'] ?? null);
            $payload['location_address'] = $this->nullIfBlank($payload['locationAddress'] ?? null);
            $incomingDescription = $payload['description'] ?? null;
            $incomingNormalized = $this->normalizeDescriptionInput($incomingDescription);
            $storedNormalized = $this->sanitizeDescription($this->rawDescription);

            if ($this->rawDescription !== null && $incomingNormalized === $storedNormalized) {
                $payload['description'] = $this->rawDescription;
            } else {
                $payload['description'] = $this->nullIfBlank($incomingDescription);
            }
            $payload['event_url'] = $this->nullIfBlank($payload['eventUrl'] ?? null);

            unset(
                $payload['cityId'],
                $payload['startsAt'],
                $payload['endsAt'],
                $payload['allDay'],
                $payload['locationName'],
                $payload['locationAddress'],
                $payload['eventUrl']
            );

            $isUpdating = $this->event?->exists === true;

            if ($isUpdating) {
                $this->event->update($payload);
            } else {
                $this->event = Event::create($payload);
            }

            return redirect()->route('admin.events.index')->with('toast', [
                'heading' => $isUpdating ? __('Event updated') : __('Event created'),
                'message' => __('Your changes have been saved.'),
                'variant' => 'success',
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatchToast(__('Event save failed'), __('We could not save the event.'), 'danger');

            return null;
        }
    }

    public function render(): View
    {
        $cities = City::query()->orderBy('name')->get();
        $timezone = $this->resolveTimezone($this->resolveCity());

        return view('livewire.admin.events.form', [
            'cities' => $cities,
            'timezone' => $timezone,
            'title' => $this->event ? __('Edit Event') : __('Create Event'),
        ])->layout('layouts.admin', [
            'title' => $this->event ? __('Edit Event') : __('Create Event'),
        ]);
    }

    protected function rules(): array
    {
        return [
            'cityId' => ['required', 'integer', 'exists:cities,id'],
            'title' => ['required', 'string', 'max:255'],
            'startsAt' => ['required', 'date_format:Y-m-d\\TH:i'],
            'endsAt' => ['nullable', 'date_format:Y-m-d\\TH:i'],
            'allDay' => ['boolean'],
            'locationName' => ['nullable', 'string', 'max:255'],
            'locationAddress' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'eventUrl' => ['nullable', 'url', 'max:2000'],
        ];
    }

    private function resolveCity(): ?City
    {
        if ($this->cityId) {
            return City::query()->find($this->cityId);
        }

        return $this->event?->city;
    }

    private function resolveTimezone(?City $city): string
    {
        return $city?->timezone ?? config('app.timezone', 'UTC');
    }

    private function formatDateTime(?DateTimeInterface $value, string $timezone): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::instance($value)->shiftTimezone($timezone)->format('Y-m-d\\TH:i');
    }

    private function parseDateTime(?string $value, string $timezone, string $field): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d\\TH:i', $value, $timezone);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                $field => __('Invalid date/time format.'),
            ]);
        }
    }

    private function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function sanitizeDescription(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = preg_replace('/<\s*br\s*\/?>/i', "\n", $value) ?? $value;
        $value = preg_replace('/<\/\s*(p|div|li|tr|h[1-6])\s*>/i', "\n\n", $value) ?? $value;
        $value = strip_tags($value);
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? $value;

        return trim($value);
    }

    private function normalizeDescriptionInput(?string $value): string
    {
        return trim($value ?? '');
    }

    private function dispatchToast(string $heading, string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', heading: $heading, message: $message, variant: $variant);
    }
}
