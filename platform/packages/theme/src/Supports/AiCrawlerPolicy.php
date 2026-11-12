<?php

namespace Botble\Theme\Supports;

use Illuminate\Support\Facades\File;

/**
 * Builds the robots.txt crawler directives for the configured AI crawler policy.
 *
 * The policy only produces robots.txt directives, which crawlers honour voluntarily.
 * Actual enforcement has to happen at the web server / CDN / WAF layer; this class
 * exists so a site's stated policy matches whatever it enforces there.
 */
class AiCrawlerPolicy
{
    public const ALLOW_ALL = 'allow_all';

    public const BLOCK_TRAINING = 'block_training';

    public const BLOCK_ALL = 'block_all';

    /**
     * Markers delimiting the section of public/robots.txt this policy owns. Anything
     * outside them belongs to the site owner and is never touched.
     */
    public const BLOCK_START = '# BEGIN Botble AI crawler policy';

    public const BLOCK_END = '# END Botble AI crawler policy';

    /**
     * Crawlers that collect content to train or ground models. Blocking these keeps a
     * site out of training corpora without giving up AI-assistant citations.
     */
    public const TRAINING_CRAWLERS = [
        'GPTBot',
        'ClaudeBot',
        'anthropic-ai',
        'CCBot',
        'Amazonbot',
        'meta-externalagent',
        'FacebookBot',
        'Bytespider',
        'Applebot-Extended',
        'Google-Extended',
        'cohere-ai',
        'Diffbot',
        'omgili',
        'omgilibot',
        'Timpibot',
        'ImagesiftBot',
    ];

    /**
     * Crawlers that fetch pages to answer a user's question right now, and normally
     * link back. Blocking these removes citations and referral traffic too.
     */
    public const RETRIEVAL_CRAWLERS = [
        'OAI-SearchBot',
        'ChatGPT-User',
        'Claude-User',
        'Claude-SearchBot',
        'PerplexityBot',
        'Perplexity-User',
        'DuckAssistBot',
        'MistralAI-User',
        'YouBot',
    ];

    public static function policies(): array
    {
        return [
            self::ALLOW_ALL,
            self::BLOCK_TRAINING,
            self::BLOCK_ALL,
        ];
    }

    public static function sanitizePolicy(?string $policy): string
    {
        return in_array($policy, self::policies(), true) ? $policy : self::ALLOW_ALL;
    }

    /**
     * User agents disallowed under the given policy.
     */
    public static function blockedCrawlers(?string $policy): array
    {
        return match (self::sanitizePolicy($policy)) {
            self::BLOCK_TRAINING => self::TRAINING_CRAWLERS,
            self::BLOCK_ALL => array_merge(self::TRAINING_CRAWLERS, self::RETRIEVAL_CRAWLERS),
            default => [],
        };
    }

    /**
     * Render the full robots.txt body.
     *
     * Deliberately emits no Disallow rules for internal paths: listing admin or private
     * URLs in robots.txt advertises them to anyone who reads the file.
     */
    public static function toRobotsTxt(?string $policy, ?string $sitemapUrl = null): string
    {
        $lines = [];

        if ($directives = self::crawlerDirectives($policy)) {
            $lines[] = $directives;
        }

        // The catch-all group goes last so a crawler that matches a specific group above
        // uses that group instead (robots.txt matching picks the most specific group).
        $lines[] = 'User-agent: *';
        $lines[] = 'Disallow:';

        if ($sitemapUrl) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $sitemapUrl;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Just the per-crawler groups, without the catch-all group or Sitemap directive.
     */
    public static function crawlerDirectives(?string $policy): string
    {
        $lines = [];

        foreach (self::blockedCrawlers($policy) as $crawler) {
            $lines[] = 'User-agent: ' . $crawler;
            $lines[] = 'Disallow: /';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Merge the policy into an existing robots.txt, replacing only the managed block.
     *
     * Everything outside the markers is left exactly as the site owner wrote it - the file
     * is editable through Admin -> Theme -> Robots.txt and by hand, so this must never
     * rewrite the whole document. Removing the block entirely is how `allow_all` is
     * expressed.
     */
    public static function mergeIntoRobotsTxt(string $existing, ?string $policy): string
    {
        $content = rtrim((string) preg_replace(
            '/\R*' . preg_quote(self::BLOCK_START, '/') . '.*?' . preg_quote(self::BLOCK_END, '/') . '/s',
            '',
            $existing
        ));

        $directives = self::crawlerDirectives($policy);

        if ($directives === '') {
            return $content === '' ? '' : $content . "\n";
        }

        $block = self::BLOCK_START . "\n"
            . "# Generated from Admin -> Settings -> Sitemap -> AI crawler policy.\n"
            . "# Edits between these markers are overwritten; add your own rules outside them.\n"
            . $directives
            . self::BLOCK_END;

        return ($content === '' ? '' : $content . "\n\n") . $block . "\n";
    }

    /**
     * Write the current policy into public/robots.txt.
     *
     * The web server serves that file directly, before Laravel routing, so this is what
     * actually makes the setting take effect. When the file is absent the dynamic
     * `robots.txt` route serves the policy instead and there is nothing to do.
     *
     * Returns false only when the policy could not be applied (the file exists but is not
     * writable), so the caller can tell the admin instead of silently doing nothing.
     */
    public static function syncRobotsTxtFile(?string $policy = null): bool
    {
        $path = apply_filters(FILTER_ROBOTS_TXT_PATH, public_path('robots.txt'));

        if (! File::exists($path)) {
            // No static file: the dynamic route already serves the policy.
            return true;
        }

        if (! File::isWritable($path)) {
            return false;
        }

        $existing = (string) File::get($path);
        $updated = self::mergeIntoRobotsTxt($existing, $policy ?? setting('ai_crawler_policy'));

        if ($updated !== $existing) {
            File::put($path, $updated);
        }

        return true;
    }
}
