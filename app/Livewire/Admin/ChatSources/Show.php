<?php

namespace App\Livewire\Admin\ChatSources;

use App\Jobs\IngestChatSource;
use App\Models\ChatSource;
use App\Models\ChatSourceIngestionRun;
use App\Services\Chat\Ingestion\ChatSourceIngestionRunner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;
use Throwable;

class Show extends Component
{
    public ChatSource $source;

    public function mount(ChatSource $source): void
    {
        $this->expireStaleRuns($source->id);
        $this->source = $source->load(['city', 'latestRun']);
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

    public function deleteSource(): RedirectResponse|Redirector|null
    {
        try {
            $sourceName = $this->source->name;
            $this->source->delete();

            return redirect()->route('admin.sources.index')->with('toast', [
                'heading' => __('Source deleted'),
                'message' => __(':name, its run history, and its indexed answer pages were removed.', ['name' => $sourceName]),
                'variant' => 'success',
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatchToast(__('Delete failed'), __('We could not delete this source.'), 'danger');

            return null;
        }
    }

    public function render(): View
    {
        $runs = $this->source->runs()->take(10)->get();

        return view('livewire.admin.chat-sources.show', [
            'title' => $this->source->name ?: __('Chat Source :id', ['id' => $this->source->id]),
            'runs' => $runs,
        ])->layout('layouts.admin', [
            'title' => $this->source->name ?: __('Chat Source :id', ['id' => $this->source->id]),
        ]);
    }

    private function refreshSource(): void
    {
        $this->expireStaleRuns($this->source->id);
        $this->source->refresh()->load(['city', 'latestRun']);
    }

    private function dispatchToast(string $heading, string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', heading: $heading, message: $message, variant: $variant);
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
