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
            $table->string('crawl_renderer', 20)->default('auto')->after('link_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_sources', function (Blueprint $table) {
            $table->dropColumn('crawl_renderer');
        });
    }
};
