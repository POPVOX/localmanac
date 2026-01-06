<?php

namespace App\Livewire\Admin\Scrapers;

use App\Models\City;
use App\Models\Organization;
use App\Models\Scraper;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;
use Throwable;

class Form extends Component
{
    private const TYPES = ['rss', 'html', 'json'];

    private const FREQUENCIES = ['hourly', 'daily', 'weekly'];

    private const WEEKDAYS = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    public ?Scraper $scraper = null;

    public ?int $cityId = null;

    public ?int $organizationId = null;

    public string $name = '';

    public string $slug = '';

    public string $type = 'rss';

    public string $sourceUrl = '';

    public bool $isActive = true;

    public string $frequency = 'daily';

    public ?string $runAt = null;

    public ?int $runDayOfWeek = null;

    public string $config = '';

    public bool $slugManuallySet = false;

    public function mount(?Scraper $scraper = null): void
    {
        $this->scraper = $scraper?->exists ? $scraper : null;

        if ($this->scraper?->exists) {
            $decodedConfig = $this->decodeStoredConfig($this->scraper);
            $this->cityId = $this->scraper->city_id;
            $this->organizationId = $this->scraper->organization_id ?? $decodedConfig['organization_id'] ?? null;
            $this->name = $this->scraper->name;
            $this->slug = $this->scraper->slug;
            $this->type = $this->scraper->type;
            $this->sourceUrl = $this->scraper->source_url ?? '';
            $this->isActive = (bool) $this->scraper->is_enabled;
            $this->frequency = $this->scraper->frequency ?? 'daily';
            $this->runAt = $this->formatRunAt($this->scraper->run_at);
            if ($this->runAt === null && in_array($this->frequency, ['daily', 'weekly'], true)) {
                $this->runAt = Scraper::DEFAULT_RUN_AT;
            }
            $this->runDayOfWeek = $this->scraper->run_day_of_week;
            $this->config = $this->prettyPrintConfig($decodedConfig);
            $this->slugManuallySet = true;
        } else {
            $this->cityId = City::query()->orderBy('name')->value('id');
            $this->runAt = Scraper::DEFAULT_RUN_AT;
            $this->config = '';
            $this->slugManuallySet = false;
        }
    }

    public function updatedName(string $value): void
    {
        if (! $this->slugManuallySet) {
            $this->slug = Str::slug($value);
        }
    }

    public function updatedSlug(): void
    {
        $this->slugManuallySet = true;
    }

    public function updatedFrequency(string $value): void
    {
        if (! in_array($value, ['daily', 'weekly'], true)) {
            return;
        }

        if ($this->runAt === null || trim($this->runAt) === '') {
            $this->runAt = Scraper::DEFAULT_RUN_AT;
        }
    }

    public function applyTemplate(string $template): void
    {
        $config = match ($template) {
            'documenters' => [
                'profile' => 'wichitadocumenters',
                'list' => [
                    'link_selector' => 'a[href*="docs.google.com"]',
                    'link_attr' => 'href',
                    'max_links' => 25,
                ],
            ],
            'generic_listing' => [
                'profile' => 'generic_listing',
                'list' => [
                    'link_selector' => 'article a',
                    'link_attr' => 'href',
                    'max_links' => 25,
                ],
                'article' => [
                    'content_selector' => 'article',
                    'remove_selectors' => ['script', 'style', 'nav', 'footer'],
                ],
                'best_effort' => true,
            ],
            'wichita_archive_pdf_list' => [
                'profile' => 'wichita_archive_pdf_list',
                'list' => [
                    'href_contains' => 'Archive.aspx?ADID=',
                    'max_links' => 50,
                ],
                'pdf' => [
                    'extract' => true,
                ],
            ],
            default => [],
        };

        $this->config = $this->prettyPrintConfig($config);
    }

    public function save(): RedirectResponse|Redirector|null
    {
        try {
            $payload = $this->validate($this->rules());
            if (in_array($payload['frequency'], ['daily', 'weekly'], true) && $this->isBlank($payload['runAt'] ?? null)) {
                $payload['runAt'] = Scraper::DEFAULT_RUN_AT;
            }
            $config = $this->decodeConfig();
            $payload['city_id'] = (int) $payload['cityId'];
            $payload['organization_id'] = $payload['organizationId'] ?: null;
            $payload['slug'] = Str::slug($payload['slug']);
            $payload['source_url'] = $payload['sourceUrl'];
            $payload['run_at'] = $this->formatRunAt($payload['runAt']);
            $payload['run_day_of_week'] = $payload['runDayOfWeek'] !== null ? (int) $payload['runDayOfWeek'] : null;
            $payload['config'] = $this->prepareConfig($config);
            $payload = $this->normalizeSchedulePayload($payload);
            unset($payload['cityId'], $payload['organizationId'], $payload['sourceUrl']);
            unset($payload['runAt'], $payload['runDayOfWeek']);

            $isUpdating = $this->scraper?->exists === true;

            if ($isUpdating) {
                $this->scraper->update($payload);
            } else {
                $this->scraper = Scraper::create($payload);
            }

            return redirect()->route('admin.scrapers.index')->with('toast', [
                'message' => $isUpdating ? __('Scraper updated') : __('Scraper saved'),
                'variant' => 'success',
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatchToast(__('Unable to save scraper'), 'danger');

            return null;
        }
    }

    public function render(): View
    {
        $cities = City::query()->orderBy('name')->get();
        $organizations = Organization::query()->orderBy('name')->get();

        return view('livewire.admin.scrapers.form', [
            'cities' => $cities,
            'organizations' => $organizations,
            'types' => self::TYPES,
            'frequencies' => self::FREQUENCIES,
            'weekdays' => self::WEEKDAYS,
            'defaultRunAt' => Scraper::DEFAULT_RUN_AT,
            'title' => $this->scraper ? __('Edit Scraper') : __('Create Scraper'),
        ])->layout('layouts.admin', [
            'title' => $this->scraper ? __('Edit Scraper') : __('Create Scraper'),
        ]);
    }

    protected function rules(): array
    {
        return [
            'cityId' => ['required', 'integer', 'exists:cities,id'],
            'organizationId' => ['nullable', 'integer', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('scrapers', 'slug')
                    ->where(fn ($query) => $query->where('city_id', $this->cityId))
                    ->ignore($this->scraper?->id),
            ],
            'type' => ['required', Rule::in(self::TYPES)],
            'sourceUrl' => ['required', 'url', 'max:2000'],
            'isActive' => ['boolean'],
            'frequency' => ['required', Rule::in(self::FREQUENCIES)],
            'runAt' => [
                'nullable',
                'date_format:H:i',
            ],
            'runDayOfWeek' => [
                'nullable',
                'integer',
                'between:0,6',
                Rule::requiredIf(fn () => $this->frequency === 'weekly'),
            ],
            'config' => ['nullable', 'string'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>
     */
    private function prepareConfig(?array $config): array
    {
        $config = $config ?? [];

        unset($config['organization_id']);

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function prettyPrintConfig(array $config): string
    {
        if ($config === []) {
            return '';
        }

        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeStoredConfig(Scraper $scraper): array
    {
        $rawConfig = $scraper->getRawOriginal('config');

        if (is_array($rawConfig)) {
            return $rawConfig;
        }

        if (is_string($rawConfig)) {
            $decoded = $this->tryDecodeJsonString($rawConfig);

            if (is_array($decoded)) {
                return $decoded;
            }

            if (is_string($decoded)) {
                $decoded = $this->tryDecodeJsonString($decoded);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>|string|null
     */
    private function tryDecodeJsonString(string $value): array|string|null
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return [];
        }

        try {
            return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $value;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(): array
    {
        if (trim($this->config) === '') {
            return [];
        }

        try {
            $parsed = json_decode($this->config, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ValidationException::withMessages([
                'config' => __('Config must be valid JSON: :message', ['message' => $e->getMessage()]),
            ]);
        }

        if (! is_array($parsed)) {
            throw ValidationException::withMessages([
                'config' => __('Config must decode to an object or array.'),
            ]);
        }

        return $parsed;
    }

    private function dispatchToast(string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', message: $message, variant: $variant);
    }

    public function resetConfigField(): void
    {
        if ($this->scraper?->exists) {
            return;
        }

        if ($this->config !== '') {
            $this->config = '';
        }
    }

    private function formatRunAt(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->format('H:i');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeSchedulePayload(array $payload): array
    {
        if (($payload['frequency'] ?? null) === 'hourly') {
            $payload['run_at'] = null;
            $payload['run_day_of_week'] = null;
        }

        if (($payload['frequency'] ?? null) === 'daily') {
            $payload['run_day_of_week'] = null;
        }

        return $payload;
    }

    private function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
