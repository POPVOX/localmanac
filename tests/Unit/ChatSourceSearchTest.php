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
    ]);

    $fallback = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'City Government',
        'tags' => ['government'],
        'priority' => 10,
    ]);

    ChatSource::factory()->create([
        'city_id' => $otherCity->id,
        'name' => 'Other City',
        'priority' => 10,
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

    $city = City::factory()->create();
    $otherCity = City::factory()->create();

    $highPriority = ChatSource::factory()->create([
        'city_id' => $city->id,
        'priority' => 10,
    ]);

    $lowPriority = ChatSource::factory()->create([
        'city_id' => $city->id,
        'priority' => 2,
    ]);

    ChatSource::factory()->create([
        'city_id' => $otherCity->id,
        'priority' => 99,
    ]);

    $selector = app(ChatSourceSelector::class);
    $results = $selector->select($city->id, 'trash pickup', 2);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->all())->toBe([
            $highPriority->id,
            $lowPriority->id,
        ]);
});
