<?php

namespace Botble\Ecommerce\Tests\Unit;

use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Enums\SpecificationAttributeFieldType;
use Botble\Ecommerce\Models\SpecificationAttribute;

class SpecificationAttributeHelpersTest extends BaseTestCase
{
    // --- generateOptionId ---

    public function test_generate_option_id_returns_8_char_hex_string(): void
    {
        $id = SpecificationAttribute::generateOptionId();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $id);
    }

    public function test_generate_option_id_produces_unique_values(): void
    {
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = SpecificationAttribute::generateOptionId();
        }

        $this->assertCount(100, array_unique($ids));
    }

    // --- hasOptions ---

    public function test_has_options_returns_true_for_select_type(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT);

        $this->assertTrue($attribute->hasOptions());
    }

    public function test_has_options_returns_true_for_radio_type(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::RADIO);

        $this->assertTrue($attribute->hasOptions());
    }

    public function test_has_options_returns_false_for_text_type(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::TEXT);

        $this->assertFalse($attribute->hasOptions());
    }

    public function test_has_options_returns_false_for_textarea_type(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::TEXTAREA);

        $this->assertFalse($attribute->hasOptions());
    }

    public function test_has_options_returns_false_for_checkbox_type(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::CHECKBOX);

        $this->assertFalse($attribute->hasOptions());
    }

    // --- hasIdBasedOptions ---

    public function test_has_id_based_options_returns_true_for_id_based_format(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            ['id' => 'abc12345', 'value' => 'Matte'],
            ['id' => 'def67890', 'value' => 'Glossy'],
        ]);

        $this->assertTrue($attribute->hasIdBasedOptions());
    }

    public function test_has_id_based_options_returns_false_for_flat_strings(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            'Matte',
            'Glossy',
        ]);

        $this->assertFalse($attribute->hasIdBasedOptions());
    }

    public function test_has_id_based_options_returns_false_for_empty_array(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, []);

        $this->assertFalse($attribute->hasIdBasedOptions());
    }

    public function test_has_id_based_options_returns_false_for_null(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, null);

        $this->assertFalse($attribute->hasIdBasedOptions());
    }

    // --- getIdBasedOptions ---

    public function test_get_id_based_options_returns_existing_id_based_options_as_is(): void
    {
        $options = [
            ['id' => 'abc12345', 'value' => 'Matte'],
            ['id' => 'def67890', 'value' => 'Glossy'],
        ];
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, $options);

        $result = $attribute->getIdBasedOptions();

        $this->assertEquals($options, $result);
    }

    public function test_get_id_based_options_converts_flat_strings_to_id_based(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            'Matte',
            'Glossy',
        ]);

        $result = $attribute->getIdBasedOptions();

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('id', $result[0]);
        $this->assertArrayHasKey('value', $result[0]);
        $this->assertEquals('Matte', $result[0]['value']);
        $this->assertEquals('Glossy', $result[1]['value']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $result[0]['id']);
    }

    public function test_get_id_based_options_returns_empty_for_null_options(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, null);

        $this->assertEquals([], $attribute->getIdBasedOptions());
    }

    public function test_get_id_based_options_returns_empty_for_empty_array(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, []);

        $this->assertEquals([], $attribute->getIdBasedOptions());
    }

    // --- getOptionValueById ---

    public function test_get_option_value_by_id_returns_matching_value(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            ['id' => 'abc12345', 'value' => 'Matte'],
            ['id' => 'def67890', 'value' => 'Glossy'],
        ]);

        $this->assertEquals('Matte', $attribute->getOptionValueById('abc12345'));
        $this->assertEquals('Glossy', $attribute->getOptionValueById('def67890'));
    }

    public function test_get_option_value_by_id_returns_null_for_unknown_id(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            ['id' => 'abc12345', 'value' => 'Matte'],
        ]);

        $this->assertNull($attribute->getOptionValueById('unknown99'));
    }

    public function test_get_option_value_by_id_returns_null_for_empty_options(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, []);

        $this->assertNull($attribute->getOptionValueById('abc12345'));
    }

    // --- getOptionIdByValue ---

    public function test_get_option_id_by_value_returns_matching_id(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            ['id' => 'abc12345', 'value' => 'Matte'],
            ['id' => 'def67890', 'value' => 'Glossy'],
        ]);

        $this->assertEquals('abc12345', $attribute->getOptionIdByValue('Matte'));
        $this->assertEquals('def67890', $attribute->getOptionIdByValue('Glossy'));
    }

    public function test_get_option_id_by_value_returns_null_for_unknown_value(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            ['id' => 'abc12345', 'value' => 'Matte'],
        ]);

        $this->assertNull($attribute->getOptionIdByValue('Satin'));
    }

    public function test_get_option_id_by_value_is_case_sensitive(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            ['id' => 'abc12345', 'value' => 'Matte'],
        ]);

        $this->assertNull($attribute->getOptionIdByValue('matte'));
        $this->assertEquals('abc12345', $attribute->getOptionIdByValue('Matte'));
    }

    // --- Backward compatibility: legacy flat arrays ---

    public function test_get_option_id_by_value_works_with_legacy_flat_strings(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            'Matte',
            'Glossy',
        ]);

        // Should still find the value via on-the-fly conversion
        $id = $attribute->getOptionIdByValue('Matte');
        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $id);
    }

    public function test_get_option_value_by_id_returns_null_for_legacy_format(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            'Matte',
            'Glossy',
        ]);

        // Legacy format generates random IDs each call, so looking up a specific ID won't match
        $this->assertNull($attribute->getOptionValueById('abc12345'));
    }

    // --- Edge cases ---

    public function test_has_id_based_options_with_array_missing_id_key(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            ['value' => 'Matte'],
            ['value' => 'Glossy'],
        ]);

        $this->assertFalse($attribute->hasIdBasedOptions());
    }

    public function test_get_option_value_by_id_with_multiple_options_same_value(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            ['id' => 'id1', 'value' => 'Same'],
            ['id' => 'id2', 'value' => 'Same'],
        ]);

        // getOptionIdByValue returns first match
        $this->assertEquals('id1', $attribute->getOptionIdByValue('Same'));
        // Both IDs resolve correctly
        $this->assertEquals('Same', $attribute->getOptionValueById('id1'));
        $this->assertEquals('Same', $attribute->getOptionValueById('id2'));
    }

    // --- Malformed / legacy option payloads (PHP 8 array-to-string crash) ---

    public function test_has_id_based_options_detects_ids_beyond_the_first_element(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            'Matte',
            ['id' => 'abc12345', 'value' => 'Glossy'],
        ]);

        $this->assertTrue($attribute->hasIdBasedOptions());
    }

    public function test_has_id_based_options_detects_ids_on_a_non_zero_indexed_array(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            3 => ['id' => 'abc12345', 'value' => 'Matte'],
        ]);

        $this->assertTrue($attribute->hasIdBasedOptions());
    }

    public function test_get_id_based_options_normalizes_a_mix_of_strings_and_pairs(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            'Matte',
            ['id' => 'abc12345', 'value' => 'Glossy'],
        ]);

        $result = $attribute->getIdBasedOptions();

        $this->assertCount(2, $result);
        $this->assertEquals('Matte', $result[0]['value']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $result[0]['id']);
        $this->assertEquals(['id' => 'abc12345', 'value' => 'Glossy'], $result[1]);
    }

    public function test_get_id_based_options_reindexes_a_non_zero_indexed_array(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            2 => ['id' => 'abc12345', 'value' => 'Matte'],
            5 => ['id' => 'def67890', 'value' => 'Glossy'],
        ]);

        $this->assertEquals([
            ['id' => 'abc12345', 'value' => 'Matte'],
            ['id' => 'def67890', 'value' => 'Glossy'],
        ], $attribute->getIdBasedOptions());
    }

    public function test_get_id_based_options_flattens_an_array_value_to_a_string(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            ['id' => 'abc12345', 'value' => ['en' => 'Matte', 'fr' => 'Mat']],
        ]);

        $result = $attribute->getIdBasedOptions();

        $this->assertSame('Matte', $result[0]['value']);
    }

    public function test_get_id_based_options_handles_an_option_with_no_value_key(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            ['id' => 'abc12345'],
            ['label' => 'Glossy'],
        ]);

        $result = $attribute->getIdBasedOptions();

        // Only the "value" key is treated as the label - an unknown shape renders blank rather than
        // leaking an id or crashing the view.
        $this->assertSame('', $result[0]['value']);
        $this->assertSame('', $result[1]['value']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $result[1]['id']);
    }

    public function test_get_id_based_options_derives_stable_ids_for_options_without_one(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            'Matte',
            'Glossy',
        ]);

        $first = $attribute->getIdBasedOptions();
        $second = $attribute->getIdBasedOptions();

        // The importer and the edit form persist these ids, so two calls must agree.
        $this->assertSame($first, $second);
        $this->assertNotSame($first[0]['id'], $first[1]['id']);
    }

    public function test_option_id_round_trips_for_options_without_an_id(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            'Matte',
            ['id' => 'abc12345', 'value' => 'Glossy'],
        ]);

        $optionId = $attribute->getOptionIdByValue('Matte');

        $this->assertNotNull($optionId);
        $this->assertSame('Matte', $attribute->getOptionValueById($optionId));
    }

    // --- getPlainOptions ---

    public function test_get_plain_options_returns_string_labels_only(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, [
            'Matte',
            ['id' => 'abc12345', 'value' => 'Glossy'],
            ['id' => 'def67890', 'value' => ['en' => 'Satin']],
        ]);

        $options = $attribute->getPlainOptions();

        $this->assertSame(['Matte', 'Glossy', 'Satin'], $options);

        foreach ($options as $option) {
            $this->assertIsString($option);
        }
    }

    public function test_get_plain_options_returns_empty_for_null_options(): void
    {
        $attribute = $this->makeAttribute(SpecificationAttributeFieldType::SELECT, null);

        $this->assertSame([], $attribute->getPlainOptions());
    }

    // --- Helper ---

    private function makeAttribute(
        string $type,
        ?array $options = null,
    ): SpecificationAttribute {
        $attribute = new SpecificationAttribute();
        $attribute->type = $type;
        $attribute->options = $options;

        return $attribute;
    }
}
