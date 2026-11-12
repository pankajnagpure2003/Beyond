<?php

namespace Botble\Blog\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the blog post JSON-LD (structured data) builder.
 *
 * Source-inspection style: CI runs the suite once with all plugins activated and once
 * with all deactivated, so a test that boots blog models or routes would fail the
 * second run. Reading the source keeps it valid in both.
 *
 * Bugs covered:
 *  1. `headline`/`description` were passed through BaseHelper::clean() only. That is an
 *     HTMLPurifier call whose output is HTML-encoded, so titles containing "&" shipped as
 *     "&amp;" inside JSON-LD, which is not HTML.
 *  2. `author.name` was `class_exists($post->author_type) ? $post->author->name : ''`,
 *     emitting a Person with an empty name.
 *  3. `author.url` was hardcoded to the homepage for every author.
 *  4. Default schema type was NewsArticle, applying news freshness semantics to
 *     evergreen posts.
 *
 * @see ../../src/Providers/HookServiceProvider.php
 */
class PostSchemaTest extends TestCase
{
    private const HOOK_PATH = __DIR__ . '/../../src/Providers/HookServiceProvider.php';

    private const FORM_PATH = __DIR__ . '/../../src/Forms/Settings/BlogSettingForm.php';

    private const MODEL_PATH = __DIR__ . '/../../src/Models/Post.php';

    private function source(string $path): string
    {
        $source = file_get_contents($path);

        $this->assertNotFalse($source, $path . ' must be readable.');

        return $source;
    }

    public function test_headline_and_description_are_normalized_for_json_ld(): void
    {
        $source = $this->source(self::HOOK_PATH);

        $this->assertStringContainsString(
            "'headline' => \$this->cleanSchemaText(\$post->name)",
            $source,
            'headline must go through cleanSchemaText() so HTML entities are decoded for JSON-LD.'
        );

        $this->assertStringContainsString(
            "'description' => \$this->cleanSchemaText(\$post->description)",
            $source,
            'description must go through cleanSchemaText().'
        );

        $this->assertStringNotContainsString(
            "'headline' => BaseHelper::clean(",
            $source,
            'Raw BaseHelper::clean() output is HTML-encoded and must not be embedded in JSON-LD.'
        );
    }

    public function test_clean_schema_text_decodes_entities_and_strips_tags(): void
    {
        $source = $this->source(self::HOOK_PATH);

        $this->assertMatchesRegularExpression(
            '/protected function cleanSchemaText\(\?string \$value\): \?string/',
            $source,
            'cleanSchemaText() must accept and return a nullable string.'
        );

        $this->assertStringContainsString('html_entity_decode(', $source, 'Entities must be decoded.');
        $this->assertStringContainsString('strip_tags(', $source, 'Tags must be stripped: clean() returns raw input when enable_less_secure_web is on.');
    }

    public function test_author_uses_model_accessors_and_is_omitted_when_unresolved(): void
    {
        $source = $this->source(self::HOOK_PATH);

        $this->assertStringContainsString('$post->author_name', $source, 'Author name must use the model accessor.');
        $this->assertStringContainsString('$post->author_url', $source, 'Author url must use the model accessor.');

        $this->assertStringNotContainsString(
            "'url' => BaseHelper::getHomepageUrl()",
            $source,
            'The homepage must not be used as every author\'s URL.'
        );

        $this->assertStringNotContainsString(
            "class_exists(\$post->author_type) ? \$post->author->name : ''",
            $source,
            'An author entity with an empty name is invalid structured data.'
        );

        // The author key is only set inside the resolved-name branch.
        $this->assertMatchesRegularExpression(
            '/if \(\$authorName = \$this->cleanSchemaText\(\$post->author_name\)\).*?\$schema\[.author.\]/s',
            $source,
            'The author entity must only be added when a name could be resolved.'
        );
    }

    public function test_default_schema_type_is_blog_posting(): void
    {
        $hook = $this->source(self::HOOK_PATH);

        $this->assertStringContainsString(
            "setting('blog_post_schema_type', 'BlogPosting')",
            $hook,
            'Default schema type must be BlogPosting, not NewsArticle.'
        );

        $this->assertStringNotContainsString(
            "setting('blog_post_schema_type', 'NewsArticle')",
            $hook,
            'NewsArticle must no longer be the default in the hook.'
        );

        // The fallback for an out-of-range stored value must match the default.
        $this->assertMatchesRegularExpression(
            '/if \(! in_array\(\$schemaType.*?\$schemaType = .BlogPosting.;/s',
            $hook,
            'The invalid-value fallback must also be BlogPosting.'
        );

        $this->assertStringContainsString(
            "setting('blog_post_schema_type', 'BlogPosting')",
            $this->source(self::FORM_PATH),
            'The settings form default must match the hook default.'
        );
    }

    public function test_json_ld_escapes_angle_brackets(): void
    {
        $source = $this->source(self::HOOK_PATH);

        // json_encode() does NOT escape < or > by default, so a value containing
        // "</script>" could otherwise terminate the surrounding script block.
        $this->assertStringContainsString(
            'JSON_HEX_TAG',
            $source,
            'JSON-LD output must escape angle brackets so no value can break out of <script>.'
        );
    }

    public function test_additional_schema_fields_are_present(): void
    {
        $source = $this->source(self::HOOK_PATH);

        foreach (['inLanguage', 'articleSection', 'keywords', 'wordCount'] as $field) {
            $this->assertStringContainsString(
                "'" . $field . "'",
                $source,
                $field . ' must be included in the post schema.'
            );
        }
    }

    public function test_tags_are_only_read_when_already_loaded(): void
    {
        $source = $this->source(self::HOOK_PATH);

        $this->assertStringContainsString(
            "\$post->relationLoaded('tags')",
            $source,
            'Tags must be guarded by relationLoaded() so the render hook adds no query.'
        );
    }

    public function test_word_count_is_shared_between_reading_time_and_schema(): void
    {
        $source = $this->source(self::MODEL_PATH);

        $this->assertStringContainsString(
            'protected function wordCount(): Attribute',
            $source,
            'Post must expose a word_count accessor.'
        );

        $this->assertStringContainsString(
            'ceil($this->word_count / 200)',
            $source,
            'The reading-time estimate must reuse word_count instead of counting words again.'
        );

        $this->assertSame(
            1,
            substr_count($source, 'str_word_count('),
            'The word count must be computed in exactly one place.'
        );
    }
}
