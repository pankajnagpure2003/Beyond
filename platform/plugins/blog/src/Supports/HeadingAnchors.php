<?php

namespace Botble\Blog\Supports;

use Illuminate\Support\Str;

/**
 * Adds `id` attributes to the headings inside a post body so sections can be linked and
 * cited directly (`.../my-post#comparison-table`).
 *
 * Deliberately conservative: an author-supplied `id` is never replaced, content inside
 * <pre>/<code>/<script>/<style> is left untouched, and running the transform twice
 * produces the same output.
 */
class HeadingAnchors
{
    /**
     * Headings that get an anchor. h1 is excluded: it is the post title, not a section.
     */
    protected const HEADING_TAGS = 'h2|h3';

    /**
     * Markdown-style explicit anchor, e.g. `## Comparison Table {#comparison-table}`.
     * Editors paste this in as literal text; it is used as the id and removed from view.
     */
    protected const EXPLICIT_ANCHOR_PATTERN = '/\{#([A-Za-z0-9_-]+)\}/';

    public static function inject(?string $content): ?string
    {
        if (! $content || ! preg_match('/<h[23][\s>]/i', $content)) {
            return $content;
        }

        [$content, $protected] = self::protectVerbatimBlocks($content);

        $used = [];

        // A regular closure with `use (&$used)`, not an arrow function: arrow functions
        // capture by value, so the slug registry would be a fresh copy on every heading and
        // duplicates would all get the same id.
        $content = (string) preg_replace_callback(
            '#<(' . self::HEADING_TAGS . ')((?:\s[^>]*)?)>(.*?)</\1\s*>#is',
            function (array $matches) use (&$used): string {
                return self::rewriteHeading($matches, $used);
            },
            $content
        );

        return strtr($content, $protected);
    }

    /**
     * Replace regions whose contents must never be rewritten with placeholder tokens.
     *
     * A code sample containing a literal `<h2>` would otherwise be treated as a heading.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    protected static function protectVerbatimBlocks(string $content): array
    {
        $protected = [];

        $content = (string) preg_replace_callback(
            '#<(pre|code|script|style)\b[^>]*>.*?</\1\s*>|<!--.*?-->#is',
            function (array $matches) use (&$protected): string {
                // The token is an HTML comment so it cannot break surrounding markup, and
                // is generated after comments are captured so it is never re-protected.
                $token = '<!--botble-heading-anchor-' . count($protected) . '-->';
                $protected[$token] = $matches[0];

                return $token;
            },
            $content
        );

        return [$content, $protected];
    }

    /**
     * @param  array<int, string>  $matches  [full, tag, attributes, inner html]
     * @param  array<string, true>  $used  slugs already assigned, by reference
     */
    protected static function rewriteHeading(array $matches, array &$used): string
    {
        [$original, $tag, $attributes, $inner] = $matches;

        $explicit = null;

        // Pull an explicit {#slug} out of the visible text whether or not we end up
        // assigning it, so the literal never renders to visitors.
        if (preg_match(self::EXPLICIT_ANCHOR_PATTERN, $inner, $found)) {
            $explicit = $found[1];
            $inner = trim((string) preg_replace(self::EXPLICIT_ANCHOR_PATTERN, '', $inner));
        }

        // Respect an id the author already set: only the stray {#slug} text is cleaned up.
        if (preg_match('/\sid\s*=/i', $attributes)) {
            return $explicit === null
                ? $original
                : sprintf('<%s%s>%s</%s>', $tag, $attributes, $inner, $tag);
        }

        $slug = $explicit ?? Str::slug(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (! $slug) {
            // Nothing linkable (an image-only or non-latin heading) - leave it alone.
            return $explicit === null
                ? $original
                : sprintf('<%s%s>%s</%s>', $tag, $attributes, $inner, $tag);
        }

        $slug = self::uniqueSlug($slug, $used);

        return sprintf('<%s%s id="%s">%s</%s>', $tag, $attributes, $slug, $inner, $tag);
    }

    /**
     * @param  array<string, true>  $used
     */
    protected static function uniqueSlug(string $slug, array &$used): string
    {
        $candidate = $slug;
        $suffix = 1;

        // Repeated headings must not produce duplicate ids in one document.
        while (isset($used[$candidate])) {
            $candidate = $slug . '-' . ++$suffix;
        }

        $used[$candidate] = true;

        return $candidate;
    }
}
