<?php

namespace Botble\Theme\Http\Controllers;

use Botble\Base\Facades\BaseHelper;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\Blog\Models\Post;
use Botble\Language\Facades\Language;
use Botble\Page\Models\Page;
use Botble\Page\Services\PageService;
use Botble\SeoHelper\Facades\SeoHelper;
use Botble\Slug\Facades\SlugHelper;
use Botble\Slug\Models\Slug;
use Botble\Theme\Events\RenderingHomePageEvent;
use Botble\Theme\Events\RenderingSingleEvent;
use Botble\Theme\Events\RenderingSiteMapEvent;
use Botble\Theme\Facades\SiteMapManager;
use Botble\Theme\Facades\Theme;
use Botble\Theme\Supports\AiCrawlerPolicy;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

class PublicController extends BaseController
{
    public function getIndex()
    {
        Theme::addBodyAttributes(['id' => 'page-home']);

        if (defined('PAGE_MODULE_SCREEN_NAME') && BaseHelper::getHomepageId()) {
            $data = (new PageService())->handleFrontRoutes(null);

            event(new RenderingSingleEvent(new Slug()));

            if ($data) {
                return Theme::scope($data['view'], $data['data'], $data['default_view'])->render();
            }
        }

        SeoHelper::setTitle(Theme::getSiteTitle());

        event(RenderingHomePageEvent::class);

        return Theme::scope('index')->render();
    }

    public function getView(?string $key = null, string $prefix = '')
    {
        if (empty($key)) {
            return $this->getIndex();
        }

        $slug = SlugHelper::getSlug($key, $prefix);

        abort_unless($slug, 404);

        if (
            defined('PAGE_MODULE_SCREEN_NAME') &&
            $slug->reference_type === Page::class &&
            BaseHelper::isHomepage($slug->reference_id)
        ) {
            return redirect()->to(BaseHelper::getHomepageUrl());
        }

        $result = apply_filters(BASE_FILTER_PUBLIC_SINGLE_DATA, $slug);

        $extension = SlugHelper::getPublicSingleEndingURL();

        if ($extension) {
            $key = Str::replaceLast($extension, '', $key);
        }

        if ($result instanceof BaseHttpResponse) {
            return $result;
        }

        if (isset($result['slug']) && $result['slug'] !== $key) {
            $prefix = SlugHelper::getPrefix(Arr::first($result['data'])::class);

            return redirect()->route('public.single', empty($prefix) ? $result['slug'] : "$prefix/{$result['slug']}");
        }

        event(new RenderingSingleEvent($slug));

        if (! empty($result) && is_array($result)) {
            if (isset($result['view'])) {
                Theme::addBodyAttributes(['id' => Str::slug(Str::snake(Str::afterLast($slug->reference_type, '\\'))) . '-' . $slug->reference_id]);

                return Theme::scope($result['view'], $result['data'], Arr::get($result, 'default_view'))->render();
            }

            return $result;
        }

        abort(404);
    }

    public function getSiteMap()
    {
        // When the Language plugin is active and the request hits the locale-less
        // /sitemap.xml URL, return one sitemap index covering every active locale
        // so search engines can discover all per-locale sitemaps from a single URL.
        if (
            is_plugin_active('language')
            && ! Language::checkLocaleInSupportedLocales(request()->segment(1))
        ) {
            // Use the supported-locales array keys (lang_locale) — these are what
            // the language plugin uses as the route prefix, not lang_code. For
            // English the lang_code is "en_US" while lang_locale is "en", so
            // /{lang_code}/sitemap.xml would 404.
            $supportedLocales = Language::getSupportedLocales();

            if (count($supportedLocales) > 1) {
                // A sitemap index must not list other sitemap indexes: Google flags
                // /{locale}/sitemap.xml entries as "Nested indexing" and reads 0 pages
                // from them. List every locale's sub-sitemaps (/{locale}/pages.xml…)
                // directly instead. Sitemap listeners do not scope their queries by
                // locale, so the locale-less index holds the same keys for each locale.
                // A hidden default locale keeps the locale-less URL: /{default}/... would
                // list page URLs that 302 to their locale-less form.
                $hiddenDefaultLocale = Language::hideDefaultLocaleInURL() ? Language::getDefaultLocale() : null;

                $urlPrefixes = array_map(
                    fn (string $localeCode) => $localeCode === $hiddenDefaultLocale ? '' : $localeCode . '/',
                    array_keys($supportedLocales)
                );

                // Key the cache on the locale set so adding/removing a language or
                // toggling the hidden default never serves a stale index.
                $siteMap = SiteMapManager::init(null, 'xml', '-locales:' . implode(',', $urlPrefixes))->getSiteMap();

                if (! $siteMap->isCached()) {
                    event(new RenderingSiteMapEvent());

                    $siteMap->resetSitemaps($this->localizeSitemapEntries($siteMap->getSitemaps(), $urlPrefixes));
                }

                return SiteMapManager::render('sitemapindex');
            }
        }

        return $this->getSiteMapIndex();
    }

    /**
     * Repeat each locale-less sub-sitemap entry (/pages.xml) once per URL prefix
     * (/{prefix}pages.xml). Entries outside this site's base URL are kept as-is.
     */
    protected function localizeSitemapEntries(array $sitemaps, array $urlPrefixes): array
    {
        $baseUrl = rtrim(url('/'), '/') . '/';

        $localized = [];

        foreach ($sitemaps as $sitemap) {
            if (! str_starts_with($sitemap['loc'], $baseUrl)) {
                $localized[] = $sitemap;
            }
        }

        foreach ($urlPrefixes as $urlPrefix) {
            foreach ($sitemaps as $sitemap) {
                if (str_starts_with($sitemap['loc'], $baseUrl)) {
                    $localized[] = [
                        ...$sitemap,
                        'loc' => url($urlPrefix . Str::after($sitemap['loc'], $baseUrl)),
                    ];
                }
            }
        }

        return $localized;
    }

    public function getSiteMapIndex(?string $key = null, string $extension = 'xml')
    {
        if ($key == 'sitemap') {
            $key = null;
        }

        if ($key && SiteMapManager::isKeyExcluded($key)) {
            abort(404);
        }

        if (! SiteMapManager::init($key, $extension)->isCached()) {
            event(new RenderingSiteMapEvent($key));
        }

        // show your site map (options: 'xml' (default), 'xml-mobile', 'html', 'txt', 'ror-rss', 'ror-rdf', 'google-news')
        return SiteMapManager::render($key ? $extension : 'sitemapindex');
    }

    public function getViewWithPrefix(string $prefix, ?string $slug = null)
    {
        return $this->getView($slug, $prefix);
    }

    /**
     * Serve robots.txt with the configured AI crawler policy.
     *
     * Served only when a static public/robots.txt file does not exist - the web server
     * serves that file first, so this route acts as the dynamic fallback (same pattern as
     * llms.txt). Sites that ship the default static robots.txt must remove it for this to
     * take effect; the setting's helper text says so.
     */
    public function getRobotsTxt()
    {
        $content = AiCrawlerPolicy::toRobotsTxt(setting('ai_crawler_policy'), $this->sitemapUrl());

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Generate a default llms.txt following the https://llmstxt.org specification.
     * Served only when a static public/llms.txt file does not exist (the web server
     * serves the static file first, so this route acts as the dynamic fallback).
     */
    public function getLlmsTxt()
    {
        if (! setting('llms_txt_enabled', true)) {
            abort(404);
        }

        $pageLimit = $this->llmsLimit('llms_txt_page_limit', 100);
        $postLimit = $this->llmsLimit('llms_txt_post_limit', 50);

        // Public, unauthenticated and crawler-facing: cache for the same hour the
        // Cache-Control header already promises. Content changes are not tracked, so a
        // freshly published item can take up to the TTL to appear.
        $content = Cache::remember(
            $this->llmsCacheKey('llms_txt', $pageLimit, $postLimit),
            3600,
            function () use ($pageLimit, $postLimit): string {
                $lines = [];

                // Spec requires the document to begin with a single H1 (the site name).
                $siteTitle = Theme::getSiteTitle() ?: setting('admin_title', config('app.name'));
                $lines[] = '# ' . $this->cleanLlmsText($siteTitle, 150);

                // Short site summary as a blockquote, right after the H1.
                $description = theme_option('seo_description') ?: setting('admin_description');
                if ($description) {
                    $lines[] = '';
                    $lines[] = '> ' . $this->cleanLlmsText($description, 300);
                }

                // Content sections. Each model is optional - skipped when its plugin is
                // absent. Plugins register their own models through the filter.
                foreach ($this->llmsSections($pageLimit, $postLimit) as $section) {
                    $lines = array_merge($lines, $this->buildLlmsModelSection(
                        (string) ($section['model'] ?? ''),
                        (string) ($section['heading'] ?? ''),
                        $this->llmsSectionLimit($section)
                    ));
                }

                // Reference the XML sitemap for full coverage.
                if ($sitemapUrl = $this->sitemapUrl()) {
                    $lines[] = '';
                    $lines[] = '## Optional';
                    $lines[] = '- [XML Sitemap](' . $sitemapUrl . ')';
                }

                return implode("\n", $lines) . "\n";
            }
        );

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Publish the full text of the site's content in one file, for LLM ingestion.
     *
     * Opt-in (`llms_full_txt_enabled`) because it republishes complete article bodies.
     * Returns 404 rather than an empty 200 when disabled or when there is nothing to
     * publish - an empty 200 reads to a crawler as "this site has no content".
     */
    public function getLlmsFullTxt()
    {
        if (! setting('llms_txt_enabled', true) || ! setting('llms_full_txt_enabled', false)) {
            abort(404);
        }

        $pageLimit = $this->llmsLimit('llms_txt_page_limit', 100);
        $postLimit = $this->llmsLimit('llms_txt_post_limit', 50);

        $key = $this->llmsCacheKey('llms_full_txt', $pageLimit, $postLimit);

        $content = Cache::get($key) ?? $this->generateLlmsFullTxt($key, $pageLimit, $postLimit);

        if (! $content) {
            abort(404);
        }

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Generate and cache llms-full.txt, serialising concurrent crawler hits.
     *
     * Generating walks every published item and its full body, so only one request should
     * do it. The lock is best-effort: a cache store without lock support, or a build that
     * outruns the wait, must not turn this endpoint into a 500.
     */
    protected function generateLlmsFullTxt(string $key, int $pageLimit, int $postLimit): string
    {
        $build = function () use ($key, $pageLimit, $postLimit): string {
            $generated = $this->buildLlmsFullTxt($pageLimit, $postLimit);

            // Never cache an empty result, or a site crawled before anything is published
            // would be pinned to 404 for the whole TTL.
            if ($generated !== '') {
                Cache::put($key, $generated, 3600);
            }

            return $generated;
        };

        try {
            $lock = Cache::lock($key . '.lock', 60);
        } catch (Throwable) {
            // Store does not implement locking - build without serialising.
            return $build();
        }

        try {
            return (string) $lock->block(10, fn (): string => Cache::get($key) ?? $build());
        } catch (LockTimeoutException) {
            // Someone else is still building it. Serve their result if it landed meanwhile,
            // otherwise tell the crawler to come back rather than duplicating the work.
            if ($content = Cache::get($key)) {
                return (string) $content;
            }

            abort(503, 'llms-full.txt is being generated, please retry shortly.');
        }
    }

    /**
     * Build the llms-full.txt body, or '' when there is nothing publishable.
     */
    protected function buildLlmsFullTxt(int $pageLimit, int $postLimit): string
    {
        $siteTitle = Theme::getSiteTitle() ?: setting('admin_title', config('app.name'));
        $lines = ['# ' . $this->cleanLlmsText($siteTitle, 150)];

        // Hard budget so a large site cannot generate an unbounded response.
        $budget = 5 * 1024 * 1024;
        $used = 0;
        $truncated = false;
        $published = 0;

        foreach ($this->llmsSections($pageLimit, $postLimit) as $section) {
            if ($truncated) {
                break;
            }

            $modelClass = (string) ($section['model'] ?? '');

            if (! class_exists($modelClass)) {
                continue;
            }

            $sectionLines = [];

            try {
                // Cursor so full article bodies are never all held in memory.
                $items = $modelClass::query()
                    ->wherePublished()
                    ->latest()
                    ->with('slugable')
                    ->limit($this->llmsSectionLimit($section))
                    ->cursor();

                foreach ($items as $item) {
                    $url = $item->url;
                    $name = $item->name;

                    if (! $url || ! $name) {
                        continue;
                    }

                    // Check the budget against the raw length before normalising the body,
                    // so an oversized row is never fully materialised just to be rejected.
                    $used += strlen((string) $item->content) + strlen((string) $url) + strlen((string) $name);

                    if ($used > $budget) {
                        $truncated = true;

                        break;
                    }

                    $body = $this->cleanLlmsText($this->stripShortcodes((string) $item->content), PHP_INT_MAX);

                    if (! $body) {
                        continue;
                    }

                    $sectionLines[] = "\n### " . $this->cleanLlmsText($name, 150)
                        . "\n" . $url . "\n\n" . $body;
                }
            } catch (Throwable $exception) {
                // A filter-registered model may not support this query shape. Skip the
                // section, but report it - a silently vanishing section is unexplainable.
                report($exception);

                continue;
            }

            if ($sectionLines) {
                $published += count($sectionLines);
                $lines[] = '';
                $lines[] = '## ' . $this->cleanLlmsText((string) ($section['heading'] ?? ''), 150);
                $lines = array_merge($lines, $sectionLines);
            }
        }

        // No entries at all means nothing is publishable. Return '' (-> 404) even when the
        // budget tripped, so the response is never just a heading plus a truncation note.
        if ($published === 0) {
            return '';
        }

        if ($truncated) {
            $lines[] = '';
            $lines[] = '> ' . __('Output truncated. See the XML sitemap for the full list of URLs.');
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Content sections for llms.txt, filterable so plugins can add their own models.
     */
    protected function llmsSections(int $pageLimit, int $postLimit): array
    {
        $sections = [
            ['model' => Page::class, 'heading' => __('Pages'), 'limit' => $pageLimit],
            ['model' => Post::class, 'heading' => __('Blog'), 'limit' => $postLimit],
        ];

        $sections = apply_filters(FILTER_LLMS_TXT_SECTIONS, $sections);

        return is_array($sections) ? array_filter($sections, 'is_array') : [];
    }

    protected function llmsLimit(string $key, int $default): int
    {
        $limit = (int) setting($key, $default);

        return $limit > 0 ? min($limit, 1000) : $default;
    }

    /**
     * A filter-supplied section limit is clamped like the settings-driven ones, so a
     * plugin cannot make this public endpoint dump an unbounded number of rows.
     */
    protected function llmsSectionLimit(array $section): int
    {
        $limit = (int) ($section['limit'] ?? 50);

        return $limit > 0 ? min($limit, 1000) : 50;
    }

    /**
     * Cache key covering every input that changes the generated output, so editing a
     * setting is reflected immediately instead of waiting out the TTL.
     */
    protected function llmsCacheKey(string $prefix, int $pageLimit, int $postLimit): string
    {
        $signature = [
            app()->getLocale(),
            $pageLimit,
            $postLimit,
            Theme::getSiteTitle(),
            setting('admin_title'),
            setting('admin_description'),
            theme_option('seo_description'),
            $this->sitemapUrl(),
            // Sections registered by plugins through FILTER_LLMS_TXT_SECTIONS.
            array_map(
                fn ($section) => [$section['model'] ?? '', $section['heading'] ?? '', $this->llmsSectionLimit($section)],
                $this->llmsSections($pageLimit, $postLimit)
            ),
        ];

        return $prefix . '.' . md5((string) json_encode($signature));
    }

    /**
     * The sitemap URL, or null when the sitemap is disabled.
     *
     * `Route::has()` matters as well as the setting: with a cached route table the sitemap
     * routes are frozen at cache time, so enabling the setting afterwards would otherwise
     * make route() throw and turn robots.txt/llms.txt into a 500.
     */
    protected function sitemapUrl(): ?string
    {
        if (! setting('sitemap_enabled', true) || ! Route::has('public.sitemap')) {
            return null;
        }

        return route('public.sitemap');
    }

    /**
     * Build a markdown "## Heading" section listing a slugable model's published
     * items as links. Returns [] (no leading blank line) when the model's plugin is
     * not installed or there are no valid items.
     */
    protected function buildLlmsModelSection(string $modelClass, string $heading, int $limit): array
    {
        if (! $modelClass || ! $heading || ! class_exists($modelClass)) {
            return [];
        }

        try {
            // Fetch one more than the limit: getting it back means there is more content
            // than this section lists, without paying for a second count() query.
            $items = $modelClass::query()
                ->wherePublished()
                ->latest()
                ->select(['id', 'name', 'description'])
                ->with('slugable')
                ->limit($limit + 1)
                ->get();
        } catch (Throwable $exception) {
            // A filter-registered model may not have `description`, `wherePublished()` or a
            // usable `url`. Skip it, but report so the missing section is explainable.
            report($exception);

            return [];
        }

        $truncated = $items->count() > $limit;
        $items = $items->take($limit);

        $bullets = [];

        foreach ($items as $item) {
            // Read into variables first: `url` is a dynamic magic accessor, so
            // empty($item->url) would short-circuit to true via __isset().
            $url = $item->url;
            $name = $item->name;

            if (! $url || ! $name) {
                continue;
            }

            // Strip brackets from the label so they cannot break the [title](url) syntax.
            $title = str_replace(['[', ']'], '', $this->cleanLlmsText($name, 150));
            $line = sprintf('- [%s](%s)', $title, $url);

            $description = $item->description;
            if ($description) {
                $line .= ': ' . $this->cleanLlmsText($description, 150);
            }

            $bullets[] = $line;
        }

        if (empty($bullets)) {
            return [];
        }

        // Never truncate silently - an unmarked cut-off list reads as the complete set.
        if ($truncated && ($sitemapUrl = $this->sitemapUrl())) {
            $bullets[] = sprintf(
                '- %s: [XML Sitemap](%s)',
                __('More items are available in the sitemap'),
                $sitemapUrl
            );
        }

        return array_merge(['', '## ' . $heading], $bullets);
    }

    /**
     * Remove page-builder shortcode tags, keeping any prose between them.
     *
     * Pages built with the block editor store shortcode markup in `content`, e.g.
     * `[featured-posts enable_lazy_loading="yes"][/featured-posts]`. Publishing that
     * verbatim fills llms-full.txt with builder syntax instead of readable text. Matched
     * case-sensitively on lowercase names so bracketed prose like "[Guide]" survives.
     */
    protected function stripShortcodes(string $content): string
    {
        return (string) preg_replace('/\[\/?[a-z][a-z0-9_-]*(?:\s[^\]]*)?\]/', ' ', $content);
    }

    /**
     * Normalize text for plain-text/markdown output: strip HTML, collapse
     * whitespace, and truncate to a readable length.
     */
    protected function cleanLlmsText(string $text, int $limit): string
    {
        // Decode HTML entities first so stored values like "&amp;" render as a clean
        // "&" instead of printing the literal entity, and so entity-encoded tags are
        // resolved before strip_tags() removes them.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Drop script/style/comment bodies before strip_tags(), which removes the tags but
        // keeps their inner text - otherwise an embedded tracking snippet or widget config
        // would be republished as prose in a file built for ingestion.
        $text = (string) preg_replace(
            ['#<(script|style)\b[^>]*>.*?</\1>#is', '#<!--.*?-->#s'],
            ' ',
            $text
        );

        $text = strip_tags($text);
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if (mb_strlen($text) > $limit) {
            $text = rtrim(mb_substr($text, 0, $limit - 3)) . '...';
        }

        return $text;
    }
}
