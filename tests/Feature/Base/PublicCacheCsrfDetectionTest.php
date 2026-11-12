<?php

namespace Tests\Feature\Base;

use Botble\Base\Http\Middleware\PublicCacheControl;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * Regression tests for CSRF detection in PublicCacheControl.
 *
 * A page carrying a CSRF token must never be advertised as publicly cacheable: a shared
 * cache would hand one visitor's token to everyone and their next submit would fail with
 * 419.
 *
 * The bug these cover: detection matched the literal string `name="_token"`, but Botble's
 * own optimize package (Settings -> Optimize page speed -> Remove quotes) rewrites that to
 * `name=_token`. Every form on every minified page was therefore invisible to the check.
 *
 * Lives under root tests/ for historical reasons: `platform/core/base/tests/` was not part
 * of any phpunit testsuite when this was written, so tests placed there never ran. That has
 * since been fixed - phpunit.xml now has a "Platform" suite - so new core tests belong next
 * to the code they cover, in `platform/core/base/tests/`.
 */
class PublicCacheCsrfDetectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['core.base.general.enable_public_cache_control' => true]);
        config(['core.base.general.public_cache_max_age' => 600]);
    }

    private function cacheControlFor(string $content): string
    {
        $request = Request::create('/', 'GET');
        $response = new Response($content);

        (new PublicCacheControl())->handleRequestHandled(new RequestHandled($request, $response));

        return (string) $response->headers->get('Cache-Control');
    }

    private function assertNotPubliclyCached(string $content, string $message): void
    {
        $this->assertStringNotContainsString('public', $this->cacheControlFor($content), $message);
    }

    private function assertPubliclyCached(string $content, string $message): void
    {
        $this->assertStringContainsString('public', $this->cacheControlFor($content), $message);
    }

    public function test_quoted_token_input_is_detected(): void
    {
        $this->assertNotPubliclyCached(
            '<form method="POST"><input type="hidden" name="_token" value="abc"></form>',
            'A standard @csrf hidden input must prevent public caching.'
        );
    }

    /**
     * The actual regression: minified markup as produced by the optimize package.
     */
    public function test_unquoted_token_input_is_detected(): void
    {
        $this->assertNotPubliclyCached(
            '<form method=POST><input name=_token type=hidden value="abc"></form>',
            'A minified (unquoted) _token input must prevent public caching.'
        );
    }

    public function test_single_quoted_token_input_is_detected(): void
    {
        $this->assertNotPubliclyCached(
            "<input name='_token' type='hidden' value='abc'>",
            'Single-quoted attributes must prevent public caching.'
        );
    }

    public function test_token_input_with_spaces_around_equals_is_detected(): void
    {
        $this->assertNotPubliclyCached(
            '<input name = "_token" value="abc">',
            'Whitespace around = must not defeat detection.'
        );
    }

    public function test_csrf_token_meta_is_detected(): void
    {
        $this->assertNotPubliclyCached(
            '<meta name="csrf-token" content="abc">',
            'A csrf-token meta tag must prevent public caching.'
        );
    }

    public function test_csrf_header_in_inline_js_is_detected_case_insensitively(): void
    {
        $this->assertNotPubliclyCached(
            "<script>fetch('/x', {headers: {'X-CSRF-TOKEN': 'abc'}})</script>",
            'An inline X-CSRF-TOKEN header must prevent public caching.'
        );

        $this->assertNotPubliclyCached(
            "<script>var t = 'x-csrf-token';</script>",
            'Detection must be case-insensitive.'
        );
    }

    public function test_csrf_token_helper_in_inline_js_is_detected(): void
    {
        $this->assertNotPubliclyCached(
            '<script>var t = "abc"; // csrf_token()</script>',
            'csrf_token() output in inline JS must prevent public caching.'
        );
    }

    public function test_page_without_any_token_is_publicly_cached(): void
    {
        // The whole point: token-free pages (listings, articles, a GET search form) still
        // get public cache headers.
        $this->assertPubliclyCached(
            '<html><body><form class="quick-search" action="/search"><input name=q></form></body></html>',
            'A page with no CSRF token must remain publicly cacheable.'
        );
    }

    public function test_word_token_alone_does_not_block_caching(): void
    {
        // `_token` must be matched as an attribute value, not as any occurrence of the word,
        // otherwise ordinary prose would silently disable caching site-wide.
        $this->assertPubliclyCached(
            '<p>Your API token is stored safely. The word token appears here.</p>',
            'Prose mentioning "token" must not block caching.'
        );
    }
}
