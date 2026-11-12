<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('mp_vendor_subscriptions') || Schema::hasColumn('mp_vendor_subscriptions', 'tax_amount')) {
            return;
        }

        Schema::table('mp_vendor_subscriptions', function (Blueprint $table): void {
            // amount stays the gross total so existing reads keep working; sub_total is
            // the net plan price and tax_rate is frozen at purchase time so an admin
            // editing the rate later cannot rewrite history.
            $table->decimal('sub_total', 15, 2)->default(0)->after('amount');
            $table->decimal('tax_amount', 15, 2)->default(0)->after('sub_total');
            $table->decimal('tax_rate', 8, 4)->default(0)->after('tax_amount');
            // What the vendor entered at checkout; the invoice snapshots from here.
            $table->json('billing_data')->nullable()->after('tax_rate');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('mp_vendor_subscriptions', 'tax_amount')) {
            return;
        }

        Schema::table('mp_vendor_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['sub_total', 'tax_amount', 'tax_rate', 'billing_data']);
        });
    }
};
