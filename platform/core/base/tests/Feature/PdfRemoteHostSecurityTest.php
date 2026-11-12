<?php

namespace Botble\Base\Tests\Feature;

use Botble\Base\Supports\Mpdf\HostRestrictedHttpClient;
use Botble\Base\Supports\Mpdf\ServiceContainer;
use Botble\Base\Supports\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mpdf\Http\ClientInterface;
use Mpdf\Http\SocketHttpClient;
use Mpdf\PsrHttpMessageShim\Request;
use Mpdf\PsrHttpMessageShim\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards finding #1: DomPDF must not fetch remote resources from arbitrary hosts, so an
 * attacker-controlled <img>/CSS URL rendered into a template cannot trigger server-side
 * request forgery (e.g. to cloud metadata at 169.254.169.254).
 */
class PdfRemoteHostSecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        remove_filter('core_base_pdf_allowed_remote_hosts');
        remove_filter('core_base_pdf_is_remote_enabled');

        parent::tearDown();
    }

    protected function invokeAllowedHosts(Pdf $pdf): ?array
    {
        $method = new ReflectionMethod($pdf, 'getAllowedRemoteHosts');
        $method->setAccessible(true);

        return $method->invoke($pdf);
    }

    public function test_allowed_remote_hosts_defaults_to_application_host(): void
    {
        $hosts = $this->invokeAllowedHosts(new Pdf());

        $appHost = parse_url((string) url(''), PHP_URL_HOST);

        $this->assertIsArray($hosts);
        $this->assertContains(strtolower((string) $appHost), $hosts);
        // The internal metadata host must never be implicitly allowed.
        $this->assertNotContains('169.254.169.254', $hosts);
    }

    public function test_filter_can_opt_out_of_allow_list(): void
    {
        add_filter('core_base_pdf_allowed_remote_hosts', fn () => null);

        $this->assertNull($this->invokeAllowedHosts(new Pdf()));
    }

    public function test_filter_can_customise_allow_list(): void
    {
        add_filter('core_base_pdf_allowed_remote_hosts', fn () => ['cdn.example.com']);

        $this->assertSame(['cdn.example.com'], $this->invokeAllowedHosts(new Pdf()));
    }

    public function test_malformed_filter_return_fails_closed(): void
    {
        $appHost = strtolower((string) parse_url((string) url(''), PHP_URL_HOST));

        // A filter returning a non-array, non-null value must not disable the allow-list.
        add_filter('core_base_pdf_allowed_remote_hosts', fn () => 'evil-string');
        $this->assertContains($appHost, (array) $this->invokeAllowedHosts(new Pdf()));
        $this->assertNotContains('evil-string', (array) $this->invokeAllowedHosts(new Pdf()));
        remove_filter('core_base_pdf_allowed_remote_hosts');

        // Non-string entries are dropped; hosts are lowercased.
        add_filter('core_base_pdf_allowed_remote_hosts', fn () => ['CDN.Example.com', null, 123]);
        $this->assertSame(['cdn.example.com'], $this->invokeAllowedHosts(new Pdf()));
    }

    public function test_remote_flag_is_filterable(): void
    {
        $method = new ReflectionMethod(Pdf::class, 'isRemoteEnabled');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(new Pdf()));

        add_filter('core_base_pdf_is_remote_enabled', fn () => false);

        $this->assertFalse($method->invoke(new Pdf()));
    }

    public function test_compiled_dompdf_blocks_internal_host_but_keeps_own_host(): void
    {
        if (! extension_loaded('libxml')) {
            $this->markTestSkipped('libxml is required for the DomPDF path.');
        }

        $templatePath = storage_path('app/pdf-ssrf-test-' . uniqid() . '.tpl');
        File::put(
            $templatePath,
            '<html><body><img src="http://169.254.169.254/latest/meta-data/"><p>{{ name }}</p></body></html>'
        );

        try {
            $pdf = (new Pdf())
                ->templatePath($templatePath)
                ->data(['name' => 'Invoice'])
                ->compile();

            $options = $pdf->getDomPDF()->getOptions();

            $this->assertTrue($options->getIsRemoteEnabled());

            $allowed = $options->getAllowedRemoteHosts();
            $this->assertIsArray($allowed);
            $this->assertNotEmpty($allowed);
            // The internal metadata host is not in the allow-list => SSRF fetch is refused.
            $this->assertNotContains('169.254.169.254', $allowed);
            $this->assertContains(strtolower((string) parse_url((string) url(''), PHP_URL_HOST)), $allowed);
        } finally {
            File::delete($templatePath);
        }
    }

    protected function invokeMpdfContainer(Pdf $pdf): ?ServiceContainer
    {
        $method = new ReflectionMethod($pdf, 'buildMpdfSecurityContainer');
        $method->setAccessible(true);

        return $method->invoke($pdf);
    }

    public function test_mpdf_container_overrides_http_client_by_default(): void
    {
        $container = $this->invokeMpdfContainer(new Pdf());

        $this->assertInstanceOf(ServiceContainer::class, $container);
        $this->assertTrue($container->has('httpClient'));
    }

    public function test_mpdf_container_is_null_when_allow_list_is_opted_out(): void
    {
        add_filter('core_base_pdf_allowed_remote_hosts', fn () => null);

        // Remote still enabled + no allow-list => mPDF keeps its own client (no restriction).
        $this->assertNull($this->invokeMpdfContainer(new Pdf()));
    }

    public function test_mpdf_container_restricts_when_remote_disabled(): void
    {
        add_filter('core_base_pdf_allowed_remote_hosts', fn () => null);
        add_filter('core_base_pdf_is_remote_enabled', fn () => false);

        // Remote disabled must still inject the guard (to block every host), even with no list.
        $this->assertInstanceOf(ServiceContainer::class, $this->invokeMpdfContainer(new Pdf()));
    }

    public function test_mpdf_http_client_blocks_disallowed_host(): void
    {
        $client = new HostRestrictedHttpClient(new SocketHttpClient(new NullLogger()), ['botble.com']);

        $response = $client->sendRequest(new Request('GET', 'http://169.254.169.254/latest/meta-data/'));

        // 403 (not an exception) => mPDF skips the asset and still renders the document.
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_mpdf_http_client_blocks_all_hosts_when_list_empty(): void
    {
        $client = new HostRestrictedHttpClient(new SocketHttpClient(new NullLogger()), []);

        $this->assertSame(
            403,
            $client->sendRequest(new Request('GET', 'https://botble.com/storage/general/logo.png'))->getStatusCode()
        );
    }

    public function test_mpdf_http_client_fetches_allowed_host(): void
    {
        $inner = new RecordingHttpClient();
        $client = new HostRestrictedHttpClient($inner, ['botble.com']);

        $response = $client->sendRequest(new Request('GET', 'https://botble.com/storage/general/logo.png'));

        // Allowed host is delegated to the real client and returns its response.
        $this->assertSame(['https://botble.com/storage/general/logo.png'], $inner->fetched);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('IMG', $response->getBody()->getContents());
    }

    public function test_disallowed_host_is_not_delegated_to_inner_client(): void
    {
        $inner = new RecordingHttpClient();
        $client = new HostRestrictedHttpClient($inner, ['botble.com']);

        $response = $client->sendRequest(new Request('GET', 'http://169.254.169.254/latest/meta-data/'));

        $this->assertSame([], $inner->fetched);
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Characterization test: host matching intentionally ignores the port. An app-host URL on a
     * non-standard port is currently permitted - a documented residual (code-review finding #2).
     * Port-blocking is deliberately not enforced because it would break legitimate non-standard
     * port storage such as MinIO on :9000. This test locks in the behaviour so any future change
     * is intentional; SSRF to internal ports is mitigated at the network layer.
     */
    public function test_host_allow_list_ignores_port_documented_residual(): void
    {
        $inner = new RecordingHttpClient();
        $client = new HostRestrictedHttpClient($inner, ['botble.com']);

        $response = $client->sendRequest(new Request('GET', 'https://botble.com:6379/'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['https://botble.com:6379/'], $inner->fetched);
    }

    protected function captureBlockedHostLog(string $content): array
    {
        // Pin the allow-list instead of relying on the app host: APP_URL differs between local
        // (cms.test) and CI (localhost), which would otherwise flip these assertions.
        add_filter('core_base_pdf_allowed_remote_hosts', fn () => ['botble.com']);

        $messages = [];
        Log::listen(function ($event) use (&$messages): void {
            $messages[] = $event->message;
        });

        $method = new ReflectionMethod(Pdf::class, 'logBlockedRemoteHosts');
        $method->setAccessible(true);
        $method->invoke(new Pdf(), $content);

        return $messages;
    }

    public function test_blocked_remote_hosts_are_logged(): void
    {
        $messages = $this->captureBlockedHostLog(
            '<img src="https://botble.com/storage/general/logo.png"><img src="http://169.254.169.254/x">'
            . '<link href="https://evil-cdn.example.com/a.css">'
        );

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('169.254.169.254', $messages[0]);
        $this->assertStringContainsString('evil-cdn.example.com', $messages[0]);
        // The allowed host must not be reported.
        $this->assertStringNotContainsString('botble.com', $messages[0]);
    }

    public function test_no_log_when_all_hosts_allowed(): void
    {
        $this->assertCount(
            0,
            $this->captureBlockedHostLog('<img src="https://botble.com/storage/general/logo.png">')
        );
    }
}

/**
 * Test double that records the URLs it is asked to fetch, so tests can assert whether the
 * host-restricted client delegated a request or refused it.
 */
class RecordingHttpClient implements ClientInterface
{
    /** @var array<int, string> */
    public array $fetched = [];

    public function sendRequest(RequestInterface $request)
    {
        $this->fetched[] = (string) $request->getUri();

        return new Response(200, [], 'IMG');
    }
}
