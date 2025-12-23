<?php

namespace App\Livewire\Admin\Scrapers;

use App\Jobs\RunScraperRun;
use App\Models\Scraper;
use App\Services\Ingestion\ScrapeRunner;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

class Show extends Component
{
    public Scraper $scraper;

    public string $configPreview = '';

    public function mount(Scraper $scraper): void
    {
        $this->scraper = $scraper->load(['city', 'organization', 'latestRun']);
        $this->configPreview = $this->prettyPrintConfig($scraper->config ?? []);
    }

    public function toggleActive(): void
    {
        try {
            $this->scraper->update([
                'is_enabled' => ! $this->scraper->is_enabled,
            ]);

            $this->dispatchToast($this->scraper->is_enabled ? __('Scraper enabled') : __('Scraper disabled'));

            $this->refreshScraper();
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatchToast(__('Unable to update scraper'), 'danger');
        }
    }

    public function queueRun(): void
    {
        try {
            $this->scraper->loadMissing('latestRun');

            $hasActiveRun = $this->scraper
                ->runs()
                ->whereIn('status', ['queued', 'running'])
                ->exists();

            if ($hasActiveRun) {
                $this->dispatchToast(__('Already running'), 'warning');

                return;
            }

            if (! $this->scraper->is_enabled || ! in_array($this->scraper->type, ['rss', 'html'], true)) {
                $this->dispatchToast(__('Enable the scraper before queuing a run.'), 'danger');

                return;
            }

            $run = app(ScrapeRunner::class)->createRun($this->scraper);

            RunScraperRun::dispatch($run->id);

            $this->dispatchToast(__('Scrape queued'));

            $this->refreshScraper();
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatchToast(__('Unable to queue scraper'), 'danger');
        }
    }

    public function render(): View
    {
        return view('livewire.admin.scrapers.show', [
            'title' => $this->scraper->name ?: __('Scraper :id', ['id' => $this->scraper->id]),
        ])->layout('layouts.admin', [
            'title' => $this->scraper->name ?: __('Scraper :id', ['id' => $this->scraper->id]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function prettyPrintConfig(array $config): string
    {
        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function refreshScraper(): void
    {
        $this->scraper->refresh()->load(['city', 'organization', 'latestRun']);
    }

    private function dispatchToast(string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', message: $message, variant: $variant);
    }
}
