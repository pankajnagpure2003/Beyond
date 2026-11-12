<?php

namespace Botble\Media\Tests\Feature;

use Botble\Media\Supports\ImageDimensionsInjector;
use Botble\Optimize\Facades\OptimizerHelper;
use Botble\Optimize\Http\Middleware\RemoveQuotes;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * End-to-end guard for the interaction that actually broke in production.
 *
 * The unit tests cover ImageDimensionsInjector::inject() in isolation; this one drives
 * the real RemoveQuotes middleware and the real RequestHandled listener in the order the
 * framework runs them, because the bug lived in that ordering rather than in either piece:
 * RemoveQuotes rewrites `src="…"` to `src=…` inside the middleware pipeline, and the
 * catch-all injector only post-processes the response afterwards, on RequestHandled.
 *
 * Reported by promarksystem.co.id (Botble 7.6.11, "Remove quotes" enabled): 4 of 59
 * homepage images shipped without width/height, all of them raw <img> tags in blade.
 */
class ImageDimensionsWithRemoveQuotesTest extends TestCase
{
    protected string $relativePath = 'test-remove-quotes/decoration.png';

    protected function setUp(): void
    {
        parent::setUp();

        ImageDimensionsInjector::flushMemo();

        Storage::fake('public');
        Storage::disk('public')->put($this->relativePath, $this->pngBytes(120, 60));

        // The middleware bails unless both the package-level kill switch and the
        // "Enable optimize page speed" setting are on. Optimizer computes isEnabled in
        // its constructor and lands on false under PHPUnit (runningInConsole), so the
        // facade has to be flipped explicitly.
        //
        // Deliberately NOT ->save(): that writes through to the real settings table,
        // which this suite does not roll back, so a developer running the tests would
        // silently end up with "Enable optimize page speed" switched on for good. The
        // in-memory value is enough for the middleware to read, and the per-test
        // application refresh discards it.
        OptimizerHelper::enable();
        setting()->set('optimize_page_speed_enable', '1');
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

    /**
     * Push HTML through RemoveQuotes, then through the RequestHandled listener that
     * MediaServiceProvider registers - the same two steps a real page view takes.
     */
    protected function throughPipeline(string $html): string
    {
        $request = Request::create('/', 'GET');

        // Held in a variable rather than read back from handle(): the middleware mutates
        // this very instance via setContent(), and RequestHandled only accepts an
        // Illuminate response, while PageSpeed::handle() is typed to the Symfony one.
        $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        (new RemoveQuotes())->handle($request, fn () => $response);

        Event::dispatch(new RequestHandled($request, $response));

        return (string) $response->getContent();
    }

    public function test_remove_quotes_unquotes_src_as_expected(): void
    {
        // Asserts the precondition rather than assuming it: if this ever stops holding,
        // the test below would pass for the wrong reason.
        $output = $this->throughPipeline(sprintf('<html><body><img src="%s" alt="decoration"></body></html>', $this->url()));

        $this->assertStringContainsString(sprintf('src=%s', $this->url()), $output);
        $this->assertStringContainsString('alt="decoration"', $output);
    }

    public function test_dimensions_are_injected_on_a_raw_img_tag_after_quotes_are_removed(): void
    {
        $output = $this->throughPipeline(sprintf('<html><body><img src="%s" alt="decoration"></body></html>', $this->url()));

        $this->assertStringContainsString('width="120"', $output);
        $this->assertStringContainsString('height="60"', $output);
        $this->assertStringContainsString('data-dims-auto', $output);
    }

    public function test_it_matches_the_production_markup_that_was_reported(): void
    {
        // Copied from the shape RemoveQuotes produced on the reporting site: unquoted
        // src, still-quoted alt and class.
        $html = sprintf(
            '<html><body><img src=%s alt="background image" class="icon-image page_speed_7ecc6655"></body></html>',
            $this->url()
        );

        $output = $this->throughPipeline($html);

        $this->assertStringContainsString('width="120"', $output);
        $this->assertStringContainsString('height="60"', $output);
    }

    public function test_an_intentionally_sized_image_keeps_its_own_dimensions(): void
    {
        // RemoveQuotes unquotes width/height too, so the "already sized" guard has to
        // read them unquoted as well or a 24x24 icon gets rewritten to 120x60.
        $output = $this->throughPipeline(
            sprintf('<html><body><img src="%s" width="24" height="24"></body></html>', $this->url())
        );

        $this->assertStringContainsString('width=24', $output);
        $this->assertStringContainsString('height=24', $output);
        $this->assertStringNotContainsString('120', $output);
        $this->assertStringNotContainsString('data-dims-auto', $output);
    }

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
