<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SignatureEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a structurally valid PNG of the given dimensions without GD
     * (only the signature + IHDR header are read by the validators).
     */
    private function fakePng(string $name, int $width = 900, int $height = 1200): UploadedFile
    {
        $ihdrData = pack('NN', $width, $height)."\x08\x02\x00\x00\x00";
        $ihdr = pack('N', strlen($ihdrData)).'IHDR'.$ihdrData.pack('N', crc32('IHDR'.$ihdrData));

        $idatData = function_exists('gzcompress') ? gzcompress("\x00") : "\x00";
        $idat = pack('N', strlen($idatData)).'IDAT'.$idatData.pack('N', crc32('IDAT'.$idatData));

        $iend = pack('N', 0).'IEND'.pack('N', crc32('IEND'));

        $png = "\x89PNG\r\n\x1a\n".$ihdr.$idat.$iend;

        return UploadedFile::fake()->createWithContent($name, $png);
    }

    public function test_signature_page_renders_for_an_unenrolled_vendor(): void
    {
        $user = User::factory()->unenrolled()->unverified()->create();

        $this->actingAs($user)
            ->get(route('signature.create'))
            ->assertOk()
            ->assertSee('Enroll your signature');
    }

    public function test_unenrolled_vendor_is_gated_to_the_signature_step(): void
    {
        $user = User::factory()->unenrolled()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('signature.create'));
        $this->actingAs($user)->get(route('vendor.dashboard'))->assertRedirect(route('signature.create'));
    }

    public function test_livewire_endpoints_are_never_gated_for_unenrolled_vendors(): void
    {
        $user = User::factory()->unenrolled()->unverified()->create();

        // Livewire v4 names its component-update route "default-livewire.update".
        // If the enrollment gate redirects it, the AJAX call receives an HTML
        // page instead of JSON and the signature page breaks silently.
        $response = $this->actingAs($user)->post(route('default-livewire.update'));

        $this->assertNotSame(
            route('signature.create'),
            $response->headers->get('Location'),
            'Livewire update requests must not be redirected by the signature-enrollment gate.'
        );
    }

    public function test_enrolled_vendor_is_not_gated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('vendor.dashboard'))->assertOk();
    }

    public function test_officers_and_admins_are_never_gated(): void
    {
        $officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->unenrolled()->create();
        $admin = User::factory()->role(User::ROLE_ADMIN)->unenrolled()->create();

        $this->actingAs($officer)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_vendor_can_enroll_an_authentic_signature_photo(): void
    {
        Storage::fake('local');
        Notification::fake();

        $user = User::factory()->unenrolled()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.signature-enroll')
            ->set('photo', $this->fakePng('signatures.png'))
            ->call('enroll')
            ->assertHasNoErrors()
            ->assertRedirect(route('verification.notice'));

        $this->assertSame('signature-enrolled', session('status'));

        $user->refresh();

        $this->assertNotNull($user->signature_enrolled_at);
        $this->assertNotNull($user->signature_path);
        Storage::disk('local')->assertExists($user->signature_path);
        $this->assertStringStartsWith("signatures/{$user->id}/", $user->signature_path);

        // Email verification is the final step: enrolling the signature is what
        // triggers the verification link (it is not sent at registration).
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_software_edited_photo_is_rejected_and_not_enrolled(): void
    {
        Storage::fake('local');

        $user = User::factory()->unenrolled()->unverified()->create();

        $component = Volt::actingAs($user)
            ->test('auth.signature-enroll')
            ->set('photo', $this->fakePng('signature-edited.png'))
            ->call('enroll')
            ->assertSet('rejected', true)
            ->assertDispatched('toast-show')
            ->assertSee('We could not verify this image');

        $this->assertNotEmpty($component->get('reasons'));

        $user->refresh();
        $this->assertNull($user->signature_enrolled_at);
        $this->assertNull($user->signature_path);
    }

    public function test_low_resolution_photo_fails_validation(): void
    {
        Storage::fake('local');

        $user = User::factory()->unenrolled()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.signature-enroll')
            ->set('photo', $this->fakePng('signatures.png', 300, 300))
            ->assertHasErrors(['photo'])
            ->assertDispatched('toast-show')
            ->assertSet('photo', null)
            ->call('enroll')
            ->assertHasErrors(['photo']);

        $this->assertNull($user->refresh()->signature_enrolled_at);
    }

    public function test_non_image_upload_fails_validation(): void
    {
        Storage::fake('local');

        $user = User::factory()->unenrolled()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.signature-enroll')
            ->set('photo', UploadedFile::fake()->createWithContent('document.pdf', '%PDF-1.4 fake'))
            ->assertHasErrors(['photo'])
            ->assertDispatched('toast-show')
            ->call('enroll')
            ->assertHasErrors(['photo']);
    }

    public function test_guests_cannot_access_the_signature_step(): void
    {
        $this->get(route('signature.create'))->assertRedirect('/login');
    }
}
