<?php

namespace Botble\Marketplace\Enums;

use Botble\Base\Facades\BaseHelper;
use Botble\Base\Supports\Enum;
use Illuminate\Support\HtmlString;

/**
 * @method static SubscriptionStatusEnum PENDING()
 * @method static SubscriptionStatusEnum ACTIVE()
 * @method static SubscriptionStatusEnum EXPIRED()
 * @method static SubscriptionStatusEnum CANCELLED()
 * @method static SubscriptionStatusEnum REJECTED()
 */
class SubscriptionStatusEnum extends Enum
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const REJECTED = 'rejected';

    public static $langPath = 'plugins/marketplace::subscription.statuses';

    public function toHtml(): HtmlString|string
    {
        $color = match ($this->value) {
            self::PENDING => 'warning',
            self::ACTIVE => 'success',
            self::EXPIRED => 'secondary',
            self::CANCELLED => 'danger',
            self::REJECTED => 'danger',
            default => 'primary',
        };

        return BaseHelper::renderBadge($this->label(), $color);
    }
}
