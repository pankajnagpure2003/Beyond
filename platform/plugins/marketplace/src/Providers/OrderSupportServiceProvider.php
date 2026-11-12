<?php

namespace Botble\Marketplace\Providers;

use Botble\Base\Facades\BaseHelper;
use Botble\Base\Facades\EmailHandler;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\Base\Supports\EmailHandler as EmailHandlerSupport;
use Botble\Ecommerce\Enums\OrderHistoryActionEnum;
use Botble\Ecommerce\Enums\OrderStatusEnum;
use Botble\Ecommerce\Enums\ShippingCodStatusEnum;
use Botble\Ecommerce\Enums\ShippingMethodEnum;
use Botble\Ecommerce\Enums\ShippingStatusEnum;
use Botble\Ecommerce\Facades\Cart;
use Botble\Ecommerce\Facades\Discount as DiscountFacade;
use Botble\Ecommerce\Facades\EcommerceHelper;
use Botble\Ecommerce\Facades\OrderHelper;
use Botble\Ecommerce\Models\Order;
use Botble\Ecommerce\Models\OrderHistory;
use Botble\Ecommerce\Models\Shipment;
use Botble\Ecommerce\Services\HandleApplyCouponService;
use Botble\Ecommerce\Services\HandleApplyPromotionsService;
use Botble\Ecommerce\Services\HandleRemoveCouponService;
use Botble\Ecommerce\Services\HandleShippingFeeService;
use Botble\Marketplace\Enums\RevenueTypeEnum;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Models\CategoryCommission;
use Botble\Marketplace\Models\Revenue;
use Botble\Marketplace\Models\Store;
use Botble\Marketplace\Models\VendorInfo;
use Botble\Marketplace\Services\CommissionFeeResolver;
use Botble\Payment\Enums\PaymentFeeTypeEnum;
use Botble\Payment\Enums\PaymentMethodEnum;
use Botble\Payment\Models\Payment;
use Botble\Payment\Supports\PaymentFeeHelper;
use Botble\Payment\Supports\PaymentHelper;
use Botble\PayPal\Services\Gateways\PayPalPaymentService;
use Botble\Theme\Facades\Theme;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderSupportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            add_filter(HANDLE_PROCESS_ORDER_DATA_ECOMMERCE, [$this, 'handleProcessOrder'], 100, 4);
            add_filter(HANDLE_PROCESS_POST_CHECKOUT_ORDER_DATA_ECOMMERCE, [$this, 'processPostCheckoutOrder'], 100, 5);
            add_filter(PROCESS_GET_CHECKOUT_SUCCESS_IN_ORDER, [$this, 'processGetCheckoutSuccess'], 100);
            add_filter(PROCESS_GET_PAYMENT_STATUS_ORDER, [$this, 'processGetPaymentStatus'], 100, 2);
            add_filter(SEND_MAIL_AFTER_PROCESS_ORDER_MULTI_DATA, [$this, 'sendMailAfterProcessOrder'], 100);
            add_filter(PROCESS_CHECKOUT_ORDER_DATA_ECOMMERCE, [$this, 'processShippingDiscountOrderData'], 100, 4);
            add_filter(RENDER_PRODUCTS_IN_CHECKOUT_PAGE, [$this, 'renderProductsInCheckoutPage'], 100);
            add_filter(HANDLE_POST_APPLY_COUPON_CODE_ECOMMERCE, [$this, 'processApplyCouponCode'], 100, 2);
            add_filter(HANDLE_POST_REMOVE_COUPON_CODE_ECOMMERCE, [$this, 'processRemoveCouponCode'], 100, 2);
            add_filter(PROCESS_POST_SAVE_INFORMATION_CHECKOUT_ECOMMERCE, [$this, 'processPostSaveInformation'], 100, 3);
            add_filter(PROCESS_GET_CHECKOUT_RECOVER_ECOMMERCE, [$this, 'processGetCheckoutRecover'], 100, 2);
            add_filter(PROCESS_CHECKOUT_RULES_REQUEST_ECOMMERCE, [$this, 'processCheckoutRulesRequest'], 100);
            add_filter(PROCESS_CHECKOUT_MESSAGES_REQUEST_ECOMMERCE, [$this, 'processCheckoutMessagesRequest'], 100);
            add_action(ACTION_AFTER_ORDER_STATUS_COMPLETED_ECOMMERCE, [$this, 'afterOrderStatusCompleted'], 12);
            add_filter('ecommerce_order_email_variables', [$this, 'addMoreOrderEmailVariables'], 12, 2);
            add_filter('ecommerce_checkout_discounts_query', [$this, 'modifyCheckoutDiscountsQuery'], 1, 2);
        });
    }

    public function modifyCheckoutDiscountsQuery(Builder $query, Collection $products): Builder
    {
        $storeIds = $products->pluck('original_product.store_id')->filter()->unique();

        if ($storeIds->isEmpty()) {
            return $query;
        }

        return $query->where(
            fn (Builder $query) => $query
            ->whereNull('store_id')
            ->orWhereIn('store_id', $storeIds)
        );
    }

    public function renderProductsInCheckoutPage(array|string|EloquentCollection $products): string|array|Collection
    {
        if ($products instanceof Collection) {
            $groupedProducts = $this->cartGroupByStore($products);
            $token = OrderHelper::getOrderSessionToken();
            $sessionCheckoutData = OrderHelper::getOrderSessionData($token);

            return view(
                'plugins/marketplace::orders.checkout.products',
                compact('groupedProducts', 'sessionCheckoutData')
            )->render();
        }

        return $products;
    }

    protected function cartGroupByStore(EloquentCollection $products): array|Collection
    {
        if ($products->isEmpty()) {
            return $products;
        }

        $products->loadMissing([
            'variationInfo',
            'variationInfo.configurableProduct',
            'variationInfo.configurableProduct.store',
        ]);

        $groupedProducts = collect();
        foreach ($products as $product) {
            $store = $product->original_product?->store;
            $storeId = $store && $store->exists ? $store->id : 0;
            if (! Arr::has($groupedProducts, $storeId)) {
                $groupedProducts[$storeId] = collect([
                    'store' => $store,
                    'products' => collect([$product]),
                ]);
            } else {
                $groupedProducts[$storeId]['products'][] = $product;
            }
        }

        return $groupedProducts;
    }

    public function processPostCheckoutOrder(
        array|EloquentCollection $products,
        Request $request,
        string $token,
        array $sessionCheckoutData,
        BaseHttpResponse $response
    ) {
        $groupedProducts = $this->cartGroupByStore($products);

        // Block checkout if any store in the cart is on vacation (covers items added before the vendor paused the store).
        // $groupedProducts is keyed by store id (0 = no store); collect() handles both array and Collection returns.
        $storeIdsInCart = collect($groupedProducts)->keys()->filter()->all();
        if ($storeIdsInCart) {
            $vacationStore = Store::query()
                ->whereIn('id', $storeIdsInCart)
                ->where('vacation_mode', true)
                ->first();

            if ($vacationStore) {
                return $response
                    ->setError()
                    ->setMessage(
                        $vacationStore->vacation_message
                            ?: trans('plugins/marketplace::store.forms.vacation_default_notice', ['store' => $vacationStore->name])
                    );
            }
        }

        $currentUserId = 0;
        if (auth('customer')->check()) {
            $currentUserId = auth('customer')->id();
        }

        $orders = collect();

        $discounts = collect();
        $couponCode = session('applied_coupon_code');

        $preOrders = collect();

        $mpSessionData = Arr::get($sessionCheckoutData, 'marketplace', []);

        if ($couponCode) {
            $this->processApplyCouponCode([], $request);
            $sessionCheckoutData = OrderHelper::getOrderSessionData($token);
            $couponCode = session('applied_coupon_code');
        } else {
            foreach ($mpSessionData as &$storeCheckoutData) {
                Arr::set($storeCheckoutData, 'coupon_discount_amount', 0);
                Arr::set($storeCheckoutData, 'applied_coupon_code', null);
                Arr::set($storeCheckoutData, 'is_free_shipping', false);
            }
            $sessionCheckoutData = OrderHelper::setOrderSessionData($token, ['marketplace' => $mpSessionData]);
        }

        $mpSessionData = Arr::get($sessionCheckoutData, 'marketplace', []);

        $orderIds = collect($mpSessionData ?: [])->pluck('created_order_id');

        if ($orderIds) {
            $preOrders = Order::query()->whereIn('id', $orderIds)->get();
        }

        $foundOrderIds = [];

        $promotionService = $this->app->make(HandleApplyPromotionsService::class);
        $shippingFeeService = $this->app->make(HandleShippingFeeService::class);
        $applyCouponService = $this->app->make(HandleApplyCouponService::class);

        // Resolve promotions for every store up front so the combined amount can be capped
        // before any sub-order is priced. handleCheckoutOrderByStore() then reads the capped
        // share from session; running the promotion service inside that per-store loop would
        // grant a fixed amount promotion once per vendor.
        $sessionCheckoutData = $this->applyCappedPromotionsForStores($groupedProducts, $token, $promotionService);
        $mpSessionData = Arr::get($sessionCheckoutData, 'marketplace', []);

        // Used to charge the payment gateway's flat fee_fixed surcharge only once per order
        // (on the first store's sub-order) instead of once per vendor. See the comment in
        // handleCheckoutOrderByStore() for the full rationale.
        $firstStoreId = collect($groupedProducts)->keys()->first();

        foreach ($groupedProducts as $storeId => $productsInStore) {
            $sessionStoreData = Arr::get($mpSessionData, $storeId, []);

            $order = $preOrders->firstWhere('store_id', $storeId);
            if ($order) {
                $foundOrderIds[] = $storeId;
            }

            $orders[$storeId] = $this->handleCheckoutOrderByStore(
                $sessionCheckoutData,
                $productsInStore,
                $token,
                $sessionStoreData,
                $request,
                $currentUserId,
                $order,
                $storeId,
                $discounts,
                $promotionService,
                $shippingFeeService,
                $applyCouponService,
                $storeId === $firstStoreId
            );
        }

        if ($preOrders) {
            foreach ($preOrders as $order) {
                if (! in_array($order->store_id, $foundOrderIds)) {
                    $order->delete();
                    if ($order->address && $order->address->id) {
                        $order->address->delete();
                    }
                }
            }
        }

        if ($couponCode && $discounts->count()) {
            DiscountFacade::getFacadeRoot()->afterOrderPlaced($couponCode);
        }

        // Extension point for cart-wide discounts that must be distributed across
        // the per-vendor orders BEFORE the payment amount is summed (line below).
        // Plugins (e.g. Loyalty Points) reduce each order's amount by its share so
        // the discounted total reaches the payment gateway instead of the full price.
        $orders = apply_filters('marketplace_checkout_orders_before_processing_payment', $orders, $request, $token);

        // Sync payment->amount whenever the filter above reduces an order's total.
        // Without this, the payment record retains the pre-discount amount, causing
        // the admin panel to show a mismatch between "Total amount" and "Paid amount".
        if (is_plugin_active('payment')) {
            foreach ($orders as $order) {
                if ($order->payment_id && $order->amount >= 0) {
                    $payment = Payment::query()->find($order->payment_id);
                    if ($payment && $payment->amount != $order->amount) {
                        $payment->amount = $order->amount;
                        $payment->save();
                    }
                }
            }
        }

        if (! is_plugin_active('payment') || ! $orders->pluck('amount')->sum()) {
            OrderHelper::processOrder($orders->pluck('id')->all());

            return $response
                ->setNextUrl(route('public.checkout.success', $token))
                ->setMessage(trans('plugins/ecommerce::order.order_placed_successfully'));
        }

        $totalAmount = format_price($orders->pluck('amount')->sum(), null, true);

        do_action('ecommerce_before_processing_payment', $products, $request, $token, $mpSessionData);

        $paymentData = $this->processPaymentMethodPostCheckout($request, (float) $totalAmount);

        if ($checkoutUrl = Arr::get($paymentData, 'checkoutUrl')) {
            return $response
                ->setError($paymentData['error'])
                ->setNextUrl($checkoutUrl)
                ->setData(['checkoutUrl' => $checkoutUrl])
                ->withInput()
                ->setMessage($paymentData['message']);
        }

        if ($paymentData['error'] || ! $paymentData['charge_id']) {
            return $response
                ->setError()
                ->setNextUrl(PaymentHelper::getCancelURL($token))
                ->withInput()
                ->setMessage($paymentData['message'] ?: trans('plugins/ecommerce::order.checkout_error'));
        }

        return $response
            ->setNextUrl(PaymentHelper::getRedirectURL($token))
            ->setMessage(OrderHelper::getCheckoutSuccessMessage($paymentData));
    }

    public function processApplyCouponCode(array $result, Request $request): array
    {
        /**
         * @var EloquentCollection $products
         */
        $products = Cart::instance('cart')->products();
        $groupedProducts = $this->cartGroupByStore($products);
        $token = OrderHelper::getOrderSessionToken();

        if (! $token) {
            $token = OrderHelper::getOrderSessionToken();
        }

        $sessionCheckoutData = OrderHelper::getOrderSessionData($token);
        $sessionMarketplaceData = Arr::get($sessionCheckoutData, 'marketplace', []);
        $results = collect();
        $couponCode = $request->input('coupon_code');

        if (! $couponCode) {
            $couponCode = session('applied_coupon_code');
        }

        foreach ($groupedProducts as $storeId => $groupedProduct) {
            $productItems = $groupedProduct['products'];
            $cartItems = $productItems->pluck('cartItem');
            $rawTotal = Cart::instance('cart')->rawTotalByItems($cartItems);
            $countCart = Cart::instance('cart')->countByItems($cartItems);
            $sessionData = Arr::get($sessionMarketplaceData, $storeId, []);
            $prefix = "marketplace.$storeId.";
            $result = $this->app->make(HandleApplyCouponService::class)
                ->execute(
                    $couponCode,
                    $sessionData,
                    compact('cartItems', 'rawTotal', 'countCart', 'productItems'),
                    $prefix
                );

            $results[$storeId] = $result;
        }

        $error = 0;
        $message = '';
        $successData = [
            'error' => true,
            'data' => [],
        ];

        $sessionCheckoutData = OrderHelper::getOrderSessionData($token);
        $sessionMarketplaceData = Arr::get($sessionCheckoutData, 'marketplace', []);

        foreach ($results as $storeId => $result) {
            $sessionData = Arr::get($sessionMarketplaceData, $storeId, []);
            if (Arr::get($result, 'error')) {
                $error += 1;
                $message = Arr::get($result, 'message');

                Arr::set($sessionData, 'coupon_discount_amount', 0);
                Arr::set($sessionData, 'applied_coupon_code', null);
                Arr::set($sessionData, 'is_free_shipping', false);
            } else {
                $discount = Arr::get($result, 'data.discount');
                if (
                    (! $discount->store_id || $discount->store_id == $storeId)
                    && (Arr::get($result, 'data.is_free_shipping', false) || Arr::get($result, 'data.discount_amount'))
                ) {
                    $successData = $result;
                    Arr::set($sessionData, 'applied_coupon_code', $couponCode);
                    Arr::set($sessionData, 'coupon_discount_amount', Arr::get($result, 'data.discount_amount'));
                } else {
                    Arr::set($sessionData, 'coupon_discount_amount', 0);
                    Arr::set($sessionData, 'applied_coupon_code', null);
                    Arr::set($sessionData, 'is_free_shipping', false);
                    $message = trans('plugins/marketplace::marketplace.coupon_code_invalid');
                    $error += 1;
                }
            }

            Arr::set($sessionMarketplaceData, $storeId, $sessionData);
        }

        if ($results->count() == $error) {
            session()->forget('applied_coupon_code');

            return compact('error', 'message');
        }

        // The loop above runs the coupon service once per vendor store, each time against
        // that store's raw total, so a fixed amount coupon ("$150 off") would be granted
        // once per store - N times the intended value for a cart spanning N stores. Cap
        // the combined discount at what the same coupon yields against the whole cart.
        $sessionMarketplaceData = $this->capCartWideCouponDiscount($sessionMarketplaceData, $results);

        $couponDiscountAmount = collect($sessionMarketplaceData)->sum('coupon_discount_amount');

        OrderHelper::setOrderSessionData($token, [
            'marketplace' => $sessionMarketplaceData,
            'coupon_discount_amount' => $couponDiscountAmount,
        ]);

        return $successData;
    }

    /**
     * Scale the per-store coupon discounts down so their sum never exceeds the amount the
     * coupon yields when evaluated against the entire cart. Coupon types that are meant to
     * accumulate per store (percentage, per-every-item) already sum to that value, so they
     * pass through untouched.
     */
    protected function capCartWideCouponDiscount(array $sessionMarketplaceData, Collection $results): array
    {
        $discount = $results
            ->first(fn ($result) => ! Arr::get($result, 'error') && Arr::get($result, 'data.discount'));

        $discount = Arr::get($discount, 'data.discount');

        if (! $discount) {
            return $sessionMarketplaceData;
        }

        $amounts = collect($sessionMarketplaceData)
            ->map(fn ($storeData) => (float) Arr::get($storeData, 'coupon_discount_amount', 0))
            ->filter(fn (float $amount) => $amount > 0);

        $appliedAmount = $amounts->sum();

        if (! $appliedAmount) {
            return $sessionMarketplaceData;
        }

        // Called without cart data so the service evaluates the coupon against the full cart.
        $maxAmount = (float) Arr::get(
            $this->app->make(HandleApplyCouponService::class)->getCouponDiscountAmount($discount),
            'discount_amount',
            0
        );

        if ($maxAmount <= 0 || $appliedAmount <= $maxAmount) {
            return $sessionMarketplaceData;
        }

        return $this->applyDistributedDiscount($sessionMarketplaceData, $amounts, $maxAmount, 'coupon_discount_amount');
    }

    /**
     * Run the promotion service for every store, then cap the combined result and persist it
     * so each vendor sub-order can be priced from its capped share.
     */
    protected function applyCappedPromotionsForStores(
        array|Collection $groupedProducts,
        string $token,
        HandleApplyPromotionsService $promotionService
    ): array {
        foreach ($groupedProducts as $storeId => $productsInStore) {
            $cartItems = $productsInStore['products']->pluck('cartItem');
            $rawTotal = Cart::instance('cart')->rawTotalByItems($cartItems);
            $countCart = Cart::instance('cart')->countByItems($cartItems);

            // Deliberately no productItems key, mirroring the call this replaced in
            // handleCheckoutOrderByStore(): the service then resolves product promotions from
            // the whole cart. Passing this store's products would narrow that set and change
            // more than the capping this fix is meant to introduce.
            $promotionService->execute($token, compact('cartItems', 'rawTotal', 'countCart'), "marketplace.$storeId.");
        }

        $sessionCheckoutData = OrderHelper::getOrderSessionData($token);

        $sessionMarketplaceData = $this->capCartWidePromotionDiscount(
            (array) Arr::get($sessionCheckoutData, 'marketplace', [])
        );

        return OrderHelper::mergeOrderSessionData($token, ['marketplace' => $sessionMarketplaceData]);
    }

    /**
     * Promotions run through the same per-store loop as coupons, so a fixed amount promotion
     * is granted once per vendor. Unlike coupons they apply automatically, with no code for
     * the buyer to enter, so every multi-vendor order is affected while one is active.
     */
    protected function capCartWidePromotionDiscount(array $sessionMarketplaceData): array
    {
        $amounts = collect($sessionMarketplaceData)
            ->map(fn ($storeData) => (float) Arr::get($storeData, 'promotion_discount_amount', 0))
            ->filter(fn (float $amount) => $amount > 0);

        if (! $amounts->sum()) {
            return $sessionMarketplaceData;
        }

        // Called without cart data so the service evaluates promotions against the full cart.
        $maxAmount = (float) $this->app->make(HandleApplyPromotionsService::class)
            ->getPromotionDiscountAmount();

        if ($maxAmount <= 0 || $amounts->sum() <= $maxAmount) {
            return $sessionMarketplaceData;
        }

        return $this->applyDistributedDiscount($sessionMarketplaceData, $amounts, $maxAmount, 'promotion_discount_amount');
    }

    /**
     * Write the distributed shares back into each store's session data under $key.
     */
    protected function applyDistributedDiscount(
        array $sessionMarketplaceData,
        Collection $amounts,
        float $maxAmount,
        string $key
    ): array {
        foreach ($this->distributeDiscountAmount($amounts, $maxAmount) as $storeId => $storeAmount) {
            $storeData = Arr::get($sessionMarketplaceData, $storeId, []);
            Arr::set($storeData, $key, $storeAmount);
            Arr::set($sessionMarketplaceData, $storeId, $storeData);
        }

        return $sessionMarketplaceData;
    }

    /**
     * Split a cart-wide discount across stores proportionally to the amount each one was
     * granted. The last store absorbs the rounding remainder so the shares always add up
     * to exactly $maxAmount.
     */
    protected function distributeDiscountAmount(Collection $amounts, float $maxAmount): array
    {
        $appliedAmount = $amounts->sum();
        $lastStoreId = $amounts->keys()->last();
        $distributedAmount = 0;
        $storeAmounts = [];

        foreach ($amounts as $storeId => $amount) {
            $storeAmount = $storeId === $lastStoreId
                ? round($maxAmount - $distributedAmount, 2)
                : round($maxAmount * $amount / $appliedAmount, 2);

            $distributedAmount += $storeAmount;
            $storeAmounts[$storeId] = $storeAmount;
        }

        return $storeAmounts;
    }

    public function handleCheckoutOrderByStore(
        array $sessionCheckoutData,
        array|Collection $products,
        string $token,
        array $sessionStoreData,
        Request $request,
        int|string|null $currentUserId,
        ?Order $order,
        int|string|null $storeId,
        array|Collection &$discounts,
        HandleApplyPromotionsService $promotionService,
        HandleShippingFeeService $shippingFeeService,
        HandleApplyCouponService $applyCouponService,
        bool $isFirstStore = true
    ) {
        $cartItems = $products['products']->pluck('cartItem');
        $rawTotal = Cart::instance('cart')->rawTotalByItems($cartItems);
        $countCart = Cart::instance('cart')->countByItems($cartItems);
        $couponCode = Arr::get($sessionStoreData, 'applied_coupon_code');

        $isAvailableShipping = EcommerceHelper::isAvailableShipping($products['products']);

        if (MarketplaceHelper::isChargeShippingPerVendor()) {
            $shippingMethodInput = $request->input("shipping_method.$storeId", $order?->shipping_method ?? ShippingMethodEnum::DEFAULT);
        } else {
            $shippingMethodInput = $request->input('shipping_method', $order?->shipping_method ?? ShippingMethodEnum::DEFAULT);
        }

        $shippingMethodInput = $this->resolveShippingValueForStore($shippingMethodInput, $storeId)
            ?: ($order?->shipping_method ?? ShippingMethodEnum::DEFAULT);

        // Primed and capped by processPostCheckoutOrder() before this loop. Re-running the
        // promotion service here would recompute the uncapped per-store amount.
        $promotionDiscountAmount = (float) Arr::get($sessionStoreData, 'promotion_discount_amount', 0);

        $couponDiscountAmount = 0;
        if ($couponCode) {
            $couponDiscountAmount = Arr::get($sessionStoreData, 'coupon_discount_amount', 0);
        }

        $paymentMethod = session('selected_payment_method');
        $orderAmount = max($rawTotal - $promotionDiscountAmount - $couponDiscountAmount, 0);

        $shippingData = [];
        $shippingMethod = [];
        $shippingAmount = 0;
        if ($isAvailableShipping) {
            if (MarketplaceHelper::isChargeShippingPerVendor()) {
                $shippingData = $this->getShippingData($sessionStoreData, $orderAmount, $products, $paymentMethod);

                $shippingOptionInput = $this->resolveShippingValueForStore(
                    $request->input("shipping_option.$storeId")
                        ?: Arr::get($sessionStoreData, 'shipping_option')
                        ?: $order?->shipping_option,
                    $storeId
                );

                $shippingMethodData = $shippingFeeService
                    ->execute(
                        $shippingData,
                        $shippingMethodInput,
                        $shippingOptionInput
                    );

                $shippingMethod = Arr::first($shippingMethodData);
                if (! $shippingMethod && ! (bool) get_ecommerce_setting('disable_shipping_options', false)) {
                    throw ValidationException::withMessages([
                        'shipping_method.' . $storeId => trans(
                            'validation.exists',
                            ['attribute' => trans('plugins/ecommerce::shipping.shipping_method')]
                        ),
                    ]);
                }

                $shippingAmount = Arr::get($shippingMethod, 'price', 0);

                if (get_shipping_setting('free_ship', $shippingMethodInput)) {
                    $shippingAmount = 0;
                }
            } else {
                [$stores, ] = $this->getStoresInCart(true);
                $storeIds = array_keys($stores);
                $firstStoreId = reset($storeIds);

                static $totalUnifiedShipping = null;
                static $totalCartAmount = null;

                if ($storeId == $firstStoreId) {
                    $allCartProducts = Cart::instance('cart')->products();
                    $totalCartAmount = Cart::instance('cart')->rawTotal();

                    $unifiedShippingData = EcommerceHelper::getShippingData(
                        $allCartProducts,
                        $sessionStoreData,
                        EcommerceHelper::getOriginAddress(),
                        $totalCartAmount,
                        $paymentMethod
                    );

                    $shippingOptionInput = $this->resolveShippingValueForStore(
                        $request->input('shipping_option')
                            ?: Arr::get($sessionStoreData, 'shipping_option')
                            ?: $order?->shipping_option,
                        $storeId
                    );

                    $shippingMethodData = $shippingFeeService
                        ->execute(
                            $unifiedShippingData,
                            $shippingMethodInput,
                            $shippingOptionInput
                        );

                    $shippingMethod = Arr::first($shippingMethodData);
                    if (! $shippingMethod && ! (bool) get_ecommerce_setting('disable_shipping_options', false)) {
                        throw ValidationException::withMessages([
                            'shipping_method' => trans(
                                'validation.exists',
                                ['attribute' => trans('plugins/ecommerce::shipping.shipping_method')]
                            ),
                        ]);
                    }

                    $totalUnifiedShipping = Arr::get($shippingMethod, 'price', 0);

                    if (get_shipping_setting('free_ship', $shippingMethodInput)) {
                        $totalUnifiedShipping = 0;
                    }
                }

                $shippingData = $this->getShippingData($sessionStoreData, $orderAmount, $products, $paymentMethod);

                if ($totalCartAmount > 0 && $totalUnifiedShipping > 0) {
                    $vendorProportion = $rawTotal / $totalCartAmount;
                    $shippingAmount = round($totalUnifiedShipping * $vendorProportion, 2);
                }

                $shippingMethod = [];
            }
        }

        if ($couponCode) {
            $discount = $applyCouponService->getCouponData($couponCode, $sessionStoreData);
            if ($discount) {
                if (! $discount->store_id || $discount->store_id == $storeId) {
                    $discounts->push($discount);
                    $shippingAmount = Arr::get($sessionStoreData, 'is_free_shipping') ? 0 : $shippingAmount;
                }
            }
        }

        $orderAmount += (float) $shippingAmount;

        $shippingTaxAmount = EcommerceHelper::calculateShippingTax($shippingAmount);
        $orderAmount += $shippingTaxAmount;

        $paymentFee = 0;
        if ($paymentMethod && is_plugin_active('payment')) {
            $paymentFee = $this->calculateStorePaymentFee($paymentMethod, $orderAmount, $isFirstStore);

            $orderAmount += $paymentFee;
        }

        $finalShippingOption = null;
        if ($isAvailableShipping) {
            if (MarketplaceHelper::isChargeShippingPerVendor()) {
                $finalShippingOption = $request->input("shipping_option.$storeId")
                    ?: Arr::get($sessionStoreData, 'shipping_option')
                    ?: $order?->shipping_option;
            } else {
                $finalShippingOption = $request->input('shipping_option')
                    ?: Arr::get($sessionStoreData, 'shipping_option')
                    ?: $order?->shipping_option;
            }

            $finalShippingOption = $this->resolveShippingValueForStore($finalShippingOption, $storeId)
                ?: $order?->shipping_option;
        }

        $requestData = $request->except(['shipping_option', 'shipping_method']);

        $data = array_merge($requestData, [
            'amount' => $orderAmount,
            'currency' => $request->input('currency', get_application_currency()->title),
            'user_id' => $currentUserId,
            'shipping_method' => $isAvailableShipping ? $shippingMethodInput : '',
            'shipping_option' => $finalShippingOption,
            'shipping_amount' => (float) $shippingAmount,
            'shipping_tax_amount' => $shippingTaxAmount,
            'payment_fee' => (float) $paymentFee,
            'tax_amount' => Cart::instance('cart')->rawTaxByItems($cartItems),
            'sub_total' => Cart::instance('cart')->rawSubTotalByItems($cartItems),
            'coupon_code' => $couponCode,
            'discount_amount' => $promotionDiscountAmount + $couponDiscountAmount,
            'status' => OrderStatusEnum::PENDING,
            'token' => $token,
        ]);

        if ($order) {
            // Mirrors OrderHelper::createOrUpdateIncompleteOrder(): a locked order must never
            // be rebuilt from the live cart. $data carries status = PENDING, so without this a
            // cancelled vendor sub-order would be quietly revived and its restocked products
            // sold again, and a paid one would have its total rewritten away from the amount
            // the gateway captured.
            if (! OrderHelper::isOrderLocked($order)) {
                $order->fill($data);
                $order->save();
            }
        } else {
            $order = Order::query()->create($data);
        }

        /**
         * @var Order $order
         */
        OrderHelper::captureFootprints($order);

        if ($isAvailableShipping) {
            Shipment::query()->firstOrCreate(
                [
                    'order_id' => $order->id,
                ],
                [
                    'order_id' => $order->id,
                    'user_id' => 0,
                    'weight' => $shippingData ? Arr::get($shippingData, 'weight') : 0,
                    // The shipment is created here before the payment record exists, so we
                    // cannot rely on $order->payment to decide the COD amount (it would always
                    // resolve to 0). Base it on the selected payment method instead, so Cash on
                    // delivery orders keep a collectible COD amount and the vendor sees the COD
                    // status field on the shipment.
                    'cod_amount' => (is_plugin_active('payment') && $paymentMethod == PaymentMethodEnum::COD) ? $order->amount : 0,
                    'cod_status' => ShippingCodStatusEnum::PENDING,
                    'type' => $order->shipping_method,
                    'status' => ShippingStatusEnum::PENDING,
                    'price' => $order->shipping_amount,
                    'store_id' => $order->store_id,
                    'rate_id' => $shippingData ? Arr::get($shippingMethod, 'id', '') : '',
                    'shipment_id' => $shippingData ? Arr::get($shippingMethod, 'shipment_id', '') : '',
                    'shipping_company_name' => $shippingData ? Arr::get($shippingMethod, 'company_name') : '',
                ]
            );
        }

        if (
            EcommerceHelper::isDisplayTaxFieldsAtCheckoutPage() &&
            $request->boolean('with_tax_information')
        ) {
            $order->taxInformation()->create($request->input('tax_information'));
        }

        $addressKeys = [
            'name',
            'phone',
            'email',
            'country',
            'state',
            'city',
            'address',
            'zip_code',
            'address_id',
            'billing_address_same_as_shipping_address',
            'billing_address',
        ];
        $addressData = Arr::only($sessionCheckoutData, $addressKeys);
        $sessionStoreData = array_merge($sessionStoreData, $addressData);
        $sessionStoreData['created_order_id'] = $order->id;
        OrderHelper::processAddressOrder($currentUserId, $sessionStoreData, $request);

        OrderHistory::query()->create([
            'action' => OrderHistoryActionEnum::CREATE_ORDER_FROM_PAYMENT_PAGE,
            'description' => trans('plugins/ecommerce::order.create_order_from_payment_page'),
            'order_id' => $order->id,
        ]);

        OrderHelper::processOrderProductData($products, $sessionStoreData);

        $request->merge([
            'order_id' => array_merge($request->input('order_id', []), [$order->id]),
        ]);

        return $order;
    }

    public function getShippingData(
        array $session,
        int|float $orderTotal,
        array|Collection $products,
        ?string $paymentMethod = null
    ): array {
        $isGroupedStructure = (is_array($products) || $products instanceof Collection)
            && isset($products['store'])
            && isset($products['products']);

        if (MarketplaceHelper::isChargeShippingPerVendor() && $isGroupedStructure && $products['store'] && $products['store']->id) {
            $keys = ['name', 'company', 'address', 'country', 'state', 'city', 'zip_code', 'email', 'phone'];
            $origin = Arr::only($products['store']->toArray(), $keys);
            if (! EcommerceHelper::isUsingInMultipleCountries()) {
                $origin['country'] = EcommerceHelper::getFirstCountryId();
            }

            $data = EcommerceHelper::getShippingData($products['products'], $session, $origin, $orderTotal, $paymentMethod);

            // Expose the vendor store id so shipping drivers can resolve provider-specific
            // location IDs per store. It belongs in `extra` (alongside order_token), not in
            // `origin`: origin is validated and cached as the carrier's from-address, so an
            // extra key there would distort address validation and its cache key.
            Arr::set($data, 'extra.store_id', $products['store']->getKey());

            return $data;
        }

        $origin = EcommerceHelper::getOriginAddress();

        $productCollection = $isGroupedStructure ? $products['products'] : $products;

        return EcommerceHelper::getShippingData($productCollection, $session, $origin, $orderTotal, $paymentMethod);
    }

    /**
     * Shipping method/option can be stored keyed by store id (per-vendor checkout posts
     * `shipping_method[$storeId]`), so the same session/old-input key may hold either a scalar
     * or an array depending on which flow wrote it last. Resolve it down to the current store's
     * scalar before it is cast to string or persisted on the order.
     */
    protected function resolveShippingValueForStore(mixed $value, int|string $storeId): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = Arr::get($value, $storeId, Arr::first($value));

        return is_array($value) ? null : $value;
    }

    public function processPaymentMethodPostCheckout(Request $request, int|float $totalAmount): array
    {
        // $totalAmount is the sum of the vendor sub-orders' `amount`, and each of those already
        // had its payment fee added in handleCheckoutOrderByStore(). Recomputing the fee here
        // and adding it again charged the gateway more than the orders recorded - a 3.5% fee on
        // a $100 order billed $107.12 while the orders totalled $103.50.
        //
        // This mirrors the single-vendor flow, which computes the fee once against a pre-fee
        // amount (PublicCheckoutController::processOrderData) and then hands the stored,
        // fee-inclusive order amount straight to the gateway without recalculating.
        $paymentMethod = $request->input('payment_method');

        $paymentData = [
            'error' => false,
            'message' => false,
            'amount' => round((float) $totalAmount, 2),
            'currency' => $request->input('currency', cms_currency()->getDefaultCurrency()->title),
            'type' => $paymentMethod,
            'charge_id' => null,
        ];

        return apply_filters(FILTER_ECOMMERCE_PROCESS_PAYMENT, $paymentData, $request);
    }

    public function processGetCheckoutSuccess(string $token)
    {
        $orders = Order::query()
            ->where('token', $token)
            ->with(['address', 'products'])
            ->get();

        foreach ($orders as $order) {
            if ($order->payment_fee > 0 && $order->amount > 0) {
                $orderAmount = $order->sub_total + $order->tax_amount + $order->shipping_amount
                    + ($order->shipping_tax_amount ?? 0) + $order->payment_fee - $order->discount_amount;
                if ($order->amount != $orderAmount) {
                    $order->amount = $orderAmount;
                    $order->save();
                }
            }
        }

        abort_if($orders->isEmpty(), 404);

        if ($orders->where('is_finished', false)->isNotEmpty()) {
            foreach ($orders->where('is_finished', false) as $order) {
                if ((float) $order->amount && ! $order->payment_id) {
                    continue;
                }

                $order->is_finished = true;
                $order->save();

                /**
                 * @var Order $order
                 */
                OrderHelper::decreaseProductQuantity($order);
            }
        }

        OrderHelper::clearSessions($token);

        return view('plugins/marketplace::orders.thank-you', compact('orders'));
    }

    public function processGetPaymentStatus(Request $request, BaseHttpResponse $response): BaseHttpResponse
    {
        $token = session('tracked_start_checkout');

        if (! $token) {
            return $response->setNextUrl(BaseHelper::getHomepageUrl());
        }

        $this->app->make(PayPalPaymentService::class)->afterMakePayment($request->input());

        return $response
            ->setNextUrl(route('public.checkout.success', $token))
            ->setMessage(trans('plugins/ecommerce::order.checkout_successfully'));
    }

    public function sendMailAfterProcessOrder(Collection $orders): Collection
    {
        try {
            /**
             * @var Order $order
             */
            $order = $orders->first();

            $mailer = $this->setEmailVariables($order);

            $mailer->sendUsingTemplate('admin_new_order', EcommerceHelper::getAdminNotificationEmails());

            $this->sendOrderConfirmationEmail($orders, true);
        } catch (Throwable $exception) {
            info($exception->getMessage());
        }

        MarketplaceHelper::sendMailToVendorAfterProcessingOrder($orders);

        return $orders;
    }

    public function setEmailVariables(Order $order): EmailHandlerSupport
    {
        $variables = OrderHelper::getEmailVariables($order);

        $variables = array_merge($variables, [
            'store_address' => $order->store->full_address ?: get_ecommerce_setting('store_address'),
            'store_name' => $order->store->name ?: get_ecommerce_setting('store_name'),
            'store_phone' => $order->store->phone ?: get_ecommerce_setting('store_phone'),
            'store_link' => $order->store->url,
            'store' => $order->store->toArray(),
            'product_list' => view('plugins/marketplace::emails.partials.order-detail', compact('order'))
                ->render(),
        ]);

        return EmailHandler::setModule(ECOMMERCE_MODULE_SCREEN_NAME)
            ->setVariableValues($variables);
    }

    public function sendOrderConfirmationEmail(Collection $orders, bool $saveHistory = false): bool
    {
        try {
            $mailer = EmailHandler::setModule(ECOMMERCE_MODULE_SCREEN_NAME);

            if (! $mailer->templateEnabled('customer_new_order')) {
                return false;
            }

            foreach ($orders as $order) {
                $mailer = $this->setEmailVariables($order);

                $mailer->sendUsingTemplate('customer_new_order', $order->user->email ?: $order->address->email);

                if ($saveHistory) {
                    OrderHistory::query()->create([
                        'action' => OrderHistoryActionEnum::SEND_ORDER_CONFIRMATION_EMAIL,
                        'description' => trans('plugins/ecommerce::order.confirmation_email_was_sent_to_customer'),
                        'order_id' => $order->id,
                    ]);
                }
            }

            return true;
        } catch (Exception $exception) {
            Log::error($exception->getMessage());
        }

        return false;
    }

    public function processShippingDiscountOrderData(
        array|EloquentCollection $products,
        string $token,
        array $sessionCheckoutData,
        Request $request
    ): array {
        $groupedProducts = $this->cartGroupByStore($products);

        $mpSessionCheckoutData = (array) Arr::get($sessionCheckoutData, 'marketplace', []);

        $couponCode = session('applied_coupon_code');
        if ($couponCode) {
            $this->processApplyCouponCode([], $request);
            $sessionCheckoutData = OrderHelper::getOrderSessionData($token);
            $couponCode = session()->get('applied_coupon_code');
        } else {
            foreach ($mpSessionCheckoutData as &$storeCheckoutData) {
                Arr::set($storeCheckoutData, 'coupon_discount_amount', 0);
                Arr::set($storeCheckoutData, 'applied_coupon_code', null);
                Arr::set($storeCheckoutData, 'is_free_shipping', false);
            }

            $sessionCheckoutData['marketplace'] = $mpSessionCheckoutData;

            OrderHelper::setOrderSessionData($token, $sessionCheckoutData);
        }

        $mpSessionCheckoutData = Arr::get($sessionCheckoutData, 'marketplace');
        $discounts = collect();

        $marketplaceData = collect();

        $promotionService = $this->app->make(HandleApplyPromotionsService::class);
        $shippingFeeService = $this->app->make(HandleShippingFeeService::class);
        $applyCouponService = $this->app->make(HandleApplyCouponService::class);

        $shippingHasValue = [];
        $shipping = [];
        $paymentMethod = session('selected_payment_method');

        $shippingAmount = 0;
        $defaultShippingMethod = null;
        $defaultShippingOption = null;

        foreach ($groupedProducts as $storeId => $productsByStore) {
            $cartItems = $productsByStore['products']->pluck('cartItem');
            $productItems = $productsByStore['products'];
            $vendorSessionData = Arr::get($mpSessionCheckoutData, $storeId);

            $rawTotal = Cart::instance('cart')->rawTotalByItems($cartItems);
            $countCart = Cart::instance('cart')->countByItems($cartItems);

            $prefixPromotion = "marketplace.$storeId.";
            $promotionDiscountAmount = $promotionService->execute(
                $token,
                compact('cartItems', 'rawTotal', 'countCart', 'productItems'),
                $prefixPromotion
            );

            $couponDiscountAmount = 0;
            if ($couponCode) {
                $couponDiscountAmount = Arr::get($vendorSessionData, 'coupon_discount_amount', 0);
            }

            $orderTotal = max($rawTotal - $promotionDiscountAmount - $couponDiscountAmount, 0);

            // Per-vendor slice. `store_id` tells listeners this is one vendor's share of the
            // cart, not the whole cart, so whole-order adjustments are not applied N times.
            $orderTotal = max((float) apply_filters('ecommerce_checkout_order_total', $orderTotal, [
                'raw_total' => $rawTotal,
                'promotion_discount_amount' => $promotionDiscountAmount,
                'coupon_discount_amount' => $couponDiscountAmount,
                'token' => $token,
                'store_id' => $storeId,
            ]), 0);
            $isAvailableShipping = EcommerceHelper::isAvailableShipping($productsByStore['products']);

            if (MarketplaceHelper::isChargeShippingPerVendor()) {
                $defaultShippingMethod = $request->input("shipping_method.$storeId") ?: Arr::get($sessionCheckoutData, 'shipping_method', ShippingMethodEnum::DEFAULT);
            } else {
                $defaultShippingMethod = $request->input('shipping_method') ?: Arr::get($sessionCheckoutData, 'shipping_method', ShippingMethodEnum::DEFAULT);
            }

            $defaultShippingMethod = $this->resolveShippingValueForStore($defaultShippingMethod, $storeId);

            $defaultShippingOption = null;
            if ($isAvailableShipping) {
                if (MarketplaceHelper::isChargeShippingPerVendor()) {
                    $shippingData = $this->getShippingData(
                        $sessionCheckoutData,
                        $orderTotal,
                        $productsByStore,
                        $paymentMethod
                    );
                } else {
                    $allCartProducts = Cart::instance('cart')->products();
                    $totalCartAmount = Cart::instance('cart')->rawTotal();

                    $shippingData = $this->getShippingData(
                        $sessionCheckoutData,
                        $totalCartAmount,
                        $allCartProducts,
                        $paymentMethod
                    );
                }

                $shipping = $shippingFeeService->execute($shippingData);

                foreach ($shipping as $key => &$shipItem) {
                    if (get_shipping_setting('free_ship', $key)) {
                        foreach ($shipItem as &$subShippingItem) {
                            Arr::set($subShippingItem, 'price', 0);
                        }
                    }
                }

                if (! $defaultShippingMethod) {
                    $defaultShippingMethod = $this->resolveShippingValueForStore(
                        old(
                            "shipping_method.$storeId",
                            Arr::get($vendorSessionData, 'shipping_method', Arr::first(array_keys($shipping)))
                        ),
                        $storeId
                    );
                }

                if (! empty($shipping)) {
                    if (! $shippingHasValue) {
                        $shippingHasValue = $shipping;
                    }

                    $defaultShippingOption = Arr::first(array_keys(Arr::first($shipping)));

                    $defaultShippingMethod = (string) $defaultShippingMethod;

                    // Ensure the resolved key exists in $shipping; otherwise the blade renders no checked radio.
                    if (! array_key_exists($defaultShippingMethod, $shipping)) {
                        $defaultShippingMethod = (string) array_key_first($shipping);
                    }

                    $optionRequest = null;
                    if (MarketplaceHelper::isChargeShippingPerVendor()) {
                        $optionRequest = $request->input("shipping_option.$storeId", old("shipping_option.$storeId"));
                    } else {
                        $optionRequest = $request->input('shipping_option', old('shipping_option'));
                    }

                    if ($optionRequest) {
                        if (
                            (is_string($optionRequest) || is_int($optionRequest))
                            && array_key_exists($optionRequest, (array) Arr::get($shipping, $defaultShippingMethod, []))
                        ) {
                            $defaultShippingOption = $optionRequest;
                        }
                    } else {
                        $defaultShippingOptionFromSession = $this->resolveShippingValueForStore(
                            Arr::get(
                                $vendorSessionData,
                                'shipping_option',
                                $defaultShippingOption
                            ),
                            $storeId
                        );

                        $defaultShippingMethodValue = (array) Arr::get($shipping, $defaultShippingMethod, []);

                        if (isset($defaultShippingMethodValue[$defaultShippingOptionFromSession])) {
                            $defaultShippingOption = $defaultShippingOptionFromSession;
                        }
                    }
                }

                $defaultShippingOption = BaseHelper::stringify($defaultShippingOption);
                $defaultShippingMethod = BaseHelper::stringify($defaultShippingMethod);

                $shippingAmount = Arr::get($shipping, "$defaultShippingMethod.$defaultShippingOption.price", 0);

                Arr::set($vendorSessionData, 'shipping_method', $defaultShippingMethod);
                Arr::set($vendorSessionData, 'shipping_option', $defaultShippingOption);
                Arr::set($vendorSessionData, 'shipping_amount', $shippingAmount);
            }

            $sessionCheckoutData['marketplace'] = [$storeId => $vendorSessionData];

            OrderHelper::setOrderSessionData($token, $sessionCheckoutData);

            if ($couponCode) {
                if (! $request->input('applied_coupon')) {
                    $discount = $applyCouponService->getCouponData($couponCode, $vendorSessionData);

                    if ($discount) {
                        if (! $discount->store_id || $discount->store_id == $storeId) {
                            $discounts->push($discount);
                            $shippingAmount = Arr::get($vendorSessionData, 'is_free_shipping') ? 0 : $shippingAmount;
                        }
                    }
                } else {
                    $shippingAmount = Arr::get($vendorSessionData, 'is_free_shipping') ? 0 : $shippingAmount;
                }
            }

            if (! $isAvailableShipping) {
                $shippingAmount = 0;
            }

            $marketplaceData[$storeId] = [
                'shipping' => $shipping,
                'default_shipping_method' => $defaultShippingMethod,
                'default_shipping_option' => $defaultShippingOption,
                'shipping_amount' => $shippingAmount,
                'promotion_discount_amount' => $promotionDiscountAmount,
                'coupon_discount_amount' => $couponDiscountAmount,
                'is_available_shipping' => $isAvailableShipping,
            ];
        }

        // The promotion service ran once per store above, each time against that store's own
        // subtotal, so cap the combined amount before it is summed or written back to session.
        $marketplaceData = collect($this->capCartWidePromotionDiscount($marketplaceData->all()));

        if (MarketplaceHelper::isChargeShippingPerVendor()) {
            $shippingAmount = $marketplaceData->pluck('shipping_amount')->sum();
        } else {
            $shippingAmount = $marketplaceData->first()['shipping_amount'] ?? 0;
        }
        $promotionDiscountAmount = $marketplaceData->pluck('promotion_discount_amount')->sum();
        $couponDiscountAmount = $marketplaceData->pluck('coupon_discount_amount')->sum();

        $sessionCheckoutData = OrderHelper::getOrderSessionData($token);

        $mpSessionCheckoutData = Arr::get($sessionCheckoutData, 'marketplace', []);

        foreach ($mpSessionCheckoutData as $storeId => $mpSessionData) {
            Arr::set(
                $mpSessionCheckoutData,
                $storeId,
                array_merge((array) $mpSessionData, Arr::get($marketplaceData, $storeId, []))
            );
        }

        $sessionCheckoutData = OrderHelper::mergeOrderSessionData($token, ['marketplace' => $mpSessionCheckoutData]);
        $sessionCheckoutData['is_available_shipping'] = $marketplaceData->where('is_available_shipping')->count();
        if ($sessionCheckoutData['is_available_shipping']) {
            $shipping = $shippingHasValue;
        }

        return [
            $sessionCheckoutData,
            $shipping,
            $defaultShippingMethod,
            $defaultShippingOption,
            $shippingAmount,
            $promotionDiscountAmount,
            $couponDiscountAmount,
        ];
    }

    public function processRemoveCouponCode(array|EloquentCollection $products): array
    {
        $groupedProducts = $this->cartGroupByStore($products);

        $results = collect();

        foreach ($groupedProducts as $storeId => $groupedProduct) {
            $prefix = "marketplace.$storeId.";
            $result = $this->app->make(HandleRemoveCouponService::class)->execute($prefix, false);
            $results[$storeId] = $result;
        }

        session()->forget('applied_coupon_code');

        $error = 0;
        $message = '';
        $successData = [
            'error' => true,
            'data' => [],
        ];

        foreach ($results as $result) {
            if (Arr::get($result, 'error')) {
                $error += 1;
                $message = Arr::get($result, 'message');
            } else {
                $successData = $result;
            }
        }

        if ($results->count() == $error) {
            return compact('error', 'message');
        }

        return $successData;
    }

    public function processPostSaveInformation(array $sessionCheckoutData, Request $request, string $token): array
    {
        if (session()->has('applied_coupon_code')) {
            $discounts = collect();
            $mpSessionData = Arr::get($sessionCheckoutData, 'marketplace', []);
            foreach ($mpSessionData as $storeId => $sessionStoreData) {
                $discount = $this->app->make(HandleApplyCouponService::class)
                    ->getCouponData(session('applied_coupon_code'), $sessionStoreData);
                if (! $discount) {
                    $discounts->push($discount);
                    $prefix = "marketplace.$storeId.";
                    $this->app->make(HandleRemoveCouponService::class)->execute($prefix, false);
                }
            }

            if (count($mpSessionData) == $discounts->count()) {
                session()->forget('applied_coupon_code');
            }

            $sessionCheckoutData = OrderHelper::getOrderSessionData($token);
        }

        $mpSessionData = Arr::get($sessionCheckoutData, 'marketplace', []);

        $addressKeys = [
            'name',
            'phone',
            'email',
            'country',
            'state',
            'city',
            'address',
            'zip_code',
            'address_id',
            'billing_address_same_as_shipping_address',
            'billing_address',
        ];
        $addressData = Arr::only((array) $request->input('address', []), $addressKeys);

        foreach ($mpSessionData as $storeId => $sessionStoreData) {
            Arr::set($mpSessionData, $storeId, array_merge($sessionStoreData, $addressData));
        }

        Arr::set($sessionCheckoutData, 'marketplace', $mpSessionData);

        return $sessionCheckoutData;
    }

    public function processGetCheckoutRecover(string $token, Request $request)
    {
        // Same as the core handler: a recovery link from an abandoned-cart email must not
        // resume an order that was cancelled and restocked in the meantime.
        $orders = Order::query()
            ->where([
                'token' => $token,
                'is_finished' => 0,
            ])
            ->where('status', '!=', OrderStatusEnum::CANCELED)
            ->with(['address', 'products'])
            ->get();

        abort_unless($orders->count(), 404);

        if (session()->has('tracked_start_checkout') && session('tracked_start_checkout') == $token) {
            $sessionCheckoutData = OrderHelper::getOrderSessionData($token);
        } else {
            $token = OrderHelper::getOrderSessionToken();

            $trashOrders = Order::query()
                ->with('address')
                ->where([
                    'token' => $token,
                    'is_finished' => 0,
                ])
                ->get();

            foreach ($trashOrders as $trashOrder) {
                $trashOrder->delete();
                if ($trashOrder->address && $trashOrder->address->id) {
                    $trashOrder->address->delete();
                }
            }

            $order = $orders->first();
            $sessionCheckoutData = [
                'name' => $order->address->name,
                'email' => $order->address->email,
                'phone' => $order->address->phone,
                'address' => $order->address->address,
                'country' => $order->address->country,
                'state' => $order->address->state,
                'city' => $order->address->city,
                'zip_code' => $order->address->zip_code,
                'shipping_method' => $order->shipping_method,
                'shipping_option' => $order->shipping_option,
                'shipping_amount' => $order->shipping_amount,
            ];
            $request->merge(['address' => $sessionCheckoutData]);
        }

        OrderHelper::setOrderSessionData($token, $sessionCheckoutData);

        $orders->loadMissing([
            'products',
            'products.product',
            'products.product.variationInfo',
            'products.product.variationInfo.configurableProduct',
            'products.product.variationInfo.configurableProduct.tax',
        ]);

        Cart::instance('cart')->destroy();
        foreach ($orders as $order) {
            foreach ($order->products as $orderProduct) {
                $request->merge(['qty' => $orderProduct->qty]);

                $product = $orderProduct->product;
                if ($product) {
                    OrderHelper::handleAddCart($product, $request);
                }
            }
        }

        /**
         * @var EloquentCollection $products
         */
        $products = Cart::instance('cart')->products();
        if ($products->count()) {
            $this->handleProcessOrder($products, $token, $sessionCheckoutData, $request);
        }

        return $this->app->make(BaseHttpResponse::class)
            ->setNextUrl(route('public.checkout.information', $token))
            ->setMessage(trans('plugins/marketplace::marketplace.recovered_from_previous_orders'));
    }

    public function handleProcessOrder(
        EloquentCollection $products,
        string $token,
        array $sessionData,
        Request $request
    ): array {
        $groupedProducts = $this->cartGroupByStore($products);

        $currentUserId = 0;
        if (auth('customer')->check()) {
            $currentUserId = auth('customer')->id();
        }
        $preOrders = collect();
        $mpSessionData = Arr::get($sessionData, 'marketplace', []);

        $orderIds = collect($mpSessionData ?: [])->pluck('created_order_id');
        if ($orderIds) {
            $preOrders = Order::query()->whereIn('id', $orderIds)->with('address')->get();
        }

        $foundOrderIds = [];

        $addressKeys = [
            'name',
            'phone',
            'email',
            'country',
            'state',
            'city',
            'address',
            'zip_code',
            'address_id',
            'billing_address_same_as_shipping_address',
            'billing_address',
        ];

        $addressData = Arr::only($sessionData, $addressKeys);

        foreach ($groupedProducts as $key => $productsByStore) {
            $sessionDataInStore = Arr::get($mpSessionData, $key, []);
            $order = $preOrders->firstWhere('store_id', $key);
            if ($order) {
                $foundOrderIds[] = $key;
            }

            $sessionDataInStore = array_merge($sessionDataInStore, $addressData);
            $mpSessionData[$key] = $this->handleOrderStore(
                $productsByStore,
                $token,
                $sessionDataInStore,
                $request,
                $currentUserId,
                $order
            );
        }

        if ($preOrders) {
            foreach ($preOrders as $order) {
                if (! in_array($order->store_id, $foundOrderIds)) {
                    $order->delete();
                }
            }
        }

        $sessionData = array_merge($sessionData, ['marketplace' => $mpSessionData]);

        OrderHelper::setOrderSessionData($token, $sessionData);

        return $sessionData;
    }

    public function handleOrderStore(
        array|Collection $products,
        string $token,
        array $sessionData,
        Request $request,
        int|string|null $currentUserId,
        ?Order $order
    ): array {
        $cartItems = $products['products']->pluck('cartItem');

        $store = $products['store'];

        if (MarketplaceHelper::isChargeShippingPerVendor() && $store && $store->id) {
            $shippingMethod = $request->input('shipping_method.' . $store->id)
                ?: Arr::get($sessionData, 'shipping_method')
                ?: $order?->shipping_method ?? ShippingMethodEnum::DEFAULT;
            $shippingOption = $request->input('shipping_option.' . $store->id)
                ?: Arr::get($sessionData, 'shipping_option')
                ?: $order?->shipping_option;
        } else {
            $shippingMethod = $request->input('shipping_method')
                ?: Arr::get($sessionData, 'shipping_method')
                ?: $order?->shipping_method ?? ShippingMethodEnum::DEFAULT;
            $shippingOption = $request->input('shipping_option')
                ?: Arr::get($sessionData, 'shipping_option')
                ?: $order?->shipping_option;
        }

        $storeKey = $store && $store->id ? $store->id : 0;
        $shippingMethod = $this->resolveShippingValueForStore($shippingMethod, $storeKey)
            ?: ($order?->shipping_method ?? ShippingMethodEnum::DEFAULT);
        $shippingOption = $this->resolveShippingValueForStore($shippingOption, $storeKey)
            ?: $order?->shipping_option;

        $generalData = [
            'user_id' => $currentUserId,
            'shipping_method' => $shippingMethod,
            'shipping_option' => $shippingOption,
            'coupon_code' => Arr::get($sessionData, 'applied_coupon_code'),
            'token' => $token,
        ];

        [$sessionData, $order] = OrderHelper::processOrderInCheckout(
            $sessionData,
            $request,
            $cartItems,
            $order,
            $generalData
        );

        Arr::set(
            $sessionData,
            'is_save_order_shipping_address',
            EcommerceHelper::isSaveOrderShippingAddress($products['products'])
        );

        Arr::set($sessionData, 'created_order_id', $order->id);
        $sessionData = OrderHelper::processAddressOrder($currentUserId, $sessionData, $request);

        $order->store_id = $store?->id;
        $order->save();

        return OrderHelper::processOrderProductData($products, $sessionData);
    }

    public function processCheckoutRulesRequest(array $rules): array
    {
        if (MarketplaceHelper::isChargeShippingPerVendor()) {
            unset($rules['shipping_method']);
            [$stores, $groupedProducts] = $this->getStoresInCart(true);
            foreach ($stores as $storeId => $storeName) {
                $products = collect($groupedProducts[$storeId]);
                if (EcommerceHelper::isAvailableShipping($products) && ! (bool) get_ecommerce_setting('disable_shipping_options', false)) {
                    $rules["shipping_method.$storeId"] = 'required|' . Rule::in(ShippingMethodEnum::values());
                    $rules["shipping_option.$storeId"] = 'required';
                }
            }
        }

        return $rules;
    }

    protected function getStoresInCart($includeProducts = false): array
    {
        $originalProducts = Cart::instance('cart')->products()->pluck('original_product');
        $storeIdsInCart = $originalProducts->pluck('store_id');
        $stores = Store::query()->whereIn('id', $storeIdsInCart)->get();
        $storesInCart = [];
        $groupedProducts = [];
        foreach ($originalProducts as $original) {
            if ($original->store_id) {
                if ($store = $stores->firstWhere('id', $original->store_id)) {
                    $storesInCart[$store->id] = $store->name;
                    $groupedProducts[$store->id][] = $original;

                    continue;
                }
            }
            $groupedProducts[0][] = $original;
            $storesInCart[0] = Theme::getSiteTitle();
        }
        if ($includeProducts) {
            return [$storesInCart, $groupedProducts];
        }

        return $storesInCart;
    }

    public function processCheckoutMessagesRequest(array $messages): array
    {
        $stores = $this->getStoresInCart();
        foreach ($stores as $storeId => $storeName) {
            $messages["shipping_method.$storeId.required"] = trans(
                'plugins/marketplace::order.shipping_method_required',
                ['name' => $storeName]
            );
            $messages["shipping_method.$storeId.in"] = trans(
                'plugins/marketplace::order.shipping_method_in',
                ['name' => $storeName]
            );
            $messages["shipping_option.$storeId.required"] = trans(
                'plugins/marketplace::order.shipping_option_required',
                ['name' => $storeName]
            );
        }

        return $messages;
    }

    public function afterOrderStatusCompleted(Order $order)
    {
        $order->loadMissing(['store', 'store.customer']);

        if ($order->store?->id && $order->store->customer->id) {
            $customer = $order->store->customer;
            $vendorInfo = $customer->vendorInfo;
            if (! $vendorInfo->id) {
                $vendorInfo = VendorInfo::query()
                    ->create([
                        'customer_id' => $customer->id,
                    ]);
            }

            if ($vendorInfo->id) {
                $orderAmountWithoutShippingFee = $order->amount - $order->shipping_amount - ($order->shipping_tax_amount ?? 0) - $order->tax_amount - $order->payment_fee;
                if (! MarketplaceHelper::shouldChargeCommission()) {
                    // Subscription mode: the admin earns from subscription plans instead,
                    // so the vendor keeps 100% of every order.
                    $fee = 0;
                } else {
                    // In hybrid the vendor's plan may override either half of the global rate.
                    $rate = app(CommissionFeeResolver::class)->forVendor($customer);

                    if (! MarketplaceHelper::isCommissionCategoryFeeBasedEnabled()) {
                        $fee = $orderAmountWithoutShippingFee * ($rate->percentage / 100);
                    } else {
                        // Per-category rates still win where the admin configured one; the plan
                        // override only replaces the default applied to everything else.
                        $fee = $this->calculatorCommissionFeeByProduct($order->products, $rate->percentage);
                    }

                    // Add the fixed commission fee, charged once per vendor sub-order.
                    // Each order in the marketplace already belongs to a single store, so this
                    // applies per vendor. Covers the flat part of payment gateway fees (e.g. Stripe/PayPal €0.25).
                    if ($rate->fixed > 0) {
                        $fee += $rate->fixed;
                    }
                }

                // Never let the total commission exceed the order sub-amount (avoid a negative vendor payout).
                $fee = min($fee, $orderAmountWithoutShippingFee);

                $amount = $orderAmountWithoutShippingFee - $fee;
                $currentBalance = $customer->balance;

                $amountByCurrency = $amount;

                $revenue = Revenue::query()->where('order_id', $order->getKey())->first();

                $revenueAmount = $revenue ? $revenue->amount : 0;

                $data = [
                    'sub_amount' => $orderAmountWithoutShippingFee,
                    'fee' => $fee,
                    'amount' => $amount,
                    'currency' => get_application_currency()->title,
                    'current_balance' => $currentBalance,
                    'customer_id' => $customer->getKey(),
                    'type' => RevenueTypeEnum::ADD_AMOUNT,
                ];

                try {
                    DB::beginTransaction();

                    if ($revenue) {
                        $amountByCurrency -= $revenueAmount;
                        $fee = 0;
                        $data['current_balance'] = $currentBalance - $revenueAmount;
                        $revenue->fill($data);
                        $revenue->save();
                    } else {
                        Revenue::query()->create(
                            array_merge([
                                'order_id' => $order->getKey(),
                            ], $data)
                        );

                        $vendorInfo->total_revenue += $amountByCurrency;
                    }

                    $vendorInfo->balance += $amountByCurrency;
                    $vendorInfo->total_fee += $fee;
                    $vendorInfo->save();

                    DB::commit();
                } catch (Throwable $th) {
                    DB::rollBack();

                    return BaseHttpResponse::make()
                        ->setError()
                        ->setMessage($th->getMessage());
                }
            }
        }

        return $order;
    }

    /**
     * @param  float|null  $defaultPercentage  Rate applied to products whose categories have no
     *                                         configured commission. Null keeps the global
     *                                         setting, which is what commission mode passes.
     */
    protected function calculatorCommissionFeeByProduct(
        Collection $orderProducts,
        ?float $defaultPercentage = null
    ): float|int {
        if (! MarketplaceHelper::shouldChargeCommission()) {
            return 0;
        }

        /**
         * @var EloquentCollection $orderProducts
         */
        $orderProducts->load([
            'product.categories',
            'product.variationInfo.configurableProduct.categories',
        ]);

        $allCategoryIds = $orderProducts
            ->map(fn ($orderProduct) => $orderProduct->product?->original_product?->categories?->pluck('id'))
            ->flatten()
            ->filter()
            ->unique()
            ->toArray();

        $commissions = CategoryCommission::query()
            ->whereIn('product_category_id', $allCategoryIds)
            ->get()
            ->groupBy('product_category_id')
            ->map(fn ($group) => $group->sortByDesc('commission_percentage')->first());

        $defaultCommissionFeePercentage = $defaultPercentage
            ?? MarketplaceHelper::getSetting('fee_per_order', 0);

        $totalFee = 0;
        foreach ($orderProducts as $orderProduct) {
            $product = $orderProduct->product->original_product;

            if (! $product) {
                continue;
            }

            $listCategories = $product->categories->pluck('id')->toArray();

            $commissionFeePercentage = $defaultCommissionFeePercentage;
            $highestCommission = null;

            foreach ($listCategories as $categoryId) {
                if (isset($commissions[$categoryId])) {
                    $commission = $commissions[$categoryId];
                    if ($highestCommission === null || $commission->commission_percentage > $highestCommission) {
                        $highestCommission = $commission->commission_percentage;
                    }
                }
            }

            if ($highestCommission !== null) {
                $commissionFeePercentage = $highestCommission;
            }

            $totalFee += $orderProduct->price * $commissionFeePercentage / 100;
        }

        return $totalFee;
    }

    /**
     * Payment fee for one vendor sub-order of a (possibly multi-vendor) checkout.
     *
     * calculateFee() returns (percentage-of-amount) + fee_fixed. This runs once per vendor
     * sub-order, but the buyer only makes a single gateway transaction, so any flat component
     * must be charged once per checkout, not once per vendor. The percentage component is safe
     * to repeat (sum of per-vendor percentages of the true total = percentage fee of the sum).
     * Apply-once: only the first store's sub-order carries the flat part; every subsequent
     * store gets it subtracted back out so per-vendor totals still sum to the true total.
     *
     * - Percentage type: the flat part is fee_fixed ("2.9% + $0.30"). The clamp mirrors
     *   calculateFee()'s own max(0, ...) so a negative fee_fixed it already discarded is not
     *   subtracted as a negative (which would inflate every vendor after the first).
     * - Fixed type (e.g. a flat COD fee): the whole fee ignores the order amount, so it is all
     *   flat component. The buyer sees it once at checkout and pays it once, on the first store.
     */
    public function calculateStorePaymentFee(string $paymentMethod, float $orderAmount, bool $isFirstStore): float
    {
        $paymentFee = PaymentFeeHelper::calculateFee($paymentMethod, $orderAmount);

        if ($isFirstStore) {
            return $paymentFee;
        }

        if (get_payment_setting('fee_type', $paymentMethod, PaymentFeeTypeEnum::FIXED) !== PaymentFeeTypeEnum::PERCENTAGE) {
            return 0;
        }

        $feeFixed = max(0, (float) get_payment_setting('fee_fixed', $paymentMethod, 0));

        return max(0, $paymentFee - $feeFixed);
    }

    public function addMoreOrderEmailVariables(array $variables, Order $order): array
    {
        $variables['store_name'] = $order->store->name;
        $variables['store_phone'] = $order->store->phone;
        $variables['store_address'] = $order->store->full_address;
        $variables['store_link'] = $order->store->url;
        $variables['store'] = $order->store->toArray();

        return $variables;
    }
}
