<?php

namespace App\Livewire\Admin\Events;

use App\Models\Event;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Show extends Component
{
    public Event $event;

    public string $descriptionPreview = '';

    public string $rawPayloadPreview = '';

    public function mount(Event $event): void
    {
        $this->event = $event->load(['city', 'sourceItems.eventSource']);
        $this->descriptionPreview = $this->sanitizeDescription($event->description);

        $sourceItem = $this->event->sourceItems->first();
        $this->rawPayloadPreview = $this->prettyPrintPayload($sourceItem?->raw_payload ?? []);
    }

    public function render(): View
    {
        return view('livewire.admin.events.show', [
            'title' => $this->event->title ?: __('Event :id', ['id' => $this->event->id]),
        ])->layout('layouts.admin', [
            'title' => $this->event->title ?: __('Event :id', ['id' => $this->event->id]),
        ]);
    }

    private function sanitizeDescription(?string $value): string
    {
        $value = $value ?? '';

        return trim(strip_tags($value));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function prettyPrintPayload(array $payload): string
    {
        if ($payload === []) {
            return '';
        }

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
