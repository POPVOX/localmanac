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
        Schema::table('chat_sources', function (Blueprint $table) {
            $table->enum('frequency', ['hourly', 'daily', 'weekly'])->default('daily')->after('is_active');
            $table->timestampTz('last_run_at')->nullable()->after('frequency');
            $table->index(['is_active', 'frequency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_sources', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'frequency']);
            $table->dropColumn(['frequency', 'last_run_at']);
        });
    }
};
