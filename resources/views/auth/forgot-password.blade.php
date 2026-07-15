<x-layouts.auth>
    <div class="flex flex-col gap-7">
        <x-auth-header :title="__('Forgot password')" :description="__('Enter your email to receive a password reset link')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-5">
            @csrf

            <flux:input
                name="email"
                :label="__('Email address')"
                type="email"
                required
                autofocus
                placeholder="email@example.com"
                :value="old('email')"
            />

            <x-auth.submit class="mt-1">{{ __('Email password reset link') }}</x-auth.submit>
        </form>

        <p class="text-center font-jakarta text-sm text-ink/60">
            {{ __('Or, return to') }}
            <a
                href="{{ route('login') }}"
                class="font-semibold text-ink underline-offset-4 transition-colors hover:text-flame hover:underline"
            >
                {{ __('log in') }}
            </a>
        </p>
    </div>
</x-layouts.auth>
