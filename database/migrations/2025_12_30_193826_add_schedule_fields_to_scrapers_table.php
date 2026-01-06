<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scrapers', function (Blueprint $table) {
            $table->enum('frequency', ['hourly', 'daily', 'weekly'])->default('daily');
            $table->time('run_at')->nullable();
            $table->tinyInteger('run_day_of_week')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scrapers', function (Blueprint $table) {
            $table->dropColumn(['frequency', 'run_at', 'run_day_of_week']);
        });
    }
};
