<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\IssueArea;
use Illuminate\Database\Seeder;

class IssueAreaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $items = config('issue-areas.shared', []);

        if (! is_array($items) || $items === []) {
            return;
        }

        $cities = City::query()->get();

        foreach ($cities as $city) {
            $this->syncIssueAreas($city->id, $items);
        }
    }

    /**
     * @param  array<int, array{name: string, slug: string, children?: array<int, array{name: string, slug: string}>}>  $items
     */
    private function syncIssueAreas(int $cityId, array $items, ?int $parentId = null): void
    {
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

            $children = $item['children'] ?? [];

            if (is_array($children) && $children !== []) {
                $this->syncIssueAreas($cityId, $children, $issueArea->id);
            }
        }
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
