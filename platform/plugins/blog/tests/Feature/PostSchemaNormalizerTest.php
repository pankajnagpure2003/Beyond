<?php

namespace Botble\Blog\Tests\Feature;

use ReflectionMethod;
use Tests\TestCase;

/**
 * Behavioural test for the JSON-LD text normalizer used by the blog post schema.
 *
 * Complements Botble\Blog\Tests\Unit\PostSchemaTest, which inspects the source: this one
 * runs the real code path (HTMLPurifier included) to prove that HTML entities are actually
 * decoded and tags actually stripped before values reach JSON-LD.
 *
 * Skips when the blog plugin classes are unavailable, so it is safe in CI's
 * plugins-deactivated run.
 */
class PostSchemaNormalizerTest extends TestCase
{
    /**
     * Resolve a protected method on the blog hook provider.
     *
     * Every access goes through here so the skip applies uniformly: when the blog plugin
     * is deactivated its classes are not autoloaded, and a bare `new ReflectionMethod()`
     * would throw ReflectionException instead of skipping (CI runs the suite once with all
     * plugins deactivated).
     */
    private function providerMethod(string $name): ReflectionMethod
    {
        $class = 'Botble\\Blog\\Providers\\HookServiceProvider';

        if (! class_exists($class)) {
            $this->markTestSkipped('Blog plugin is not available.');
        }

        $method = new ReflectionMethod($class, $name);
        $method->setAccessible(true);

        return $method;
    }

    private function invokeProvider(string $name, mixed ...$arguments): mixed
    {
        $method = $this->providerMethod($name);

        return $method->invoke($method->getDeclaringClass()->newInstance($this->app), ...$arguments);
    }

    private function clean(?string $value): ?string
    {
        return $this->invokeProvider('cleanSchemaText', $value);
    }

    public function test_html_entities_are_decoded(): void
    {
        // The bug: HTMLPurifier output is HTML-encoded, so "&" shipped as "&amp;" inside
        // JSON-LD, which is not HTML.
        $result = $this->clean('Ranked & Compared');

        $this->assertSame('Ranked & Compared', $result);
        $this->assertStringNotContainsString('&amp;', (string) $result);
    }

    public function test_pre_encoded_entities_are_also_decoded(): void
    {
        $this->assertSame('Ranked & Compared', $this->clean('Ranked &amp; Compared'));
        $this->assertSame("It's here", $this->clean('It&#39;s here'));
    }

    public function test_tags_are_stripped(): void
    {
        $result = (string) $this->clean('Title <em>with</em> markup');

        $this->assertSame('Title with markup', $result);
        $this->assertStringNotContainsString('<', $result);
    }

    public function test_script_payload_cannot_survive(): void
    {
        // Combined with JSON_HEX_TAG this is what keeps a title from terminating the
        // surrounding <script type="application/ld+json"> block.
        $result = (string) $this->clean('Safe </script><script>alert(1)</script>');

        $this->assertStringNotContainsString('</script>', $result);
        $this->assertStringNotContainsString('<script', $result);
    }

    /**
     * Regression: stripping tags before decoding entities turned "&lt;script&gt;" back
     * into live markup after the tags had already been removed.
     */
    public function test_entity_encoded_script_payload_cannot_survive(): void
    {
        $result = (string) $this->clean('Safe &lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;');

        $this->assertStringNotContainsString('</script>', $result);
        $this->assertStringNotContainsString('<script', $result);
    }

    public function test_double_encoded_payload_cannot_survive(): void
    {
        $result = (string) $this->clean('Safe &amp;lt;script&amp;gt;alert(1)&amp;lt;/script&amp;gt;');

        $this->assertStringNotContainsString('<script', $result);
    }

    public function test_admin_author_url_is_not_published(): void
    {
        $adminPrefix = trim((string) \Botble\Base\Facades\BaseHelper::getAdminPrefix(), '/');

        // Botble's default post author is an admin user whose url accessor points into the
        // admin panel: publishing it would disclose the admin directory and user IDs.
        $this->assertNull($this->invokeProvider('publicAuthorUrl', url($adminPrefix . '/system/users/profile/1')));
        $this->assertNull($this->invokeProvider('publicAuthorUrl', null));

        // A public author profile is still published.
        $this->assertSame(
            url('members/jane'),
            $this->invokeProvider('publicAuthorUrl', url('members/jane'))
        );
    }

    public function test_whitespace_is_collapsed(): void
    {
        $this->assertSame('one two three', $this->clean("one \n\t two    three"));
    }

    public function test_empty_and_null_values_return_null(): void
    {
        $this->assertNull($this->clean(null));
        $this->assertNull($this->clean(''));
        $this->assertNull($this->clean('   '));
        $this->assertNull($this->clean('<em></em>'));
    }
}
