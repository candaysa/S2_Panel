<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // /api/install/status is used below purely as a lightweight, always
        // -public GET endpoint to inspect response headers - it has nothing
        // to do with the installer itself. That route is only reachable
        // while the panel is not yet installed (InstallLock), so this suite
        // must override phpunit.xml's global INSTALLED=true.
        config()->set('app.installed', false);
    }

    public function test_api_responses_carry_security_headers(): void
    {
        $this->getJson('/api/install/status')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), interest-cohort=()')
            ->assertHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
    }

    public function test_no_hsts_header_over_plain_http(): void
    {
        $this->getJson('/api/install/status')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_header_when_request_is_https(): void
    {
        $this->withHeader('X-Forwarded-Proto', 'https')
            ->getJson('/api/install/status')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_html_responses_carry_security_headers(): void
    {
        // Unlike the other tests in this class, "/" is not an install route:
        // while uninstalled InstallLock redirects it to /install (302), so
        // this one test needs the panel marked as installed to get past
        // InstallLock. "/" itself always redirects (guest -> /login), so
        // assert headers on the login page instead, which renders HTML.
        config()->set('app.installed', true);

        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }
}