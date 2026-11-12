<?php

namespace Tests\Feature\Theme;

use Botble\Theme\Supports\AiCrawlerPolicy;
use Tests\TestCase;

/**
 * Covers the AI crawler policy that backs the dynamic robots.txt.
 *
 * The assertions target AiCrawlerPolicy directly rather than an HTTP request, because in
 * production the web server serves a static public/robots.txt before Laravel routing ever
 * runs. The policy builder is the part that must be correct regardless of who serves it.
 */
class RobotsTxtTest extends TestCase
{
    public function test_allow_all_emits_no_crawler_blocks(): void
    {
        $output = AiCrawlerPolicy::toRobotsTxt(AiCrawlerPolicy::ALLOW_ALL);

        $this->assertStringContainsString("User-agent: *\nDisallow:", $output);
        $this->assertStringNotContainsString('GPTBot', $output);
        $this->assertStringNotContainsString('Disallow: /', $output);
    }

    public function test_block_training_blocks_training_crawlers_only(): void
    {
        $output = AiCrawlerPolicy::toRobotsTxt(AiCrawlerPolicy::BLOCK_TRAINING);

        foreach (['GPTBot', 'ClaudeBot', 'CCBot', 'Bytespider', 'Google-Extended'] as $crawler) {
            $this->assertStringContainsString('User-agent: ' . $crawler, $output);
        }

        // Retrieval crawlers still bring citations and referral traffic.
        foreach (['OAI-SearchBot', 'ChatGPT-User', 'Claude-User', 'PerplexityBot'] as $crawler) {
            $this->assertStringNotContainsString('User-agent: ' . $crawler, $output);
        }

        $this->assertStringContainsString("User-agent: *\nDisallow:", $output);
    }

    public function test_block_all_blocks_training_and_retrieval_crawlers(): void
    {
        $output = AiCrawlerPolicy::toRobotsTxt(AiCrawlerPolicy::BLOCK_ALL);

        foreach (['GPTBot', 'ClaudeBot', 'OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot'] as $crawler) {
            $this->assertStringContainsString('User-agent: ' . $crawler, $output);
        }
    }

    public function test_sitemap_directive_is_included_when_provided(): void
    {
        $output = AiCrawlerPolicy::toRobotsTxt(
            AiCrawlerPolicy::ALLOW_ALL,
            'https://example.test/sitemap.xml'
        );

        $this->assertStringContainsString('Sitemap: https://example.test/sitemap.xml', $output);
    }

    public function test_no_internal_paths_are_disclosed(): void
    {
        // Listing admin paths in robots.txt advertises them; only crawler groups and the
        // sitemap belong in the file.
        $output = AiCrawlerPolicy::toRobotsTxt(AiCrawlerPolicy::BLOCK_ALL, 'https://example.test/sitemap.xml');

        foreach (['/admin', '/storage', '/vendor', '.env'] as $path) {
            $this->assertStringNotContainsString($path, $output);
        }
    }

    public function test_unknown_or_null_policy_falls_back_to_allow_all(): void
    {
        $this->assertSame(AiCrawlerPolicy::ALLOW_ALL, AiCrawlerPolicy::sanitizePolicy(null));
        $this->assertSame(AiCrawlerPolicy::ALLOW_ALL, AiCrawlerPolicy::sanitizePolicy('nonsense'));
        $this->assertSame([], AiCrawlerPolicy::blockedCrawlers('nonsense'));
    }

    public function test_catch_all_group_is_rendered_last(): void
    {
        // robots.txt matching picks the most specific matching group, but keeping the
        // wildcard group last avoids ambiguity for lenient parsers.
        $output = AiCrawlerPolicy::toRobotsTxt(AiCrawlerPolicy::BLOCK_TRAINING);

        $this->assertGreaterThan(
            strpos($output, 'User-agent: GPTBot'),
            strpos($output, 'User-agent: *'),
            'The wildcard group must come after the specific crawler groups.'
        );
    }

    public function test_output_ends_with_a_newline(): void
    {
        $this->assertStringEndsWith("\n", AiCrawlerPolicy::toRobotsTxt(AiCrawlerPolicy::ALLOW_ALL));
    }

    /**
     * public/robots.txt is editable by site owners (by hand and through
     * Admin -> Theme -> Robots.txt), so merging the policy must only ever touch the block
     * between the managed markers.
     */
    public function test_merge_preserves_owner_written_rules(): void
    {
        $existing = "User-agent: *\nDisallow: /private-section/\n\nSitemap: https://example.test/sitemap.xml\n";

        $merged = AiCrawlerPolicy::mergeIntoRobotsTxt($existing, AiCrawlerPolicy::BLOCK_TRAINING);

        $this->assertStringContainsString('Disallow: /private-section/', $merged);
        $this->assertStringContainsString('Sitemap: https://example.test/sitemap.xml', $merged);
        $this->assertStringContainsString('User-agent: GPTBot', $merged);
        $this->assertStringContainsString(AiCrawlerPolicy::BLOCK_START, $merged);
        $this->assertStringContainsString(AiCrawlerPolicy::BLOCK_END, $merged);
    }

    public function test_merge_replaces_a_previous_block_instead_of_stacking(): void
    {
        $existing = "User-agent: *\nDisallow:\n";

        $once = AiCrawlerPolicy::mergeIntoRobotsTxt($existing, AiCrawlerPolicy::BLOCK_TRAINING);
        $twice = AiCrawlerPolicy::mergeIntoRobotsTxt($once, AiCrawlerPolicy::BLOCK_TRAINING);

        $this->assertSame($once, $twice, 'Re-saving the same policy must be idempotent.');
        $this->assertSame(1, substr_count($twice, AiCrawlerPolicy::BLOCK_START));
        $this->assertSame(1, substr_count($twice, 'User-agent: GPTBot'));
    }

    public function test_switching_policy_swaps_the_block_contents(): void
    {
        $existing = "User-agent: *\nDisallow:\n";

        $blocked = AiCrawlerPolicy::mergeIntoRobotsTxt($existing, AiCrawlerPolicy::BLOCK_ALL);
        $this->assertStringContainsString('User-agent: PerplexityBot', $blocked);

        $training = AiCrawlerPolicy::mergeIntoRobotsTxt($blocked, AiCrawlerPolicy::BLOCK_TRAINING);
        $this->assertStringContainsString('User-agent: GPTBot', $training);
        $this->assertStringNotContainsString('User-agent: PerplexityBot', $training);
    }

    public function test_allow_all_removes_the_managed_block_entirely(): void
    {
        $existing = "User-agent: *\nDisallow: /private-section/\n";

        $blocked = AiCrawlerPolicy::mergeIntoRobotsTxt($existing, AiCrawlerPolicy::BLOCK_TRAINING);
        $restored = AiCrawlerPolicy::mergeIntoRobotsTxt($blocked, AiCrawlerPolicy::ALLOW_ALL);

        $this->assertStringNotContainsString(AiCrawlerPolicy::BLOCK_START, $restored);
        $this->assertStringNotContainsString('GPTBot', $restored);
        $this->assertSame($existing, $restored, 'Reverting to allow-all must restore the original file byte for byte.');
    }

    public function test_merge_handles_an_empty_file(): void
    {
        $this->assertSame('', AiCrawlerPolicy::mergeIntoRobotsTxt('', AiCrawlerPolicy::ALLOW_ALL));

        $merged = AiCrawlerPolicy::mergeIntoRobotsTxt('', AiCrawlerPolicy::BLOCK_TRAINING);
        $this->assertStringStartsWith(AiCrawlerPolicy::BLOCK_START, $merged);
        $this->assertStringEndsWith("\n", $merged);
    }

    public function test_crawler_directives_exclude_the_catch_all_group_and_sitemap(): void
    {
        // The managed block must not restate the owner's wildcard group or Sitemap line.
        $directives = AiCrawlerPolicy::crawlerDirectives(AiCrawlerPolicy::BLOCK_TRAINING);

        $this->assertStringContainsString('User-agent: GPTBot', $directives);
        $this->assertStringNotContainsString('User-agent: *', $directives);
        $this->assertStringNotContainsString('Sitemap:', $directives);
    }
}
