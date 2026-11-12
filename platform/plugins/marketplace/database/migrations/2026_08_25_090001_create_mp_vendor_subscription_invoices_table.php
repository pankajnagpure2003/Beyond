<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mp_vendor_subscription_invoices')) {
            return;
        }

        Schema::create('mp_vendor_subscription_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 60)->unique();
            $table->foreignId('vendor_subscription_id')->index();
            // Denormalised so an invoice can be authorised and listed without loading
            // the subscription, and survives the subscription being deleted.
            $table->foreignId('customer_id')->index();

            // Narrative frozen at creation: the wording the vendor saw at the time.
            $table->string('title', 191);
            $table->text('description')->nullable();

            $table->decimal('sub_total', 15, 2)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('currency', 10)->nullable();

            $table->string('status', 60)->default('pending');
            $table->foreignId('payment_id')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Billing snapshot; the vendor's address may change after the fact.
            $table->string('billing_name', 191)->nullable();
            $table->string('billing_email', 191)->nullable();
            $table->string('billing_phone', 60)->nullable();
            $table->string('billing_address', 191)->nullable();
            $table->string('billing_country', 120)->nullable();
            $table->string('billing_state', 120)->nullable();
            $table->string('billing_city', 120)->nullable();
            $table->string('billing_zip_code', 20)->nullable();
            $table->string('billing_tax_id', 60)->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mp_vendor_subscription_invoices');
    }
};
