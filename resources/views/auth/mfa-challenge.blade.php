<x-layouts.auth>
    <div
        class="flex flex-col gap-7"
        x-data="mfaChallenge(@js((int) session('email_otp.pending.sent_at', now()->timestamp)), @js(\App\Services\EmailOtpService::RESEND_COOLDOWN_SECONDS), @js((string) old('code', '')))"
        x-init="startCooldown()"
    >
        <x-auth-header :title="__('Verify your sign-in')" :description="__('Enter the six-digit code we sent to your email address. It expires in 10 minutes.')" />

        <x-auth-session-status class="text-center" :status="session('status')" />
        <flux:error name="email" />
        <flux:error name="code" />

        <form method="POST" action="{{ route('mfa-challenge.verify') }}" class="flex flex-col gap-5" x-on:submit="onSubmit($event)">
            @csrf

            <input type="hidden" name="code" x-ref="code" />

            <div class="grid gap-3">
                @php($oldCode = preg_replace('/\D/', '', (string) old('code', '')))

                <div class="grid w-full grid-cols-6 gap-3">
                    @for ($i = 0; $i < 6; $i++)
                        <input
                            id="code-{{ $i }}"
                            data-flux-control
                            x-ref="code-{{ $i }}"
                            :value="digits[{{ $i }}]"
                            value="{{ $oldCode[$i] ?? '' }}"
                            @input="onDigitInput($event, {{ $i }})"
                            @keydown="onDigitKeydown($event, {{ $i }})"
                            @paste.prevent="onCodePaste($event, {{ $i }})"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            pattern="[0-9]*"
                            enterkeyhint="done"
                            maxlength="1"
                            aria-label="{{ __('Code digit :number', ['number' => $i + 1]) }}"
                            {{ $i === 0 ? 'autofocus' : '' }}
                            class="h-14 min-w-0 w-full rounded-xl border border-cu-border bg-cu-surface text-center font-jakarta text-2xl font-semibold text-cu-text shadow-xs transition focus:border-cu-text focus:ring-2 focus:ring-cu-text/15 sm:h-16"
                        />
                    @endfor
                </div>
            </div>

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

            <flux:button
                type="submit"
                variant="ghost"
                :loading="false"
                x-bind:disabled="cooldownRemaining > 0"
                x-bind:class="{ 'cursor-not-allowed opacity-40': cooldownRemaining > 0 }"
            >
                <span aria-live="polite" x-text="resendLabel()">{{ __('Resend Code') }}</span>
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
    window.mfaChallenge = window.mfaChallenge || function (sentAt, cooldownSeconds, oldCode) {
        return {
            submitting: false,
            passkeyPending: false,
            cooldownRemaining: 0,
            cooldownTimer: null,
            digits: Array.from({ length: 6 }, (_, index) => {
                const digit = String(oldCode || '')[index];

                return digit !== undefined && /[0-9]/.test(digit) ? digit : '';
            }),

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
                    return @js(__('Resend Code'));
                }

                const minutes = Math.floor(this.cooldownRemaining / 60);
                const seconds = String(this.cooldownRemaining % 60).padStart(2, '0');

                return `${@js(__('Resend Code'))} (${minutes}:${seconds})`;
            },

            focusDigit(index) {
                if (index < 0 || index > 5) {
                    return;
                }

                this.$refs[`code-${index}`]?.focus();
            },

            code() {
                return this.digits.join('');
            },

            onDigitInput(event, index) {
                const value = (event.target.value || '').replace(/\D/g, '');

                if (value.length > 1) {
                    // Multi-digit entry (paste or mobile autofill) fills forward from this box.
                    let cursor = index;

                    for (const digit of value) {
                        if (cursor > 5) {
                            break;
                        }

                        this.digits[cursor] = digit;
                        cursor++;
                    }

                    event.target.value = this.digits[index] || '';
                    this.focusDigit(cursor);

                    return;
                }

                this.digits[index] = value;
                event.target.value = value;

                if (value !== '') {
                    this.focusDigit(index + 1);
                }
            },

            onDigitKeydown(event, index) {
                if (event.key === 'Backspace') {
                    if (this.digits[index] === '' && index > 0) {
                        event.preventDefault();
                        this.digits[index - 1] = '';
                        this.focusDigit(index - 1);
                    }
                } else if (event.key === 'Delete') {
                    if (this.digits[index] === '' && index < 5) {
                        event.preventDefault();
                        this.focusDigit(index + 1);
                    }
                } else if (event.key === 'ArrowLeft') {
                    event.preventDefault();
                    this.focusDigit(index - 1);
                } else if (event.key === 'ArrowRight') {
                    event.preventDefault();
                    this.focusDigit(index + 1);
                }
            },

            onCodePaste(event, index) {
                const clipboard = event.clipboardData || window.clipboardData;
                const value = ((clipboard && clipboard.getData('text')) || '').replace(/\D/g, '');

                if (value === '') {
                    return;
                }

                const start = index + value.length > 6 ? 0 : index;
                let cursor = start;

                for (const digit of value) {
                    if (cursor > 5) {
                        break;
                    }

                    this.digits[cursor] = digit;
                    cursor++;
                }

                this.focusDigit(cursor);
            },

            onSubmit(event) {
                if (!/^[0-9]{6}$/.test(this.code())) {
                    event.preventDefault();
                    const firstEmpty = this.digits.indexOf('');
                    this.focusDigit(firstEmpty === -1 ? 0 : firstEmpty);

                    return;
                }

                this.$refs.code.value = this.code();
                this.submitting = true;
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
