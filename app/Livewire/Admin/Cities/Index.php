<?php

namespace App\Livewire\Admin\Cities;

use App\Services\Admin\CityOverviewQuery;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public function render(): View
    {
        $cities = app(CityOverviewQuery::class)
            ->build()
            ->paginate(12);

        return view('livewire.admin.cities.index', [
            'cities' => $cities,
        ])->layout('layouts.admin', [
            'title' => __('Cities'),
        ]);
    }
}
