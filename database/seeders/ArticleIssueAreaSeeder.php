<?php

namespace Database\Seeders;

use App\Models\ArticleIssueArea;
use Illuminate\Database\Seeder;

class ArticleIssueAreaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ArticleIssueArea::factory()->count(5)->create();
    }
}
