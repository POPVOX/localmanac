<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('production')) {
            Artisan::call('horizon:terminate');
        }
    }

    public function down(): void
    {
        // Horizon will keep using the currently deployed configuration.
    }
};
