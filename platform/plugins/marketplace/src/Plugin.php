<?php

namespace Botble\Marketplace;

use Botble\PluginManagement\Abstracts\PluginOperationAbstract;
use Botble\Setting\Facades\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class Plugin extends PluginOperationAbstract
{
    public static function remove(): void
    {
        Schema::dropIfExists('mp_vendor_subscription_invoices');
        Schema::dropIfExists('mp_vendor_subscription_logs');
        Schema::dropIfExists('mp_vendor_subscriptions');
        Schema::dropIfExists('mp_subscription_plans');
        Schema::dropIfExists('mp_messages');
        Schema::dropIfExists('mp_vendor_info');
        Schema::dropIfExists('mp_customer_revenues');
        Schema::dropIfExists('mp_customer_withdrawals');
        Schema::dropIfExists('mp_category_sale_commissions');
        Schema::dropIfExists('mp_stores_translations');

        Schema::table('ec_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('ec_orders', 'store_id')) {
                $table->dropColumn('store_id');
            }
        });

        Schema::table('ec_products', function (Blueprint $table): void {
            if (Schema::hasColumn('ec_products', 'unpublished_by_subscription_at')) {
                $table->dropColumn('unpublished_by_subscription_at');
            }

            if (Schema::hasColumn('ec_products', 'store_id')) {
                $table->dropColumn('store_id');
            }

            if (Schema::hasColumn('ec_products', 'approved_by')) {
                $table->dropColumn('approved_by');
            }
        });

        Schema::table('ec_customers', function (Blueprint $table): void {
            if (Schema::hasColumn('ec_customers', 'is_vendor')) {
                $table->dropColumn('is_vendor');
            }

            if (Schema::hasColumn('ec_customers', 'balance')) {
                $table->dropColumn('balance');
            }

            if (Schema::hasColumn('ec_customers', 'vendor_info_id')) {
                $table->dropColumn('vendor_info_id');
            }

            if (Schema::hasColumn('ec_customers', 'vendor_verified_at')) {
                $table->dropColumn('vendor_verified_at');
            }
        });

        Schema::dropIfExists('mp_stores');

        Setting::delete([
            'marketplace_enable_commission_fee_for_each_category',
            'marketplace_check_valid_signature',
            'marketplace_verify_vendor',
            'marketplace_enable_product_approval',
            'marketplace_hide_store_phone_number',
            'marketplace_hide_store_email',
            'marketplace_allow_vendor_manage_shipping',
            'marketplace_fee_per_order',
            'marketplace_fee_withdrawal',
            'marketplace_payout_methods',
            'marketplace_mode',
            'marketplace_subscription_unpublish_products_on_expired',
            'marketplace_subscription_grace_period_days',
            'marketplace_subscription_reminder_days',
            'marketplace_subscription_allow_balance_payment',
            'marketplace_subscription_require_admin_approval',
            'marketplace_subscription_payment_methods',
            'marketplace_subscription_allow_vendor_cancel',
            'marketplace_subscription_tax_enabled',
            'marketplace_subscription_invoice_prefix',
        ]);
    }
}
