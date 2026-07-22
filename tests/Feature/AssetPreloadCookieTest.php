<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "preload once" cookie (assets_warm) lets a visitor's first page load emit
 * Vite preload hints, then suppresses them for returning visitors whose browser
 * already cached the assets. The preload tags themselves only exist in a real
 * build, so these tests cover the deterministic plumbing: the cookie-setter
 * script renders on a cold visit and is gated off once the cookie is present.
 */
class AssetPreloadCookieTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_visit_renders_the_assets_warm_cookie_setter(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('assets_warm', false);
    }

    public function test_returning_visit_suppresses_the_cookie_setter(): void
    {
        // withUnencryptedCookie proves the exemption too: a non-exempt plaintext
        // cookie would be discarded by EncryptCookies and the setter would still
        // render, failing this assertion.
        $this->withUnencryptedCookie('assets_warm', '1')
            ->get('/login')
            ->assertOk()
            ->assertDontSee('assets_warm', false);
    }
}
