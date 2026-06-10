<?php

namespace Tests\Unit;

use App\Http\Middleware\CleanupLegacyCookies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class CleanupLegacyCookiesTest extends TestCase
{
    public function test_legacy_hotline_session_and_xsrf_domain_cookies_are_expired(): void
    {
        config([
            'app.url' => 'https://hotline.pbb.ph',
            'session.domain' => null,
        ]);

        $request = Request::create('https://hotline.pbb.ph/command');
        $request->cookies->set('pbb_hotline_session', 'old-session');
        $request->cookies->set('XSRF-TOKEN', 'old-xsrf');

        $response = (new CleanupLegacyCookies)->handle(
            $request,
            fn () => new Response('ok'),
        );

        $cookies = $response->headers->getCookies();
        $cookieSignatures = array_map(
            fn ($cookie) => [$cookie->getName(), $cookie->getDomain(), $cookie->isHttpOnly()],
            $cookies
        );

        $this->assertContains(['pbb_hotline_session', 'hotline.pbb.ph', true], $cookieSignatures);
        $this->assertContains(['pbb_hotline_session', 'pbb.ph', true], $cookieSignatures);
        $this->assertContains(['pbb_hotline_session', '.pbb.ph', true], $cookieSignatures);
        $this->assertContains(['XSRF-TOKEN', 'hotline.pbb.ph', false], $cookieSignatures);
        $this->assertContains(['XSRF-TOKEN', 'pbb.ph', false], $cookieSignatures);
        $this->assertContains(['XSRF-TOKEN', '.pbb.ph', false], $cookieSignatures);
        $this->assertNotContains(['pbb_hotline_session', '', true], $cookieSignatures);
        $this->assertNotContains(['XSRF-TOKEN', '', false], $cookieSignatures);
    }
}
