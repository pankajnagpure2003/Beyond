<?php

namespace Botble\Marketplace\Services;

use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Models\VendorSubscriptionLog;
use Botble\Payment\Enums\PaymentMethodEnum;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Drives a vendor through paying for a plan.
 *
 * Three entry paths — free/balance, an online gateway, and an offline method that lands
 * in the admin approval queue — but they all converge on SubscribeVendorService.
 */
class SubscriptionCheckoutService
{
    /**
     * Payment methods that have no online confirmation and therefore always need a
     * human to sign off, mirroring how the gateway itself decides.
     */
    public const MANUAL_REVIEW_METHODS = [
        PaymentMethodEnum::COD,
        PaymentMethodEnum::BANK_TRANSFER,
    ];

    public function __construct(
        protected SubscribeVendorService $subscribeService,
        protected SubscriptionBalancePaymentService $balanceService,
        protected SubscriptionNotifier $notifier,
        protected SubscriptionPaymentSession $paymentSession,
        protected SubscriptionBillingService $billingService
    ) {
    }

    public function handle(SubscriptionPlan $plan, Customer $vendor, Request $request)
    {
        $method = (string) $request->input('payment_method');
        $autoRenew = $request->boolean('auto_renew');
        $billing = $this->billingService->fromRequest($request, $vendor);

        if ($request->boolean('billing_save_address')) {
            $this->billingService->saveAsDefaultAddress($vendor, $billing);
        }

        $attributes = ['auto_renew' => $autoRenew, 'billing_data' => $billing];

        if ($plan->isFree()) {
            $subscription = $this->subscribeService->claim($vendor, $plan, $attributes);
            $this->subscribeService->activate($subscription);

            return $this->successRedirect();
        }

        if ($method === 'balance') {
            return $this->payWithBalance($plan, $vendor, $attributes);
        }

        if (! $method) {
            throw new RuntimeException(trans('plugins/payment::payment.payment_method_is_required'));
        }

        // The checkout page hides disallowed gateways; this stops a hand-crafted POST
        // from using one anyway.
        $this->assertMethodAllowed($method);

        $subscription = $this->subscribeService->claim(
            $vendor,
            $plan,
            $attributes + ['payment_channel' => $method]
        );

        if ($this->requiresManualReview($method)) {
            $this->notifier->pendingApproval($subscription);

            return redirect()
                ->route('marketplace.vendor.subscriptions.index')
                ->with('success_msg', trans('plugins/marketplace::subscription.vendor.claimed_success'));
        }

        return $this->payWithGateway($subscription, $method, $request);
    }

    /**
     * @throws RuntimeException when the admin has not enabled this gateway for subscriptions
     */
    public function assertMethodAllowed(string $method): void
    {
        $allowed = MarketplaceHelper::subscriptionPaymentMethods();

        if ($allowed && ! in_array($method, $allowed, true)) {
            throw new RuntimeException(trans('plugins/marketplace::subscription.vendor.payment_method_not_allowed'));
        }
    }

    public function requiresManualReview(string $method): bool
    {
        return MarketplaceHelper::subscriptionRequiresAdminApproval()
            || in_array($method, self::MANUAL_REVIEW_METHODS, true);
    }

    protected function payWithBalance(SubscriptionPlan $plan, Customer $vendor, array $attributes)
    {
        if (! MarketplaceHelper::isSubscriptionBalancePaymentEnabled()) {
            throw new RuntimeException(trans('plugins/marketplace::subscription.vendor.insufficient_balance'));
        }

        $subscription = $this->subscribeService->claim(
            $vendor,
            $plan,
            $attributes + ['payment_channel' => 'balance']
        );

        if (! $this->balanceService->pay($subscription)) {
            $subscription->delete();

            throw new RuntimeException(trans('plugins/marketplace::subscription.vendor.insufficient_balance'));
        }

        $this->subscribeService->log($subscription, VendorSubscriptionLog::TYPE_PAID, ['channel' => 'balance']);
        $this->subscribeService->activate($subscription);

        return $this->successRedirect();
    }

    /**
     * Hands the charge to the gateway the same way the storefront checkout does. The
     * marker in the session is what tells our payment-data filter and the return-URL
     * filters that this round trip belongs to a subscription, not an order.
     */
    protected function payWithGateway(VendorSubscription $subscription, string $method, Request $request)
    {
        $this->paymentSession->start($subscription);

        $data = apply_filters(PAYMENT_FILTER_AFTER_POST_CHECKOUT, [
            'error' => false,
            'message' => false,
            'amount' => (float) $subscription->amount,
            'currency' => $subscription->currency,
            'type' => $method,
            'charge_id' => null,
        ], $request);

        if (! empty($data['error'])) {
            $this->paymentSession->forget();
            $subscription->delete();

            throw new RuntimeException($data['message'] ?: trans('plugins/marketplace::subscription.vendor.payment_failed'));
        }

        if (! empty($data['charge_id'])) {
            $subscription->fill(['charge_id' => $data['charge_id']])->save();
        }

        if (! empty($data['checkoutUrl'])) {
            return redirect()->away($data['checkoutUrl']);
        }

        // Charged inline with no redirect: the round trip is over, so drop the marker
        // rather than leaving it to shadow the vendor's next storefront checkout.
        $this->paymentSession->forget();

        return $this->successRedirect();
    }

    /** @return array<string, string|null> */
    public function prefilledBilling(Customer $vendor): array
    {
        return $this->billingService->prefilled($vendor);
    }

    public function abandon(Request $request): void
    {
        $subscription = $this->paymentSession->current();

        // A pending row from an abandoned gateway round trip is noise, not a request
        // the admin should have to act on.
        if ($subscription?->isPending() && ! $this->requiresManualReview((string) $subscription->payment_channel)) {
            $subscription->delete();
        }

        $this->paymentSession->forget();
    }

    protected function successRedirect()
    {
        return redirect()
            ->route('marketplace.vendor.subscriptions.index')
            ->with('success_msg', trans('plugins/marketplace::subscription.vendor.subscribed_success'));
    }
}
