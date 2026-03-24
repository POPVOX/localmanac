<?php

namespace App\Livewire\Admin\ChatSources;

use App\Jobs\IngestChatSource;
use App\Models\ChatSource;
use App\Models\ChatSourceIngestionRun;
use App\Models\City;
use App\Services\Chat\Ingestion\ChatSourceIngestionRunner;
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

    public string $frequency = 'daily';

    public string $linkFollowMode = 'auto';

    public int $linkLimit = 6;

    public string $crawlRenderer = 'auto';

    public bool $showAdvanced = false;

    public function mount(?ChatSource $source = null): void
    {
        $this->source = $source?->exists ? $source : null;

        if ($this->source) {
            $this->expireStaleRuns($this->source->id);
            $this->source = $this->source->load('latestRun');
            $this->cityId = $this->source->city_id;
            $this->name = $this->source->name;
            $this->sourceUrl = $this->source->source_url;
            $this->description = $this->source->description;
            $this->tags = implode(', ', $this->source->tags ?? []);
            $this->priority = (int) $this->source->priority;
            $this->isActive = (bool) $this->source->is_active;
            $this->frequency = $this->source->frequency ?? 'daily';
            $this->linkFollowMode = $this->source->link_follow_mode ?? 'auto';
            $this->linkLimit = (int) ($this->source->link_limit ?? 6);
            $this->crawlRenderer = $this->source->crawl_renderer ?? 'auto';
        } else {
            $this->cityId = $this->cityId ?? City::query()->orderBy('name')->value('id');
        }
    }

    public function toggleAdvanced(): void
    {
        $this->showAdvanced = ! $this->showAdvanced;
    }

    public function queueRun(): void
    {
        if (! $this->source) {
            return;
        }

        $run = null;

        try {
            $this->expireStaleRuns($this->source->id);
            $this->source->loadMissing('latestRun');

            if (! $this->source->is_active) {
                $this->dispatchToast(__('Source disabled'), __('Enable it before queuing a run.'), 'danger');

                return;
            }

            $hasActiveRun = $this->source->runs()->freshActive()->exists();

            if ($hasActiveRun) {
                $this->dispatchToast(__('Already running'), __('A run is already queued or in progress.'), 'warning');

                return;
            }

            $run = app(ChatSourceIngestionRunner::class)->createRun($this->source);

            dispatch(new IngestChatSource($this->source->id, false, $run->id));

            $this->dispatchToast(__('Run queued'), __('We will ingest this source in the background.'));

            $this->refreshSource();
        } catch (Throwable $exception) {
            if ($run) {
                $run->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error_class' => $exception::class,
                    'error_message' => __('Failed to dispatch run job: :message', ['message' => $exception->getMessage()]),
                ]);
            }

            report($exception);

            $this->dispatchToast(__('Queue failed'), __('We could not queue this run.'), 'danger');
        }
    }

    public function save(): RedirectResponse|Redirector|null
    {
        try {
            $payload = $this->validate($this->rules());
            $payload['city_id'] = (int) $payload['cityId'];
            $payload['source_url'] = $payload['sourceUrl'];
            $payload['is_active'] = (bool) $payload['isActive'];
            $payload['frequency'] = $payload['frequency'];
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
            'frequencies' => ChatSource::FREQUENCIES,
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
            'frequency' => ['required', Rule::in(ChatSource::FREQUENCIES)],
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

    private function refreshSource(): void
    {
        if (! $this->source) {
            return;
        }

        $this->expireStaleRuns($this->source->id);
        $this->source->refresh()->load('latestRun');
    }

    private function expireStaleRuns(int $sourceId): void
    {
        ChatSourceIngestionRun::query()
            ->where('chat_source_id', $sourceId)
            ->staleActive()
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_class' => null,
                'error_message' => __('Run timed out before the worker started.'),
                'updated_at' => now(),
            ]);
    }
}
