<x-layouts.auth>
    <div class="flex flex-col gap-7">
        <x-auth-header
            :title="__('Confirm password')"
            :description="__('This is a secure area of the application. Please confirm your password before continuing.')"
        />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-5">
            @csrf

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autofocus
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            <x-auth.submit class="mt-1">{{ __('Confirm') }}</x-auth.submit>
        </form>
    </div>
</x-layouts.auth>
