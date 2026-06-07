<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Forgot password')" :description="__('Enter your email to receive a password reset link')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6">
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

            <flux:button variant="primary" type="submit" class="w-full">{{ __('Email password reset link') }}</flux:button>
        </form>

        <div class="space-x-1 text-center text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('Or, return to') }}
            <flux:link :href="route('login')">{{ __('log in') }}</flux:link>
        </div>
    </div>
</x-layouts.auth>
