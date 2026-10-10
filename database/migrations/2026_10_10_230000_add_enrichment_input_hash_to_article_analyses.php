<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_analyses', function (Blueprint $table) {
            $table->string('enrichment_input_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('article_analyses', function (Blueprint $table) {
            $table->dropColumn('enrichment_input_hash');
        });
    }
};
