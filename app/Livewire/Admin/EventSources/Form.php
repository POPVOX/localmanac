<?php

namespace App\Livewire\Admin\EventSources;

use App\Models\City;
use App\Models\EventSource;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;
use Throwable;

class Form extends Component
{
    /**
     * @var list<string>
     */
    private const TYPES = ['ics', 'rss', 'json_api', 'json', 'html'];

    public ?EventSource $source = null;

    public ?int $cityId = null;

    public string $name = '';

    public string $sourceType = 'ics';

    public string $sourceUrl = '';

    public bool $isActive = true;

    public string $config = '';

    public function mount(?EventSource $source = null): void
    {
        $this->source = $source?->exists ? $source : null;

        if ($this->source?->exists) {
            $decodedConfig = $this->decodeStoredConfig($this->source);
            $this->cityId = $this->source->city_id;
            $this->name = $this->source->name;
            $this->sourceType = $this->source->source_type;
            $this->sourceUrl = $this->source->source_url ?? '';
            $this->isActive = (bool) $this->source->is_active;
            $this->config = $this->prettyPrintConfig($decodedConfig);
        } else {
            $this->cityId = City::query()->orderBy('name')->value('id');
            $this->config = '';
        }
    }

    public function applyTemplate(string $template): void
    {
        $config = match ($template) {
            'ics' => [
                'timezone' => null,
            ],
            'libcal' => [
                'profile' => 'wichita_libnet_libcal',
                'json' => [
                    'root_path' => '',
                    'days' => 43,
                    'req' => [
                        'private' => false,
                        'locations' => [],
                        'ages' => [],
                        'types' => [],
                    ],
                ],
            ],
            'visit_wichita' => [
                'profile' => 'visit_wichita_simpleview',
                'json' => [
                    'root_path' => 'docs.docs',
                ],
            ],
            'html_calendar' => [
                'list' => [
                    'item_selector' => '.calendars .calendar ol li',
                    'title_selector' => 'h3 a span',
                    'date_selector' => '.subHeader .date',
                    'link_selector' => 'h3 a',
                    'link_attr' => 'href',
                    'location_selector' => '.subHeader .eventLocation .name',
                    'max_items' => 25,
                ],
                'detail' => [
                    'enabled' => false,
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
            $config = $this->decodeConfig();

            $payload['city_id'] = (int) $payload['cityId'];
            $payload['source_type'] = $payload['sourceType'];
            $payload['source_url'] = $payload['sourceUrl'];
            $payload['is_active'] = (bool) $payload['isActive'];
            $payload['config'] = $config;

            unset($payload['cityId'], $payload['sourceType'], $payload['sourceUrl'], $payload['isActive']);

            $isUpdating = $this->source?->exists === true;

            if ($isUpdating) {
                $this->source->update($payload);
            } else {
                $this->source = EventSource::create($payload);
            }

            return redirect()->route('admin.event-sources.index')->with('toast', [
                'heading' => $isUpdating ? __('Event source updated') : __('Event source saved'),
                'message' => __('Your changes have been saved.'),
                'variant' => 'success',
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatchToast(__('Event source save failed'), __('We could not save the event source.'), 'danger');

            return null;
        }
    }

    public function render(): View
    {
        $cities = City::query()->orderBy('name')->get();

        return view('livewire.admin.event-sources.form', [
            'cities' => $cities,
            'types' => self::TYPES,
            'title' => $this->source ? __('Edit Event Source') : __('Create Event Source'),
        ])->layout('layouts.admin', [
            'title' => $this->source ? __('Edit Event Source') : __('Create Event Source'),
        ]);
    }

    protected function rules(): array
    {
        return [
            'cityId' => ['required', 'integer', 'exists:cities,id'],
            'name' => ['required', 'string', 'max:255'],
            'sourceType' => ['required', Rule::in(self::TYPES)],
            'sourceUrl' => [
                'required',
                'string',
                'max:2000',
                function (string $attribute, mixed $value, callable $fail): void {
                    $url = trim((string) $value);

                    if ($url === '') {
                        return;
                    }

                    $normalized = preg_replace('/\{[^}]+\}/', '1', $url) ?? $url;

                    if (! filter_var($normalized, FILTER_VALIDATE_URL)) {
                        $fail(__('The source url field must be a valid URL.'));
                    }
                },
            ],
            'isActive' => ['boolean'],
            'config' => ['nullable', 'string'],
        ];
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
    private function decodeStoredConfig(EventSource $source): array
    {
        $rawConfig = $source->getRawOriginal('config');

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
        } catch (JsonException $exception) {
            throw ValidationException::withMessages([
                'config' => __('Config must be valid JSON: :message', ['message' => $exception->getMessage()]),
            ]);
        }

        if (! is_array($parsed)) {
            throw ValidationException::withMessages([
                'config' => __('Config must decode to an object or array.'),
            ]);
        }

        return $parsed;
    }

    private function dispatchToast(string $heading, string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', heading: $heading, message: $message, variant: $variant);
    }

    public function resetConfigField(): void
    {
        if ($this->source?->exists) {
            return;
        }

        if ($this->config !== '') {
            $this->config = '';
        }
    }
}
