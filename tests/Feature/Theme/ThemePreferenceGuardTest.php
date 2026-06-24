<?php

namespace Tests\Feature\Theme;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dark theme must survive `wire:navigate` transitions across every role's
 * dashboard. The fix is a server-side guard: each authenticated layout reads
 * the client-set `theme` cookie and renders `class="dark"` on <html> directly,
 * so every server response (initial load AND each navigate fetch) carries the
 * correct theme instead of relying on a head script that only runs once.
 *
 * These assert rendered markup; the cookie write/`system` OS detection are
 * client-only and covered by the head-runtime assertion (no JS runner exists).
 */
class ThemePreferenceGuardTest extends TestCase
{
    use RefreshDatabase;

    private const HTML_DARK = '/<html[^>]*\bclass="[^"]*\bdark\b/';

    public function test_vendor_dashboard_html_is_dark_when_theme_cookie_is_dark(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_VENDOR]);

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('theme', 'dark')
            ->get(route('vendor.dashboard'));

        $response->assertOk();
        $this->assertMatchesRegularExpression(self::HTML_DARK, $response->getContent());
    }

    public function test_admin_dashboard_html_is_dark_when_theme_cookie_is_dark(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_COMPLIANCE_OFFICER]);

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('theme', 'dark')
            ->get(route('admin.dashboard'));

        $response->assertOk();
        $this->assertMatchesRegularExpression(self::HTML_DARK, $response->getContent());
    }

    public function test_preference_page_html_is_dark_when_theme_cookie_is_dark(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('theme', 'dark')
            ->get(route('settings.preference'));

        $response->assertOk();
        $this->assertMatchesRegularExpression(self::HTML_DARK, $response->getContent());
    }

    public function test_dashboard_html_is_not_dark_when_theme_cookie_is_light(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_VENDOR]);

        $response = $this->actingAs($user)
            ->withCookie('theme', 'light')
            ->get(route('vendor.dashboard'));

        $response->assertOk();
        $this->assertDoesNotMatchRegularExpression(self::HTML_DARK, $response->getContent());
    }

    public function test_dashboard_html_is_not_dark_when_no_theme_cookie_is_set(): void
    {
        // `system` (the default when absent) is resolved client-side from the OS
        // setting, so the server must not force a class either way.
        $user = User::factory()->create(['role' => User::ROLE_VENDOR]);

        $response = $this->actingAs($user)->get(route('vendor.dashboard'));

        $response->assertOk();
        $this->assertDoesNotMatchRegularExpression(self::HTML_DARK, $response->getContent());
    }

    public function test_profile_menu_name_and_email_are_theme_responsive(): void
    {
        // The name/email are raw content injected into a Flux menu, so they do
        // not inherit Flux's item-level `dark:text-white` and must carry their
        // own flipping tokens (white in dark, dark in light) to stay legible.
        // Tie the tokens to the exact name/email spans so the assertion can't
        // pass off unrelated dashboard markup that happens to use the tokens.
        $user = User::factory()->create([
            'role' => User::ROLE_VENDOR,
            'name' => 'Theme Probe',
            'email' => 'theme-probe@advs.test',
        ]);

        $this->actingAs($user)
            ->get(route('vendor.dashboard'))
            ->assertOk()
            ->assertSee('<span class="truncate font-semibold text-cu-text">Theme Probe</span>', false)
            ->assertSee('<span class="truncate text-xs text-cu-muted">theme-probe@advs.test</span>', false);
    }

    public function test_theme_runtime_reapplies_on_livewire_navigation(): void
    {
        // Covers `system` mode (server can't know the OS) and acts as defense in
        // depth: re-assert the cookie theme after every SPA navigation so the
        // .dark class is never lost when wire:navigate swaps in a fresh <html>.
        $head = (string) file_get_contents(resource_path('views/partials/head.blade.php'));

        $this->assertStringContainsString('livewire:navigated', $head);
    }
}
