<?php

namespace Botble\Marketplace\Enums;

use Botble\Base\Supports\Enum;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * @method static SubscriptionDurationUnitEnum DAY()
 * @method static SubscriptionDurationUnitEnum MONTH()
 * @method static SubscriptionDurationUnitEnum YEAR()
 * @method static SubscriptionDurationUnitEnum LIFETIME()
 */
class SubscriptionDurationUnitEnum extends Enum
{
    public const DAY = 'day';

    public const MONTH = 'month';

    public const YEAR = 'year';

    public const LIFETIME = 'lifetime';

    public static $langPath = 'plugins/marketplace::subscription.duration_units';

    /**
     * Add $value units of this duration to $from. Returns null for lifetime plans,
     * which never expire and therefore have a null ends_at.
     */
    public static function addTo(string $unit, int $value, CarbonInterface $from): ?Carbon
    {
        $date = Carbon::parse($from);

        // No-overflow variants: a plan bought on 31 Jan must end on 28 Feb, not 3 Mar.
        // Plain addMonths() would hand the vendor extra days and drift the billing day
        // further every renewal.
        return match ($unit) {
            self::DAY => $date->addDays($value),
            self::MONTH => $date->addMonthsNoOverflow($value),
            self::YEAR => $date->addYearsNoOverflow($value),
            default => null,
        };
    }
}
