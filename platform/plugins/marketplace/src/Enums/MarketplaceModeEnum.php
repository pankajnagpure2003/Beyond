<?php

namespace Botble\Marketplace\Enums;

use Botble\Base\Facades\BaseHelper;
use Botble\Base\Supports\Enum;
use Illuminate\Support\HtmlString;

/**
 * @method static MarketplaceModeEnum COMMISSION()
 * @method static MarketplaceModeEnum SUBSCRIPTION()
 * @method static MarketplaceModeEnum HYBRID()
 */
class MarketplaceModeEnum extends Enum
{
    public const COMMISSION = 'commission';

    public const SUBSCRIPTION = 'subscription';

    /** Vendors pay a subscription AND the marketplace takes a commission on each order. */
    public const HYBRID = 'hybrid';

    public static $langPath = 'plugins/marketplace::subscription.modes';

    public function toHtml(): HtmlString|string
    {
        $color = match ($this->value) {
            self::SUBSCRIPTION => 'info',
            self::HYBRID => 'azure',
            default => 'primary',
        };

        return BaseHelper::renderBadge($this->label(), $color);
    }
}
