<?php

namespace Tests\Unit;

use App\Support\Http\SessionCookieDomain;
use PHPUnit\Framework\TestCase;

class SessionCookieDomainTest extends TestCase
{
    public function test_app_host_session_domain_is_normalized_to_host_only(): void
    {
        $this->assertNull(SessionCookieDomain::normalize('hotline.pbb.ph', 'https://hotline.pbb.ph'));
    }

    public function test_parent_session_domain_is_normalized_to_host_only(): void
    {
        $this->assertNull(SessionCookieDomain::normalize('.pbb.ph', 'https://hotline.pbb.ph'));
        $this->assertNull(SessionCookieDomain::normalize('pbb.ph', 'https://hotline.pbb.ph'));
    }

    public function test_unrelated_and_stale_session_domains_are_normalized_to_host_only(): void
    {
        $this->assertNull(SessionCookieDomain::normalize('apas-cebu-cebu-hotline.pbb.ph', 'https://hotline.pbb.ph'));
        $this->assertNull(SessionCookieDomain::normalize('sessions.example.net', 'https://hotline.pbb.ph'));
    }

    public function test_legacy_domains_include_app_host_and_parent_variants(): void
    {
        $this->assertSame(
            ['hotline.pbb.ph', 'pbb.ph', '.pbb.ph'],
            SessionCookieDomain::legacyDomains('https://hotline.pbb.ph')
        );
    }
}
