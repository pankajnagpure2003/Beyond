<?php

namespace Botble\Base\Tests\Feature;

use Tests\TestCase;

class LanguageFlagTest extends TestCase
{
    protected function flagPath(string $flag): string
    {
        return public_path(BASE_LANGUAGE_FLAG_PATH . $flag . '.svg');
    }

    protected function flagSize(string $flag): int
    {
        $path = $this->flagPath($flag);

        return file_exists($path) ? (int) filesize($path) : 0;
    }

    public function test_returns_empty_string_without_a_flag(): void
    {
        $this->assertSame('', language_flag(null));
        $this->assertSame('', language_flag(''));
    }

    public function test_small_flag_is_inlined_as_svg(): void
    {
        if (! file_exists($this->flagPath('fr'))) {
            $this->markTestSkipped('fr.svg is not present.');
        }

        $this->assertLessThanOrEqual(4096, $this->flagSize('fr'), 'fr.svg is expected to be a small flag.');

        $output = language_flag('fr', 'French');

        $this->assertStringContainsString('<svg', $output);
        $this->assertStringNotContainsString('<img', $output);
        $this->assertStringContainsString('class="flag"', $output);
    }

    public function test_oversized_flag_is_served_as_an_image_instead_of_being_inlined(): void
    {
        if (! file_exists($this->flagPath('sa'))) {
            $this->markTestSkipped('sa.svg is not present.');
        }

        // sa.svg draws the Arabic shahada as vector paths and is ~100KB. Inlining it -
        // twice, since themes render the language switcher in both the desktop and
        // mobile header - used to account for the majority of a page's HTML.
        $this->assertGreaterThan(4096, $this->flagSize('sa'), 'sa.svg is expected to be an oversized flag.');

        $output = language_flag('sa', 'Arabic');

        $this->assertStringContainsString('<img', $output);
        $this->assertStringNotContainsString('<svg', $output);
        $this->assertStringContainsString('loading="lazy"', $output);
        $this->assertLessThan(500, strlen($output), 'The <img> fallback must be tiny compared to the inlined SVG.');
    }

    public function test_threshold_is_configurable(): void
    {
        if (! file_exists($this->flagPath('fr'))) {
            $this->markTestSkipped('fr.svg is not present.');
        }

        config(['core.base.general.max_inline_language_flag_size' => 0]);

        // 0 means "never inline", so even the smallest flag becomes an <img>.
        $this->assertStringContainsString('<img', language_flag('fr', 'French'));

        config(['core.base.general.max_inline_language_flag_size' => 4096]);

        $this->assertStringContainsString('<svg', language_flag('fr', 'French'));
    }

    public function test_missing_flag_falls_back_to_an_image(): void
    {
        $output = language_flag('this-flag-does-not-exist', 'Nowhere');

        $this->assertStringContainsString('<img', $output);
    }

    public function test_invalid_threshold_config_falls_back_to_the_default(): void
    {
        config(['core.base.general.max_inline_language_flag_size' => 'not-a-number']);

        $nonNumericSize = max_inline_language_flag_size();

        config(['core.base.general.max_inline_language_flag_size' => -1]);

        $negativeSize = max_inline_language_flag_size();

        $this->assertSame(4096, $nonNumericSize);
        $this->assertSame(4096, $negativeSize);
    }
}
