<?php

namespace Database\Seeders;

use App\Models\ArticleKeyword;
use Illuminate\Database\Seeder;

class ArticleKeywordSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ArticleKeyword::factory()->count(5)->create();
    }
}
