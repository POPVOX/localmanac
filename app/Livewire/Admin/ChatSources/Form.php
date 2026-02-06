<?php

namespace App\Livewire\Admin\ChatSources;

use App\Models\ChatSource;
use App\Models\City;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

class Form extends Component
{
    public ?ChatSource $source = null;

    public ?int $cityId = null;

    public string $name = '';

    public string $sourceUrl = '';

    public ?string $description = null;

    public string $tags = '';

    public int $priority = 0;

    public bool $isActive = true;

    public string $linkFollowMode = 'auto';

    public int $linkLimit = 6;

    public string $crawlRenderer = 'auto';

    public bool $showAdvanced = false;

    public function mount(?ChatSource $source = null): void
    {
        $this->source = $source;

        if ($source) {
            $this->cityId = $source->city_id;
            $this->name = $source->name;
            $this->sourceUrl = $source->source_url;
            $this->description = $source->description;
            $this->tags = implode(', ', $source->tags ?? []);
            $this->priority = (int) $source->priority;
            $this->isActive = (bool) $source->is_active;
            $this->linkFollowMode = $source->link_follow_mode ?? 'auto';
            $this->linkLimit = (int) ($source->link_limit ?? 6);
            $this->crawlRenderer = $source->crawl_renderer ?? 'auto';
        } else {
            $this->cityId = $this->cityId ?? City::query()->orderBy('name')->value('id');
        }
    }

    public function toggleAdvanced(): void
    {
        $this->showAdvanced = ! $this->showAdvanced;
    }

    public function save(): RedirectResponse|Redirector|null
    {
        try {
            $payload = $this->validate($this->rules());
            $payload['city_id'] = (int) $payload['cityId'];
            $payload['source_url'] = $payload['sourceUrl'];
            $payload['is_active'] = (bool) $payload['isActive'];
            $payload['link_follow_mode'] = $payload['linkFollowMode'];
            $payload['link_limit'] = (int) $payload['linkLimit'];
            $payload['crawl_renderer'] = $payload['crawlRenderer'];
            $payload['tags'] = $this->tagsToArray($payload['tags'] ?? '');
            unset($payload['cityId'], $payload['sourceUrl'], $payload['isActive'], $payload['linkFollowMode'], $payload['linkLimit'], $payload['crawlRenderer']);

            $isUpdating = $this->source !== null;

            if ($this->source) {
                $this->source->update($payload);
            } else {
                $this->source = ChatSource::create($payload);
            }

            return redirect()->route('admin.chat-sources.index')->with('toast', [
                'heading' => $isUpdating ? __('Chat source updated') : __('Chat source created'),
                'message' => __('Your changes have been saved.'),
                'variant' => 'success',
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isConstraintViolation($exception, '23505', 'chat_sources_city_id_source_url_unique')) {
                throw ValidationException::withMessages([
                    'sourceUrl' => __('A source with this URL already exists for the selected city.'),
                ]);
            }

            if ($this->isConstraintViolation($exception, '23503', 'chat_sources_city_id_foreign')) {
                throw ValidationException::withMessages([
                    'cityId' => __('The selected city is invalid.'),
                ]);
            }

            report($exception);

            $this->dispatchToast(__('Save failed'), __('We could not save the chat source.'), 'danger');

            return null;
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatchToast(__('Save failed'), __('We could not save the chat source.'), 'danger');

            return null;
        }
    }

    public function render(): View
    {
        $cities = City::query()
            ->orderBy('name')
            ->get();

        return view('livewire.admin.chat-sources.form', [
            'cities' => $cities,
            'title' => $this->source ? __('Edit Chat Source') : __('Create Chat Source'),
        ])->layout('layouts.admin', [
            'title' => $this->source ? __('Edit Chat Source') : __('Create Chat Source'),
        ]);
    }

    protected function rules(): array
    {
        return [
            'cityId' => ['required', 'integer', 'exists:cities,id'],
            'name' => ['required', 'string', 'max:255'],
            'sourceUrl' => [
                'required',
                'url',
                'max:2048',
                Rule::unique('chat_sources', 'source_url')
                    ->where(fn ($query) => $query->where('city_id', $this->cityId))
                    ->ignore($this->source?->id),
            ],
            'description' => ['nullable', 'string'],
            'tags' => ['nullable', 'string'],
            'priority' => ['required', 'integer', 'min:0'],
            'isActive' => ['boolean'],
            'linkFollowMode' => ['required', Rule::in(['auto', 'none'])],
            'linkLimit' => ['required', 'integer', 'min:0', 'max:20'],
            'crawlRenderer' => ['required', Rule::in(['auto', 'http', 'playwright'])],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function tagsToArray(string $tags): array
    {
        $parts = preg_split('/[,\\n]/', $tags) ?: [];

        $clean = [];

        foreach ($parts as $part) {
            $value = trim($part);

            if ($value !== '') {
                $clean[] = $value;
            }
        }

        return array_values(array_unique($clean));
    }

    private function dispatchToast(string $heading, string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', heading: $heading, message: $message, variant: $variant);
    }

    private function isConstraintViolation(QueryException $exception, string $sqlState, string $constraint): bool
    {
        $state = $exception->errorInfo[0] ?? null;

        if ($state !== $sqlState) {
            return false;
        }

        return str_contains($exception->getMessage(), $constraint);
    }
}
