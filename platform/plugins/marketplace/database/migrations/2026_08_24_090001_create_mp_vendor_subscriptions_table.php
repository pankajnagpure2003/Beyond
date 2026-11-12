<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mp_vendor_subscriptions')) {
            return;
        }

        Schema::create('mp_vendor_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->index();
            $table->foreignId('subscription_plan_id')->nullable()->index();
            // Snapshot of the plan at purchase time so later plan edits never change
            // what an existing subscriber bought.
            $table->json('plan_data')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('currency', 10)->nullable();
            $table->string('status', 60)->default('pending');
            $table->timestamp('starts_at')->nullable();
            // Null ends_at means a lifetime plan.
            $table->timestamp('ends_at')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->foreignId('payment_id')->nullable();
            // Correlates a gateway callback or webhook back to this subscription.
            $table->string('charge_id', 191)->nullable()->index();
            $table->string('payment_channel', 60)->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('renewed_from_id')->nullable();
            $table->foreignId('created_by_id')->nullable();
            $table->string('created_by_type', 191)->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['status', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mp_vendor_subscriptions');
    }
};
