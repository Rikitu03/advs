<x-layouts.auth>
    <div
        class="flex flex-col gap-7"
        x-data="mfaChallenge(@js((int) session('email_otp.pending.sent_at', now()->timestamp)), @js(\App\Services\EmailOtpService::RESEND_COOLDOWN_SECONDS))"
        x-init="startCooldown()"
    >
        <x-auth-header :title="__('Verify your sign-in')" :description="__('Enter the six-digit code we sent to your email address. It expires in 10 minutes.')" />

        <x-auth-session-status class="text-center" :status="session('status')" />
        <flux:error name="email" />

        <form method="POST" action="{{ route('mfa-challenge.verify') }}" class="flex flex-col gap-5" x-on:submit="submitting = true">
            @csrf

            <flux:input
                name="code"
                :label="__('Email verification code')"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                pattern="[0-9]{6}"
                autofocus
                required
            />

            <flux:button type="submit" variant="primary" x-bind:disabled="submitting">
                <span x-show="! submitting">{{ __('Verify and continue') }}</span>
                <span x-cloak x-show="submitting" class="inline-flex items-center gap-2">
                    <flux:icon icon="arrow-path" variant="micro" class="size-4 shrink-0 animate-spin" />
                    {{ __('Verifying...') }}
                </span>
            </flux:button>
        </form>

        <form method="POST" action="{{ route('mfa-challenge.resend') }}" class="text-center">
            @csrf

            <flux:button type="submit" variant="ghost" x-bind:disabled="cooldownRemaining > 0">
                <span x-text="resendLabel()">{{ __('Send a new code') }}</span>
            </flux:button>
        </form>

        <flux:button type="button" variant="ghost" x-on:click="verifyPasskey" x-bind:disabled="passkeyPending">
            <span x-show="! passkeyPending">{{ __('Use a passkey instead') }}</span>
            <span x-cloak x-show="passkeyPending" class="inline-flex items-center gap-2">
                <flux:icon icon="arrow-path" variant="micro" class="size-4 shrink-0 animate-spin" />
                {{ __('Waiting for passkey...') }}
            </span>
        </flux:button>

        <a href="{{ route('login') }}" class="text-center text-sm text-cu-muted hover:text-cu-text">{{ __('Return to sign in') }}</a>
    </div>
</x-layouts.auth>

<script>
    window.mfaChallenge = window.mfaChallenge || function (sentAt, cooldownSeconds) {
        return {
            submitting: false,
            passkeyPending: false,
            cooldownRemaining: 0,
            cooldownTimer: null,

            startCooldown() {
                const update = () => {
                    const elapsed = Math.floor(Date.now() / 1000) - sentAt;
                    this.cooldownRemaining = Math.max(0, cooldownSeconds - elapsed);

                    if (this.cooldownRemaining === 0 && this.cooldownTimer !== null) {
                        window.clearInterval(this.cooldownTimer);
                        this.cooldownTimer = null;
                    }
                };

                update();

                if (this.cooldownRemaining > 0) {
                    this.cooldownTimer = window.setInterval(update, 250);
                }
            },

            resendLabel() {
                if (this.cooldownRemaining === 0) {
                    return @js(__('Send a new code'));
                }

                const minutes = Math.floor(this.cooldownRemaining / 60);
                const seconds = String(this.cooldownRemaining % 60).padStart(2, '0');

                return `${@js(__('Send a new code'))} (${minutes}:${seconds})`;
            },

            async verifyPasskey() {
                if (!window.PublicKeyCredential) {
                    window.Flux?.toast({ heading: 'Passkeys unavailable', text: 'This browser does not support passkeys.', variant: 'danger' });
                    return;
                }

                const allowedOrigins = @json(config('passkeys.allowed_origins'));
                if (!allowedOrigins.includes(window.location.origin)) {
                    window.Flux?.toast({ heading: 'Passkey origin mismatch', text: `Open this app at ${allowedOrigins[0] || 'the configured application URL'} before using a passkey.`, variant: 'danger' });
                    return;
                }

                this.passkeyPending = true;

                try {
                    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                    if (!csrf) {
                        throw new Error('Missing CSRF token. Refresh the page and try again.');
                    }

                    const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf };
                    const optionsResponse = await fetch('{{ route('passkey.login-options') }}', { headers, credentials: 'same-origin' });
                    if (!optionsResponse.ok) {
                        throw new Error(`Options request failed (HTTP ${optionsResponse.status}).`);
                    }

                    const payload = await optionsResponse.json();
                    const options = payload?.options;
                    if (!options?.challenge) {
                        throw new Error('The server returned incomplete passkey login options.');
                    }

                    const decode = (value) => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - value.length % 4) % 4)), c => c.charCodeAt(0));
                    options.challenge = decode(options.challenge);
                    (options.allowCredentials || []).forEach(item => item.id = decode(item.id));

                    let credential;
                    try {
                        credential = await navigator.credentials.get({ publicKey: options });
                    } catch (error) {
                        console.error('[passkey]', error);
                        const detail = `${error?.name || 'Error'}: ${error?.message || 'Unknown error'}`;
                        const hint = error?.name === 'NotAllowedError'
                            ? 'No passkey was selected or this browser has no matching passkey.'
                            : 'Please try again or use the email code.';
                        window.Flux?.toast({ heading: 'Passkey verification failed', text: `${hint} (${detail})`, variant: 'danger' });
                        return;
                    }

                    if (!credential) {
                        throw new Error('The authenticator did not return a credential.');
                    }

                    const encode = (buffer) => {
                        const bytes = new Uint8Array(buffer);
                        let binary = '';

                        for (const byte of bytes) {
                            binary += String.fromCharCode(byte);
                        }

                        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
                    };
                    const response = await fetch('{{ route('passkey.login') }}', {
                        method: 'POST',
                        headers: { ...headers, 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ credential: { id: credential.id, rawId: encode(credential.rawId), type: credential.type, response: { clientDataJSON: encode(credential.response.clientDataJSON), authenticatorData: encode(credential.response.authenticatorData), signature: encode(credential.response.signature), userHandle: credential.response.userHandle ? encode(credential.response.userHandle) : null } } })
                    });

                    if (!response.ok) {
                        const result = await response.json().catch(() => ({}));
                        throw new Error(result?.message || `Credential verification failed (HTTP ${response.status}).`);
                    }

                    const result = await response.json();
                    window.location.assign(result.redirect || '{{ route('dashboard') }}');
                } catch (error) {
                    console.error('[passkey]', error);
                    const detail = `${error?.name || 'Error'}: ${error?.message || 'Unknown error'}`;
                    window.Flux?.toast({ heading: 'Passkey verification failed', text: `Please use the email code instead (${detail}).`, variant: 'danger' });
                } finally {
                    this.passkeyPending = false;
                }
            }
        };
    };
</script>
