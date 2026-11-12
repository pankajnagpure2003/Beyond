<?php

namespace Botble\Media\Tests\Unit;

use Botble\Media\Supports\ImageDimensionsInjector;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Guards the attribute parsing of the width/height injector.
 *
 * The regression these tests exist for: the optimize package's RemoveQuotes middleware
 * (Settings -> Optimize page speed) strips the quotes from `src`, `width` and `height`
 * (among a few others), and it runs inside the middleware pipeline - before the catch-all
 * injector post-processes the response on RequestHandled. The injector only matched
 * `src="..."`, so on any site with that toggle enabled the catch-all layer silently did
 * nothing and raw <img> tags in blade templates kept shipping without dimensions
 * (reported from production, promarksystem.co.id).
 *
 * Attributes it leaves quoted - `alt`, `class`, `data-src` - are why the parser has to
 * cope with quoted and bare values in the same tag.
 */
class ImageDimensionsInjectorTest extends TestCase
{
    protected string $relativePath = 'test-image-dimensions/sample.png';

    protected function setUp(): void
    {
        parent::setUp();

        ImageDimensionsInjector::flushMemo();

        // A real 8x4 PNG, so resolveFromUrl() reads genuine dimensions instead of a
        // mocked value - the bug was in parsing, and a mock would hide it. Faked so the
        // test never writes into the app's own storage/app/public.
        Storage::fake('public');
        Storage::disk('public')->put($this->relativePath, $this->pngBytes(8, 4));
    }

    protected function tearDown(): void
    {
        ImageDimensionsInjector::flushMemo();

        parent::tearDown();
    }

    protected function url(): string
    {
        return '/storage/' . $this->relativePath;
    }

    protected function inject(string $attrs): string
    {
        return ImageDimensionsInjector::inject(sprintf('<img%s>', $attrs), $attrs);
    }

    public function test_it_injects_dimensions_for_a_double_quoted_src(): void
    {
        $result = $this->inject(sprintf(' src="%s" alt="Sample"', $this->url()));

        $this->assertStringContainsString('width="8"', $result);
        $this->assertStringContainsString('height="4"', $result);
        $this->assertStringContainsString('data-dims-auto', $result);
    }

    public function test_it_injects_dimensions_for_an_unquoted_src(): void
    {
        // Exactly the markup RemoveQuotes produces.
        $result = $this->inject(sprintf(' src=%s alt=Sample', $this->url()));

        $this->assertStringContainsString('width="8"', $result);
        $this->assertStringContainsString('height="4"', $result);
    }

    public function test_it_injects_dimensions_for_a_single_quoted_src(): void
    {
        $result = $this->inject(sprintf(" src='%s'", $this->url()));

        $this->assertStringContainsString('width="8"', $result);
        $this->assertStringContainsString('height="4"', $result);
    }

    public function test_it_prefers_unquoted_data_src_over_the_lazy_placeholder(): void
    {
        // Lazy-loading moves the real URL to data-src and leaves a placeholder in src.
        // Reading src would reserve the placeholder's aspect ratio and shift the layout.
        $result = $this->inject(sprintf(' src=/storage/placeholder.png data-src=%s', $this->url()));

        $this->assertStringContainsString('width="8"', $result);
        $this->assertStringContainsString('height="4"', $result);
    }

    public function test_it_leaves_tags_that_already_declare_dimensions_untouched(): void
    {
        // Unquoted too: a theme's intentional 24x24 icon must not be rewritten to 8x4.
        $attrs = sprintf(' src=%s width=24 height=24', $this->url());

        $this->assertSame(sprintf('<img%s>', $attrs), $this->inject($attrs));
    }

    public function test_it_does_not_mistake_srcset_for_src(): void
    {
        $attrs = sprintf(' srcset=%s 1x', $this->url());

        $this->assertSame(sprintf('<img%s>', $attrs), $this->inject($attrs));
    }

    public function test_it_leaves_remote_images_untouched(): void
    {
        $attrs = ' src=https://example.com/remote.png';

        $this->assertSame(sprintf('<img%s>', $attrs), $this->inject($attrs));
    }

    public function test_it_ignores_a_src_that_appears_inside_another_attribute_value(): void
    {
        // Regression: a per-attribute regex search also fires on the ` src=` inside the
        // alt text and reserves the wrong (or no) aspect ratio.
        $result = $this->inject(sprintf(' alt="a src=b" src="%s"', $this->url()));

        $this->assertStringContainsString('width="8"', $result);
        $this->assertStringContainsString('height="4"', $result);
    }

    public function test_it_ignores_a_src_inside_an_attribute_value_when_the_real_src_is_unquoted(): void
    {
        // RemoveQuotes leaves values containing spaces quoted, so both forms coexist.
        $result = $this->inject(sprintf(' alt="photo src=b" src=%s', $this->url()));

        $this->assertStringContainsString('width="8"', $result);
        $this->assertStringContainsString('height="4"', $result);
    }

    public function test_it_ignores_a_width_that_appears_inside_another_attribute_value(): void
    {
        $result = $this->inject(sprintf(' alt="a width=100" src=%s', $this->url()));

        $this->assertStringContainsString('width="8"', $result);
        $this->assertStringContainsString('height="4"', $result);
    }

    public function test_it_does_not_mistake_a_suffixed_attribute_name_for_src(): void
    {
        $result = $this->inject(sprintf(' data-original-src=/nope.png src=%s', $this->url()));

        $this->assertStringContainsString('width="8"', $result);
        $this->assertStringContainsString('height="4"', $result);
    }

    public function test_it_tolerates_boolean_attributes_and_spaces_around_equals(): void
    {
        $result = $this->inject(sprintf(' data-bb-lazy src = "%s" decoding=async', $this->url()));

        $this->assertStringContainsString('width="8"', $result);
        $this->assertStringContainsString('height="4"', $result);
    }

    /**
     * Smallest valid PNG of the given size, built with GD so the bytes are real.
     */
    protected function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }
}
