<?php

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Volt\Component;

new class extends Component {
    public string $currentPassword = '';

    public function deletePasskey(int $passkeyId, DeletePasskey $deletePasskey): void
    {
        $this->confirmPassword();

        $passkey = Auth::user()->passkeys()->whereKey($passkeyId)->firstOrFail();
        $deletePasskey(Auth::user(), $passkey);
        session()->flash('status', 'passkey-deleted');
    }

    public function confirmPasswordForPasskey(): void
    {
        $this->confirmPassword();
    }

    public function getPasskeysProperty(): Collection
    {
        return Auth::user()->passkeys()->latest()->get();
    }

    private function confirmPassword(): void
    {
        $this->validate(['currentPassword' => ['required', 'current_password']]);
        Session::passwordConfirmed();
        $this->reset('currentPassword');
    }
}; ?>

<section class="mx-auto w-full max-w-5xl rounded-2xl border border-cu-border bg-cu-surface p-6 shadow-sm">
    @include('partials.settings-heading')

    <x-settings.layout heading="Security" subheading="Email verification codes protect every password login; a passkey can complete the same sign-in challenge.">
        <div class="grid gap-6">
            <flux:callout icon="shield-check" variant="success" inline>
                {{ __('A six-digit code is sent to your verified email address for every password login. There is no trusted-device or remember-me bypass.') }}
            </flux:callout>

            <div class="rounded-xl border border-cu-border p-5" x-data="securityPasskeys()">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="lg">Passkeys</flux:heading>
                        <flux:subheading>After entering your password, use a device biometric, PIN, or security key instead of the emailed code.</flux:subheading>
                    </div>
                    <flux:badge :color="$this->passkeys->isNotEmpty() ? 'green' : 'zinc'">{{ $this->passkeys->count() }} registered</flux:badge>
                </div>

                <div class="mt-5 flex flex-wrap items-end gap-3">
                    <flux:input x-model="name" label="Passkey name" placeholder="Work laptop" />
                    <flux:input wire:model="currentPassword" label="Current password" type="password" autocomplete="current-password" required />
                    <flux:button type="button" variant="primary" x-on:click="register">Register passkey</flux:button>
                </div>

                <div class="mt-5 grid gap-2">
                    @forelse ($this->passkeys as $passkey)
                        <div class="flex items-center justify-between gap-4 rounded-lg border border-cu-border px-3 py-2">
                            <div>
                                <div class="font-medium">{{ $passkey->name }}</div>
                                <div class="text-xs text-cu-muted">{{ $passkey->authenticator ?: 'Security key' }} · {{ $passkey->last_used_at?->diffForHumans() ?: 'Not used yet' }}</div>
                            </div>
                            <flux:button type="button" variant="ghost" wire:click="deletePasskey({{ $passkey->id }})">Remove</flux:button>
                        </div>
                    @empty
                        <p class="text-sm text-cu-muted">No passkeys are registered yet.</p>
                    @endforelse
                </div>
            </div>

            @if (session('status'))
                <p class="text-sm font-medium text-emerald-700 dark:text-emerald-300">{{ __('Security settings updated.') }}</p>
            @endif
        </div>
    </x-settings.layout>
</section>

@script
<script>
    window.securityPasskeys = function () {
        return {
            name: 'This device',
            async register() {
                if (!window.PublicKeyCredential) {
                    window.Flux?.toast({ heading: 'Passkeys unavailable', text: 'This browser does not support passkeys.', variant: 'danger' });
                    return;
                }

                const allowedOrigins = @json(config('passkeys.allowed_origins'));
                if (!allowedOrigins.includes(window.location.origin)) {
                    window.Flux?.toast({ heading: 'Passkey origin mismatch', text: `Open this app at ${allowedOrigins[0] || 'the configured application URL'} before registering a passkey.`, variant: 'danger' });
                    return;
                }

                try {
                    await this.$wire.confirmPasswordForPasskey();

                    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                    if (!csrf) {
                        throw new Error('Missing CSRF token. Refresh the page and try again.');
                    }

                    const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf };
                    const optionsResponse = await fetch('{{ route('passkey.registration-options') }}', { headers, credentials: 'same-origin' });
                    if (!optionsResponse.ok) {
                        throw new Error(`Options request failed (HTTP ${optionsResponse.status}).`);
                    }

                    const payload = await optionsResponse.json();
                    const options = payload?.options;
                    if (!options?.challenge || !options?.user?.id) {
                        throw new Error('The server returned incomplete passkey registration options.');
                    }

                    const decode = (value) => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - value.length % 4) % 4)), c => c.charCodeAt(0));
                    options.challenge = decode(options.challenge);
                    options.user.id = decode(options.user.id);
                    (options.excludeCredentials || []).forEach(item => item.id = decode(item.id));

                    let credential;
                    try {
                        credential = await navigator.credentials.create({ publicKey: options });
                    } catch (error) {
                        console.error('[passkey]', error);
                        const detail = `${error?.name || 'Error'}: ${error?.message || 'Unknown error'}`;
                        window.Flux?.toast({ heading: 'Passkey registration failed', text: `The authenticator rejected the request (${detail}).`, variant: 'danger' });
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
                    const response = await fetch('{{ route('passkey.store') }}', {
                        method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' }, credentials: 'same-origin',
                        body: JSON.stringify({ name: this.name || 'This device', credential: {
                            id: credential.id, rawId: encode(credential.rawId), type: credential.type,
                            response: { clientDataJSON: encode(credential.response.clientDataJSON), attestationObject: encode(credential.response.attestationObject) }
                        }})
                    });

                    if (!response.ok) {
                        throw new Error(`Credential registration failed (HTTP ${response.status}).`);
                    }

                    window.location.reload();
                } catch (error) {
                    console.error('[passkey]', error);
                    const detail = `${error?.name || 'Error'}: ${error?.message || 'Unknown error'}`;
                    window.Flux?.toast({ heading: 'Passkey registration failed', text: `Please try again or use a different authenticator (${detail}).`, variant: 'danger' });
                }
            }
        };
    };
</script>
@endscript
