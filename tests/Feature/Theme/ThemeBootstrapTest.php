<?php

namespace Tests\Feature\Theme;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_head_renders_the_cookie_theme_runtime(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_VENDOR]);

        $response = $this->actingAs($user)->get(route('vendor.dashboard'));

        $response->assertOk()
            ->assertSee('window.advsTheme', false)
            ->assertSee('max-age=604800', false)
            ->assertSee('theme=', false);
    }

    public function test_flux_local_storage_appearance_directive_is_removed(): void
    {
        // @fluxAppearance injects Flux's localStorage-based applier, which we
        // replace with the cookie runtime. Its absence is asserted via the head partial.
        $head = (string) file_get_contents(resource_path('views/partials/head.blade.php'));

        $this->assertStringNotContainsString('@fluxAppearance', $head);
        $this->assertStringContainsString('window.advsTheme', $head);
    }
}
