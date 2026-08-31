<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table): void {
            $table->string('chat_access_code_hash')->nullable()->after('timezone');
        });

        Schema::create('city_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['city_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_user');

        Schema::table('cities', function (Blueprint $table): void {
            $table->dropColumn('chat_access_code_hash');
        });
    }
};
