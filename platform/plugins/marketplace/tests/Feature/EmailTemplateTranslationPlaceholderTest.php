<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Supports\BaseTestCase;

/**
 * Email template body strings are resolved through `| trans({...})`, i.e. Laravel's translator,
 * which only substitutes :placeholder. Subjects are substituted by EmailHandler and keep {{ }}.
 */
class EmailTemplateTranslationPlaceholderTest extends BaseTestCase
{
    /**
     * Map every `plugins/marketplace::marketplace.email_templates.*` key used with trans({...})
     * in the email templates to the parameter names it receives.
     */
    protected function translatedKeysWithParameters(): array
    {
        $map = [];

        foreach (glob(plugin_path('marketplace/resources/email-templates/*.tpl')) as $template) {
            preg_match_all(
                "/'plugins\/marketplace::marketplace\.email_templates\.([a-z0-9_]+)'\s*\|\s*trans\(\{([^}]*)\}\)/",
                file_get_contents($template),
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as [, $key, $arguments]) {
                preg_match_all("/'?([a-z_]+)'?\s*:/", $arguments, $parameters);

                $map[$key] = array_unique([...$map[$key] ?? [], ...$parameters[1]]);
            }
        }

        return $map;
    }

    protected function languageFiles(): array
    {
        $files = glob(plugin_path('marketplace/resources/lang/*/marketplace.php'));

        $this->assertNotEmpty($files);

        return $files;
    }

    public function test_translated_email_strings_use_colon_placeholders(): void
    {
        $map = $this->translatedKeysWithParameters();

        $this->assertArrayHasKey('email_sent_by_team', $map);
        $this->assertContains('site_title', $map['vendor_account_blocked_message']);

        foreach ($this->languageFiles() as $file) {
            $lines = (include $file)['email_templates'] ?? [];

            foreach ($map as $key => $parameters) {
                if (! isset($lines[$key])) {
                    continue;
                }

                foreach ($parameters as $parameter) {
                    $this->assertDoesNotMatchRegularExpression(
                        '/{{\s*' . $parameter . '\s*}}/',
                        $lines[$key],
                        "$file: email_templates.$key must use :$parameter"
                    );
                }
            }
        }
    }

    public function test_vendor_account_strings_keep_every_placeholder(): void
    {
        foreach ($this->languageFiles() as $file) {
            $lines = (include $file)['email_templates'];

            $this->assertStringContainsString(':site_title', $lines['email_sent_by_team'], $file);
            $this->assertStringContainsString(':site_title', $lines['vendor_account_blocked_message'], $file);
            $this->assertStringContainsString(':block_date', $lines['vendor_account_blocked_message'], $file);
            $this->assertStringContainsString(':site_title', $lines['vendor_account_unblocked_message'], $file);
            $this->assertStringContainsString(':unblock_date', $lines['vendor_account_unblocked_message'], $file);
        }
    }

    public function test_email_subjects_keep_twig_placeholders(): void
    {
        foreach ($this->languageFiles() as $file) {
            foreach ((include $file)['email'] ?? [] as $key => $line) {
                if (is_string($line) && str_ends_with($key, '_subject')) {
                    $this->assertStringNotContainsString(':site_title', $line, "$file: email.$key");
                    $this->assertStringNotContainsString(':store_name', $line, "$file: email.$key");
                }
            }
        }
    }

    public function test_vendor_account_blocked_email_renders_site_title(): void
    {
        app()->setLocale('en');

        // Use the shipped line, not a local lang/vendor override that may exist on this machine
        $key = 'marketplace.email_templates.vendor_account_blocked_message';
        trans("plugins/marketplace::$key");
        app('translator')->addLines([
            $key => (include plugin_path('marketplace/resources/lang/en/marketplace.php'))['email_templates']['vendor_account_blocked_message'],
        ], 'en', 'plugins/marketplace');

        $output = trans('plugins/marketplace::marketplace.email_templates.vendor_account_blocked_message', [
            'site_title' => 'Botble Store',
            'block_date' => '2026-09-30',
        ]);

        $this->assertSame(
            'Your vendor account at Botble Store has been <strong>blocked</strong> as of 2026-09-30.',
            $output
        );
    }
}
