<?php

namespace Botble\Base\Tests\Feature;

use Botble\Base\Supports\TwigCompiler;
use Botble\Base\Supports\TwigExtension;
use Illuminate\Support\HtmlString;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Twig "trans" filter must substitute both Laravel's :placeholder and the legacy Twig-style
 * {{ placeholder }} that older / customer-overridden language files still use, without ever
 * expanding placeholders found inside the substituted values.
 */
class TwigTransFilterTest extends TestCase
{
    protected const GROUP = 'twig_trans_filter_test';

    protected TwigExtension $extension;

    protected function setUp(): void
    {
        parent::setUp();

        app('translator')->addLines([
            self::GROUP . '.modern' => 'Hello :name, you have :amount.',
            self::GROUP . '.legacy' => 'Hello {{ name }}, you have {{amount}}.',
            self::GROUP . '.mixed' => 'Hello {{ name }}, you have :amount.',
            self::GROUP . '.cases' => '{{ name }} / :Name / :NAME',
            self::GROUP . '.unknown' => 'Hello {{ name }} from {{ site_title }}.',
            self::GROUP . '.plain' => 'Nothing to replace here.',
        ], 'en');

        app()->setLocale('en');

        $this->extension = new TwigExtension();
    }

    protected function trans(string $key, array $replace = []): mixed
    {
        return $this->extension->trans(self::GROUP . '.' . $key, $replace);
    }

    public function test_modern_placeholders_are_replaced(): void
    {
        $this->assertSame('Hello John, you have $30.', $this->trans('modern', ['name' => 'John', 'amount' => '$30']));
    }

    public function test_legacy_placeholders_with_and_without_spaces_are_replaced(): void
    {
        $this->assertSame('Hello John, you have $30.', $this->trans('legacy', ['name' => 'John', 'amount' => '$30']));
    }

    public function test_mixed_placeholder_styles_are_replaced(): void
    {
        $this->assertSame('Hello John, you have $30.', $this->trans('mixed', ['name' => 'John', 'amount' => '$30']));
    }

    public function test_legacy_line_keeps_laravel_case_variants(): void
    {
        $this->assertSame('john / John / JOHN', $this->trans('cases', ['name' => 'john']));
    }

    public function test_legacy_placeholder_without_value_is_kept(): void
    {
        $this->assertSame('Hello John from {{ site_title }}.', $this->trans('unknown', ['name' => 'John']));
    }

    public function test_line_is_untouched_without_replacements(): void
    {
        $this->assertSame('Hello {{ name }}, you have {{amount}}.', $this->trans('legacy'));
        $this->assertSame('Nothing to replace here.', $this->trans('plain', ['name' => 'John']));
    }

    public function test_missing_key_returns_the_key(): void
    {
        $this->assertSame(self::GROUP . '.missing', $this->trans('missing', ['name' => 'John']));
    }

    public function test_zero_null_and_stringable_values_are_supported(): void
    {
        $this->assertSame(
            'Hello 0, you have <b>$30</b>.',
            $this->trans('legacy', ['name' => 0, 'amount' => new HtmlString('<b>$30</b>')])
        );

        $this->assertSame('Hello , you have $30.', $this->trans('legacy', ['name' => null, 'amount' => '$30']));
    }

    public function test_enum_values_are_supported_like_laravel(): void
    {
        $this->assertSame(
            'Hello paid, you have Pending.',
            $this->trans('legacy', ['name' => TwigTransFilterTestBackedEnum::Paid, 'amount' => TwigTransFilterTestUnitEnum::Pending])
        );
    }

    public function test_non_stringable_value_leaves_legacy_placeholder_untouched(): void
    {
        $this->assertSame('Hello {{ name }}, you have $30.', $this->trans('legacy', ['name' => ['x'], 'amount' => '$30']));
    }

    /**
     * A user-controlled value (e.g. a customer name) must be inserted literally - placeholders
     * inside it must never pull in other values, whatever syntax the translation line uses.
     */
    #[DataProvider('injectedValues')]
    public function test_placeholders_inside_values_are_not_expanded(string $key, string $name): void
    {
        $this->assertSame(
            "Hello $name, you have \$30.",
            $this->trans($key, ['name' => $name, 'amount' => '$30'])
        );
    }

    public static function injectedValues(): array
    {
        return [
            'legacy line, twig value' => ['legacy', '{{ amount }}'],
            'legacy line, colon value' => ['legacy', ':amount'],
            'modern line, twig value' => ['modern', '{{ amount }}'],
            'mixed line, colon value' => ['mixed', ':amount'],
        ];
    }

    public function test_filter_is_registered_on_the_twig_compiler(): void
    {
        $output = (new TwigCompiler())->compile(
            "{{ '" . self::GROUP . ".legacy' | trans({'name': name, 'amount': amount}) }}",
            ['name' => 'John', 'amount' => '$30']
        );

        $this->assertSame('Hello John, you have $30.', $output);
    }

    public function test_values_are_never_compiled_as_twig(): void
    {
        $output = (new TwigCompiler())->compile(
            "{{ '" . self::GROUP . ".legacy' | trans({'name': name, 'amount': amount}) }}",
            ['name' => '{{ 7 * 7 }}', 'amount' => '$30']
        );

        $this->assertStringContainsString('{{ 7 * 7 }}', $output);
        $this->assertStringNotContainsString('49', $output);
    }
}

enum TwigTransFilterTestBackedEnum: string
{
    case Paid = 'paid';
}

enum TwigTransFilterTestUnitEnum
{
    case Pending;
}
