<?php

namespace Botble\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the 43 locale directories against the ways a machine translation pass breaks
 * them. Every failure mode below was observed for real while translating this plugin:
 *
 *  - mask markers (@@, @2@@) leaking into output that ships to customers
 *  - the :placeholder token itself getting translated, so it never interpolates
 *  - <strong>/<br> tags dropped from email bodies
 *  - keys silently missing, which a naive "is it still English?" check cannot see
 *
 * Reads the lang files directly, so it needs no database and no booted application.
 * There is deliberately no baseline or allow-list: every locale mirrors en/ exactly,
 * so a regression fails here instead of being recorded as accepted debt.
 */
class LanguageFileIntegrityTest extends TestCase
{
    protected string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = dirname(__DIR__, 2) . '/resources/lang';
    }

    /** @return array<string, string> dot-notation key => value */
    protected function flatten(array $items, string $prefix = ''): array
    {
        $flat = [];

        foreach ($items as $key => $value) {
            $name = $prefix ? "$prefix.$key" : (string) $key;

            if (is_array($value)) {
                $flat = array_merge($flat, $this->flatten($value, $name));
            } else {
                $flat[$name] = (string) $value;
            }
        }

        return $flat;
    }

    /** @return array<int, string> file names in en/ */
    protected function sourceFiles(): array
    {
        return array_values(array_filter(
            scandir("$this->base/en"),
            fn (string $f) => str_ends_with($f, '.php')
        ));
    }

    /** @return array<int, string> every locale directory except en */
    protected function locales(): array
    {
        return array_values(array_filter(
            scandir($this->base),
            fn (string $d) => $d[0] !== '.' && $d !== 'en' && is_dir("$this->base/$d")
        ));
    }

    protected function load(string $locale, string $file): array
    {
        return $this->flatten(require "$this->base/$locale/$file");
    }

    protected function countTags(string $value): int
    {
        return preg_match_all('/<[a-z\/][^>]*>/', $value);
    }

    /** @return array<int, string> the :placeholders a string declares */
    protected function placeholders(string $value): array
    {
        preg_match_all('/:[a-z_]+/', $value, $matches);

        return array_unique($matches[0]);
    }

    public function test_every_locale_has_every_source_file(): void
    {
        $missing = [];

        foreach ($this->sourceFiles() as $file) {
            foreach ($this->locales() as $locale) {
                if (! file_exists("$this->base/$locale/$file")) {
                    $missing[] = "$locale/$file";
                }
            }
        }

        $this->assertSame([], $missing, 'Locale files missing entirely: ' . implode(', ', $missing));
    }

    /**
     * The check that matters most: a translator that drops the mask it wrapped a
     * placeholder in leaves literal "@@@@" in a customer-facing email.
     */
    public function test_no_translation_leaks_internal_mask_markers(): void
    {
        $leaks = [];

        foreach ($this->sourceFiles() as $file) {
            foreach ($this->locales() as $locale) {
                foreach ($this->load($locale, $file) as $key => $value) {
                    if (preg_match('/@[0-9]*@@|@@[0-9@]/', $value)) {
                        $leaks[] = "$locale/$file::$key";
                    }
                }
            }
        }

        $this->assertSame([], $leaks, 'Leaked mask markers in: ' . implode(', ', $leaks));
    }

    public function test_no_locale_declares_keys_that_english_does_not(): void
    {
        $extra = [];

        foreach ($this->sourceFiles() as $file) {
            $en = array_keys($this->load('en', $file));

            foreach ($this->locales() as $locale) {
                foreach (array_diff(array_keys($this->load($locale, $file)), $en) as $key) {
                    $extra[] = "$locale/$file::$key";
                }
            }
        }

        $this->assertSame([], $extra, 'Keys absent from en/: ' . implode(', ', array_slice($extra, 0, 20)));
    }

    /**
     * A missing key falls back to the English string at runtime, so it is invisible to
     * any "is this still English?" heuristic. Every file must mirror en/ exactly.
     */
    public function test_key_parity_is_exact(): void
    {
        $problems = [];

        foreach ($this->sourceFiles() as $file) {
            $en = array_keys($this->load('en', $file));

            foreach ($this->locales() as $locale) {
                $missing = array_diff($en, array_keys($this->load($locale, $file)));

                if ($missing) {
                    $problems[] = "$locale/$file missing " . count($missing)
                        . ' (' . implode(', ', array_slice($missing, 0, 3)) . ')';
                }
            }
        }

        $this->assertSame([], $problems, implode(' | ', $problems));
    }

    /**
     * A translated ":store" becomes ":tienda" and silently stops interpolating.
     */
    public function test_placeholders_survive_translation(): void
    {
        $losses = [];

        foreach ($this->sourceFiles() as $file) {
            $en = $this->load('en', $file);

            foreach ($this->locales() as $locale) {
                $translated = $this->load($locale, $file);

                foreach ($en as $key => $value) {
                    if (! isset($translated[$key])) {
                        continue;
                    }

                    foreach ($this->placeholders($value) as $placeholder) {
                        if (! str_contains($translated[$key], $placeholder)) {
                            $losses[] = "$locale/$file::$key lost $placeholder";
                        }
                    }
                }
            }
        }

        $this->assertSame([], $losses, 'Placeholder loss: ' . implode(', ', array_slice($losses, 0, 15)));
    }

    public function test_html_markup_survives_translation(): void
    {
        $losses = [];

        foreach ($this->sourceFiles() as $file) {
            $en = $this->load('en', $file);

            foreach ($this->locales() as $locale) {
                $translated = $this->load($locale, $file);

                foreach ($en as $key => $value) {
                    if (! isset($translated[$key])) {
                        continue;
                    }

                    $expected = $this->countTags($value);

                    if ($expected !== $this->countTags($translated[$key])) {
                        $losses[] = "$locale/$file::$key ($expected tags in en)";
                    }
                }
            }
        }

        $this->assertSame([], $losses, 'HTML tag loss: ' . implode(', ', array_slice($losses, 0, 15)));
    }
}
