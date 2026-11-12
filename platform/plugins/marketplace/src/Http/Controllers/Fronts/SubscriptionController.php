<?php

namespace Botble\Marketplace\Http\Controllers\Fronts;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Forms\Fronts\SubscriptionBillingForm;
use Botble\Marketplace\Http\Requests\Fronts\SubscriptionBillingRequest;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Models\VendorSubscriptionInvoice;
use Botble\Marketplace\Models\VendorSubscriptionLog;
use Botble\Marketplace\Services\CancelVendorSubscriptionService;
use Botble\Marketplace\Services\SubscribeVendorService;
use Botble\Marketplace\Services\SubscriptionBalancePaymentService;
use Botble\Marketplace\Services\SubscriptionCheckoutService;
use Botble\Marketplace\Services\SubscriptionPaymentSession;
use Botble\Marketplace\Services\SubscriptionTaxService;
use Botble\Marketplace\Services\VendorSubscriptionService;
use Botble\Payment\Enums\PaymentMethodEnum;
use Botble\Payment\Facades\PaymentMethods;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SubscriptionController extends BaseController
{
    public function __construct(
        protected VendorSubscriptionService $subscriptionService,
        protected SubscribeVendorService $subscribeService,
        protected SubscriptionBalancePaymentService $balanceService,
        protected SubscriptionCheckoutService $checkoutService,
        protected SubscriptionPaymentSession $paymentSession,
        protected SubscriptionTaxService $taxService
    ) {
    }

    public function index(CancelVendorSubscriptionService $cancelService)
    {
        $this->pageTitle(trans('plugins/marketplace::subscription.vendor.menu'));

        $vendor = auth('customer')->user();

        $subscriptionIds = VendorSubscription::query()
            ->where('customer_id', $vendor->getKey())
            ->pluck('id');

        return MarketplaceHelper::view('vendor-dashboard.subscriptions.index', [
            'subscription' => $this->subscriptionService->current($vendor),
            'pending' => $this->subscriptionService->pending($vendor),
            'latest' => $this->subscriptionService->latest($vendor),
            'usedSlots' => $this->subscriptionService->usedProductSlots($vendor),
            'productLimit' => $this->subscriptionService->productLimit($vendor),
            'canCancel' => $cancelService->isEnabled() && $this->subscriptionService->current($vendor),
            // auth('customer') yields a Customer, not the Vendor subclass that carries
            // the subscriptions() relation, so query the model directly.
            'history' => VendorSubscription::query()
                ->where('customer_id', $vendor->getKey())
                ->latest('id')
                ->limit(20)
                ->get(),
            'invoices' => VendorSubscriptionInvoice::query()
                ->forCustomer($vendor->getKey())
                ->latest('id')
                ->limit(20)
                ->get(),
            // Eager-loaded: without it each row re-queries its subscription for the plan
            // name, which is the N+1 acelle's own history page has.
            'logs' => VendorSubscriptionLog::query()
                ->whereIn('vendor_subscription_id', $subscriptionIds)
                ->with('subscription')
                ->latest('id')
                ->limit(30)
                ->get(),
        ]);
    }

    public function plans()
    {
        $this->pageTitle(trans('plugins/marketplace::subscription.vendor.choose_plan'));

        $vendor = auth('customer')->user();

        return MarketplaceHelper::view('vendor-dashboard.subscriptions.plans', [
            'plans' => SubscriptionPlan::query()->available()->get(),
            'subscription' => $this->subscriptionService->current($vendor),
            'pending' => $this->subscriptionService->pending($vendor),
            'usedSlots' => $this->subscriptionService->usedProductSlots($vendor),
            'productLimit' => $this->subscriptionService->productLimit($vendor),
        ]);
    }

    public function checkout(SubscriptionPlan $plan)
    {
        $this->abortIfPlanUnavailable($plan);

        $vendor = auth('customer')->user();

        if ($redirect = $this->guardCheckout()) {
            return $redirect;
        }

        $this->pageTitle($plan->name);

        $this->restrictPaymentMethods();

        $billing = $this->checkoutService->prefilledBilling($vendor);
        $tax = $this->taxService->calculate((float) $plan->price, $billing);

        return MarketplaceHelper::view('vendor-dashboard.subscriptions.checkout', [
            'plan' => $plan,
            'billingForm' => SubscriptionBillingForm::createFromArray($billing),
            'taxEnabled' => $this->taxService->isEnabled(),
            'taxRate' => $tax['rate'],
            'taxAmount' => $tax['amount'],
            'total' => (float) $plan->price + $tax['amount'],
            'balance' => $this->balanceService->availableBalance($vendor),
            // Balance has to cover the gross total, tax included.
            'canPayWithBalance' => MarketplaceHelper::isSubscriptionBalancePaymentEnabled()
                && $this->balanceService->canPay($vendor, (float) $plan->price + $tax['amount']),
        ]);
    }

    public function processCheckout(SubscriptionPlan $plan, SubscriptionBillingRequest $request)
    {
        $this->abortIfPlanUnavailable($plan);

        if ($redirect = $this->guardCheckout()) {
            return $redirect;
        }

        try {
            return $this->checkoutService->handle($plan, auth('customer')->user(), $request);
        } catch (RuntimeException $exception) {
            // Domain refusals (insufficient balance, disallowed gateway, gateway error)
            // carry a message written for the vendor.
            return redirect()
                ->route('marketplace.vendor.subscriptions.checkout', $plan->getKey())
                ->with('error_msg', $exception->getMessage());
        } catch (Throwable $exception) {
            // Anything else is a bug: log it, and never surface the internals.
            Log::error('Vendor subscription checkout failed', [
                'plan_id' => $plan->getKey(),
                'customer_id' => auth('customer')->id(),
                'message' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('marketplace.vendor.subscriptions.checkout', $plan->getKey())
                ->with('error_msg', trans('plugins/marketplace::subscription.vendor.payment_failed'));
        }
    }

    public function callback(Request $request)
    {
        $subscription = $this->paymentSession->resolveFromCallback($request);

        if ($subscription?->isActive()) {
            return redirect()
                ->route('marketplace.vendor.subscriptions.index')
                ->with('success_msg', trans('plugins/marketplace::subscription.vendor.subscribed_success'));
        }

        if ($subscription?->isPending()) {
            return redirect()
                ->route('marketplace.vendor.subscriptions.index')
                ->with('success_msg', trans('plugins/marketplace::subscription.vendor.claimed_success'));
        }

        return redirect()
            ->route('marketplace.vendor.subscriptions.index')
            ->with('error_msg', trans('plugins/marketplace::subscription.vendor.payment_failed'));
    }

    public function cancelPayment(Request $request)
    {
        $this->checkoutService->abandon($request);

        return redirect()
            ->route('marketplace.vendor.subscriptions.plans')
            ->with('error_msg', trans('plugins/marketplace::subscription.vendor.payment_cancelled'));
    }

    public function toggleAutoRenew(Request $request)
    {
        $vendor = auth('customer')->user();
        $subscription = $this->subscriptionService->current($vendor);

        if ($subscription) {
            $subscription->fill(['auto_renew' => $request->boolean('auto_renew')])->save();
        }

        return redirect()->route('marketplace.vendor.subscriptions.index');
    }

    public function cancel(Request $request, CancelVendorSubscriptionService $cancelService)
    {
        // Typing the word is the confirmation; a checkbox is too easy to click through
        // for something that takes effect immediately and is not refunded.
        $expected = trans('plugins/marketplace::subscription.vendor.cancel_confirm_word');

        if (mb_strtolower(trim((string) $request->input('confirmation'))) !== mb_strtolower($expected)) {
            return redirect()
                ->route('marketplace.vendor.subscriptions.index')
                ->with('error_msg', trans('plugins/marketplace::subscription.vendor.cancel_confirm_failed', [
                    'word' => $expected,
                ]));
        }

        try {
            $cancelService->handle(auth('customer')->user());
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('marketplace.vendor.subscriptions.index')
                ->with('error_msg', $exception->getMessage());
        }

        return redirect()
            ->route('marketplace.vendor.subscriptions.index')
            ->with('success_msg', trans('plugins/marketplace::subscription.vendor.cancelled_success'));
    }

    /**
     * A plan the admin has not published is not purchasable, however the vendor got
     * to the URL.
     */
    protected function abortIfPlanUnavailable(SubscriptionPlan $plan): void
    {
        abort_unless($plan->status == BaseStatusEnum::PUBLISHED, 404);
    }

    /**
     * Hide any gateway the admin has not enabled for subscriptions. An empty setting
     * means "no restriction", so a fresh install keeps every enabled method.
     */
    protected function restrictPaymentMethods(): void
    {
        $allowed = MarketplaceHelper::subscriptionPaymentMethods();

        if (! $allowed) {
            return;
        }

        foreach (array_keys(PaymentMethodEnum::labels()) as $method) {
            if (! in_array($method, $allowed, true)) {
                PaymentMethods::excludeMethod($method);
            }
        }
    }

    /**
     * A vendor with a request already awaiting approval must not start a second one.
     */
    protected function guardCheckout()
    {
        if (! MarketplaceHelper::hasVendorSubscriptions()) {
            return redirect()->route('marketplace.vendor.dashboard');
        }

        $pending = $this->subscriptionService->pending(auth('customer')->user());

        if ($pending) {
            return redirect()
                ->route('marketplace.vendor.subscriptions.index')
                ->with('error_msg', trans('plugins/marketplace::subscription.vendor.pending_description'));
        }

        return null;
    }
}
