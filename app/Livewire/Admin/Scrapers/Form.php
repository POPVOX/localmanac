<?php

namespace App\Livewire\Admin\Scrapers;

use App\Models\City;
use App\Models\Organization;
use App\Models\Scraper;
use App\Services\Ingestion\Assistant\ScraperAssistantSourceFetcher;
use App\Services\Ingestion\Assistant\ScraperConfigDrafter;
use App\Services\Ingestion\Assistant\ScraperConfigPreviewer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
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

    private const HTML_PROFILES = [
        'documenters',
        'generic_listing',
        'civicplus_archive_pdf_list',
        'wichitadocumenters',
        'wichita_archive_pdf_list',
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

    public bool $isSuperAdmin = false;

    public bool $showAdvancedConfig = false;

    public string $assistantInputMode = 'url';

    public string $assistantSourceHtml = '';

    public string $assistantFetchedHtml = '';

    public ?string $assistantFetchRenderer = null;

    public ?string $assistantDraftProfile = null;

    /**
     * @var array<string, mixed>
     */
    public array $assistantDraftConfig = [];

    public bool $assistantHasDraft = false;

    public bool $assistantConfigMappable = true;

    public ?string $assistantConfigNotice = null;

    /**
     * @var array<int, string>
     */
    public array $assistantWarnings = [];

    public ?float $assistantConfidence = null;

    public string $assistantGenerationMode = 'heuristic';

    /**
     * @var array<int, array<string, string|null>>
     */
    public array $assistantPreviewItems = [];

    /**
     * @var array<int, string>
     */
    public array $assistantPreviewWarnings = [];

    public bool $assistantPreviewValid = false;

    public ?string $assistantPreviewError = null;

    public ?string $assistantPreviewHash = null;

    public function mount(?Scraper $scraper = null): void
    {
        $this->isSuperAdmin = Auth::user()?->can('manage-raw-scraper-config') === true;
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

            $this->initializeAssistantFromConfig($decodedConfig);
        } else {
            $this->cityId = City::query()->orderBy('name')->value('id');
            $this->runAt = Scraper::DEFAULT_RUN_AT;
            $this->config = '';
            $this->slugManuallySet = false;

            $this->resetAssistantState();
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

    public function updatedType(): void
    {
        $this->invalidateAssistantPreview();
    }

    public function updatedSourceUrl(): void
    {
        $this->invalidateAssistantPreview();
    }

    public function updatedAssistantInputMode(string $value): void
    {
        if (! in_array($value, ['url', 'paste'], true)) {
            $this->assistantInputMode = 'url';
        }

        $this->assistantFetchedHtml = '';
        $this->assistantFetchRenderer = null;
        $this->invalidateAssistantPreview();
    }

    public function toggleAdvancedConfig(): void
    {
        if (! $this->isSuperAdmin) {
            return;
        }

        $this->showAdvancedConfig = ! $this->showAdvancedConfig;
    }

    public function generateConfigDraft(
        ScraperAssistantSourceFetcher $sourceFetcher,
        ScraperConfigDrafter $drafter,
    ): void {
        try {
            $this->validateAssistantGenerationInputs();

            $sourceHtml = $this->resolveAssistantHtml($sourceFetcher);
            $draft = $drafter->draft($this->type, $this->sourceUrl, $sourceHtml);

            $this->assistantDraftProfile = $draft['profile'];
            $this->assistantDraftConfig = $draft['config'];
            $this->assistantWarnings = $draft['warnings'];
            $this->assistantConfidence = $draft['confidence'];
            $this->assistantGenerationMode = $draft['mode'];
            $this->assistantHasDraft = true;
            $this->assistantConfigMappable = true;
            $this->assistantConfigNotice = null;
            $this->config = $this->prettyPrintConfig($this->assistantDraftConfig);

            if ($this->assistantInputMode === 'paste') {
                $this->assistantSourceHtml = '';
            }

            // Avoid carrying large HTML payloads in Livewire component state.
            $this->assistantFetchedHtml = '';

            $this->invalidateAssistantPreview();

            $this->dispatchToast(__('Draft generated'), __('Review and preview the generated scraper config before saving.'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $message = trim($exception->getMessage()) !== ''
                ? $exception->getMessage()
                : __('We could not generate a scraper config draft.');

            $this->dispatchToast(__('Draft generation failed'), $message, 'danger');
        }
    }

    public function previewGeneratedConfig(ScraperConfigPreviewer $previewer): void
    {
        try {
            $draftConfig = $this->assistantDraftConfig;

            if ($draftConfig === []) {
                throw ValidationException::withMessages([
                    'config' => __('Generate a draft before previewing.'),
                ]);
            }

            if ($this->cityId === null) {
                throw ValidationException::withMessages([
                    'cityId' => __('Select a city before running preview.'),
                ]);
            }

            $preview = $previewer->preview(
                cityId: $this->cityId,
                organizationId: $this->organizationId,
                type: $this->type,
                sourceUrl: $this->sourceUrl,
                config: $draftConfig,
                scraperId: $this->scraper?->id,
            );

            $this->assistantPreviewItems = $this->formatAssistantPreviewItems($preview['items']);
            $this->assistantPreviewWarnings = $preview['warnings'];
            $this->assistantPreviewValid = (bool) $preview['valid'];
            $this->assistantPreviewError = null;
            $this->assistantPreviewHash = $this->currentAssistantDraftHash();

            if ($this->assistantPreviewValid) {
                $this->dispatchToast(__('Preview complete'), __('Preview extracted sample items successfully.'));
            } else {
                $this->dispatchToast(__('Preview needs adjustment'), __('No sample items were found. Refine and preview again.'), 'warning');
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $this->assistantPreviewValid = false;
            $this->assistantPreviewError = $exception->getMessage();
            $this->assistantPreviewItems = [];
            $this->assistantPreviewHash = null;

            $message = trim($exception->getMessage()) !== ''
                ? $exception->getMessage()
                : __('We could not run a config preview for this draft.');

            $this->dispatchToast(__('Preview failed'), $message, 'danger');
        }
    }

    public function applyTemplate(string $template): void
    {
        $config = match ($template) {
            'documenters' => [
                'default_content_type' => 'meeting_notes',
                'lang' => 'en',
                'max_items' => 50,
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
            'civicplus_archive_pdf_list', 'wichita_archive_pdf_list' => [
                'profile' => 'civicplus_archive_pdf_list',
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

        if ($template === 'documenters') {
            $this->type = 'rss';
        } elseif (in_array($template, ['generic_listing', 'civicplus_archive_pdf_list', 'wichita_archive_pdf_list'], true)) {
            $this->type = 'html';
        }

        if ($this->isSuperAdmin) {
            $this->config = $this->prettyPrintConfig($config);

            return;
        }

        $this->assistantDraftConfig = $config;
        $this->assistantDraftProfile = is_string($config['profile'] ?? null) ? (string) $config['profile'] : null;
        $this->assistantHasDraft = $config !== [];
        $this->assistantWarnings = [];
        $this->assistantConfidence = null;
        $this->assistantGenerationMode = 'template';
        $this->config = $this->prettyPrintConfig($config);

        $this->invalidateAssistantPreview();
    }

    public function save(): RedirectResponse|Redirector|null
    {
        try {
            $payload = $this->validate($this->rules());
            if (in_array($payload['frequency'], ['daily', 'weekly'], true) && $this->isBlank($payload['runAt'] ?? null)) {
                $payload['runAt'] = Scraper::DEFAULT_RUN_AT;
            }

            $config = $this->isSuperAdmin
                ? $this->decodeConfig()
                : $this->resolveNonSuperConfig();

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
                'heading' => $isUpdating ? __('Scraper updated') : __('Scraper saved'),
                'message' => __('Your changes have been saved.'),
                'variant' => 'success',
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatchToast(__('Scraper save failed'), __('We could not save the scraper.'), 'danger');

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

    private function validateAssistantGenerationInputs(): void
    {
        $this->validate([
            'type' => ['required', Rule::in(['rss', 'html'])],
            'sourceUrl' => ['required', 'url', 'max:2000'],
            'assistantInputMode' => ['required', Rule::in(['url', 'paste'])],
            'assistantSourceHtml' => [
                Rule::requiredIf(fn () => $this->assistantInputMode === 'paste'),
                'nullable',
                'string',
            ],
        ], [
            'assistantSourceHtml.required' => __('Paste source HTML is required when input mode is set to paste.'),
        ]);
    }

    private function resolveAssistantHtml(ScraperAssistantSourceFetcher $sourceFetcher): string
    {
        if ($this->assistantInputMode === 'paste') {
            $html = trim($this->assistantSourceHtml);

            if ($html === '') {
                throw ValidationException::withMessages([
                    'assistantSourceHtml' => __('Paste source HTML cannot be empty.'),
                ]);
            }

            return $html;
        }

        $fetched = $sourceFetcher->fetch($this->sourceUrl);

        // Keep only renderer metadata in component state; raw HTML can exceed Livewire payload limits.
        $this->assistantFetchedHtml = '';
        $this->assistantFetchRenderer = $fetched['renderer'];
        $this->assistantWarnings = array_merge($this->assistantWarnings, $fetched['warnings']);

        return $fetched['html'];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveNonSuperConfig(): array
    {
        $isUpdating = $this->scraper?->exists === true;

        if (! $isUpdating) {
            $this->ensureAssistantPreviewIsCurrent();

            return $this->prepareConfig($this->assistantDraftConfig);
        }

        if ($this->assistantHasDraft) {
            $this->ensureAssistantPreviewIsCurrent();

            return $this->prepareConfig($this->assistantDraftConfig);
        }

        if (! $this->assistantConfigMappable) {
            return $this->prepareConfig($this->decodeStoredConfig($this->scraper));
        }

        throw ValidationException::withMessages([
            'config' => __('Generate and preview a config draft before saving.'),
        ]);
    }

    private function ensureAssistantPreviewIsCurrent(): void
    {
        if (! $this->assistantHasDraft) {
            throw ValidationException::withMessages([
                'config' => __('Generate a config draft before saving.'),
            ]);
        }

        if (! $this->assistantPreviewValid) {
            throw ValidationException::withMessages([
                'config' => __('Run preview and confirm sample items before saving.'),
            ]);
        }

        $currentHash = $this->currentAssistantDraftHash();

        if ($currentHash === null || $this->assistantPreviewHash !== $currentHash) {
            throw ValidationException::withMessages([
                'config' => __('Draft changed after preview. Run preview again before saving.'),
            ]);
        }
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
     * @param  array<string, mixed>  $config
     */
    private function initializeAssistantFromConfig(array $config): void
    {
        $normalized = $this->normalizeExistingConfigForAssistant($this->type, $config);

        $this->assistantConfigMappable = $normalized['mappable'];
        $this->assistantConfigNotice = $normalized['notice'];

        if (! $normalized['mappable']) {
            $this->assistantHasDraft = false;
            $this->assistantDraftConfig = [];
            $this->assistantDraftProfile = null;
            $this->assistantWarnings = [];
            $this->assistantConfidence = null;
            $this->assistantGenerationMode = 'existing_unmappable';
            $this->assistantPreviewItems = [];
            $this->assistantPreviewWarnings = [];
            $this->assistantPreviewValid = false;
            $this->assistantPreviewError = null;
            $this->assistantPreviewHash = null;
            $this->assistantSourceHtml = '';
            $this->assistantFetchedHtml = '';
            $this->assistantFetchRenderer = null;
            $this->assistantInputMode = 'url';
            $this->showAdvancedConfig = $this->isSuperAdmin;

            return;
        }

        $this->assistantHasDraft = true;
        $this->assistantDraftConfig = $normalized['config'];
        $this->assistantDraftProfile = $normalized['profile'];
        $this->assistantWarnings = [];
        $this->assistantConfidence = null;
        $this->assistantGenerationMode = 'existing';
        $this->assistantPreviewItems = [];
        $this->assistantPreviewWarnings = [];
        $this->assistantPreviewValid = false;
        $this->assistantPreviewError = null;
        $this->assistantPreviewHash = null;
        $this->assistantSourceHtml = '';
        $this->assistantFetchedHtml = '';
        $this->assistantFetchRenderer = null;
        $this->assistantInputMode = 'url';
        $this->showAdvancedConfig = $this->isSuperAdmin;
    }

    private function resetAssistantState(): void
    {
        $this->assistantHasDraft = false;
        $this->assistantConfigMappable = true;
        $this->assistantConfigNotice = null;
        $this->assistantDraftConfig = [];
        $this->assistantDraftProfile = null;
        $this->assistantWarnings = [];
        $this->assistantConfidence = null;
        $this->assistantGenerationMode = 'heuristic';
        $this->assistantPreviewItems = [];
        $this->assistantPreviewWarnings = [];
        $this->assistantPreviewValid = false;
        $this->assistantPreviewError = null;
        $this->assistantPreviewHash = null;
        $this->assistantSourceHtml = '';
        $this->assistantFetchedHtml = '';
        $this->assistantFetchRenderer = null;
        $this->assistantInputMode = 'url';
        $this->showAdvancedConfig = $this->isSuperAdmin;
    }

    private function invalidateAssistantPreview(): void
    {
        $this->assistantPreviewItems = [];
        $this->assistantPreviewWarnings = [];
        $this->assistantPreviewValid = false;
        $this->assistantPreviewError = null;
        $this->assistantPreviewHash = null;
    }

    /**
     * @param  array<int, array<string, string|null>>  $items
     * @return array<int, array<string, string|null>>
     */
    private function formatAssistantPreviewItems(array $items): array
    {
        $timezone = $this->resolveAssistantPreviewTimezone();

        return array_map(function (array $item) use ($timezone): array {
            $item['published_at'] = $this->formatAssistantPreviewPublishedAt($item['published_at'] ?? null, $timezone);

            return $item;
        }, $items);
    }

    private function resolveAssistantPreviewTimezone(): string
    {
        if ($this->cityId === null) {
            return config('app.timezone', 'UTC');
        }

        $timezone = City::query()
            ->whereKey($this->cityId)
            ->value('timezone');

        if (! is_string($timezone) || trim($timezone) === '') {
            return config('app.timezone', 'UTC');
        }

        return $timezone;
    }

    private function formatAssistantPreviewPublishedAt(mixed $value, string $timezone): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)
                ->setTimezone($timezone)
                ->format('M j, Y');
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{mappable: bool, profile: string|null, config: array<string, mixed>, notice: string|null}
     */
    private function normalizeExistingConfigForAssistant(string $type, array $config): array
    {
        if ($type === 'rss') {
            return [
                'mappable' => true,
                'profile' => 'rss',
                'config' => $config,
                'notice' => null,
            ];
        }

        if ($type !== 'html') {
            return [
                'mappable' => false,
                'profile' => null,
                'config' => [],
                'notice' => __('This scraper type is not yet supported by the no-code assistant.'),
            ];
        }

        $profile = is_string($config['profile'] ?? null)
            ? trim((string) $config['profile'])
            : '';

        if ($profile === '' || ! in_array($profile, self::HTML_PROFILES, true)) {
            return [
                'mappable' => false,
                'profile' => null,
                'config' => [],
                'notice' => __('This existing config uses a custom profile. It will stay unchanged unless you generate a new draft.'),
            ];
        }

        return [
            'mappable' => true,
            'profile' => $profile,
            'config' => $config,
            'notice' => null,
        ];
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

    private function currentAssistantDraftHash(): ?string
    {
        if ($this->assistantDraftConfig === []) {
            return null;
        }

        return hash('sha256', json_encode($this->assistantDraftConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function dispatchToast(string $heading, string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', heading: $heading, message: $message, variant: $variant);
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
