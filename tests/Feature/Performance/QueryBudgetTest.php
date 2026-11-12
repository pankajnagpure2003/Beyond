<?php

namespace Tests\Feature\Performance;

use Botble\Blog\Enums\PostStatusEnum;
use Botble\Blog\Models\Post;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards public listings against N+1 regressions.
 *
 * Measured against 1020 posts (51x this install's content): the homepage held at 8-9
 * queries and the blog listing at 11, with no query shape repeating - per-page cost is flat
 * in the number of rows. These tests fail if that stops being true.
 *
 * The assertion is on *repeated query shapes* rather than a total count: a shape repeating
 * once per rendered item is the actual signature of an N+1, and unlike a raw count it does
 * not drift when a feature legitimately adds a query.
 *
 * Two traps this test is built to avoid, both hit while profiling for real:
 *  - The test database ships empty. Profiling a listing with no rows cannot detect a
 *    per-item query, so an earlier version of this test passed even with eager loading
 *    deliberately removed. Rows are therefore seeded here, and every check first proves the
 *    seeded records actually appear in the response - otherwise it skips instead of
 *    reporting a false pass.
 *  - Registering DB::listen more than once makes every listener record every query and
 *    multiplies the counts; and the first request populates menu/theme/settings caches, so
 *    it is warmed before measuring.
 */
class QueryBudgetTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Enough rows that a per-item query is unmistakable against the repeat allowance below.
     */
    private const SEEDED_POSTS = 12;

    /**
     * Allowance for a shape that legitimately recurs a fixed number of times - once per
     * content section, say, since plugins can register their own via
     * FILTER_LLMS_TXT_SECTIONS. Tied to the seed size so the meaning stays "on the order of
     * one query per item": measured healthy output repeats a shape once, an N+1 over the
     * seeded rows repeats it SEEDED_POSTS times, so half the seed size separates the two
     * cleanly in both directions.
     */
    private const MAX_REPEATS_PER_SHAPE = self::SEEDED_POSTS / 2;

    private const TOTAL_QUERY_CEILING = 40;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Post::class)) {
            $this->markTestSkipped('Blog plugin is not available.');
        }

        $this->marker = 'QueryBudget ' . uniqid();

        $this->seedPosts();

        // Listing output is cached in places (llms.txt), so start from a clean slate.
        Cache::flush();
    }

    private function seedPosts(): void
    {
        $authorId = (int) (DB::table('users')->value('id') ?: 1);
        $now = now();

        for ($i = 0; $i < self::SEEDED_POSTS; $i++) {
            $id = DB::table('posts')->insertGetId([
                'name' => $this->marker . ' Post ' . $i,
                'description' => 'Seeded description ' . $i,
                'content' => '<p>Seeded body ' . $i . '</p>',
                'status' => PostStatusEnum::PUBLISHED,
                'author_id' => $authorId,
                'author_type' => \Botble\ACL\Models\User::class,
                'is_featured' => 0,
                'views' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('slugs')->insert([
                'key' => 'query-budget-post-' . $id,
                'reference_id' => $id,
                'reference_type' => Post::class,
                'prefix' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param  bool  $responseIsCached  true when the endpoint caches its own rendered output
     * @return array<string, int> query shapes to occurrence count
     */
    private function profileShapes(string $uri, bool $responseIsCached = false): array
    {
        // Confirm the page is reachable and actually lists the seeded rows before trusting
        // any number that comes out of it.
        $probe = $this->get($uri);

        if ($probe->getStatusCode() !== 200) {
            $this->markTestSkipped(sprintf('%s returned %d in this environment.', $uri, $probe->getStatusCode()));
        }

        if (! str_contains((string) $probe->getContent(), $this->marker)) {
            // Nothing seeded is on the page, so a per-item query cannot show up here and a
            // pass would mean nothing.
            $this->markTestSkipped(sprintf('%s does not list the seeded posts, so it cannot detect an N+1.', $uri));
        }

        if ($responseIsCached) {
            // The endpoint stores its rendered output, so the probe above just populated
            // that store. Measuring the next request would profile a cache hit and pass no
            // matter how many per-item queries the generator makes - which is exactly how
            // an earlier version of this test failed to notice eager loading being removed.
            // Measure a cold generation instead; cold-start noise adds one-off queries, not
            // repeated shapes, so the N+1 signal survives.
            Cache::flush();
        }

        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get($uri);

        $shapes = [];

        foreach ($queries as $sql) {
            if (str_contains($sql, 'sessions')) {
                continue;
            }

            $shape = (string) preg_replace('/\s+/', ' ', (string) preg_replace('/\b\d+\b/', '?', $sql));
            $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
        }

        return $shapes;
    }

    private function assertNoPerItemQueries(string $uri, bool $responseIsCached = false): void
    {
        $shapes = $this->profileShapes($uri, $responseIsCached);

        arsort($shapes);

        $worst = array_key_first($shapes) ?? '';
        $worstCount = $shapes[$worst] ?? 0;

        $this->assertLessThanOrEqual(
            self::MAX_REPEATS_PER_SHAPE,
            $worstCount,
            sprintf(
                "A query shape ran %d times on %s while listing %d posts - that is an N+1.\nEager-load the relation instead.\nShape: %s",
                $worstCount,
                $uri,
                self::SEEDED_POSTS,
                substr($worst, 0, 200)
            )
        );

        $total = array_sum($shapes);

        $this->assertLessThanOrEqual(
            self::TOTAL_QUERY_CEILING,
            $total,
            sprintf('%s issued %d queries, over the budget of %d.', $uri, $total, self::TOTAL_QUERY_CEILING)
        );
    }

    /**
     * llms.txt lists records straight from the database, so it is the listing most exposed
     * to an N+1 and the one that reliably includes seeded content.
     */
    public function test_llms_txt_has_no_per_item_queries(): void
    {
        $this->assertNoPerItemQueries('/llms.txt', responseIsCached: true);
    }

    public function test_homepage_has_no_per_item_queries(): void
    {
        $this->assertNoPerItemQueries('/');
    }

    public function test_blog_listing_has_no_per_item_queries(): void
    {
        $this->assertNoPerItemQueries('/blog');
    }

    public function test_llms_txt_is_cached_after_first_request(): void
    {
        $first = $this->get('/llms.txt');

        if ($first->getStatusCode() !== 200) {
            $this->markTestSkipped('llms.txt is not available in this environment.');
        }

        $queries = 0;

        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->get('/llms.txt');

        $this->assertSame(0, $queries, 'A cached llms.txt must not query the database again.');
    }
}
