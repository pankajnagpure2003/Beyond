<?php

namespace Tests\Feature\Theme;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Covers the llms.txt / llms-full.txt endpoints (https://llmstxt.org).
 *
 * The routes are registered by the active theme, so each test skips when the route is
 * absent (no theme active in this environment) instead of failing.
 */
class LlmsTxtTest extends TestCase
{
    private function skipUnlessRouteExists(string $name): void
    {
        if (! Route::has($name)) {
            $this->markTestSkipped('Route [' . $name . '] is not registered (no active theme).');
        }
    }

    public function test_llms_txt_follows_the_spec_shape(): void
    {
        $this->skipUnlessRouteExists('public.llms-txt');

        $response = $this->get('/llms.txt');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/plain; charset=UTF-8');

        $content = (string) $response->getContent();

        // Spec: the document opens with exactly one H1.
        $this->assertStringStartsWith('# ', $content);
        $this->assertSame(1, preg_match_all('/^# /m', $content), 'llms.txt must contain a single H1.');
    }

    public function test_llms_txt_is_cached_between_requests(): void
    {
        $this->skipUnlessRouteExists('public.llms-txt');

        // Clear first, so the request under test is the one that populates the cache and
        // the second one is provably served from it.
        Cache::flush();

        $queriesFirst = 0;
        DB::listen(function () use (&$queriesFirst): void {
            $queriesFirst++;
        });

        $first = (string) $this->get('/llms.txt')->getContent();

        $this->assertGreaterThan(0, $queriesFirst, 'The first request must build the file from the database.');

        $queriesSecond = 0;
        DB::listen(function () use (&$queriesSecond): void {
            $queriesSecond++;
        });

        $second = (string) $this->get('/llms.txt')->getContent();

        $this->assertSame($first, $second);
        $this->assertSame(0, $queriesSecond, 'A cached llms.txt must not re-query the database.');
    }

    /**
     * Regression: `llms-full.txt` must 404 while disabled, never 200 with an empty body.
     *
     * The sitemap catch-all route `{key}.{extension}` accepts the `txt` extension, so a
     * conditionally registered llms route used to fall through to it and render an empty
     * txt sitemap - which reads to a crawler as "this site has no content".
     */
    public function test_llms_full_txt_returns_404_while_disabled(): void
    {
        $this->assertTrue(
            Route::has('public.llms-full-txt'),
            'The route must be registered unconditionally so the controller can 404 instead of falling through to the sitemap catch-all.'
        );

        $response = $this->get('/llms-full.txt');

        if (setting('llms_full_txt_enabled', false)) {
            // Enabled: a 200 is fine, but the body must not be empty.
            if ($response->getStatusCode() === 200) {
                $this->assertNotSame('', trim((string) $response->getContent()));
            }

            return;
        }

        $response->assertNotFound();
        $this->assertNotSame(
            'text/plain; charset=utf-8',
            strtolower((string) $response->headers->get('content-type')),
            'A disabled llms-full.txt must not be answered by the sitemap catch-all.'
        );
    }

    public function test_llms_txt_returns_404_when_disabled(): void
    {
        $this->skipUnlessRouteExists('public.llms-txt');

        setting()->set(['llms_txt_enabled' => 0])->save();

        try {
            $this->get('/llms.txt')->assertNotFound();
        } finally {
            setting()->set(['llms_txt_enabled' => 1])->save();
        }
    }

    /**
     * @return array{0: \ReflectionMethod, 1: object}
     */
    private function controllerMethod(string $name): array
    {
        $method = new \ReflectionMethod(\Botble\Theme\Http\Controllers\PublicController::class, $name);
        $method->setAccessible(true);

        return [$method, $method->getDeclaringClass()->newInstanceWithoutConstructor()];
    }

    /**
     * Regression: pages built with the block editor store shortcode markup in `content`,
     * so llms-full.txt published `[featured-posts ...][/featured-posts]` builder syntax
     * instead of readable text.
     */
    public function test_shortcodes_are_stripped_from_full_text(): void
    {
        [$method, $controller] = $this->controllerMethod('stripShortcodes');

        $result = $method->invoke(
            $controller,
            '[featured-posts enable_lazy_loading="yes"][/featured-posts]Real prose here.[recent-posts title="What\'s new?"][/recent-posts]'
        );

        $this->assertStringNotContainsString('[featured-posts', $result);
        $this->assertStringNotContainsString('[/featured-posts]', $result);
        $this->assertStringNotContainsString('recent-posts', $result);
        $this->assertStringContainsString('Real prose here.', $result);
    }

    public function test_bracketed_prose_is_not_mistaken_for_a_shortcode(): void
    {
        [$method, $controller] = $this->controllerMethod('stripShortcodes');

        $this->assertSame('A title [Guide] and [2026] stay', $method->invoke($controller, 'A title [Guide] and [2026] stay'));
    }

    public function test_shortcode_only_content_normalizes_to_nothing(): void
    {
        [$strip, $controller] = $this->controllerMethod('stripShortcodes');
        [$clean] = $this->controllerMethod('cleanLlmsText');

        $stripped = $strip->invoke($controller, '[all-galleries limit="6"][/all-galleries][featured-posts][/featured-posts]');

        // Such an item contributes nothing and is skipped rather than published empty.
        $this->assertSame('', $clean->invoke($controller, $stripped, PHP_INT_MAX));
    }

    public function test_script_and_style_bodies_are_not_published(): void
    {
        [$method, $controller] = $this->controllerMethod('cleanLlmsText');

        $result = $method->invoke(
            $controller,
            '<p>Visible.</p><script>var apiKey="secret-token";</script><style>.a{color:red}</style>',
            PHP_INT_MAX
        );

        $this->assertStringContainsString('Visible.', $result);
        $this->assertStringNotContainsString('secret-token', $result);
        $this->assertStringNotContainsString('color:red', $result);
    }

    public function test_sections_filter_constant_is_defined(): void
    {
        // Plugins rely on this constant to register their own llms.txt sections.
        $this->assertTrue(defined('FILTER_LLMS_TXT_SECTIONS'));
        $this->assertSame('filter_llms_txt_sections', FILTER_LLMS_TXT_SECTIONS);
    }
}
