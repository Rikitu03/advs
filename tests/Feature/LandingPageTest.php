<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_landing_page_renders_every_section(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Fake documents don&rsquo;t', escape: false)
            ->assertSee('works for you')
            ->assertSee('Smarter Vendor Accreditation powered by')
            ->assertSee('Five models read every document')
            ->assertSee('Not marketed.')
            ->assertSee('Stamp detection accuracy')
            ->assertSee('How it works?')
            ->assertSee('Core features')
            ->assertSee('Request a demo here.')
            ->assertSee('advs_support@gmail.com');
    }

    public function test_guests_are_offered_sign_in_and_registration(): void
    {
        $this->get(route('home'))
            ->assertSee('Sign in')
            ->assertSee('Register')
            ->assertSee(route('login'), escape: false)
            ->assertSee(route('register'), escape: false)
            ->assertDontSee('Dashboard');
    }

    public function test_authenticated_users_are_offered_their_dashboard_instead(): void
    {
        $user = User::factory()->role(User::ROLE_VENDOR)->create();

        $this->actingAs($user)->get(route('home'))
            ->assertSee('Dashboard')
            ->assertSee(route('dashboard'), escape: false)
            ->assertDontSee('Register');
    }

    /**
     * Every model in the roster is rendered, and each one carries a portrait slot.
     * Until an avatar path is set in config/landing.php the slot falls back to the
     * model's initials, so the section stays presentable rather than showing a
     * broken image.
     */
    public function test_the_model_roster_renders_a_portrait_slot_for_each_model(): void
    {
        $response = $this->get(route('home'))->assertOk();

        foreach (config('landing.models') as $model) {
            $response->assertSee($model['name']);
            $response->assertSee($model['initials']);
        }
    }

    public function test_a_configured_model_avatar_replaces_the_initials_placeholder(): void
    {
        config()->set('landing.models.resnet.avatar', 'images/models/resnet50.png');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee(asset('images/models/resnet50.png'), escape: false);
    }

    /**
     * The design is light: the contrast between the white page, the flame accent and
     * the near-black panels carries the whole identity, so the landing page must not
     * opt into the app's dark theme even for a visitor whose saved preference is dark.
     */
    public function test_the_landing_page_never_opts_into_the_dark_theme(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<html lang="en" class="dark"', $html);
        $this->assertStringNotContainsString('dark:', $html);
    }
}
