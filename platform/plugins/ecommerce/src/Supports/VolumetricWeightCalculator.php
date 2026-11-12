<?php

namespace Botble\Ecommerce\Supports;

use Illuminate\Support\Arr;

/**
 * Calculates industry-standard billable weight (dimensional/volumetric weight)
 * for built-in shipping fee rules: billable = max(actual weight, volumetric weight).
 *
 * Disabled by default (divisor = 0) so existing installs see zero behaviour change
 * until a merchant explicitly opts in via the "volumetric_weight_divisor" setting.
 */
class VolumetricWeightCalculator
{
    /**
     * Compute the billable weight for an order, in the store's configured weight unit.
     *
     * @param float $actualWeight Actual weight, already floored by EcommerceHelper::validateOrderWeight(),
     *                            expressed in the store weight unit.
     * @param array $items Cart/order items, each optionally containing length/wide/height (store
     *                     width/height unit) and qty. Missing 'items' key must be passed as [].
     */
    public static function billableWeight(float $actualWeight, array $items): float
    {
        $divisor = (float) get_ecommerce_setting('volumetric_weight_divisor', 0);

        // The off switch: 0 (default), blank, or negative disables the feature entirely.
        // This is the path every existing install takes until a merchant opts in.
        if ($divisor <= 0) {
            return $actualWeight;
        }

        $volumeCm3 = 0.0;

        foreach ($items as $item) {
            $length = Arr::get($item, 'length');
            $wide = Arr::get($item, 'wide');
            $height = Arr::get($item, 'height');

            // A partial box is not measurable; skip it rather than guessing.
            if ($length === null || $wide === null || $height === null) {
                continue;
            }

            $length = (float) $length;
            $wide = (float) $wide;
            $height = (float) $height;

            if ($length <= 0 || $wide <= 0 || $height <= 0) {
                continue;
            }

            $qty = max(1, (int) Arr::get($item, 'qty', 1));

            // Convert each dimension individually before multiplying. The conversion factor
            // is linear per-dimension; converting the product instead would cube the factor
            // (e.g. a 2.54x error would become 16.4x).
            $volumeCm3 += ecommerce_convert_width_height($length)
                * ecommerce_convert_width_height($wide)
                * ecommerce_convert_width_height($height)
                * $qty;
        }

        if ($volumeCm3 <= 0) {
            return $actualWeight;
        }

        $volumetricKg = $volumeCm3 / $divisor;

        // Grams-per-store-unit (g=1, kg=1000, lb=453.592, oz=28.3495), used to convert the
        // volumetric weight (computed in kg) back into the store's configured weight unit.
        $gramsPerUnit = ecommerce_convert_weight(1);

        if ($gramsPerUnit <= 0) {
            return $actualWeight;
        }

        $volumetricInStoreUnit = ($volumetricKg * 1000) / $gramsPerUnit;

        return max($actualWeight, $volumetricInStoreUnit);
    }
}
