<?php

declare(strict_types=1);

namespace CorePanelTenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetTenantAwareSessionCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $defaultCookie = (string) config('session.cookie_base', config('session.cookie', 'laravel_session'));
        $host = $request->getHost();

        $suffix = $this->cookieSuffixFor($host);
        $cookieName = $defaultCookie.'_'.$suffix;

        config()->set('session.cookie_base', $defaultCookie);
        config()->set('session.cookie', $cookieName);

        if (app()->bound('session')) {
            app('session')->setName($cookieName);
        }

        if (app()->bound('session.store')) {
            app('session.store')->setName($cookieName);
        }

        return $next($request);
    }

    private function cookieSuffixFor(string $host): string
    {
        $centralDomains = array_values(array_filter(
            array_map(
                static fn (mixed $domain): ?string => is_string($domain) && $domain !== '' ? $domain : null,
                (array) config('tenancy.central_domains', []),
            ),
        ));

        if (in_array($host, $centralDomains, true)) {
            return 'central';
        }

        return trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $host), '_');
    }
}
