<?php

namespace Botble\Base\Tests\Feature;

use Botble\Theme\Facades\ThemeOption;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class AdminFormAccessibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Normally shared by the web middleware; form components read it for validation state.
        View::share('errors', new ViewErrorBag());
    }

    public function test_array_named_select_does_not_reuse_name_as_id(): void
    {
        $html = Blade::render('<x-core::form.select name="filter_columns[]" :options="[1 => \'A\']" />')
            . Blade::render('<x-core::form.select name="filter_columns[]" :options="[1 => \'A\']" />');

        $this->assertStringNotContainsString('id="filter_columns[]"', $html);

        preg_match_all('/<select[^>]*\bid="([^"]+)"/', $html, $matches);
        $this->assertCount(2, array_unique($matches[1]));
    }

    public function test_plain_named_select_keeps_name_as_id(): void
    {
        $html = Blade::render('<x-core::form.select name="status" label="Status" :options="[1 => \'A\']" />');

        $this->assertStringContainsString('id="status"', $html);
        $this->assertStringContainsString('for="status"', $html);
    }

    public function test_icon_only_button_uses_tooltip_as_accessible_name(): void
    {
        $html = Blade::render('<x-core::button icon="ti ti-x" :icon-only="true" tooltip="Close" />');

        $this->assertStringContainsString('aria-label="Close"', $html);
    }

    public function test_button_with_text_gets_no_redundant_aria_label(): void
    {
        $html = Blade::render('<x-core::button icon="ti ti-x" tooltip="Close">Close</x-core::button>');

        $this->assertStringNotContainsString('aria-label', $html);
    }

    public function test_theme_option_text_field_gets_the_field_id(): void
    {
        $html = ThemeOption::renderField([
            'id' => 'a11y_test_text',
            'type' => 'text',
            'attributes' => [
                'name' => 'a11y_test_text',
                'value' => null,
                'options' => ['class' => 'form-control'],
            ],
        ]);

        $this->assertMatchesRegularExpression('/<input[^>]*\bid="a11y_test_text"/', $html);
    }

    public function test_theme_option_legacy_custom_select_gets_the_field_id_and_keeps_value(): void
    {
        $html = ThemeOption::renderField([
            'id' => 'a11y_test_select',
            'type' => 'customSelect',
            'attributes' => [
                'name' => 'a11y_test_select',
                'list' => ['-' => 'Dash', '|' => 'Pipe'],
                'value' => '|',
            ],
        ]);

        $this->assertMatchesRegularExpression('/<select[^>]*\bid="a11y_test_select"/', $html);
        $this->assertMatchesRegularExpression('/<option value="\|" selected/', $html);
    }
}
