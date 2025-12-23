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
        Schema::table('article_bodies', function (Blueprint $table) {
            $table->string('extraction_status', 50)->default('success');
            $table->text('extraction_error')->nullable();
            $table->json('extraction_meta')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('article_bodies', function (Blueprint $table) {
            $table->dropColumn([
                'extraction_status',
                'extraction_error',
                'extraction_meta',
            ]);
        });
    }
};
