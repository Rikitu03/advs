<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The available ADVS user roles (see CLAUDE.md §5).
     */
    public const ROLE_VENDOR = 'vendor';

    public const ROLE_COMPLIANCE_OFFICER = 'compliance_officer';

    public const ROLE_ADMIN = 'admin';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'signature_enrolled_at' => 'datetime',
        ];
    }

    /**
     * Send the email verification notification, degrading gracefully when the
     * mail transport (SMTP) is unavailable.
     *
     * Email verification is the FINAL step of vendor registration: the link must
     * not be sent until the vendor has enrolled a reference signature. This guard
     * suppresses the automatic send fired by the Registered event at account
     * creation — the signature-enrollment step triggers this method explicitly
     * once it succeeds (see resources/views/livewire/auth/signature-enroll).
     *
     * When the mail is sent it goes out synchronously, so a transport failure
     * (e.g. Gmail rejecting bad credentials with a 535) would otherwise crash the
     * request with a 500. Instead we log it and flash a toast so the user keeps
     * their account and can retry via "Resend".
     */
    public function sendEmailVerificationNotification(): void
    {
        if ($this->hasRole(self::ROLE_VENDOR) && ! $this->hasEnrolledSignature()) {
            return;
        }

        try {
            $this->notify(new VerifyEmail);
        } catch (TransportExceptionInterface $e) {
            report($e);

            session()->put('toast', [
                'variant' => 'danger',
                'heading' => __('Verification email not sent'),
                'text' => __('Your account was created, but the verification email could not be sent right now. Please try again shortly using the "Resend verification email" button.'),
            ]);
        }
    }

    /**
     * Whether the vendor has completed the signature-enrollment step of
     * registration (see the EnsureSignatureEnrolled middleware).
     */
    public function hasEnrolledSignature(): bool
    {
        return $this->signature_enrolled_at !== null;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }

    /**
     * Determine whether the user has any of the given roles.
     */
    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Resolve the named route the user should land on after authentication,
     * based on their role. Used by the /dashboard dispatcher.
     */
    public function dashboardRoute(): string
    {
        return match ($this->role) {
            self::ROLE_ADMIN, self::ROLE_COMPLIANCE_OFFICER => 'admin.dashboard',
            default => 'vendor.dashboard',
        };
    }
}
