<?php

namespace Botble\Setting\Tests\Feature;

use Botble\Base\Supports\BaseTestCase;

/**
 * Email translation strings must use the placeholder syntax of whatever renders them:
 * - body strings go through `| trans({...})` (Laravel's translator) and must use :param,
 *   and only params the template actually passes (otherwise ":param" is sent as text);
 * - subjects are substituted by EmailHandler (Twig) and must use {{ param }}.
 *
 * Checks the translation files shipped with core and plugins, in every locale.
 */
class EmailTemplateTranslationPlaceholdersTest extends BaseTestCase
{
    protected function line(string $namespace, string $group, string $key, string $localeDirectory): ?string
    {
        $file = "$localeDirectory/$group.php";

        if (! is_file($file)) {
            return null;
        }

        $line = include $file;

        foreach (explode('.', $key) as $segment) {
            $line = is_array($line) ? ($line[$segment] ?? null) : null;
        }

        return is_string($line) ? $line : null;
    }

    protected function colonPlaceholders(string $line): array
    {
        preg_match_all('/(?<![A-Za-z0-9_:\/]):([a-z_]+)(?![A-Za-z0-9_])/', strip_tags($line), $matches);

        return array_unique($matches[1]);
    }

    public function test_body_strings_only_use_passed_colon_placeholders(): void
    {
        $problems = [];
        $checked = 0;

        foreach (glob(platform_path('*/*/resources/email-templates/*.tpl')) as $template) {
            preg_match_all(
                "/'([a-z-]+\/[a-z0-9-]+)::([a-z0-9-]+)\.([a-z0-9_.]+)'\s*\|\s*trans(?:\(\{([^}]*)\}\))?/",
                file_get_contents($template),
                $calls,
                PREG_SET_ORDER
            );

            foreach ($calls as $call) {
                [, $namespace, $group, $key] = $call;
                preg_match_all("/'?([a-z_]+)'?\s*:/", $call[4] ?? '', $parameters);

                foreach (glob(platform_path("$namespace/resources/lang/*"), GLOB_ONLYDIR) as $localeDirectory) {
                    if (($line = $this->line($namespace, $group, $key, $localeDirectory)) === null) {
                        continue;
                    }

                    $checked++;
                    $where = basename($localeDirectory) . " $namespace::$group.$key (" . basename($template) . ')';

                    preg_match_all('/{{\s*(\w+)\s*}}/', $line, $twigPlaceholders);

                    foreach (array_intersect($twigPlaceholders[1], $parameters[1]) as $parameter) {
                        $problems[] = "$where uses {{ $parameter }}, use :$parameter";
                    }

                    foreach (array_diff($this->colonPlaceholders($line), $parameters[1]) as $parameter) {
                        $problems[] = "$where uses :$parameter, which the template does not pass";
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $checked);
        $this->assertSame([], $problems);
    }

    public function test_subjects_use_twig_placeholders(): void
    {
        $problems = [];

        foreach (glob(platform_path('*/*/config/email.php')) as $config) {
            preg_match_all(
                "/'subject' => '([a-z-]+\/[a-z0-9-]+)::([a-z0-9-]+)\.([a-z0-9_.]+)'/",
                file_get_contents($config),
                $subjects,
                PREG_SET_ORDER
            );

            foreach ($subjects as [, $namespace, $group, $key]) {
                foreach (glob(platform_path("$namespace/resources/lang/*"), GLOB_ONLYDIR) as $localeDirectory) {
                    $line = $this->line($namespace, $group, $key, $localeDirectory);

                    foreach ($line === null ? [] : $this->colonPlaceholders($line) as $parameter) {
                        $problems[] = basename($localeDirectory) . " $namespace::$group.$key uses :$parameter, use {{ $parameter }}";
                    }
                }
            }
        }

        $this->assertSame([], $problems);
    }
}
