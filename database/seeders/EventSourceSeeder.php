<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\EventSource;
use Illuminate\Database\Seeder;

class EventSourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $city = City::firstOrCreate(
            ['slug' => 'wichita'],
            [
                'name' => 'Wichita',
                'state' => 'KS',
                'country' => 'US',
                'timezone' => 'America/Chicago',
            ]
        );

        EventSource::updateOrCreate(
            [
                'city_id' => $city->id,
                'source_url' => 'https://www.wichita.gov/common/modules/iCalendar/iCalendar.aspx?catID=25&feed=calendar',
            ],
            [
                'name' => 'City of Wichita Events',
                'source_type' => 'ics',
                'config' => [
                    'timezone' => 'America/Chicago',
                ],
                'frequency' => 'daily',
                'is_active' => true,
            ]
        );

        EventSource::updateOrCreate(
            [
                'city_id' => $city->id,
                'source_url' => 'https://www.wichita.gov/Calendar.aspx?view=list',
            ],
            [
                'name' => 'City of Wichita Calendar',
                'source_type' => 'html',
                'config' => [
                    'timezone' => 'America/Chicago',
                    'list' => [
                        'item_selector' => '.calendars .calendar ol li',
                        'title_selector' => 'h3 a span',
                        'date_selector' => '.subHeader .date',
                        'link_selector' => 'h3 a',
                        'link_attr' => 'href',
                        'location_selector' => '.subHeader .eventLocation .name',
                        'max_items' => 25,
                    ],
                    'detail' => [
                        'enabled' => false,
                    ],
                ],
                'frequency' => 'daily',
                'is_active' => true,
            ]
        );

        EventSource::updateOrCreate(
            [
                'city_id' => $city->id,
                'source_url' => 'https://wichitalibrary.libnet.info/eeventcaldata?event_type=0',
            ],
            [
                'name' => 'Wichita Public Library',
                'source_type' => 'json_api',
                'config' => [
                    'profile' => 'wichita_libnet_libcal',
                    'json' => [
                        'root_path' => '',
                        'days' => 43,
                        'req' => [
                            'private' => false,
                            'locations' => [],
                            'ages' => [],
                            'types' => [],
                        ],
                    ],
                ],
                'frequency' => 'daily',
                'is_active' => true,
            ]
        );

        EventSource::updateOrCreate(
            [
                'city_id' => $city->id,
                'source_url' => 'https://www.visitwichita.com/includes/rest_v2/plugins_events_events_by_date/find/',
            ],
            [
                'name' => 'Visit Wichita',
                'source_type' => 'json_api',
                'config' => [
                    'profile' => 'visit_wichita_simpleview',
                    'json' => [
                        'root_path' => 'docs.docs',
                    ],
                ],
                'frequency' => 'daily',
                'is_active' => true,
            ]
        );
    }
}
