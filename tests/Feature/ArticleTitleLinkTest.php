<?php

use App\Livewire\Dashboard;
use App\Models\Article;
use App\Models\City;
use Livewire\Livewire;

it('links article titles to the article page', function () {
    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Council Preview',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Council Preview')
        ->assertSee(route('articles.show', $article));
});
