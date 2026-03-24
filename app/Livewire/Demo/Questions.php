<?php

namespace App\Livewire\Demo;

use App\Models\City;
use App\Services\Chat\AskService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

class Questions extends Component
{
    public string $question = '';

    /**
     * @var array<int, array{
     *     role: string,
     *     content: string,
     *     citations?: array<int, array{title: string, source_url: string, type: string}>
     * }>
     */
    public array $messages = [];

    public ?int $cityId = null;

    public function mount(): void
    {
        $this->cityId = request()->integer('city_id') ?: null;
    }

    public function ask(): void
    {
        $question = trim($this->question);

        if ($question === '') {
            return;
        }

        $this->messages[] = [
            'role' => 'user',
            'content' => $question,
        ];

        try {
            $city = $this->resolveCity();
            $response = app(AskService::class)->answer(
                question: $question,
                cityId: $city?->id,
                citySlug: null,
            );

            $this->messages[] = [
                'role' => 'assistant',
                'content' => $response['answer'] ?? '',
                'citations' => $response['citations'] ?? [],
            ];
        } catch (Throwable $exception) {
            report($exception);

            $this->messages[] = [
                'role' => 'assistant',
                'content' => __('Sorry, something went wrong while answering that.'),
            ];
        }

        $this->question = '';
    }

    public function render(): View
    {
        $city = $this->resolveCity();

        return view('livewire.demo.questions', [
            'city' => $city,
        ])->layout('layouts.app-dashboard');
    }

    private function resolveCity(): ?City
    {
        if ($this->cityId) {
            return City::query()->find($this->cityId);
        }

        return City::query()
            ->where('slug', 'wichita')
            ->first()
            ?? City::query()->first();
    }
}
