<?php

use App\Models\ChatSource;
use App\Models\City;
use App\Services\Chat\ChatSourceSelector;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('selects sources by question relevance and priority fallback', function () {
    config()->set('scout.driver', 'collection');

    $city = City::factory()->create();
    $otherCity = City::factory()->create();

    $relevant = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Recycling & Trash',
        'tags' => ['recycling', 'trash'],
        'priority' => 1,
        'is_active' => true,
    ]);

    $fallback = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'City Government',
        'tags' => ['government'],
        'priority' => 10,
        'is_active' => true,
    ]);

    ChatSource::factory()->create([
        'city_id' => $otherCity->id,
        'name' => 'Other City',
        'priority' => 10,
        'is_active' => true,
    ]);

    $selector = app(ChatSourceSelector::class);

    $results = $selector->select($city->id, 'trash pickup', 2);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('id'))->toContain($relevant->id)
        ->and($results->pluck('id'))->toContain($fallback->id);
});

it('falls back to priority-ordered database sources when scout search fails', function () {
    config()->set('scout.driver', 'fake');

    $engine = new class extends Engine
    {
        public function update(mixed $models): void {}

        public function delete(mixed $models): void {}

        public function search(Builder $builder): array
        {
            throw new RuntimeException('Scout unavailable');
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

    $city = City::factory()->create();
    $otherCity = City::factory()->create();

    $highPriority = ChatSource::factory()->create([
        'city_id' => $city->id,
        'priority' => 10,
        'is_active' => true,
    ]);

    $lowPriority = ChatSource::factory()->create([
        'city_id' => $city->id,
        'priority' => 2,
        'is_active' => true,
    ]);

    ChatSource::factory()->create([
        'city_id' => $otherCity->id,
        'priority' => 99,
        'is_active' => true,
    ]);

    $selector = app(ChatSourceSelector::class);
    $results = $selector->select($city->id, 'trash pickup', 2);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->all())->toBe([
            $highPriority->id,
            $lowPriority->id,
        ]);
});

it('keeps procedural questions on the same scout plus db fallback path', function () {
    config()->set('scout.driver', 'collection');

    $city = City::factory()->create();

    $scoutMatch = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Building Permit Center',
        'source_url' => 'https://example.com/demolition-permit',
        'tags' => ['permits', 'demolition'],
        'priority' => 6,
        'is_active' => true,
    ]);

    $fallback = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'City Government',
        'source_url' => 'https://example.com/government',
        'tags' => ['government'],
        'priority' => 10,
        'is_active' => true,
    ]);

    $selector = app(ChatSourceSelector::class);
    $results = $selector->select($city->id, 'How do I get a demolition permit?', 2);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('id'))->toContain($scoutMatch->id)
        ->and($results->pluck('id'))->toContain($fallback->id);
});

it('deduplicates scout and fallback results and enforces the configured limit', function () {
    config()->set('scout.driver', 'collection');

    $city = City::factory()->create();

    $first = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Trash Service',
        'source_url' => 'https://example.com/trash',
        'priority' => 10,
        'is_active' => true,
    ]);

    $second = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Recycling Service',
        'source_url' => 'https://example.com/recycling',
        'priority' => 9,
        'is_active' => true,
    ]);

    ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'City Hall',
        'source_url' => 'https://example.com/city-hall',
        'priority' => 8,
        'is_active' => true,
    ]);

    $selector = app(ChatSourceSelector::class);
    $results = $selector->select($city->id, 'trash recycling', 2);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->unique()->count())->toBe(2)
        ->and($results->pluck('id'))->toContain($first->id)
        ->and($results->pluck('id'))->toContain($second->id);
});
