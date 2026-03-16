<?php

use App\Livewire\Dashboard;
use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ArticleIssueArea;
use App\Models\City;
use App\Models\Event;
use App\Models\IssueArea;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Livewire\Livewire;

it('searches dashboard articles by full text body content', function () {
    config()->set('scout.driver', 'collection');

    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $matching = Article::create([
        'city_id' => $city->id,
        'title' => 'Permit Overview',
        'summary' => 'General city update',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::factory()->create([
        'article_id' => $matching->id,
        'cleaned_text' => 'Council packet includes bluebonnet permit signal details.',
    ]);

    $nonMatching = Article::create([
        'city_id' => $city->id,
        'title' => 'Water Main Work',
        'summary' => 'Infrastructure update',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::factory()->create([
        'article_id' => $nonMatching->id,
        'cleaned_text' => 'Road closure details for downtown construction.',
    ]);

    Livewire::test(Dashboard::class)
        ->set('cityId', $city->id)
        ->set('articleSearch', 'bluebonnet permit signal')
        ->assertSee('Permit Overview')
        ->assertDontSee('Water Main Work');
});

it('scopes dashboard article search results to the selected city', function () {
    config()->set('scout.driver', 'collection');

    $wichita = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $topeka = City::create([
        'name' => 'Topeka',
        'slug' => 'topeka',
    ]);

    $wichitaArticle = Article::create([
        'city_id' => $wichita->id,
        'title' => 'Wichita Permit Notice',
        'summary' => 'Neighborhood update',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::factory()->create([
        'article_id' => $wichitaArticle->id,
        'cleaned_text' => 'Magnolia permit bulletin for Wichita residents.',
    ]);

    $topekaArticle = Article::create([
        'city_id' => $topeka->id,
        'title' => 'Topeka Permit Notice',
        'summary' => 'Neighborhood update',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::factory()->create([
        'article_id' => $topekaArticle->id,
        'cleaned_text' => 'Magnolia permit bulletin for Topeka residents.',
    ]);

    Livewire::test(Dashboard::class)
        ->set('cityId', $wichita->id)
        ->set('articleSearch', 'magnolia permit bulletin')
        ->assertSee('Wichita Permit Notice')
        ->assertDontSee('Topeka Permit Notice');
});

it('applies issue area filtering to dashboard full text search results', function () {
    config()->set('scout.driver', 'collection');

    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $zoning = IssueArea::create([
        'city_id' => $city->id,
        'name' => 'Zoning',
        'slug' => 'zoning',
    ]);

    $transit = IssueArea::create([
        'city_id' => $city->id,
        'name' => 'Transit',
        'slug' => 'transit',
    ]);

    $zoningArticle = Article::create([
        'city_id' => $city->id,
        'title' => 'Zoning Hearing Schedule',
        'summary' => 'Board agenda',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::factory()->create([
        'article_id' => $zoningArticle->id,
        'cleaned_text' => 'Maple corridor zoning ordinance hearing timeline.',
    ]);

    ArticleIssueArea::create([
        'article_id' => $zoningArticle->id,
        'issue_area_id' => $zoning->id,
        'confidence' => 0.95,
        'source' => 'llm',
    ]);

    $transitArticle = Article::create([
        'city_id' => $city->id,
        'title' => 'Transit Detour Notice',
        'summary' => 'Bus route update',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::factory()->create([
        'article_id' => $transitArticle->id,
        'cleaned_text' => 'Maple corridor zoning ordinance hearing timeline.',
    ]);

    ArticleIssueArea::create([
        'article_id' => $transitArticle->id,
        'issue_area_id' => $transit->id,
        'confidence' => 0.95,
        'source' => 'llm',
    ]);

    Livewire::test(Dashboard::class)
        ->set('cityId', $city->id)
        ->set('activeIssueAreaId', $zoning->id)
        ->set('articleSearch', 'maple corridor zoning ordinance')
        ->assertSee('Zoning Hearing Schedule')
        ->assertDontSee('Transit Detour Notice');
});

it('falls back to sql like filtering when scout search errors on dashboard', function () {
    registerFailingScoutEngine();

    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    Article::create([
        'city_id' => $city->id,
        'title' => 'Fallback Permit Article',
        'summary' => 'Rosewood fallback needle summary',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    Article::create([
        'city_id' => $city->id,
        'title' => 'Unrelated Notice',
        'summary' => 'Different summary text',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    Livewire::test(Dashboard::class)
        ->set('cityId', $city->id)
        ->set('articleSearch', 'rosewood fallback needle')
        ->assertSee('Fallback Permit Article')
        ->assertDontSee('Unrelated Notice');
});

it('paginates dashboard feed results', function () {
    config()->set('scout.driver', 'collection');

    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    foreach (range(1, 11) as $number) {
        $label = str_pad((string) $number, 2, '0', STR_PAD_LEFT);

        Article::create([
            'city_id' => $city->id,
            'title' => 'Feed Entry '.$label,
            'summary' => 'Summary '.$number,
            'status' => 'published',
            'content_type' => 'html',
            'published_at' => now()->subMinutes(12 - $number),
        ]);
    }

    Livewire::test(Dashboard::class)
        ->set('cityId', $city->id)
        ->assertSee('Feed Entry 11')
        ->assertDontSee('Feed Entry 01')
        ->call('setPage', 2, 'articles-page')
        ->assertSee('Feed Entry 01')
        ->assertDontSee('Feed Entry 11');
});

it('renders dashboard feed dates as absolute labels', function () {
    config()->set('scout.driver', 'collection');

    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    Article::create([
        'city_id' => $city->id,
        'title' => 'Date Only Article',
        'summary' => 'A date-only article.',
        'status' => 'published',
        'content_type' => 'html',
        'published_at' => '2026-03-13 00:00:00',
    ]);

    Article::create([
        'city_id' => $city->id,
        'title' => 'Timed Article',
        'summary' => 'An article with a specific publish time.',
        'status' => 'published',
        'content_type' => 'html',
        'published_at' => '2026-03-13 20:45:00',
    ]);

    Livewire::test(Dashboard::class)
        ->set('cityId', $city->id)
        ->assertSee('Date Only Article')
        ->assertSee('Mar 13, 2026')
        ->assertSee('Timed Article')
        ->assertSee('Mar 13, 2026')
        ->assertDontSee('3:45 PM')
        ->assertDontSee('hours ago');
});

it('renders the dashboard when there are no upcoming events', function () {
    config()->set('scout.driver', 'collection');

    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    Article::create([
        'city_id' => $city->id,
        'title' => 'Article Without Events',
        'summary' => 'Dashboard should still render.',
        'status' => 'published',
        'content_type' => 'html',
        'published_at' => '2026-03-13 00:00:00',
    ]);

    Livewire::test(Dashboard::class)
        ->set('cityId', $city->id)
        ->assertSee('Article Without Events')
        ->assertSee('No upcoming events.');
});

it('renders upcoming events on the dashboard when they exist', function () {
    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Board Meeting',
        'starts_at' => now()->addDays(2)->setTime(18, 0),
        'ends_at' => now()->addDays(2)->setTime(19, 0),
        'all_day' => false,
    ]);

    Livewire::test(Dashboard::class)
        ->set('cityId', $city->id)
        ->assertSee('Board Meeting');
});

function registerFailingScoutEngine(): void
{
    config()->set('scout.driver', 'fake');

    $engine = new class extends Engine
    {
        public function update(mixed $models): void {}

        public function delete(mixed $models): void {}

        public function search(Builder $builder): array
        {
            throw new \RuntimeException('Scout unavailable');
        }

        public function paginate(Builder $builder, mixed $perPage, mixed $page): array
        {
            return $this->search($builder);
        }

        public function mapIds(mixed $results): Collection
        {
            return collect();
        }

        public function map(Builder $builder, mixed $results, mixed $model): EloquentCollection
        {
            return $model->newCollection();
        }

        public function lazyMap(Builder $builder, mixed $results, mixed $model): LazyCollection
        {
            return $this->map($builder, $results, $model)->lazy();
        }

        public function getTotalCount(mixed $results): int
        {
            return 0;
        }

        public function flush(mixed $model): void {}

        public function createIndex(mixed $name, array $options = []): mixed
        {
            return null;
        }

        public function deleteIndex(mixed $name): mixed
        {
            return null;
        }
    };

    $engineManager = app(EngineManager::class);
    $engineManager->forgetDrivers();
    $engineManager->extend('fake', fn () => $engine);
}
