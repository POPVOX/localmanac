<?php

namespace App\Livewire\Admin\EventSources;

use App\Jobs\RunEventSourceIngestion;
use App\Models\EventSource;
use App\Services\Ingestion\EventIngestionRunner;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

class Show extends Component
{
    public EventSource $source;

    public string $configPreview = '';

    public function mount(EventSource $source): void
    {
        $this->source = $source->load(['city', 'latestRun']);
        $this->configPreview = $this->prettyPrintConfig($source->config ?? []);
    }

    public function toggleActive(): void
    {
        try {
            $this->source->update([
                'is_active' => ! $this->source->is_active,
            ]);

            if ($this->source->is_active) {
                $this->dispatchToast(__('Source enabled'), __('Runs will be included in schedules.'));
            } else {
                $this->dispatchToast(__('Source disabled'), __('Runs will be skipped until re-enabled.'));
            }

            $this->refreshSource();
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatchToast(__('Update failed'), __('We could not update the source.'), 'danger');
        }
    }

    public function queueRun(): void
    {
        try {
            $this->source->loadMissing('latestRun');

            $hasActiveRun = $this->source
                ->runs()
                ->whereIn('status', ['queued', 'running'])
                ->exists();

            if ($hasActiveRun) {
                $this->dispatchToast(__('Already running'), __('A run is already queued or in progress.'), 'warning');

                return;
            }

            if (! $this->source->is_active) {
                $this->dispatchToast(__('Source disabled'), __('Enable it before queuing a run.'), 'danger');

                return;
            }

            if (! in_array($this->source->source_type, ['ics', 'rss', 'json', 'json_api', 'html'], true)) {
                $this->dispatchToast(__('Unsupported source type'), __('Update the source type and try again.'), 'danger');

                return;
            }

            $run = app(EventIngestionRunner::class)->createRun($this->source);

            RunEventSourceIngestion::dispatch($this->source->id, $run->id);

            $this->dispatchToast(__('Run queued'), __('We will ingest this source in the background.'));

            $this->refreshSource();
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatchToast(__('Queue failed'), __('We could not queue this run.'), 'danger');
        }
    }

    public function render(): View
    {
        $runs = $this->source->runs()->take(10)->get();

        return view('livewire.admin.event-sources.show', [
            'title' => $this->source->name ?: __('Event Source :id', ['id' => $this->source->id]),
            'runs' => $runs,
        ])->layout('layouts.admin', [
            'title' => $this->source->name ?: __('Event Source :id', ['id' => $this->source->id]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function prettyPrintConfig(array $config): string
    {
        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function refreshSource(): void
    {
        $this->source->refresh()->load(['city', 'latestRun']);
    }

    private function dispatchToast(string $heading, string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', heading: $heading, message: $message, variant: $variant);
    }
}
