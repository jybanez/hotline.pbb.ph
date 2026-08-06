<?php

namespace App\Http\Middleware;

use App\Support\Http\SessionCookieDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CleanupLegacyCookies
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->cookies->has('pbb_maestro_session')) {
            $this->expireHostOnlyCookie($response, 'pbb_maestro_session', true);
            $this->expireDomainCookie($response, 'pbb_maestro_session', 'hotline.pbb.ph', true);
        }

        $activeSessionDomain = SessionCookieDomain::normalize(config('session.domain'), config('app.url'));
        $activeSessionCookie = (string) config('session.cookie', 'pbb-hotline-beta-session');
        $legacyDomains = SessionCookieDomain::legacyDomains(config('app.url'));

        if ($activeSessionCookie !== '' && $request->cookies->has($activeSessionCookie)) {
            foreach ($legacyDomains as $domain) {
                if ($domain === $activeSessionDomain) {
                    continue;
                }

                $this->expireDomainCookie($response, $activeSessionCookie, $domain, true);
            }
        }

        if ($request->cookies->has('pbb_hotline_session')) {
            $this->expireHostOnlyCookie($response, 'pbb_hotline_session', true);

            foreach ($legacyDomains as $domain) {
                if ($domain === $activeSessionDomain) {
                    continue;
                }

                $this->expireDomainCookie($response, 'pbb_hotline_session', $domain, true);
            }
        }

        if ($request->cookies->has('XSRF-TOKEN')) {
            foreach ($legacyDomains as $domain) {
                if ($domain === $activeSessionDomain) {
                    continue;
                }

                $this->expireDomainCookie($response, 'XSRF-TOKEN', $domain, false);
            }
        }

        return $response;
    }

    private function expireHostOnlyCookie(Response $response, string $name, bool $httpOnly): void
    {
        $response->headers->clearCookie($name, '/', null, true, $httpOnly, false, 'lax');
    }

    private function expireDomainCookie(Response $response, string $name, string $domain, bool $httpOnly): void
    {
        $response->headers->clearCookie($name, '/', $domain, true, $httpOnly, false, 'lax');
    }
}
