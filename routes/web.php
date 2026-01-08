<?php

use App\Http\Controllers\ArticleSourceController;
use App\Livewire\Admin\Cities\Form as CitiesForm;
use App\Livewire\Admin\Cities\Index as CitiesIndex;
use App\Livewire\Admin\Dashboard as AdminDashboard;
use App\Livewire\Admin\Organizations\Form as OrganizationsForm;
use App\Livewire\Admin\Organizations\Index as OrganizationsIndex;
use App\Livewire\Admin\Scrapers\Form as ScrapersForm;
use App\Livewire\Admin\Scrapers\Index as ScrapersIndex;
use App\Livewire\Admin\Scrapers\Show as ScrapersShow;
use App\Livewire\Demo\ArticleExplainer;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('demo/articles/{article}', ArticleExplainer::class)->name('demo.articles.show');
Route::get('articles/{article}/source', ArticleSourceController::class)->name('articles.source');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('profile.edit');
    Volt::route('settings/password', 'settings.password')->name('user-password.edit');
    Volt::route('settings/appearance', 'settings.appearance')->name('appearance.edit');

    Volt::route('settings/two-factor', 'settings.two-factor')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                    && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('two-factor.show');
});

Route::middleware(['auth', 'verified', 'can:access-admin'])->group(function () {
    Route::get('dashboard', AdminDashboard::class)->name('dashboard');

    Route::prefix('admin')->as('admin.')->group(function () {
        Route::get('cities', CitiesIndex::class)->name('cities.index');
        Route::get('cities/create', CitiesForm::class)->name('cities.create');
        Route::get('cities/{city}/edit', CitiesForm::class)->name('cities.edit');

        Route::get('organizations', OrganizationsIndex::class)->name('organizations.index');
        Route::get('organizations/create', OrganizationsForm::class)->name('organizations.create');
        Route::get('organizations/{organization}/edit', OrganizationsForm::class)->name('organizations.edit');

        Route::get('scrapers', ScrapersIndex::class)->name('scrapers.index');
        Route::get('scrapers/create', ScrapersForm::class)->name('scrapers.create');
        Route::get('scrapers/{scraper}/edit', ScrapersForm::class)->name('scrapers.edit');
        Route::get('scrapers/{scraper}', ScrapersShow::class)->name('scrapers.show');
    });
});
