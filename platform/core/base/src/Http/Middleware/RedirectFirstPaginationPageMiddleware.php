<?php

namespace Botble\Base\Http\Middleware;

use Botble\Base\Facades\AdminHelper;
use Closure;
use Illuminate\Http\Request;

/**
 * Redirects `?page=1` to the same URL without the parameter.
 *
 * Laravel's paginator emits `?page=1` for the first-page link, so crawlers
 * discover a URL that is a byte-for-byte duplicate of the unparameterized one.
 * Both the canonical tag (SeoHelper MiscTags) and the hreflang set (Language
 * plugin) intentionally drop `page=1`, which leaves the `?page=1` variant with
 * no self-referencing hreflang - SEO auditors flag that as a conflict between
 * hreflang and rel=canonical.
 *
 * Removing the URL at the source is the fix: canonical and hreflang stay in
 * sync, and `?page=1` never enters the index to be flagged in the first place.
 */
class RedirectFirstPaginationPageMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (! $this->shouldRedirect($request)) {
            return $next($request);
        }

        $query = $request->query();
        unset($query['page']);

        return redirect()->to(
            $request->url() . ($query ? '?' . http_build_query($query) : ''),
            301
        );
    }

    protected function shouldRedirect(Request $request): bool
    {
        // Admin listings paginate over XHR and must keep their own page state.
        if (AdminHelper::isInAdmin(true)) {
            return false;
        }

        if (! $request->isMethod('GET') || $request->ajax() || $request->expectsJson()) {
            return false;
        }

        return $request->query('page') === '1';
    }
}
