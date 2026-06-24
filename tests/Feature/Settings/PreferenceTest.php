<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preference_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.preference'))
            ->assertOk()
            ->assertSee('Preference')
            ->assertSee('Interface theme')
            ->assertSee('Light')
            ->assertSee('Dark')
            ->assertSee('System');
    }

    public function test_preference_page_is_linked_in_settings_navigation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.profile'))
            ->assertOk()
            ->assertSee(route('settings.preference'), false);
    }

    public function test_guests_cannot_access_the_preference_page(): void
    {
        $this->get(route('settings.preference'))
            ->assertRedirect(route('login'));
    }

    public function test_preference_toggle_is_wired_to_the_cookie_runtime(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.preference'))
            ->assertOk()
            ->assertSee('advsTheme.set', false)
            ->assertDontSee('$flux.appearance', false);
    }

    public function test_appearance_page_is_wired_to_the_cookie_runtime(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.appearance'))
            ->assertOk()
            ->assertSee('advsTheme.set', false)
            ->assertDontSee('$flux.appearance', false);
    }
}
