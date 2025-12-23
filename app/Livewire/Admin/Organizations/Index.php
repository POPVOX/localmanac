<?php

namespace App\Livewire\Admin\Organizations;

use App\Models\City;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public ?int $cityId = null;

    protected array $queryString = [
        'cityId' => ['except' => null],
    ];

    public function updatingCityId(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $organizations = Organization::query()
            ->with('city')
            ->when($this->cityId, fn ($query) => $query->where('city_id', $this->cityId))
            ->orderBy('name')
            ->paginate(15);

        $cities = City::query()
            ->orderBy('name')
            ->get();

        return view('livewire.admin.organizations.index', [
            'organizations' => $organizations,
            'cities' => $cities,
        ])->layout('layouts.admin', [
            'title' => __('Organizations'),
        ]);
    }
}
