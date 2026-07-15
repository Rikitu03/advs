<x-layouts.auth>
    <div class="flex flex-col gap-7">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5">
            @csrf

            <flux:input
                name="email"
                :label="__('Email address')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
                :value="old('email')"
            />

            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <a
                        href="{{ route('password.request') }}"
                        class="absolute end-0 top-0 font-jakarta text-xs font-medium text-ink/60 transition-colors hover:text-flame"
                    >
                        {{ __('Forgot your password?') }}
                    </a>
                @endif
            </div>

            <flux:checkbox name="remember" value="1" :label="__('Remember me')" />

            <x-auth.submit class="mt-1">{{ __('Log in') }}</x-auth.submit>
        </form>

        @if (Route::has('register'))
            <p class="text-center font-jakarta text-sm text-ink/60">
                {{ __('Don\'t have an account?') }}
                <a
                    href="{{ route('register') }}"
                    class="font-semibold text-ink underline-offset-4 transition-colors hover:text-flame hover:underline"
                >
                    {{ __('Sign up') }}
                </a>
            </p>
        @endif
    </div>
</x-layouts.auth>
