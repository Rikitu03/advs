<x-layouts.auth>
    <div class="flex flex-col gap-7">
        <x-auth-header
            :title="__('Verify your email')"
            :description="__('Step 4 of 4 — click the link we just emailed you to finish accreditation.')"
        />

        <x-auth.steps current="verify" />

        @if (session('status') == 'signature-enrolled')
            <p class="rounded-2xl bg-emerald-50 p-3 text-center font-jakarta text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
                {{ __('Your reference signature was enrolled successfully.') }}
            </p>
        @endif

        @if (session('status') == 'verification-link-sent')
            <p class="rounded-2xl bg-emerald-50 p-3 text-center font-jakarta text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
                {{ __('A new verification link has been sent to the email address you provided during registration.') }}
            </p>
        @endif

        <div class="flex flex-col items-center gap-5">
            <form method="POST" action="{{ route('verification.send') }}" class="w-full">
                @csrf
                <x-auth.submit>{{ __('Resend verification email') }}</x-auth.submit>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button
                    type="submit"
                    class="cursor-pointer font-jakarta text-sm font-semibold text-ink/60 underline-offset-4 transition-colors hover:text-flame hover:underline"
                >
                    {{ __('Log out') }}
                </button>
            </form>
        </div>
    </div>
</x-layouts.auth>
