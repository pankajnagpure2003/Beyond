<?php

namespace Tests\Feature\SeoHelper;

use Botble\SeoHelper\Entities\PropertyMetaCollection;
use Botble\SeoHelper\SeoMeta;
use Tests\TestCase;

/**
 * Covers `property=` meta tag support (article:*, profile:*, ...).
 *
 * These cannot go through SeoOpenGraph: its collection forces an `og:` prefix on every
 * property it renders, so `article:published_time` would come out as
 * `og:article:published_time`.
 *
 * seo-helper is a core package (always loaded), so a booted-app test is safe in both CI
 * modes - unlike a plugin-coupled test.
 */
class PropertyMetaTest extends TestCase
{
    public function test_property_collection_renders_property_attribute_without_prefix(): void
    {
        $collection = new PropertyMetaCollection();
        $collection->add(['name' => 'article:published_time', 'content' => '2026-07-30T08:00:00+07:00']);

        $output = $collection->render();

        $this->assertStringContainsString('property="article:published_time"', $output);
        $this->assertStringNotContainsString('og:article:published_time', $output);
        $this->assertStringNotContainsString('name="article:published_time"', $output);
    }

    public function test_seo_meta_renders_added_property_tags(): void
    {
        $meta = new SeoMeta();
        $meta->addPropertyMeta('article:published_time', '2026-07-30T08:00:00+07:00');
        $meta->addPropertyMeta('article:section', 'Ecommerce');

        $output = $meta->render();

        $this->assertStringContainsString('property="article:published_time"', $output);
        $this->assertStringContainsString('content="2026-07-30T08:00:00+07:00"', $output);
        $this->assertStringContainsString('property="article:section"', $output);
        $this->assertStringContainsString('Ecommerce', $output);
    }

    public function test_add_property_metas_accepts_a_keyed_array(): void
    {
        $meta = new SeoMeta();
        $meta->addPropertyMetas([
            'article:published_time' => '2026-07-30T08:00:00+07:00',
            'article:modified_time' => '2026-07-30T09:00:00+07:00',
        ]);

        $output = $meta->render();

        $this->assertStringContainsString('property="article:published_time"', $output);
        $this->assertStringContainsString('property="article:modified_time"', $output);
    }

    public function test_empty_values_are_ignored(): void
    {
        $meta = new SeoMeta();
        $meta->addPropertyMetas([
            'article:author' => null,
            'article:section' => '',
            'article:tag' => 'laravel',
        ]);

        $output = $meta->render();

        $this->assertStringNotContainsString('article:author', $output);
        $this->assertStringNotContainsString('article:section', $output);
        $this->assertStringContainsString('property="article:tag"', $output);
    }

    /**
     * Documents why the blog plugin joins tags into one value: the collection is keyed by
     * meta name, so a repeated name replaces the previous entry rather than adding a
     * second tag.
     */
    public function test_repeating_a_property_name_replaces_the_previous_value(): void
    {
        $meta = new SeoMeta();
        $meta->addPropertyMeta('article:tag', 'laravel');
        $meta->addPropertyMeta('article:tag', 'botble');

        $output = $meta->render();

        $this->assertSame(1, substr_count($output, 'property="article:tag"'));
        $this->assertStringContainsString('botble', $output);
        $this->assertStringNotContainsString('laravel', $output);
    }

    public function test_render_is_unaffected_when_no_property_tags_are_added(): void
    {
        $meta = new SeoMeta();
        $meta->setTitle('Example');

        // An empty property collection must not introduce blank lines or stray markup.
        $this->assertStringNotContainsString('property=', $meta->render());
    }
}
