<?php

namespace Botble\Media\Supports;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Resolves intrinsic image dimensions from local files and injects explicit
 * `width` and `height` attributes into rendered <img> tags. Matches the pattern
 * used by WordPress core since 5.5 — the browser's UA stylesheet derives
 * `aspect-ratio` from the attrs automatically, and the theme package ships a
 * global `img{max-width:100%;height:auto}` reset so attrs scale with CSS.
 *
 * Results are cached forever (keyed by URL path). Bust via `cache:clear` if a
 * source image is replaced with different dimensions.
 */
class ImageDimensionsInjector
{
    /**
     * In-request memo so repeated lookups for the same path don't hit the cache store repeatedly.
     *
     * @var array<string, array{int, int}|null>
     */
    protected static array $memo = [];

    /**
     * Inject width/height attributes into a raw <img> tag if missing.
     * The $attrs param is the raw string *inside* the tag (excluding `<img` and `>`).
     */
    public static function inject(string $tag, string $attrs): string
    {
        $attributes = static::attributes($attrs);

        // Skip if width/height already present.
        if (array_key_exists('width', $attributes) || array_key_exists('height', $attributes)) {
            return $tag;
        }

        // Prefer the real lazy-loaded image in `data-src` over the placeholder in
        // `src`. Lazy-loading rewrites `src` to a generic placeholder (e.g. a
        // 600x400 placeholder.png) and moves the real URL to `data-src`; reading
        // `src` would reserve the placeholder's aspect ratio and shift the layout
        // when the real image is swapped in.
        $imageUrl = static::firstNonEmpty($attributes, ['data-src', 'src']);

        if ($imageUrl === null) {
            return $tag;
        }

        $dims = static::resolveFromUrl($imageUrl);
        if (! $dims) {
            return $tag;
        }

        // The `data-dims-auto` marker lets the theme reset CSS target ONLY images
        // whose dimensions we injected, so static markup like
        //   <img src="icon.png" width="24" height="24">  (on a 256x256 source file)
        // is never touched by the reset and renders at its intentional 24x24 size.
        return sprintf('<img width="%d" height="%d" data-dims-auto%s>', $dims[0], $dims[1], $attrs);
    }

    /**
     * Parse the raw attribute string of an <img> tag into a lowercased name => value map.
     *
     * Values are read quote-insensitively. The optimize package's RemoveQuotes middleware
     * (Settings -> Optimize page speed) rewrites `src="/storage/a.png"` to
     * `src=/storage/a.png`, and it runs inside the middleware pipeline - so by the time
     * the catch-all injector post-processes the response on RequestHandled, those values
     * are already bare. It unquotes a fixed list rather than the whole tag, but that list
     * is `src`, `width`, `height`, `name`, `charset`, `align`, `border`, `crossorigin`
     * and `type` - which covers every attribute this class reads. Matching only the
     * quoted form made this whole layer silently do nothing on such sites: raw <img> tags
     * in blade templates kept shipping without width/height and kept costing CLS, while
     * images rendered through RvMedia::image() (injected earlier, during view compilation)
     * still had theirs - which made the failure look like a template bug.
     *
     * The scan is sequential rather than a per-attribute search precisely BECAUSE values
     * are unquoted here: a bare `/\ssrc\s*=/` search also fires on the ` src=` sitting
     * inside `alt="a src=b"` and returns "b". Matching name/value as one unit consumes a
     * quoted value whole, so text inside it can never be mistaken for an attribute.
     */
    protected static function attributes(string $attrs): array
    {
        preg_match_all(
            '/([^\s=\/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]*)))?/',
            $attrs,
            $matches,
            PREG_SET_ORDER
        );

        $attributes = [];

        foreach ($matches as $match) {
            $name = strtolower($match[1]);

            // First occurrence wins, which is how browsers resolve a duplicated attribute.
            if ($name === '' || array_key_exists($name, $attributes)) {
                continue;
            }

            $value = '';

            // Exactly one of the three alternatives can be non-empty; unmatched trailing
            // groups are absent from the set rather than empty, hence the `??`.
            foreach ([2, 3, 4] as $group) {
                if (($match[$group] ?? '') !== '') {
                    $value = $match[$group];

                    break;
                }
            }

            $attributes[$name] = $value;
        }

        return $attributes;
    }

    /**
     * First of $names present with a non-empty value, or null.
     */
    protected static function firstNonEmpty(array $attributes, array $names): ?string
    {
        foreach ($names as $name) {
            if (($attributes[$name] ?? '') !== '') {
                return $attributes[$name];
            }
        }

        return null;
    }

    /**
     * Resolve [width, height] for a URL pointing to a local storage or public theme/plugin asset.
     * Returns null for remote URLs or unreadable files.
     *
     * @return array{int, int}|null
     */
    public static function resolveFromUrl(?string $url): ?array
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        if (! str_contains($path, '/storage/') && ! str_contains($path, '/themes/') && ! str_contains($path, '/vendor/')) {
            return null;
        }

        if (array_key_exists($path, static::$memo)) {
            return static::$memo[$path];
        }

        $cacheKey = 'bb_img_dims:' . md5($path);

        $dims = Cache::rememberForever($cacheKey, fn () => static::readDimensionsFromPath($path));

        return static::$memo[$path] = $dims;
    }

    /**
     * Reset in-request memo (used by tests).
     */
    public static function flushMemo(): void
    {
        static::$memo = [];
    }

    /**
     * @return array{int, int}|null
     */
    protected static function readDimensionsFromPath(string $path): ?array
    {
        try {
            $full = static::resolveFilesystemPath($path);

            if (! $full || ! is_file($full)) {
                return null;
            }

            if (str_ends_with(strtolower($full), '.svg')) {
                return static::readSvgDimensions($full);
            }

            $info = @getimagesize($full);
            if ($info && $info[0] > 0 && $info[1] > 0) {
                return [(int) $info[0], (int) $info[1]];
            }
        } catch (Throwable) {
            // ignore and fall through
        }

        return null;
    }

    protected static function resolveFilesystemPath(string $path): ?string
    {
        if (str_contains($path, '/storage/')) {
            $relative = ltrim(substr($path, strpos($path, '/storage/') + strlen('/storage/')), '/');
            if (Storage::disk('public')->exists($relative)) {
                return Storage::disk('public')->path($relative);
            }

            return null;
        }

        $candidate = public_path(ltrim($path, '/'));

        return is_file($candidate) ? $candidate : null;
    }

    /**
     * @return array{int, int}|null
     */
    protected static function readSvgDimensions(string $fullPath): ?array
    {
        $head = @file_get_contents($fullPath, false, null, 0, 2048);
        if (! is_string($head) || ! preg_match('/<svg\b[^>]*>/i', $head, $svgTag)) {
            return null;
        }

        $svgAttrs = $svgTag[0];

        if (
            preg_match('/\swidth\s*=\s*"([0-9.]+)"/i', $svgAttrs, $w)
            && preg_match('/\sheight\s*=\s*"([0-9.]+)"/i', $svgAttrs, $h)
        ) {
            return [(int) round((float) $w[1]), (int) round((float) $h[1])];
        }

        if (preg_match('/\sviewBox\s*=\s*"[\d.\s-]*?\s([\d.]+)\s+([\d.]+)\s*"/i', $svgAttrs, $vb)) {
            return [(int) round((float) $vb[1]), (int) round((float) $vb[2])];
        }

        return null;
    }
}
