<?php

namespace App\Http\Controllers;

use App\Models\City;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        return view('welcome', [
            'cities' => City::query()
                ->orderBy('name')
                ->orderBy('state')
                ->get(),
        ]);
    }
}
