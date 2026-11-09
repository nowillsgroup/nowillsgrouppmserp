<?php

namespace Tests\Feature;

use Tests\TestCase;

class SmokeTest extends TestCase
{
    public function test_login_page_renders_for_anonymous_users(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('password', false);
    }

    public function test_protected_pages_redirect_anonymous_users_to_login(): void
    {
        $response = $this->get('/reservations');

        $response->assertRedirect('/login');
    }

    public function test_security_headers_are_set(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("default-src 'self'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_unknown_urls_return_404_not_a_stack_trace(): void
    {
        $response = $this->get('/this-page-does-not-exist-anywhere');

        $response->assertNotFound();
    }
}
