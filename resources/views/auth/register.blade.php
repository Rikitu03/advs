<x-layouts.auth>
    <div class="flex flex-col gap-8">
        <x-auth-header :title="__('Create a vendor account')" :description="__('Register to submit accreditation documents for validation')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        @if ($errors->any())
            <x-flux-toast
                variant="danger"
                :heading="__('Registration failed')"
                :text="__('Please review the highlighted fields and try again.')"
            />
        @endif

        <x-auth.steps current="account" />

        {{-- What the next stage asks for, so the account form does not look like the
             whole of registration. --}}
        <div class="flex items-start gap-3 rounded-2xl bg-ink-mute/50 p-4 ring-1 ring-black/5">
            <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-ink text-white" aria-hidden="true">
                <flux:icon icon="finger-print" variant="micro" class="size-4" />
            </span>
            <div>
                <p class="font-jakarta text-sm font-semibold text-ink">{{ __('Next: your business & owner details') }}</p>
                <p class="mt-0.5 font-jakarta text-xs leading-[1.5] text-ink/60">
                    {{ __('After creating your account you will declare your business and representative information, then enroll your reference signature.') }}
                </p>
            </div>
        </div>

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="name"
                :label="__('Name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
                :value="old('name')"
            />

            <flux:input
                name="email"
                :label="__('Email address')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
                :value="old('email')"
            />

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                viewable
            />

            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                viewable
            />

            <x-auth.submit class="mt-1">{{ __('Create account') }}</x-auth.submit>
        </form>

        <p class="text-center font-jakarta text-sm text-ink/60">
            {{ __('Already have an account?') }}
            <a
                href="{{ route('login') }}"
                class="font-semibold text-ink underline-offset-4 transition-colors hover:text-flame hover:underline"
            >
                {{ __('Log in') }}
            </a>
        </p>
    </div>
</x-layouts.auth>
