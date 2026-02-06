<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\IssueArea;
use Illuminate\Console\Command;

class IssueAreasSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'issue-areas:sync {--city=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Upsert shared issue areas for each city';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $items = config('issue-areas.shared', []);

        if (! is_array($items) || $items === []) {
            $this->warn('No issue areas configured.');

            return self::SUCCESS;
        }

        $cities = $this->resolveCities();

        if ($cities->isEmpty()) {
            $this->warn('No cities found to sync.');

            return self::SUCCESS;
        }

        $syncedCount = 0;

        foreach ($cities as $city) {
            $syncedCount += $this->syncIssueAreas($city->id, $items);
        }

        $this->info(sprintf('Synced issue areas for %d city(ies).', $cities->count()));
        $this->info(sprintf('Upserted %d issue area(s).', $syncedCount));

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, City>
     */
    private function resolveCities()
    {
        $cityOption = $this->option('city');

        if (! $cityOption) {
            return City::query()->orderBy('id')->get();
        }

        $query = City::query();

        if (is_numeric($cityOption)) {
            $query->where('id', (int) $cityOption);
        } else {
            $query->where('slug', (string) $cityOption);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * @param  array<int, array{name: string, slug: string, children?: array<int, array{name: string, slug: string}>}>  $items
     */
    private function syncIssueAreas(int $cityId, array $items, ?int $parentId = null): int
    {
        $count = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->stringValue($item['name'] ?? null);
            $slug = $this->stringValue($item['slug'] ?? null);

            if (! $name || ! $slug) {
                continue;
            }

            $issueArea = IssueArea::query()->updateOrCreate(
                [
                    'city_id' => $cityId,
                    'slug' => $slug,
                ],
                [
                    'parent_id' => $parentId,
                    'name' => $name,
                ]
            );

            $count++;

            $children = $item['children'] ?? [];

            if (is_array($children) && $children !== []) {
                $count += $this->syncIssueAreas($cityId, $children, $issueArea->id);
            }
        }

        return $count;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
