<?php

namespace Botble\Base\Tests\Feature;

use Botble\Base\Events\SendMailEvent;
use Botble\Base\Supports\EmailHandler;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards finding #3: plugin-supplied email variable values (customer name, order data, ...)
 * must be treated as literal data and never compiled as Twig templates, so a low-privilege
 * user cannot inject {{ ... }} into an outgoing email (stored SSTI).
 */
class EmailHandlerTemplateInjectionTest extends TestCase
{
    protected function renderWithVariable(string $value, string $content): string
    {
        $handler = new EmailHandler();
        $handler->setType('plugins')->setModule('test');
        $handler->setVariableValue('payload', $value, 'test');

        $method = new ReflectionMethod($handler, 'replaceVariableValue');
        $method->setAccessible(true);

        return $method->invoke($handler, ['payload'], 'test', $content);
    }

    public function test_expression_in_variable_value_is_not_evaluated(): void
    {
        $output = $this->renderWithVariable('{{ 7 * 7 }}', 'Result: {{ payload }}');

        // The injected expression is echoed verbatim, not computed to "49".
        $this->assertStringContainsString('{{ 7 * 7 }}', $output);
        $this->assertStringNotContainsString('49', $output);
    }

    public function test_rce_payload_in_variable_value_is_inert(): void
    {
        $payload = '{{ ["id"]|map("system")|join }}';

        $output = $this->renderWithVariable($payload, '{{ payload }}');

        // Payload is passed through as a literal string; no command output leaks in.
        $this->assertStringContainsString($payload, $output);
        $this->assertStringNotContainsString('uid=', $output);
    }

    public function test_legitimate_variable_value_still_renders(): void
    {
        $output = $this->renderWithVariable('John Doe', 'Hello {{ payload }}');

        $this->assertStringContainsString('Hello John Doe', $output);
    }

    /**
     * The production template flow prepares content once (getContent) then calls send(). send()
     * must NOT prepare it again, otherwise a value rendered on the first pass ({{ 7*7 }} from a
     * user-controlled variable) would be evaluated on the second pass.
     */
    public function test_prepared_content_is_not_recompiled_by_send(): void
    {
        // Fake the event instead of listening for it: SendMailListener is registered at boot, so
        // it runs before any listener added here and would hand the mail to the mailer - which
        // fails without a configured MAIL_FROM_ADDRESS (null in CI).
        Event::fake([SendMailEvent::class]);

        $handler = new EmailHandler();
        $handler->setType('plugins')->setModule('test');
        $handler->setVariableValue('payload', '{{ 7*7 }} {{ site_admin_email }}', 'test');

        $method = new ReflectionMethod($handler, 'replaceVariableValue');
        $method->setAccessible(true);
        $prepared = $method->invoke($handler, ['payload'], 'test', 'Hi {{ payload }}');

        // Mimic sendUsingTemplate: content already prepared => send() must treat it as final.
        $handler->send($prepared, 'subject', 'to@example.com', [], true, true);

        $captured = null;
        Event::assertDispatched(SendMailEvent::class, function (SendMailEvent $event) use (&$captured): bool {
            $captured = $event->content;

            return true;
        });

        $this->assertNotNull($captured);
        $this->assertStringContainsString('{{ 7*7 }}', $captured);
        $this->assertStringNotContainsString('49', $captured);
        // The in-scope core variable must not resolve on a second pass either.
        $this->assertStringContainsString('{{ site_admin_email }}', $captured);
    }

    protected function tearDown(): void
    {
        remove_filter('cms_email_sanitize_output');
        remove_filter('cms_email_sanitize_output_strict');

        parent::tearDown();
    }

    protected function sanitizeOutput(string $content): string
    {
        $handler = new EmailHandler();

        $method = new ReflectionMethod($handler, 'sanitizeOutput');
        $method->setAccessible(true);

        return $method->invoke($handler, $content);
    }

    public function test_default_strip_removes_xss_but_preserves_structure(): void
    {
        $output = $this->sanitizeOutput(
            '<body dir="rtl"><!--[if mso]><table><![endif]-->'
            . '<p>Hi <b>John</b> <a href="https://ok.test">l</a></p>'
            . '<img src="https://cms.test/l.png" onerror="alert(1)">'
            . '<a href="javascript:alert(1)">x</a><script>alert(1)</script></body>'
        );

        // XSS vectors removed.
        $this->assertStringNotContainsString('<script', $output);
        $this->assertStringNotContainsString('onerror', $output);
        $this->assertDoesNotMatchRegularExpression('/href\s*=\s*"javascript:/i', $output);
        // Structure preserved (RTL direction, Outlook comment, wrapper, content, safe assets).
        $this->assertStringContainsString('dir="rtl"', $output);
        $this->assertStringContainsString('[if mso]', $output);
        $this->assertStringContainsString('John', $output);
        $this->assertStringContainsString('src="https://cms.test/l.png"', $output);
        $this->assertStringContainsString('https://ok.test', $output);
    }

    public function test_sanitization_can_be_disabled_via_filter(): void
    {
        add_filter('cms_email_sanitize_output', fn () => false);

        $dirty = '<p>Hi</p><script>alert(1)</script>';

        $this->assertSame($dirty, $this->sanitizeOutput($dirty));
    }

    public function test_strict_filter_uses_html_purifier(): void
    {
        add_filter('cms_email_sanitize_output_strict', fn () => true);

        $output = $this->sanitizeOutput('<p>Hi <b>John</b></p><script>alert(1)</script>');

        // Purifier strips the script and the document wrapper.
        $this->assertStringNotContainsString('<script', $output);
        $this->assertStringContainsString('John', $output);
    }

    #[DataProvider('obfuscatedXss')]
    public function test_sanitizer_strips_obfuscated_xss(string $payload): void
    {
        $output = $this->sanitizeOutput($payload);

        $this->assertDoesNotMatchRegularExpression('/<script/i', $output, $payload);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $output, $payload);
        $this->assertDoesNotMatchRegularExpression('/(?:javascript|vbscript)\s*:/i', $output, $payload);
    }

    public static function obfuscatedXss(): array
    {
        return [
            'basic script' => ['<script>alert(1)</script>'],
            'script with attrs and newlines' => ["<script type=\"text/javascript\">\nalert(1)\n</script>"],
            'uppercase script' => ['<SCRIPT>alert(1)</SCRIPT>'],
            'onerror quoted' => ['<img src=x onerror="alert(1)">'],
            'onerror unquoted' => ['<img src=x onerror=alert(1)>'],
            'mixed-case handler' => ['<img src=x OnError="alert(1)">'],
            'svg onload' => ['<svg onload="alert(1)">'],
            'javascript uri spaced' => ['<a href="  javascript:alert(1)">x</a>'],
            'vbscript uri' => ['<a href="vbscript:msgbox(1)">x</a>'],
        ];
    }

    #[DataProvider('legitimateContent')]
    public function test_sanitizer_preserves_legitimate_email_content(string $needle, string $html): void
    {
        $this->assertStringContainsString($needle, $this->sanitizeOutput($html));
    }

    public static function legitimateContent(): array
    {
        return [
            'data uri image' => ['data:image/png;base64,iVBOR', '<img src="data:image/png;base64,iVBOR">'],
            'mailto link' => ['mailto:x@y.com', '<a href="mailto:x@y.com">m</a>'],
            'tel link' => ['tel:+15550000', '<a href="tel:+15550000">c</a>'],
            'css url in style' => ['url(https://cms.test/bg.png)', '<td style="background:url(https://cms.test/bg.png)">x</td>'],
            'on-word in text' => ['Turn on notifications = yes', '<p>Turn on notifications = yes</p>'],
            'rtl direction' => ['dir="rtl"', '<body dir="rtl">x</body>'],
            'https link' => ['https://ok.test/p?a=1', '<a href="https://ok.test/p?a=1">l</a>'],
        ];
    }
}
