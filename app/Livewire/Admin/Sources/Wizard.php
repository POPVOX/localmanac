<?php

namespace App\Livewire\Admin\Sources;

use App\Models\City;
use App\Models\EventSource;
use App\Models\Organization;
use App\Services\Ingestion\Assistant\EventSourcePreviewer;
use App\Services\Ingestion\Assistant\ScraperConfigPreviewer;
use App\Services\Ingestion\Assistant\SourceDiscoveryService;
use App\Services\Ingestion\Assistant\SourceRecordCreator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;
use Throwable;

class Wizard extends Component
{
    public int $step = 1;

    public ?int $cityId = null;

    public ?int $organizationId = null;

    public string $sourceUrl = '';

    public string $name = '';

    public string $frequency = 'daily';

    public bool $isActive = true;

    public string $discoveredKind = '';

    public string $discoveredType = '';

    public string $discoveredUrl = '';

    public string $rawConfig = '';

    public ?float $confidence = null;

    /** @var array<int, string> */
    public array $reasons = [];

    /** @var array<int, string> */
    public array $warnings = [];

    /** @var array<int, array{url: string, type: string, label: string}> */
    public array $endpoints = [];

    /** @var array<int, array<string, mixed>> */
    public array $previewItems = [];

    /** @var array<int, string> */
    public array $previewWarnings = [];

    public bool $previewValid = false;

    public ?string $previewError = null;

    public ?string $previewHash = null;

    public ?string $discoveryError = null;

    public function mount(): void
    {
        $requestedCityId = request()->integer('cityId');
        $this->cityId = $requestedCityId && City::query()->whereKey($requestedCityId)->exists()
            ? $requestedCityId
            : City::query()->orderBy('name')->value('id');
    }

    public function analyze(
        SourceDiscoveryService $discovery,
        ScraperConfigPreviewer $scraperPreviewer,
        EventSourcePreviewer $eventPreviewer,
    ): void {
        $this->validate([
            'cityId' => ['required', 'integer', 'exists:cities,id'],
            'sourceUrl' => ['required', 'url:http,https', 'max:2000'],
        ]);

        $this->discoveryError = null;
        $this->previewError = null;

        try {
            $result = $discovery->discover($this->sourceUrl);
            $this->discoveredKind = $result['kind'];
            $this->discoveredType = $result['type'];
            $this->discoveredUrl = $result['source_url'];
            $this->rawConfig = json_encode($result['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
            $this->confidence = (float) $result['confidence'];
            $this->reasons = $result['reasons'];
            $this->warnings = $result['warnings'];
            $this->endpoints = $result['endpoints'];
            $this->name = trim($this->name) !== '' ? $this->name : $result['name'];
            $this->step = 2;

            $this->runPreview($scraperPreviewer, $eventPreviewer);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->discoveryError = trim($exception->getMessage()) ?: __('We could not analyze this source.');
            $this->dispatchToast(__('Source analysis failed'), $this->discoveryError, 'danger');
        }
    }

    public function preview(
        ScraperConfigPreviewer $scraperPreviewer,
        EventSourcePreviewer $eventPreviewer,
    ): void {
        $this->validateDiscoveryFields();
        $this->runPreview($scraperPreviewer, $eventPreviewer);
    }

    public function save(SourceRecordCreator $creator): RedirectResponse|Redirector|null
    {
        $payload = $this->validate([
            'cityId' => ['required', 'integer', 'exists:cities,id'],
            'organizationId' => [
                'nullable',
                'integer',
                Rule::exists('organizations', 'id')->where(fn ($query) => $query->where('city_id', $this->cityId)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'frequency' => ['required', Rule::in(['hourly', 'daily', 'weekly'])],
            'isActive' => ['boolean'],
        ]);
        $this->validateDiscoveryFields();

        if (! $this->previewValid || $this->previewHash !== $this->currentPreviewHash()) {
            throw ValidationException::withMessages([
                'sourceUrl' => __('Test the current source settings successfully before saving.'),
            ]);
        }

        try {
            $record = $creator->create(
                discovery: $this->currentDiscovery(),
                cityId: (int) $payload['cityId'],
                name: $payload['name'],
                organizationId: $this->discoveredKind === 'article' ? ($payload['organizationId'] ?: null) : null,
                frequency: $payload['frequency'],
                active: (bool) $payload['isActive'],
            );

            $route = $record instanceof EventSource
                ? route('admin.event-sources.show', $record)
                : route('admin.scrapers.show', $record);

            return redirect($route)->with('toast', [
                'heading' => __('Source added'),
                'message' => $record instanceof EventSource
                    ? __('The event source passed preview and is ready to ingest.')
                    : __('The article source passed preview and is ready to ingest.'),
                'variant' => 'success',
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatchToast(__('Source save failed'), __('We could not create the source.'), 'danger');

            return null;
        }
    }

    public function startOver(): void
    {
        $cityId = $this->cityId;
        $organizationId = $this->organizationId;
        $this->reset();
        $this->cityId = $cityId;
        $this->organizationId = $organizationId;
        $this->frequency = 'daily';
        $this->isActive = true;
        $this->step = 1;
    }

    public function updated(string $property): void
    {
        if (
            $property === 'cityId'
            && $this->organizationId !== null
            && ! Organization::query()->whereKey($this->organizationId)->where('city_id', $this->cityId)->exists()
        ) {
            $this->organizationId = null;
        }

        if (in_array($property, ['discoveredKind', 'discoveredType', 'discoveredUrl', 'rawConfig', 'cityId', 'organizationId'], true)) {
            $this->previewValid = false;
            $this->previewHash = null;
            $this->step = 2;
        }
    }

    public function render(): View
    {
        $organizations = Organization::query()
            ->when($this->cityId, fn ($query) => $query->where('city_id', $this->cityId))
            ->orderBy('name')
            ->get();

        return view('livewire.admin.sources.wizard', [
            'cities' => City::query()->orderBy('name')->get(),
            'organizations' => $organizations,
        ])->layout('layouts.admin', [
            'title' => __('Add Source'),
        ]);
    }

    private function runPreview(ScraperConfigPreviewer $scraperPreviewer, EventSourcePreviewer $eventPreviewer): void
    {
        $this->previewItems = [];
        $this->previewWarnings = [];
        $this->previewValid = false;
        $this->previewError = null;
        $this->previewHash = null;

        try {
            $config = $this->decodeConfig();
            $preview = $this->discoveredKind === 'event'
                ? $eventPreviewer->preview(
                    cityId: (int) $this->cityId,
                    type: $this->discoveredType,
                    sourceUrl: $this->discoveredUrl,
                    config: $config,
                )
                : $scraperPreviewer->preview(
                    cityId: (int) $this->cityId,
                    organizationId: $this->organizationId,
                    type: $this->discoveredType,
                    sourceUrl: $this->discoveredUrl,
                    config: $config,
                );

            $this->previewItems = $preview['items'];
            $this->previewWarnings = $preview['warnings'];
            $this->previewValid = (bool) $preview['valid'];
            $this->previewHash = $this->previewValid ? $this->currentPreviewHash() : null;
            $this->step = $this->previewValid ? 3 : 2;

            $this->dispatchToast(
                $this->previewValid ? __('Source verified') : __('Preview needs attention'),
                $this->previewValid
                    ? __('Sample items were extracted successfully.')
                    : __('No usable sample items were found.'),
                $this->previewValid ? 'success' : 'warning',
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->previewError = trim($exception->getMessage()) ?: __('The preview could not be completed.');
            $this->step = 2;
            $this->dispatchToast(__('Preview failed'), $this->previewError, 'danger');
        }
    }

    private function validateDiscoveryFields(): void
    {
        $this->validate([
            'discoveredKind' => ['required', Rule::in(['article', 'event'])],
            'discoveredType' => [
                'required',
                Rule::in($this->discoveredKind === 'event'
                    ? ['ics', 'rss', 'json', 'json_api', 'html']
                    : ['rss', 'html']),
            ],
            'discoveredUrl' => ['required', 'url:http,https', 'max:2000'],
            'rawConfig' => ['required', 'string'],
        ]);

        $this->decodeConfig();
    }

    /** @return array<string, mixed> */
    private function decodeConfig(): array
    {
        try {
            $config = json_decode($this->rawConfig, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw ValidationException::withMessages([
                'rawConfig' => __('Advanced config must be valid JSON: :message', ['message' => $exception->getMessage()]),
            ]);
        }

        if (! is_array($config)) {
            throw ValidationException::withMessages([
                'rawConfig' => __('Advanced config must decode to an object or array.'),
            ]);
        }

        return $config;
    }

    /** @return array{kind: string, type: string, source_url: string, config: array<string, mixed>} */
    private function currentDiscovery(): array
    {
        return [
            'kind' => $this->discoveredKind,
            'type' => $this->discoveredType,
            'source_url' => $this->discoveredUrl,
            'config' => $this->decodeConfig(),
        ];
    }

    private function currentPreviewHash(): string
    {
        return hash('sha256', json_encode([
            'city_id' => $this->cityId,
            'organization_id' => $this->organizationId,
            'kind' => $this->discoveredKind,
            'type' => $this->discoveredType,
            'url' => $this->discoveredUrl,
            'config' => $this->decodeConfig(),
        ], JSON_THROW_ON_ERROR));
    }

    private function dispatchToast(string $heading, string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', heading: $heading, message: $message, variant: $variant);
    }
}
