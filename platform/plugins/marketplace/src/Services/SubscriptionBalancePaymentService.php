<?php

namespace Botble\Marketplace\Services;

use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Enums\RevenueTypeEnum;
use Botble\Marketplace\Models\Revenue;
use Botble\Marketplace\Models\VendorInfo;
use Botble\Marketplace\Models\VendorSubscription;
use Illuminate\Support\Facades\DB;

/**
 * Pays for a subscription out of the vendor's marketplace balance.
 *
 * This is also the only auto-renew mechanism we support: no installed payment gateway
 * can charge a stored card off-session.
 */
class SubscriptionBalancePaymentService
{
    public function availableBalance(Customer $vendor): float
    {
        return (float) ($vendor->vendorInfo->balance ?? 0);
    }

    public function canPay(Customer $vendor, float $amount): bool
    {
        return $amount > 0 && $this->availableBalance($vendor) >= $amount;
    }

    /**
     * Debit the vendor's balance for this subscription. Returns false when the balance
     * is not enough, leaving the subscription untouched.
     */
    public function pay(VendorSubscription $subscription): bool
    {
        $vendor = $subscription->customer;
        $amount = (float) $subscription->amount;

        if (! $vendor || ! $this->canPay($vendor, $amount)) {
            return false;
        }

        return DB::transaction(function () use ($vendor, $subscription, $amount) {
            $vendorInfo = VendorInfo::query()
                ->where('customer_id', $vendor->getKey())
                ->lockForUpdate()
                ->first();

            if (! $vendorInfo || (float) $vendorInfo->balance < $amount) {
                return false;
            }

            $currentBalance = (float) $vendorInfo->balance;

            Revenue::query()->create([
                'customer_id' => $vendor->getKey(),
                'sub_amount' => 0,
                'fee' => 0,
                // Stored negative: this is money leaving the vendor's wallet.
                'amount' => -$amount,
                'current_balance' => $currentBalance,
                'currency' => $subscription->currency ?: get_application_currency()->title,
                'description' => trans('plugins/marketplace::subscription.vendor.menu') . ': ' . $subscription->planName(),
                'type' => RevenueTypeEnum::SUBSCRIPTION_FEE,
            ]);

            $vendorInfo->balance = $currentBalance - $amount;
            $vendorInfo->save();

            $subscription->fill(['payment_channel' => 'balance'])->save();

            return true;
        });
    }
}
