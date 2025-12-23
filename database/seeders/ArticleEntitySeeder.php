<?php

namespace Database\Seeders;

use App\Models\ArticleEntity;
use Illuminate\Database\Seeder;

class ArticleEntitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ArticleEntity::factory()->count(5)->create();
    }
}
