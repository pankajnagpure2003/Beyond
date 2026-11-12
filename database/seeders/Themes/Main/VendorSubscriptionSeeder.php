<?php

namespace Database\Seeders\Themes\Main;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Database\Seeders\Traits\HasVendorSubscriptionSeeder;
use Botble\Marketplace\Enums\SubscriptionDurationUnitEnum;
use Botble\Marketplace\Enums\SubscriptionStatusEnum;
use Botble\Theme\Database\Seeders\ThemeSeeder;

/**
 * Demo data for the vendor subscription feature.
 *
 * The marketplace ships in commission mode, so none of this is visible until an admin
 * switches Admin -> Settings -> Marketplace to subscription based. That flip then reveals
 * a marketplace that already has a plan ladder, vendors spread across every subscription
 * state, and the invoices and audit trail behind them — rather than empty tables.
 *
 * Runs after MarketplaceSeeder, which is what creates the vendors this attaches to.
 */
class VendorSubscriptionSeeder extends ThemeSeeder
{
    use HasVendorSubscriptionSeeder;

    public function run(): void
    {
        $plans = $this->createSubscriptionPlans($this->plans());

        $vendors = Customer::query()
            ->where('is_vendor', 1)
            ->with('store')
            ->orderBy('id')
            ->get();

        if ($vendors->isEmpty()) {
            return;
        }

        $this->createVendorSubscriptions($plans, $this->subscriptions($vendors));

        $this->saveSubscriptionSettings([
            'subscription_unpublish_products_on_expired' => 1,
            'subscription_grace_period_days' => 3,
            'subscription_reminder_days' => '7,3,1',
            'subscription_allow_balance_payment' => 1,
            'subscription_require_admin_approval' => 0,
            'subscription_allow_vendor_cancel' => 1,
            'subscription_invoice_prefix' => 'SUB-',
        ]);
    }

    /**
     * A ladder a real marketplace would plausibly sell: a free tier to get vendors in,
     * two monthly tiers, a discounted annual tier, and a one-off lifetime deal.
     */
    protected function plans(): array
    {
        return [
            [
                'name' => 'Starter',
                'description' => 'Everything you need to list your first products and make your first sale. Free forever.',
                'price' => 0,
                'duration_value' => 1,
                'duration_unit' => SubscriptionDurationUnitEnum::MONTH,
                'is_default' => true,
                'status' => BaseStatusEnum::PUBLISHED,
                'options' => [
                    'product_limit' => 10,
                    'featured_product_limit' => 0,
                    'allow_digital_products' => 0,
                    'allow_coupons' => 0,
                    'allow_product_import' => 0,
                    'listing_priority' => 0,
                ],
            ],
            [
                'name' => 'Basic',
                'description' => 'For growing shops that need more room on the shelf and their own promotions.',
                'price' => 19,
                'duration_value' => 1,
                'duration_unit' => SubscriptionDurationUnitEnum::MONTH,
                'status' => BaseStatusEnum::PUBLISHED,
                'options' => [
                    'product_limit' => 100,
                    'featured_product_limit' => 3,
                    'allow_digital_products' => 0,
                    'allow_coupons' => 1,
                    'allow_product_import' => 0,
                    'listing_priority' => 1,
                ],
            ],
            [
                'name' => 'Professional',
                'description' => 'Bulk imports, digital downloads and higher placement across the storefront.',
                'price' => 49,
                'duration_value' => 1,
                'duration_unit' => SubscriptionDurationUnitEnum::MONTH,
                'status' => BaseStatusEnum::PUBLISHED,
                'options' => [
                    'product_limit' => 500,
                    'featured_product_limit' => 15,
                    'allow_digital_products' => 1,
                    'allow_coupons' => 1,
                    'allow_product_import' => 1,
                    'listing_priority' => 5,
                ],
            ],
            [
                'name' => 'Business',
                'description' => 'Unlimited listings billed yearly — two months cheaper than paying monthly.',
                'price' => 490,
                'duration_value' => 1,
                'duration_unit' => SubscriptionDurationUnitEnum::YEAR,
                'status' => BaseStatusEnum::PUBLISHED,
                'options' => [
                    'product_limit' => -1,
                    'featured_product_limit' => 50,
                    'allow_digital_products' => 1,
                    'allow_coupons' => 1,
                    'allow_product_import' => 1,
                    'listing_priority' => 10,
                ],
            ],
            [
                'name' => 'Lifetime',
                'description' => 'Pay once, sell forever. Every Business feature with no renewal to think about.',
                'price' => 1290,
                'duration_value' => 1,
                'duration_unit' => SubscriptionDurationUnitEnum::LIFETIME,
                'status' => BaseStatusEnum::PUBLISHED,
                'options' => [
                    'product_limit' => -1,
                    'featured_product_limit' => 100,
                    'allow_digital_products' => 1,
                    'allow_coupons' => 1,
                    'allow_product_import' => 1,
                    'listing_priority' => 20,
                ],
            ],
        ];
    }

    /**
     * One row per vendor, deliberately spread across every state the admin queue and the
     * vendor dashboard can show — including the ones that need attention.
     */
    protected function subscriptions($vendors): array
    {
        $recipes = [
            // The demo login (vendor@botble.com) is first, and gets the most interesting
            // account: a paid plan mid-period with auto-renew on.
            [
                'plan' => 'Professional',
                'status' => SubscriptionStatusEnum::ACTIVE,
                'started_days_ago' => 12,
                'auto_renew' => true,
                'payment_channel' => 'stripe',
            ],
            [
                'plan' => 'Business',
                'status' => SubscriptionStatusEnum::ACTIVE,
                'started_days_ago' => 96,
                'auto_renew' => true,
                'payment_channel' => 'paypal',
            ],
            [
                'plan' => 'Lifetime',
                'status' => SubscriptionStatusEnum::ACTIVE,
                'started_days_ago' => 220,
                'payment_channel' => 'bank_transfer',
            ],
            [
                'plan' => 'Basic',
                'status' => SubscriptionStatusEnum::ACTIVE,
                'started_days_ago' => 26,
                'payment_channel' => 'balance',
                'changed_from' => 'Starter',
            ],
            // Free tier, assigned automatically the way a new vendor would receive it.
            [
                'plan' => 'Starter',
                'status' => SubscriptionStatusEnum::ACTIVE,
                'started_days_ago' => 5,
            ],
            // Waiting on the admin: an offline transfer sitting in the approval queue.
            [
                'plan' => 'Professional',
                'status' => SubscriptionStatusEnum::PENDING,
                'payment_channel' => 'bank_transfer',
            ],
            // Lapsed last month and never renewed — products unpublished.
            [
                'plan' => 'Basic',
                'status' => SubscriptionStatusEnum::EXPIRED,
                'started_days_ago' => 74,
                'payment_channel' => 'stripe',
            ],
            // Cancelled by the vendor part-way through the period.
            [
                'plan' => 'Professional',
                'status' => SubscriptionStatusEnum::CANCELLED,
                'started_days_ago' => 40,
                'payment_channel' => 'paypal',
            ],
        ];

        $subscriptions = [];

        foreach ($vendors as $index => $vendor) {
            // Fewer vendors than recipes on a trimmed demo is fine; the extra states are
            // simply not represented.
            if (! isset($recipes[$index])) {
                break;
            }

            $subscriptions[] = $recipes[$index] + ['vendor' => $vendor];
        }

        // History first, so ids ascend in the order the vendor actually bought them —
        // the dashboard and admin table both read newest-id-first.
        return array_merge($this->history($vendors), $subscriptions);
    }

    /**
     * Earlier subscriptions for a few vendors, so the ledger a visitor clicks into has
     * more than one line in it and the admin queue shows a rejected request as well as a
     * pending one.
     *
     * Ordered oldest first, which is the order they would have been bought in.
     */
    protected function history($vendors): array
    {
        $rows = [
            // The demo login started on Basic and upgraded — two invoices and a visible
            // upgrade in the activity tab.
            [
                'index' => 0,
                'plan' => 'Basic',
                'status' => SubscriptionStatusEnum::EXPIRED,
                'started_days_ago' => 74,
                'payment_channel' => 'stripe',
            ],
            [
                'index' => 0,
                'plan' => 'Basic',
                'status' => SubscriptionStatusEnum::EXPIRED,
                'started_days_ago' => 43,
                'payment_channel' => 'stripe',
            ],
            // The annual vendor's previous year, so their renewal has something behind it.
            [
                'index' => 1,
                'plan' => 'Business',
                'status' => SubscriptionStatusEnum::EXPIRED,
                'started_days_ago' => 461,
                'payment_channel' => 'paypal',
            ],
            // The free tier this vendor outgrew, cut short the day they upgraded to Basic.
            [
                'index' => 3,
                'plan' => 'Starter',
                'status' => SubscriptionStatusEnum::EXPIRED,
                'started_days_ago' => 58,
                'ends_days_ago' => 26,
            ],
            // The vendor now waiting on approval had an earlier transfer turned down.
            [
                'index' => 5,
                'plan' => 'Business',
                'status' => SubscriptionStatusEnum::REJECTED,
                'requested_days_ago' => 9,
                'payment_channel' => 'bank_transfer',
                'rejected_reason' => 'We could not match the bank transfer to this account. Please resend with your store name as the reference.',
            ],
        ];

        $subscriptions = [];

        foreach ($rows as $row) {
            $vendor = $vendors[$row['index']] ?? null;

            if (! $vendor) {
                continue;
            }

            unset($row['index']);

            $subscriptions[] = $row + ['vendor' => $vendor];
        }

        return $subscriptions;
    }
}
