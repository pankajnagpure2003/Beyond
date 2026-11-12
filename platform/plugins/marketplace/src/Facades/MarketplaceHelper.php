<?php

namespace Botble\Marketplace\Facades;

use Botble\Marketplace\Supports\MarketplaceHelper as MarketplaceHelperSupport;
use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed view(string $view, array $data = [])
 * @method static string viewPath(string $view, bool $checkViewExists = true)
 * @method static array|string|int|float|bool|null getSetting(string $key, array|string|int|float|bool|null $default = '')
 * @method static string getSettingKey(string $key = '')
 * @method static array discountTypes()
 * @method static string getAssetVersion()
 * @method static bool isStoresPageEnabled()
 * @method static bool hideStorePhoneNumber()
 * @method static bool hideStoreEmail()
 * @method static bool hideStoreSocialLinks()
 * @method static bool hideStoreAddress()
 * @method static bool allowVendorManageShipping()
 * @method static mixed sendMailToVendorAfterProcessingOrder($orders)
 * @method static \Botble\Base\Supports\EmailHandler setEmailVendorVariables(\Botble\Ecommerce\Models\Order $order)
 * @method static bool isCommissionCategoryFeeBasedEnabled()
 * @method static string getMode()
 * @method static bool isSubscriptionMode()
 * @method static bool isCommissionMode()
 * @method static bool shouldChargeCommission()
 * @method static bool hasVendorSubscriptions()
 * @method static bool isSubscriptionBalancePaymentEnabled()
 * @method static bool isPublicSubscriptionPlansEnabled()
 * @method static array subscriptionPlanCta(\Botble\Marketplace\Models\SubscriptionPlan $plan)
 * @method static bool subscriptionRequiresAdminApproval()
 * @method static int subscriptionGracePeriodDays()
 * @method static array subscriptionReminderDays()
 * @method static bool shouldUnpublishProductsOnSubscriptionExpired()
 * @method static array subscriptionPaymentMethods()
 * @method static float maxFilesizeUploadByVendor()
 * @method static int maxProductImagesUploadByVendor()
 * @method static bool isVendorRegistrationEnabled()
 * @method static float getMinimumWithdrawalAmount()
 * @method static bool allowVendorDeleteTheirOrders()
 * @method static bool isEnabledMessagingSystem()
 * @method static array getAllowedSocialLinks()
 * @method static bool isSingleVendorCheckout()
 * @method static array mediaMimeTypesAllowed()
 * @method static bool isEnabledVendorCategoriesFilter()
 * @method static bool allowVendorManageProductCurrency()
 * @method static int lowStockThreshold()
 * @method static bool isVendorDigitalProductsEnabled()
 * @method static bool isChargeShippingPerVendor()
 * @method static \Illuminate\Support\Collection getCategoriesForVendor(string|int $storeId)
 *
 * @see \Botble\Marketplace\Supports\MarketplaceHelper
 */
class MarketplaceHelper extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MarketplaceHelperSupport::class;
    }
}
