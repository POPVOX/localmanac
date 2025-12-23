<?php

namespace App\Livewire\Admin\Cities;

use App\Models\City;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public function render(): View
    {
        $cities = City::query()
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.admin.cities.index', [
            'cities' => $cities,
        ])->layout('layouts.admin', [
            'title' => __('Cities'),
        ]);
    }
}
