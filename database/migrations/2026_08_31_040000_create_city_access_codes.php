<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_access_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('code_hash');
            $table->string('lookup_digest', 64)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_legacy')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_redeemed_at')->nullable();
            $table->timestamps();

            $table->unique(['city_id', 'label']);
            $table->index(['city_id', 'is_active']);
        });

        Schema::create('city_access_code_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_access_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('redeemed_at');
            $table->timestamps();

            $table->unique(['city_id', 'user_id']);
            $table->index(['city_access_code_id', 'redeemed_at']);
        });

        DB::table('cities')
            ->whereNotNull('chat_access_code_hash')
            ->where('chat_access_code_hash', '!=', '')
            ->orderBy('id')
            ->each(function (object $city): void {
                DB::table('city_access_codes')->insert([
                    'city_id' => $city->id,
                    'label' => 'Legacy city code',
                    'description' => 'Migrated from the original single city access code.',
                    'code_hash' => $city->chat_access_code_hash,
                    'lookup_digest' => null,
                    'is_active' => true,
                    'is_legacy' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('cities')
                    ->where('id', $city->id)
                    ->update(['chat_access_code_hash' => null]);
            });
    }

    public function down(): void
    {
        DB::table('city_access_codes')
            ->where('is_legacy', true)
            ->orderBy('id')
            ->each(function (object $code): void {
                DB::table('cities')
                    ->where('id', $code->city_id)
                    ->update(['chat_access_code_hash' => $code->code_hash]);
            });

        Schema::dropIfExists('city_access_code_redemptions');
        Schema::dropIfExists('city_access_codes');
    }
};
