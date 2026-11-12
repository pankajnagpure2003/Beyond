<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mp_subscription_plans')) {
            return;
        }

        Schema::create('mp_subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->unsignedInteger('duration_value')->default(1);
            $table->string('duration_unit', 20)->default('month');
            // Quotas and feature flags, merged over SubscriptionPlan::defaultOptions().
            $table->json('options')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->string('status', 60)->default('published');
            $table->timestamps();

            $table->index(['status', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mp_subscription_plans');
    }
};
