<?php

use App\Enums\SiteFeedbackType;
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
        Schema::create('site_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', SiteFeedbackType::values());
            $table->text('message');
            $table->text('page_url');
            $table->string('route_name')->nullable();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('type');
            $table->index('created_at');
            $table->index('user_id');
            $table->index('city_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_feedback');
    }
};
