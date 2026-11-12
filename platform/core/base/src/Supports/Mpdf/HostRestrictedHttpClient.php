<?php

namespace Botble\Base\Supports\Mpdf;

use Mpdf\Http\ClientInterface;
use Mpdf\PsrHttpMessageShim\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Decorates an mPDF HTTP client and refuses remote requests to hosts outside an allow-list.
 *
 * This prevents server-side request forgery when attacker-controlled data renders a remote
 * <img>/CSS URL into a PDF template (e.g. an internal service or cloud metadata endpoint at
 * 169.254.169.254). A blocked request returns a 403 response with an empty body instead of
 * throwing, so mPDF simply skips the resource and still produces the document.
 */
final class HostRestrictedHttpClient implements ClientInterface
{
    /**
     * @param array<int, string>|null $allowedHosts Lowercase hosts allowed for remote fetches.
     *     null allows every host; an empty array blocks every remote fetch.
     */
    public function __construct(
        protected ClientInterface $client,
        protected ?array $allowedHosts
    ) {
    }

    public function sendRequest(RequestInterface $request)
    {
        $host = strtolower((string) $request->getUri()->getHost());

        if ($this->allowedHosts !== null && ($host === '' || ! in_array($host, $this->allowedHosts, true))) {
            return new Response(403);
        }

        return $this->client->sendRequest($request);
    }
}
