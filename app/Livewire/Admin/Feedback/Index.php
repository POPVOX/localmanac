<?php

namespace App\Livewire\Admin\Feedback;

use App\Enums\SiteFeedbackType;
use App\Models\City;
use App\Models\SiteFeedback;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $type = '';

    public ?int $cityId = null;

    protected array $queryString = [
        'type' => ['except' => ''],
        'cityId' => ['except' => null],
    ];

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function updatingCityId(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $feedbackEntries = SiteFeedback::query()
            ->with(['user', 'city'])
            ->when($this->type !== '', fn ($query) => $query->where('type', $this->type))
            ->when($this->cityId, fn ($query) => $query->where('city_id', $this->cityId))
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('livewire.admin.feedback.index', [
            'feedbackEntries' => $feedbackEntries,
            'feedbackTypes' => SiteFeedbackType::cases(),
            'cities' => City::query()->orderBy('name')->get(),
        ])->layout('layouts.admin', [
            'title' => __('Feedback'),
        ]);
    }
}
