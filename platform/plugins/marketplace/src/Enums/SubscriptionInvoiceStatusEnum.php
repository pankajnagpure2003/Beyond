<?php

namespace Botble\Marketplace\Enums;

use Botble\Base\Facades\BaseHelper;
use Botble\Base\Supports\Enum;
use Illuminate\Support\HtmlString;

/**
 * @method static SubscriptionInvoiceStatusEnum PENDING()
 * @method static SubscriptionInvoiceStatusEnum PAID()
 * @method static SubscriptionInvoiceStatusEnum CANCELLED()
 */
class SubscriptionInvoiceStatusEnum extends Enum
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public static $langPath = 'plugins/marketplace::subscription.invoices.statuses';

    public function toHtml(): HtmlString|string
    {
        $color = match ($this->value) {
            self::PAID => 'success',
            self::CANCELLED => 'danger',
            default => 'warning',
        };

        return BaseHelper::renderBadge($this->label(), $color);
    }
}
