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
        Schema::table('article_analyses', function (Blueprint $table) {
            $table->string('coverage_scope')->nullable()->after('civic_relevance_score');
            $table->decimal('local_relevance_score', 4, 3)->nullable()->after('coverage_scope');
            $table->text('locality_reason')->nullable()->after('local_relevance_score');

            $table->index('coverage_scope');
            $table->index('local_relevance_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('article_analyses', function (Blueprint $table) {
            $table->dropIndex(['coverage_scope']);
            $table->dropIndex(['local_relevance_score']);
            $table->dropColumn([
                'coverage_scope',
                'local_relevance_score',
                'locality_reason',
            ]);
        });
    }
};
