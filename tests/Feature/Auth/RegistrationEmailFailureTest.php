<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class RegistrationEmailFailureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The verification email is the final registration step, sent only after
     * signature enrollment succeeds. A transport failure (e.g. Gmail rejecting
     * bad credentials with a 535) at that point must not 500 or discard the
     * just-enrolled signature: it is logged and surfaced as a danger toast so the
     * vendor can retry via "Resend verification email".
     */
    public function test_enrollment_completes_with_a_toast_when_the_smtp_transport_fails(): void
    {
        Storage::fake('local');
        $this->forceFailingMailTransport();

        $user = User::factory()->unenrolled()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.signature-enroll')
            ->set('photo', $this->fakePng('signatures.png'))
            ->call('enroll')
            ->assertHasNoErrors()
            ->assertRedirect(route('verification.notice'));

        // The signature is enrolled and kept despite the SMTP failure — the
        // enrollment step must not roll back when only the email send fails.
        $user->refresh();
        $this->assertNotNull($user->signature_enrolled_at);
        $this->assertNotNull($user->signature_path);

        // The failure is surfaced to the user as a danger toast.
        $this->assertNotNull(session('toast'));
        $this->assertSame('danger', session('toast.variant'));
    }

    /**
     * Build a structurally valid PNG of the given dimensions without GD (only the
     * IHDR header is read by the dimensions validator).
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

    /**
     * Swap the default mailer for a transport that fails like Gmail rejecting bad
     * credentials (535), exercising the real notification → mail path.
     */
    private function forceFailingMailTransport(): void
    {
        Mail::extend('failing', fn () => new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('535 Username and Password not accepted');
            }

            public function __toString(): string
            {
                return 'failing';
            }
        });

        config([
            'mail.default' => 'failing',
            'mail.mailers.failing' => ['transport' => 'failing'],
        ]);
    }
}
