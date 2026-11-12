<?php

namespace Botble\Blog\Tests\Feature;

use Tests\TestCase;

/**
 * Covers heading anchor injection for post bodies.
 *
 * The transform itself is pure, but plugin namespaces are registered at runtime by the
 * plugin loader rather than in composer's autoload map - so the app has to boot for
 * `Botble\Blog\*` to resolve at all. It therefore skips (rather than erroring) in CI's
 * plugins-deactivated run.
 */
class HeadingAnchorsTest extends TestCase
{
    private function inject(?string $content): ?string
    {
        $class = 'Botble\\Blog\\Supports\\HeadingAnchors';

        if (! class_exists($class)) {
            $this->markTestSkipped('Blog plugin is not available.');
        }

        return $class::inject($content);
    }

    public function test_adds_slug_ids_to_h2_and_h3(): void
    {
        $result = $this->inject('<h2>Comparison Table</h2><p>x</p><h3>Pricing &amp; Plans</h3>');

        $this->assertStringContainsString('<h2 id="comparison-table">Comparison Table</h2>', $result);
        $this->assertStringContainsString('id="pricing-plans"', $result);
    }

    public function test_leaves_h1_and_h4_alone(): void
    {
        // h1 is the post title, not a section; h4+ is too granular to be worth linking.
        $content = '<h1>Title</h1><h4>Minor</h4>';

        $this->assertSame($content, $this->inject($content));
    }

    public function test_never_overwrites_an_author_supplied_id(): void
    {
        $content = '<h2 id="my-own-anchor">Section</h2>';

        $this->assertSame($content, $this->inject($content));
    }

    public function test_preserves_other_attributes(): void
    {
        $result = $this->inject('<h2 class="fancy" data-x="1">Section</h2>');

        $this->assertStringContainsString('class="fancy"', $result);
        $this->assertStringContainsString('data-x="1"', $result);
        $this->assertStringContainsString('id="section"', $result);
    }

    /**
     * Regression for the literal `{#comparison-table}` seen rendering on botble.com: the
     * markdown anchor syntax is used as the id and removed from the visible text.
     */
    public function test_explicit_markdown_anchor_is_used_and_hidden(): void
    {
        $result = $this->inject('<h2>Comparison Table {#comparison-table}</h2>');

        $this->assertSame('<h2 id="comparison-table">Comparison Table</h2>', $result);
        $this->assertStringNotContainsString('{#', $result);
    }

    public function test_explicit_anchor_is_cleaned_even_when_an_id_exists(): void
    {
        $result = $this->inject('<h2 id="kept">Title {#ignored}</h2>');

        $this->assertStringNotContainsString('{#ignored}', $result);
        $this->assertStringContainsString('id="kept"', $result);
    }

    public function test_duplicate_headings_get_unique_ids(): void
    {
        $result = $this->inject('<h2>Setup</h2><h2>Setup</h2><h3>Setup</h3>');

        $this->assertStringContainsString('id="setup"', $result);
        $this->assertStringContainsString('id="setup-2"', $result);
        $this->assertStringContainsString('id="setup-3"', $result);
        $this->assertSame(1, substr_count($result, 'id="setup"'));
    }

    public function test_is_idempotent(): void
    {
        $once = $this->inject('<h2>Setup</h2><h2>Setup</h2>');

        $this->assertSame($once, $this->inject($once), 'Re-running must not change the output or renumber ids.');
    }

    /**
     * A code sample containing a literal heading must not be rewritten - that would corrupt
     * the sample shown to readers.
     */
    public function test_headings_inside_code_blocks_are_untouched(): void
    {
        $content = '<pre><code>&lt;h2&gt;Example&lt;/h2&gt;</code></pre><h2>Real</h2>';

        $result = $this->inject($content);

        $this->assertStringContainsString('<pre><code>&lt;h2&gt;Example&lt;/h2&gt;</code></pre>', $result);
        $this->assertStringContainsString('<h2 id="real">Real</h2>', $result);
    }

    public function test_raw_heading_markup_inside_pre_is_untouched(): void
    {
        $content = '<pre><h2>Not a section</h2></pre><h2>Real</h2>';

        $result = $this->inject($content);

        $this->assertStringContainsString('<pre><h2>Not a section</h2></pre>', $result);
        $this->assertStringContainsString('id="real"', $result);
        $this->assertStringNotContainsString('id="not-a-section"', $result);
    }

    public function test_script_and_style_blocks_are_untouched(): void
    {
        $content = '<script>var a = "<h2>x</h2>";</script><style>h2{color:red}</style><h2>Real</h2>';

        $result = $this->inject($content);

        $this->assertStringContainsString('var a = "<h2>x</h2>";', $result);
        $this->assertStringContainsString('h2{color:red}', $result);
        $this->assertStringContainsString('id="real"', $result);
    }

    public function test_html_comments_survive_intact(): void
    {
        $content = '<!-- a note with <h2>markup</h2> --><h2>Real</h2>';

        $result = $this->inject($content);

        $this->assertStringContainsString('<!-- a note with <h2>markup</h2> -->', $result);
        $this->assertStringContainsString('id="real"', $result);
    }

    public function test_placeholder_token_cannot_be_injected_by_content(): void
    {
        // Content that mimics the internal placeholder must not corrupt the output.
        $content = '<p>botble-heading-anchor-0</p><!--botble-heading-anchor-0--><h2>Real</h2>';

        $result = $this->inject($content);

        $this->assertStringContainsString('<p>botble-heading-anchor-0</p>', $result);
        $this->assertStringContainsString('id="real"', $result);
    }

    public function test_heading_with_nested_markup_uses_its_text(): void
    {
        $result = $this->inject('<h2><strong>Bold</strong> Heading</h2>');

        $this->assertStringContainsString('id="bold-heading"', $result);
        $this->assertStringContainsString('<strong>Bold</strong> Heading', $result);
    }

    public function test_unsluggable_heading_is_left_unchanged(): void
    {
        // An image-only heading produces no slug; it must not gain id="".
        $content = '<h2><img src="/x.png" alt=""></h2>';

        $result = $this->inject($content);

        $this->assertStringNotContainsString('id=""', $result);
        $this->assertSame($content, $result);
    }

    public function test_content_without_headings_is_returned_unchanged(): void
    {
        $content = '<p>Just a paragraph with h2 mentioned in text.</p>';

        $this->assertSame($content, $this->inject($content));
    }

    public function test_null_and_empty_content(): void
    {
        $this->assertNull($this->inject(null));
        $this->assertSame('', $this->inject(''));
    }

    public function test_generated_ids_are_attribute_safe(): void
    {
        $result = $this->inject('<h2>Quote " and <em>angle</em> > chars</h2>');

        // The id must never contain characters that could break out of the attribute.
        $this->assertMatchesRegularExpression('/<h2 id="[a-z0-9-]+"/', $result);
    }
}
