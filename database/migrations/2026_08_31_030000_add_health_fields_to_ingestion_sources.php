<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrapers', function (Blueprint $table) {
            $table->string('health_status')->default('unknown')->after('is_enabled');
            $table->timestampTz('health_checked_at')->nullable()->after('health_status');
            $table->text('health_error')->nullable()->after('health_checked_at');
            $table->json('repair_proposal')->nullable()->after('health_error');
            $table->index(['is_enabled', 'health_status']);
        });

        Schema::table('event_sources', function (Blueprint $table) {
            $table->string('health_status')->default('unknown')->after('is_active');
            $table->timestampTz('health_checked_at')->nullable()->after('health_status');
            $table->text('health_error')->nullable()->after('health_checked_at');
            $table->json('repair_proposal')->nullable()->after('health_error');
            $table->index(['is_active', 'health_status']);
        });
    }

    public function down(): void
    {
        Schema::table('scrapers', function (Blueprint $table) {
            $table->dropIndex(['is_enabled', 'health_status']);
            $table->dropColumn(['health_status', 'health_checked_at', 'health_error', 'repair_proposal']);
        });

        Schema::table('event_sources', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'health_status']);
            $table->dropColumn(['health_status', 'health_checked_at', 'health_error', 'repair_proposal']);
        });
    }
};
