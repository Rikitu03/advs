<x-layouts.auth>
    <div class="flex flex-col gap-6 text-center">
        <x-auth-header
            :title="__('Verify your email')"
            :description="__('Please verify your email address by clicking the link we just emailed to you.')"
        />

        @if (session('status') == 'verification-link-sent')
            <flux:text class="text-center font-medium !text-green-600">
                {{ __('A new verification link has been sent to the email address you provided during registration.') }}
            </flux:text>
        @endif

        <div class="flex flex-col items-center justify-between gap-4">
            <form method="POST" action="{{ route('verification.send') }}" class="w-full">
                @csrf
                <flux:button variant="primary" type="submit" class="w-full">
                    {{ __('Resend verification email') }}
                </flux:button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:link as="button" type="submit" class="cursor-pointer text-sm">
                    {{ __('Log out') }}
                </flux:link>
            </form>
        </div>
    </div>
</x-layouts.auth>
