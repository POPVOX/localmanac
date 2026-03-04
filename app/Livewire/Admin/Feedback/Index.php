<?php

namespace App\Livewire\Admin\Feedback;

use App\Enums\SiteFeedbackType;
use App\Models\SiteFeedback;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $type = '';

    protected array $queryString = [
        'type' => ['except' => ''],
    ];

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $feedbackEntries = SiteFeedback::query()
            ->with(['user', 'city'])
            ->when($this->type !== '', fn ($query) => $query->where('type', $this->type))
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('livewire.admin.feedback.index', [
            'feedbackEntries' => $feedbackEntries,
            'feedbackTypes' => SiteFeedbackType::cases(),
        ])->layout('layouts.admin', [
            'title' => __('Feedback'),
        ]);
    }
}
