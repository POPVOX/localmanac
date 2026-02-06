<?php

namespace Database\Seeders;

use App\Models\ChatSource;
use App\Models\City;
use Illuminate\Database\Seeder;

class ChatSourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $city = City::query()->firstOrCreate(
            ['slug' => 'wichita'],
            [
                'name' => 'Wichita',
                'state' => 'KS',
                'country' => 'US',
                'timezone' => 'America/Chicago',
            ]
        );

        $sources = [
            [
                'name' => 'Wichita Code of Ordinances',
                'source_url' => 'https://library.municode.com/ks/wichita/codes/code_of_ordinances',
                'tags' => ['ordinances', 'codes'],
                'priority' => 10,
            ],
            [
                'name' => 'Wichita FAQ',
                'source_url' => 'https://www.wichita.gov/m/faq',
                'tags' => ['faq'],
                'priority' => 9,
            ],
            [
                'name' => 'Apply For (Wichita)',
                'source_url' => 'https://www.wichita.gov/1056/Apply-For',
                'tags' => ['apply', 'permits', 'licenses'],
                'priority' => 8,
            ],
            [
                'name' => 'Recycling & Trash (Wichita)',
                'source_url' => 'https://www.wichita.gov/955/Recycling-Trash',
                'tags' => ['recycling', 'trash', 'pickup'],
                'priority' => 9,
            ],
            [
                'name' => 'Wichita Government',
                'source_url' => 'https://www.wichita.gov/27/Government',
                'tags' => ['government', 'city'],
                'priority' => 7,
            ],
            [
                'name' => 'Licenses & Permits (Wichita)',
                'source_url' => 'https://www.wichita.gov/958/Licenses-Permits',
                'tags' => ['licenses', 'permits'],
                'priority' => 9,
            ],
            [
                'name' => 'Animals (Wichita)',
                'source_url' => 'https://www.wichita.gov/971/Animals',
                'tags' => ['animals', 'pets'],
                'priority' => 7,
            ],
            [
                'name' => 'Sedgwick County How Do I',
                'source_url' => 'https://www.sedgwickcounty.org/how-do-i/',
                'tags' => ['county', 'how do i', 'services'],
                'priority' => 6,
            ],
            [
                'name' => 'Wichita Public Schools',
                'source_url' => 'https://www.usd259.org/about-wps',
                'tags' => ['schools', 'education'],
                'priority' => 6,
            ],
        ];

        foreach ($sources as $source) {
            ChatSource::updateOrCreate(
                [
                    'city_id' => $city->id,
                    'source_url' => $source['source_url'],
                ],
                [
                    'name' => $source['name'],
                    'description' => $source['description'] ?? null,
                    'tags' => $source['tags'] ?? [],
                    'priority' => $source['priority'] ?? 0,
                    'is_active' => true,
                    'link_follow_mode' => 'auto',
                    'link_limit' => 6,
                ]
            );
        }
    }
}
