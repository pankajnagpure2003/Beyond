<?php

namespace Botble\Ecommerce\Tests\Unit;

use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Supports\VolumetricWeightCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Covers VolumetricWeightCalculator::billableWeight() — billable weight = max(actual, volumetric).
 *
 * The calculator reads settings (volumetric_weight_divisor, store_weight_unit,
 * store_width_height_unit) via global helpers backed by the settings table, so this extends
 * BaseTestCase (boots the app) rather than a plain PHPUnit TestCase.
 */
class VolumetricWeightCalculatorTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function itemsOf(float $length, float $wide, float $height, int $qty = 1): array
    {
        return [
            [
                'length' => $length,
                'wide' => $wide,
                'height' => $height,
                'qty' => $qty,
            ],
        ];
    }

    public function test_divisor_zero_by_default_returns_actual_weight_unchanged(): void
    {
        // No settings written: volumetric_weight_divisor defaults to 0 (disabled).
        $actual = 500.0;

        $result = VolumetricWeightCalculator::billableWeight($actual, $this->itemsOf(40, 30, 20));

        $this->assertSame($actual, $result);
    }

    public function test_negative_divisor_treated_as_disabled(): void
    {
        setting()->set('ecommerce_volumetric_weight_divisor', -100)->save();

        $actual = 500.0;

        $result = VolumetricWeightCalculator::billableWeight($actual, $this->itemsOf(40, 30, 20));

        $this->assertSame($actual, $result);
    }

    public function test_no_items_key_falls_back_to_actual_weight_without_error(): void
    {
        setting()->set('ecommerce_volumetric_weight_divisor', 5000)->save();

        $actual = 12.5;

        $result = VolumetricWeightCalculator::billableWeight($actual, []);

        $this->assertSame($actual, $result);
    }

    public function test_volumetric_less_than_actual_weight_returns_actual(): void
    {
        setting()->set('ecommerce_volumetric_weight_divisor', 5000)->save();
        setting()->set('ecommerce_store_weight_unit', 'kg')->save();
        setting()->set('ecommerce_store_width_height_unit', 'cm')->save();

        // 40 x 30 x 20 cm = 24000 cm3 / 5000 = 4.8 kg volumetric.
        $actual = 10.0;

        $result = VolumetricWeightCalculator::billableWeight($actual, $this->itemsOf(40, 30, 20));

        $this->assertEqualsWithDelta(10.0, $result, 0.001);
    }

    public function test_volumetric_greater_than_actual_weight_returns_volumetric(): void
    {
        setting()->set('ecommerce_volumetric_weight_divisor', 5000)->save();
        setting()->set('ecommerce_store_weight_unit', 'kg')->save();
        setting()->set('ecommerce_store_width_height_unit', 'cm')->save();

        // 40 x 30 x 20 cm = 24000 cm3 / 5000 = 4.8 kg volumetric.
        $actual = 0.5;

        $result = VolumetricWeightCalculator::billableWeight($actual, $this->itemsOf(40, 30, 20));

        $this->assertEqualsWithDelta(4.8, $result, 0.001);
    }

    public function test_weight_unit_grams_converts_volumetric_correctly(): void
    {
        setting()->set('ecommerce_volumetric_weight_divisor', 5000)->save();
        setting()->set('ecommerce_store_weight_unit', 'g')->save();
        setting()->set('ecommerce_store_width_height_unit', 'cm')->save();

        // Same package as above: volumetric = 4.8 kg = 4800 g.
        $actual = 500.0;

        $result = VolumetricWeightCalculator::billableWeight($actual, $this->itemsOf(40, 30, 20));

        $this->assertEqualsWithDelta(4800.0, $result, 0.01);
    }

    public function test_missing_dimension_falls_back_to_actual_weight(): void
    {
        setting()->set('ecommerce_volumetric_weight_divisor', 5000)->save();
        setting()->set('ecommerce_store_weight_unit', 'kg')->save();
        setting()->set('ecommerce_store_width_height_unit', 'cm')->save();

        $actual = 7.0;

        $items = [
            [
                'length' => 40,
                'wide' => 30,
                'height' => null,
                'qty' => 1,
            ],
        ];

        $result = VolumetricWeightCalculator::billableWeight($actual, $items);

        $this->assertSame($actual, $result);
    }

    public function test_zero_dimension_falls_back_to_actual_weight_never_zero(): void
    {
        setting()->set('ecommerce_volumetric_weight_divisor', 5000)->save();
        setting()->set('ecommerce_store_weight_unit', 'kg')->save();
        setting()->set('ecommerce_store_width_height_unit', 'cm')->save();

        $actual = 3.0;

        $items = [
            [
                'length' => 0,
                'wide' => 30,
                'height' => 20,
                'qty' => 1,
            ],
        ];

        $result = VolumetricWeightCalculator::billableWeight($actual, $items);

        $this->assertSame($actual, $result);
        $this->assertGreaterThan(0.0, $result);
    }

    public function test_multi_item_quantity_sums_volume_before_dividing(): void
    {
        setting()->set('ecommerce_volumetric_weight_divisor', 5000)->save();
        setting()->set('ecommerce_store_weight_unit', 'kg')->save();
        setting()->set('ecommerce_store_width_height_unit', 'cm')->save();

        // Item 1: 40x30x20 cm, qty 2 -> 24000 * 2 = 48000 cm3
        // Item 2: 10x10x10 cm, qty 1 -> 1000 cm3
        // Total 49000 cm3 / 5000 = 9.8 kg volumetric.
        $items = [
            [
                'length' => 40,
                'wide' => 30,
                'height' => 20,
                'qty' => 2,
            ],
            [
                'length' => 10,
                'wide' => 10,
                'height' => 10,
                'qty' => 1,
            ],
        ];

        $result = VolumetricWeightCalculator::billableWeight(1.0, $items);

        $this->assertEqualsWithDelta(9.8, $result, 0.001);
    }

    public function test_negative_quantity_is_clamped_to_one(): void
    {
        setting()->set('ecommerce_volumetric_weight_divisor', 5000)->save();
        setting()->set('ecommerce_store_weight_unit', 'kg')->save();
        setting()->set('ecommerce_store_width_height_unit', 'cm')->save();

        // qty is customer-controlled (cart quantity); a negative value must not suppress
        // or invert the volumetric contribution. Clamped to 1: 24000 / 5000 = 4.8 kg.
        $items = $this->itemsOf(40, 30, 20, -5);

        $result = VolumetricWeightCalculator::billableWeight(0.1, $items);

        $this->assertEqualsWithDelta(4.8, $result, 0.001);
    }

    public function test_inch_dimensions_convert_per_dimension_not_cubed(): void
    {
        setting()->set('ecommerce_volumetric_weight_divisor', 5000)->save();
        setting()->set('ecommerce_store_weight_unit', 'kg')->save();

        // Reference: 10 x 20 x 30 cm = 6000 cm3.
        setting()->set('ecommerce_store_width_height_unit', 'cm')->save();
        $cmResult = VolumetricWeightCalculator::billableWeight(0.0, $this->itemsOf(10, 20, 30));

        // The exact same physical package, expressed in inches. If conversion were applied to
        // the product (volume) instead of each dimension, this would be off by a factor of
        // 2.54^3 / 2.54 ≈ 6.45x rather than matching.
        setting()->forgetAll();
        setting()->set('ecommerce_volumetric_weight_divisor', 5000)->save();
        setting()->set('ecommerce_store_weight_unit', 'kg')->save();
        setting()->set('ecommerce_store_width_height_unit', 'inch')->save();
        $inchResult = VolumetricWeightCalculator::billableWeight(
            0.0,
            $this->itemsOf(10 / 2.54, 20 / 2.54, 30 / 2.54)
        );

        $this->assertEqualsWithDelta($cmResult, $inchResult, 0.01);
    }
}
