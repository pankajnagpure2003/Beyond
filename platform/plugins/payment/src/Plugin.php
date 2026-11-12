<?php

namespace Botble\Payment;

use Botble\PluginManagement\Abstracts\PluginOperationAbstract;
use Botble\Setting\Facades\Setting;
use Illuminate\Support\Facades\Schema;

class Plugin extends PluginOperationAbstract
{
    public static function remove(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_logs');

        // Only this plugin's own built-in methods (COD, bank transfer) are cleaned up here.
        // Third-party gateway settings (payment_stripe_*, payment_paypal_*, ...) are owned by
        // their own plugins, which may still be installed when this one is deleted - removing
        // them from here would destroy another plugin's configuration.
        Setting::delete([
            'default_payment_method',
            'payment_cod_status',
            'payment_cod_description',
            'payment_cod_name',
            'payment_cod_fee',
            'payment_cod_fee_type',
            'payment_cod_fee_fixed',
            'payment_bank_transfer_status',
            'payment_bank_transfer_description',
            'payment_bank_transfer_name',
            'payment_bank_transfer_fee',
            'payment_bank_transfer_fee_type',
            'payment_bank_transfer_fee_fixed',
        ]);
    }
}
