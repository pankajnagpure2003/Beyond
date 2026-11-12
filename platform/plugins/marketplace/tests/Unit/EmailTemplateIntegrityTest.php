<?php

namespace Botble\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the plugin's email templates against the failures that render silently rather
 * than throwing. All three below shipped in the vendor-subscription emails and were only
 * caught by rendering them by hand:
 *
 *  - an icon name with no matching PNG: `icon_url` returns '' and the mail shows a broken
 *    image (alert-circle, clock, x, refresh and warning were all used, none exist)
 *  - a button class with no CSS rule: `bb-btn-primary` gave white text and no background,
 *    so the call to action was invisible in every subscription email
 *  - a `plugins/marketplace::` key with no translation, which prints the raw key
 *
 * Reads the files directly, so it needs no database and no booted application.
 */
class EmailTemplateIntegrityTest extends TestCase
{
    protected string $plugin;

    protected string $core;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = dirname(__DIR__, 2);
        $this->core = dirname(__DIR__, 4) . '/core/base';
    }

    /**
     * @return array<string, string> template basename => contents
     */
    protected function templates(): array
    {
        $templates = [];

        foreach (glob($this->plugin . '/resources/email-templates/*.tpl') as $path) {
            $templates[basename($path)] = file_get_contents($path);
        }

        return $templates;
    }

    protected function availableIcons(): array
    {
        $path = dirname(__DIR__, 5) . '/public/vendor/core/core/base/images/email-icons';

        if (! is_dir($path)) {
            $this->markTestSkipped('Email icon assets are not published.');
        }

        return array_map(
            fn (string $file) => pathinfo($file, PATHINFO_FILENAME),
            array_values(array_diff(scandir($path), ['.', '..']))
        );
    }

    public function test_every_icon_referenced_by_a_template_exists(): void
    {
        $icons = $this->availableIcons();
        $missing = [];

        foreach ($this->templates() as $name => $content) {
            preg_match_all("#'([a-z0-9-]+)'\s*\|\s*icon_url#", $content, $matches);

            foreach (array_unique($matches[1]) as $icon) {
                if (! in_array($icon, $icons, true)) {
                    $missing[] = "$name references icon '$icon'";
                }
            }
        }

        $this->assertSame([], $missing, sprintf(
            "Templates reference icons with no PNG, which render as a broken image.\nAvailable: %s",
            implode(', ', $icons)
        ));
    }

    public function test_every_button_class_used_by_a_template_is_defined_in_the_email_css(): void
    {
        $css = file_get_contents($this->core . '/resources/email-templates/default.css');

        preg_match_all('#\.(bb-[a-z0-9-]+)#', $css, $matches);
        $defined = array_unique($matches[1]);

        $missing = [];

        foreach ($this->templates() as $name => $content) {
            preg_match_all('#class="(bb-btn[^"]*)"#', $content, $classMatches);

            foreach ($classMatches[1] as $classList) {
                foreach (preg_split('#\s+#', trim($classList)) as $class) {
                    if ($class !== '' && ! in_array($class, $defined, true)) {
                        $missing[] = "$name uses undefined class '$class'";
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'A button class with no CSS rule renders as invisible text.');
    }

    public function test_every_translation_key_used_by_a_template_resolves(): void
    {
        $langPath = $this->plugin . '/resources/lang/en';
        $files = [];

        foreach (glob($langPath . '/*.php') as $path) {
            $files[pathinfo($path, PATHINFO_FILENAME)] = require $path;
        }

        $missing = [];

        foreach ($this->templates() as $name => $content) {
            preg_match_all('#plugins/marketplace::([a-z0-9_.]+)#', $content, $matches);

            foreach (array_unique($matches[1]) as $key) {
                if (! $this->resolves($files, $key)) {
                    $missing[] = "$name references '$key'";
                }
            }
        }

        $this->assertSame([], $missing, 'An unresolved key prints as the raw key string in the email.');
    }

    protected function resolves(array $files, string $key): bool
    {
        $segments = explode('.', $key);
        $file = array_shift($segments);

        if (! isset($files[$file]) || ! $segments) {
            return false;
        }

        $value = $files[$file];

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return false;
            }

            $value = $value[$segment];
        }

        return is_string($value);
    }
}
