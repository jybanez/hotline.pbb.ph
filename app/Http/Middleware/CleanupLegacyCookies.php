<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CleanupLegacyCookies
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->cookies->has('pbb-hotline-beta-session')) {
            $this->expireHostOnlyCookie($response, 'pbb-hotline-beta-session', true);
        }

        if ($request->cookies->has('pbb_maestro_session')) {
            $this->expireHostOnlyCookie($response, 'pbb_maestro_session', true);
            $this->expireDomainCookie($response, 'pbb_maestro_session', 'hotline.pbb.ph', true);
        }

        if ($request->cookies->has('pbb_hotline_session')) {
            $this->expireDomainCookie($response, 'pbb_hotline_session', 'hotline.pbb.ph', true);
            $this->expireDomainCookie($response, 'pbb_hotline_session', '.pbb.ph', true);
            $this->expireDomainCookie($response, 'pbb_hotline_session', 'pbb.ph', true);
        }

        if ($request->cookies->has('XSRF-TOKEN')) {
            $this->expireDomainCookie($response, 'XSRF-TOKEN', 'hotline.pbb.ph', false);
            $this->expireDomainCookie($response, 'XSRF-TOKEN', '.pbb.ph', false);
            $this->expireDomainCookie($response, 'XSRF-TOKEN', 'pbb.ph', false);
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
