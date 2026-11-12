<?php

namespace Botble\Marketplace\Supports;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Facades\EmailHandler;
use Botble\Base\Supports\EmailHandler as BaseEmailHandler;
use Botble\Ecommerce\Enums\DiscountTypeOptionEnum;
use Botble\Ecommerce\Facades\EcommerceHelper;
use Botble\Ecommerce\Facades\OrderHelper;
use Botble\Ecommerce\Models\Order as OrderModel;
use Botble\Ecommerce\Models\ProductCategory;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Media\Facades\RvMedia;
use Botble\Slug\Facades\SlugHelper;
use Botble\Theme\Facades\Theme;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketplaceHelper
{
    public function view(string $view, array $data = [])
    {
        return view($this->viewPath($view), $data);
    }

    public function viewPath(string $view, bool $checkViewExists = true): string
    {
        if ($checkViewExists && view()->exists($themeView = Theme::getThemeNamespace('views.marketplace.' . $view))) {
            return $themeView;
        }

        return 'plugins/marketplace::themes.' . $view;
    }

    /**
     * float belongs in both unions: commission rates are decimals, and without it a value
     * still held in memory as a float (rather than read back from the database as a string)
     * is implicitly truncated - 4.5% silently becomes 4%.
     */
    public function getSetting(
        string $key,
        string|int|float|array|null|bool $default = ''
    ): string|int|float|array|null|bool {
        return setting($this->getSettingKey($key), $default);
    }

    public function getSettingKey(string $key = ''): string
    {
        return config('plugins.marketplace.general.prefix') . $key;
    }

    public function discountTypes(): array
    {
        return Arr::except(DiscountTypeOptionEnum::labels(), [DiscountTypeOptionEnum::SAME_PRICE, DiscountTypeOptionEnum::SHIPPING]);
    }

    public function getAssetVersion(): string
    {
        return '2.3.0';
    }

    public function isStoresPageEnabled(): bool
    {
        return (bool) $this->getSetting('enable_stores_page', true);
    }

    public function hideStorePhoneNumber(): bool
    {
        return (bool) $this->getSetting('hide_store_phone_number', false);
    }

    public function hideStoreEmail(): bool
    {
        return (bool) $this->getSetting('hide_store_email', false);
    }

    public function hideStoreSocialLinks(): bool
    {
        return (bool) $this->getSetting('hide_store_social_links', false);
    }

    public function hideStoreAddress(): bool
    {
        return (bool) $this->getSetting('hide_store_address', false);
    }

    public function allowVendorManageShipping(): bool
    {
        return (bool) $this->getSetting('allow_vendor_manage_shipping', false);
    }

    public function isChargeShippingPerVendor(): bool
    {
        return (bool) $this->getSetting('charge_shipping_per_vendor', true);
    }

    public function sendMailToVendorAfterProcessingOrder($orders)
    {
        if ($orders instanceof Collection) {
            $orders->loadMissing(['store']);
        } else {
            $orders = [$orders];
        }

        $mailer = EmailHandler::setModule(MARKETPLACE_MODULE_SCREEN_NAME);

        if ($mailer->templateEnabled('store_new_order')) {
            foreach ($orders as $order) {
                if (! $order->store || ! $order->store->email) {
                    continue;
                }

                $this->setEmailVendorVariables($order);
                $mailer->sendUsingTemplate('store_new_order', $order->store->email);
            }
        }

        return $orders;
    }

    public function setEmailVendorVariables(OrderModel $order): BaseEmailHandler
    {
        return EmailHandler::setModule(MARKETPLACE_MODULE_SCREEN_NAME)
            ->setVariableValues(OrderHelper::getEmailVariables($order));
    }

    public function isCommissionCategoryFeeBasedEnabled(): bool
    {
        return (bool) $this->getSetting('enable_commission_fee_for_each_category');
    }

    public function getMode(): string
    {
        $mode = (string) $this->getSetting('mode', MarketplaceModeEnum::COMMISSION);

        // Enum::values() yields Enum instances, not strings — compare against the raw map.
        $allowed = array_values(MarketplaceModeEnum::toArray());

        return in_array($mode, $allowed, true) ? $mode : MarketplaceModeEnum::COMMISSION;
    }

    public function isSubscriptionMode(): bool
    {
        return $this->getMode() === MarketplaceModeEnum::SUBSCRIPTION;
    }

    public function isCommissionMode(): bool
    {
        return $this->getMode() === MarketplaceModeEnum::COMMISSION;
    }

    /**
     * The commission half of hybrid: true whenever the marketplace takes a cut of each order.
     * Prefer this over isCommissionMode() for anything touching fee math.
     */
    public function shouldChargeCommission(): bool
    {
        return in_array(
            $this->getMode(),
            [MarketplaceModeEnum::COMMISSION, MarketplaceModeEnum::HYBRID],
            true
        );
    }

    /**
     * The subscription half of hybrid: true whenever vendors hold subscription plans.
     * Prefer this over isSubscriptionMode() for plans, quotas, menus, cron and middleware.
     */
    public function hasVendorSubscriptions(): bool
    {
        return in_array(
            $this->getMode(),
            [MarketplaceModeEnum::SUBSCRIPTION, MarketplaceModeEnum::HYBRID],
            true
        );
    }

    public function isSubscriptionBalancePaymentEnabled(): bool
    {
        return $this->hasVendorSubscriptions() && (bool) $this->getSetting('subscription_allow_balance_payment', true);
    }

    public function isPublicSubscriptionPlansEnabled(): bool
    {
        return $this->hasVendorSubscriptions()
            && (bool) $this->getSetting('enable_public_subscription_plans', false);
    }

    /**
     * @return array{url: string|null, label: string, disabled: bool}
     */
    public function subscriptionPlanCta(SubscriptionPlan $plan): array
    {
        return app(SubscriptionPlanCtaResolver::class)->resolve($plan);
    }

    public function subscriptionRequiresAdminApproval(): bool
    {
        return (bool) $this->getSetting('subscription_require_admin_approval', false);
    }

    public function subscriptionGracePeriodDays(): int
    {
        return max(0, (int) $this->getSetting('subscription_grace_period_days', 0));
    }

    /**
     * @return array<int, int> Days before expiry to send a reminder, descending, de-duplicated.
     */
    public function subscriptionReminderDays(): array
    {
        $days = collect(explode(',', (string) $this->getSetting('subscription_reminder_days', '7,3,1')))
            ->map(fn ($day) => (int) trim($day))
            ->filter(fn (int $day) => $day > 0)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        return $days;
    }

    public function shouldUnpublishProductsOnSubscriptionExpired(): bool
    {
        return (bool) $this->getSetting('subscription_unpublish_products_on_expired', true);
    }

    /**
     * @return array<int, string> Payment channels the admin allows for subscriptions. Empty = all enabled methods.
     */
    public function subscriptionPaymentMethods(): array
    {
        $methods = $this->getSetting('subscription_payment_methods', []);

        if (is_string($methods)) {
            $methods = json_decode($methods, true) ?: [];
        }

        return array_values(array_filter((array) $methods));
    }

    public function maxFilesizeUploadByVendor(): float
    {
        $size = $this->getSetting('max_filesize_upload_by_vendor');

        if (! $size) {
            $size = setting('max_upload_filesize') ?: 10;
        }

        return $size;
    }

    public function maxProductImagesUploadByVendor(): int
    {
        return (int) $this->getSetting('max_product_images_upload_by_vendor', 20);
    }

    public function isVendorRegistrationEnabled(): bool
    {
        return (bool) $this->getSetting('enabled_vendor_registration', true);
    }

    public function getMinimumWithdrawalAmount(): float
    {
        return (float) $this->getSetting('minimum_withdrawal_amount') ?: 0;
    }

    public function allowVendorDeleteTheirOrders(): bool
    {
        return (bool) $this->getSetting('allow_vendor_delete_their_orders', true);
    }

    public function isEnabledMessagingSystem(): bool
    {
        return (bool) $this->getSetting('enabled_messaging_system', true);
    }

    public function allowVendorManageProductCurrency(): bool
    {
        return (bool) $this->getSetting('allow_vendor_manage_product_currency', false);
    }

    public function getAllowedSocialLinks(): array
    {
        return [
            'facebook' => [
                'title' => 'Facebook',
                'icon' => 'facebook',
                'url' => 'https://facebook.com/',
            ],
            'twitter' => [
                'title' => 'X (Twitter)',
                'icon' => 'x',
                'url' => 'https://x.com/',
            ],
            'instagram' => [
                'title' => 'Instagram',
                'icon' => 'instagram',
                'url' => 'https://instagram.com/',
            ],
            'pinterest' => [
                'title' => 'Pinterest',
                'icon' => 'pinterest',
                'url' => 'https://pinterest.com/',
            ],
            'youtube' => [
                'title' => 'Youtube',
                'icon' => 'youtube',
                'url' => 'https://youtube.com/',
            ],
            'linkedin' => [
                'title' => 'Linkedin',
                'icon' => 'linkedin',
                'url' => 'https://linkedin.com/',
            ],
            'messenger' => [
                'title' => 'Messenger',
                'icon' => 'messenger',
                'url' => 'https://messenger.com/',
            ],
            'flickr' => [
                'title' => 'Flickr',
                'icon' => 'flickr',
                'url' => 'https://flickr.com/',
            ],
            'tiktok' => [
                'title' => 'Tiktok',
                'icon' => 'tiktok',
                'url' => 'https://tiktok.com/',
            ],
            'skype' => [
                'title' => 'Skype',
                'icon' => 'skype',
                'placeholder' => 'Ex: https://skype.com/{username}',
            ],
            'snapchat' => [
                'title' => 'Snapchat',
                'icon' => 'snapchat',
                'placeholder' => 'Ex: https://snapchat.com/{username}',
            ],
            'tumblr' => [
                'title' => 'Tumblr',
                'icon' => 'tumblr',
                'placeholder' => 'Ex: https://tumblr.com/{username}',
            ],
            'whatsapp' => [
                'title' => 'Whatsapp',
                'icon' => 'whatsapp',
                'placeholder' => 'Ex: https://whatsapp.com/{username}',
            ],
            'wechat' => [
                'title' => 'Wechat',
                'icon' => 'wechat',
                'placeholder' => 'Ex: https://wechat.com/{username}',
            ],
            'vimeo' => [
                'title' => 'Vimeo',
                'icon' => 'vimeo',
                'placeholder' => 'Ex: https://vimeo.com/{username}',
            ],
        ];
    }

    public function lowStockThreshold(): int
    {
        return (int) $this->getSetting('low_stock_threshold', 5);
    }

    public function isSingleVendorCheckout(): bool
    {
        return (bool) $this->getSetting('single_vendor_checkout', false);
    }

    public function mediaMimeTypesAllowed(): array
    {
        $allowedMimeTypes = $this->getSetting('media_mime_types_allowed', []);

        if (! is_array($allowedMimeTypes) && Str::isJson($allowedMimeTypes)) {
            $allowedMimeTypes = json_decode($allowedMimeTypes, true);
        }

        if (empty($allowedMimeTypes)) {
            $allowedMimeTypes = RvMedia::getConfig('allowed_mime_types');
            $allowedMimeTypes = explode(',', $allowedMimeTypes);
        }

        return $allowedMimeTypes;
    }

    public function isEnabledVendorCategoriesFilter(): bool
    {
        return (bool) $this->getSetting('enable_vendor_categories_filter', true);
    }

    public function isVendorDigitalProductsEnabled(): bool
    {
        if (! EcommerceHelper::isEnabledSupportDigitalProducts()) {
            return false;
        }

        return (bool) $this->getSetting('allow_vendor_digital_products', true);
    }

    public function getCategoriesForVendor(string|int $storeId): SupportCollection
    {
        $cacheKey = 'marketplace_store_categories_' . $storeId;

        return Cache::remember($cacheKey, 3600, function () use ($storeId) {
            $categoryIds = DB::table('ec_product_category_product')
                ->whereIn('product_id', function ($query) use ($storeId): void {
                    $query->select('id')
                        ->from('ec_products')
                        ->where('store_id', $storeId)
                        ->where('is_variation', 0)
                        ->where('status', BaseStatusEnum::PUBLISHED);
                })
                ->distinct()
                ->pluck('category_id');

            if ($categoryIds->isEmpty()) {
                return collect();
            }

            $allCategoryIds = collect($categoryIds);
            $parentIds = $categoryIds;

            while ($parentIds->isNotEmpty()) {
                $parentIds = ProductCategory::query()
                    ->whereIn('id', $parentIds)
                    ->whereNotNull('parent_id')
                    ->where('parent_id', '>', 0)
                    ->pluck('parent_id')
                    ->unique();

                if ($parentIds->isNotEmpty()) {
                    $allCategoryIds = $allCategoryIds->merge($parentIds)->unique();
                }
            }

            $categories = ProductCategory::query()
                ->whereIn('id', $allCategoryIds)
                ->wherePublished()
                ->with(['slugable'])
                ->orderBy('parent_id')
                ->orderBy('order')
                ->get();

            $prefix = SlugHelper::getPrefix(ProductCategory::class);

            return $categories->map(function ($category) use ($prefix) {
                $slug = $category->slugable?->key;

                $item = (object) $category->attributesToArray();
                $item->url = $slug
                    ? ($prefix ? $prefix . '/' . $slug : $slug)
                    : '#';

                return $item;
            });
        });
    }
}
