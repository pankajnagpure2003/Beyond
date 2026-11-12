<?php

namespace Botble\Setting\Tests\Feature;

use Botble\Base\Facades\EmailHandler;
use Botble\Base\Supports\BaseTestCase;

/**
 * Every variable an email template uses must be declared in its module's config/email.php.
 * Undeclared variables get no field in the admin preview form and - worse - are never passed
 * to the template when the email is sent, so they render empty even if the code sets them.
 */
class EmailTemplateVariablesDeclaredTest extends BaseTestCase
{
    /**
     * Twig keywords, tests, filters and helpers that look like identifiers inside {{ }} / {% %}.
     */
    protected const TWIG_WORDS = [
        'if', 'endif', 'else', 'elseif', 'for', 'endfor', 'in', 'not', 'and', 'or', 'is', 'set', 'endset',
        'true', 'false', 'null', 'none', 'loop', 'odd', 'even', 'defined', 'empty', 'iterable', 'same', 'as',
        'length', 'raw', 'trans', 'icon_url', 'price_format', 'urlencode', 'default', 'date', 'format',
        'upper', 'lower', 'join', 'first', 'last', 'keys', 'escape', 'e', 'nl2br', 'striptags', 'number_format',
    ];

    /**
     * Top-level variable names used by a template (loop/set variables and properties excluded).
     */
    protected function usedVariables(string $content): array
    {
        preg_match_all('/{[{%](.*?)[}%]}/s', $content, $expressions);
        preg_match_all('/{%\s*for\s+(?:(\w+)\s*,\s*)?(\w+)\s+in/', $content, $loops);
        preg_match_all('/{%\s*set\s+(\w+)/', $content, $sets);

        $used = [];

        foreach ($expressions[1] as $expression) {
            $expression = preg_replace(
                ["/'[^']*'|\"[^\"]*\"/", '/\|\s*\w+/', '/\.\s*\w+/', '/\b\d+\b/'],
                '',
                $expression
            );

            preg_match_all('/\b[a-z_]\w*\b/i', $expression, $identifiers);

            $used = [...$used, ...$identifiers[0]];
        }

        return array_values(array_diff(
            array_unique($used),
            self::TWIG_WORDS,
            array_filter($loops[1]),
            $loops[2],
            $sets[1]
        ));
    }

    public function test_every_used_variable_is_declared(): void
    {
        $coreVariables = array_keys(EmailHandler::getCoreVariables());
        $checked = 0;
        $undeclared = [];

        foreach (EmailHandler::getTemplates() as $type => $modules) {
            foreach ($modules as $module => $data) {
                foreach (array_keys($data['templates'] ?? []) as $template) {
                    if (in_array($template, ['header', 'footer'])) {
                        continue;
                    }

                    $content = file_exists($path = platform_path("$type/$module/resources/email-templates/$template.tpl"))
                        ? file_get_contents($path)
                        : '';

                    if (! $content) {
                        continue;
                    }

                    $checked++;

                    $declared = array_keys(EmailHandler::getVariables($type, $module, $template));

                    foreach (array_diff($this->usedVariables($content), $declared, $coreVariables) as $variable) {
                        $undeclared[] = "$module/$template: $variable";
                    }
                }
            }
        }

        // Guard against silently checking nothing (e.g. plugin templates not registered).
        // Check the email-templates dir, not the plugin dir: a project may have an unrelated plugin with the same name.
        foreach (['e-wallet', 'ecommerce', 'marketplace'] as $module) {
            if (is_dir(plugin_path("$module/resources/email-templates"))) {
                $this->assertArrayHasKey($module, EmailHandler::getTemplates()['plugins'] ?? [], "$module templates are not registered");
            }
        }

        $this->assertGreaterThan(0, $checked);
        $this->assertSame([], $undeclared, 'Declare these variables in the module config/email.php');
    }
}
