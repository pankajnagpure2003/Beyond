<?php

namespace Botble\Language\Tests\Feature;

use Botble\Base\Supports\BaseTestCase;
use Botble\Language\Facades\Language;
use Botble\Language\Models\Language as LanguageModel;
use Botble\Language\Models\LanguageMeta;
use Botble\Theme\Events\RenderingSiteMapEvent;
use Botble\Theme\Facades\SiteMapManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/**
 * The locale-less /sitemap.xml of a multilingual site must be a flat sitemap index.
 * Google rejects an index that lists other indexes ("Nested indexing"), so it must
 * point at each locale's sub-sitemaps, never at /{locale}/sitemap.xml.
 */
class MultilingualSitemapIndexTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_plugin_active('language')) {
            $this->markTestSkipped('Language plugin is not active.');
        }

        setting()->set('enable_cache_site_map', false);
        Cache::flush();

        LanguageModel::query()->delete();
        LanguageMeta::query()->delete();

        foreach ([['ar', 'ar', 'Arabic', true], ['en', 'en_US', 'English', false]] as $order => [$locale, $code, $name, $isDefault]) {
            LanguageModel::query()->create([
                'lang_name' => $name,
                'lang_locale' => $locale,
                'lang_code' => $code,
                'lang_is_default' => $isDefault,
                'lang_is_rtl' => $locale === 'ar',
                'lang_flag' => $locale === 'ar' ? 'sa' : 'us',
                'lang_order' => $order,
            ]);
        }

        Language::setSupportedLocales([
            'ar' => ['lang_name' => 'Arabic', 'lang_locale' => 'ar', 'lang_code' => 'ar', 'lang_is_default' => true, 'lang_is_rtl' => true, 'lang_flag' => 'sa'],
            'en' => ['lang_name' => 'English', 'lang_locale' => 'en', 'lang_code' => 'en_US', 'lang_is_default' => false, 'lang_is_rtl' => false, 'lang_flag' => 'us'],
        ]);

        Language::setDefaultLocale();
    }

    public function test_root_sitemap_lists_each_locale_sub_sitemap_instead_of_nested_indexes(): void
    {
        setting()->set('language_hide_default', false);

        $content = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<sitemapindex', $content);

        $this->assertStringContainsString($this->loc('ar/pages.xml'), $content);
        $this->assertStringContainsString($this->loc('en/pages.xml'), $content);

        $this->assertStringNotContainsString($this->loc('ar/sitemap.xml'), $content);
        $this->assertStringNotContainsString($this->loc('en/sitemap.xml'), $content);
        $this->assertStringNotContainsString($this->loc('pages.xml'), $content);
    }

    public function test_hidden_default_locale_uses_locale_less_sub_sitemaps(): void
    {
        setting()->set('language_hide_default', true);

        $content = $this->get('/sitemap.xml')->assertOk()->getContent();

        // "ar" is the default: /ar/... page URLs would 302 to their locale-less form.
        $this->assertStringContainsString($this->loc('pages.xml'), $content);
        $this->assertStringContainsString($this->loc('en/pages.xml'), $content);

        $this->assertStringNotContainsString($this->loc('ar/pages.xml'), $content);
        $this->assertStringNotContainsString($this->loc('en/sitemap.xml'), $content);
    }

    public function test_root_sitemap_is_served_from_cache_on_repeat_requests(): void
    {
        setting()->set('enable_cache_site_map', true);

        $first = $this->get('/sitemap.xml')->assertOk()->getContent();

        // Would show up in the index if the second request rebuilt it instead of reading the cache.
        Event::listen(RenderingSiteMapEvent::class, fn () => SiteMapManager::addSitemap(url('cache-probe.xml')));

        $cached = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertSame($first, $cached);
        $this->assertStringNotContainsString('cache-probe.xml', $cached);
        $this->assertStringContainsString($this->loc('en/pages.xml'), $cached);
    }

    protected function loc(string $path): string
    {
        return '<loc>' . url($path) . '</loc>';
    }
}
