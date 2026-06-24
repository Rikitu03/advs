<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create a vendor account')" :description="__('Register to submit accreditation documents for validation')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        @if ($errors->any())
            <x-flux-toast
                variant="danger"
                :heading="__('Registration failed')"
                :text="__('Please review the highlighted fields and try again.')"
            />
        @endif

        {{-- Step indicator: account → signature enrollment → email verification --}}
        <ol class="flex items-center gap-2 text-xs font-medium">
            <li class="flex items-center gap-1.5 text-cu-purple">
                <span class="flex size-4 items-center justify-center rounded-full bg-cu-purple text-[10px] text-white">1</span>
                {{ __('Account') }}
            </li>
            <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
            <li class="flex items-center gap-1.5 text-zinc-400 dark:text-zinc-500">
                <span class="flex size-4 items-center justify-center rounded-full border border-current text-[10px]">2</span>
                {{ __('Signature') }}
            </li>
            <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
            <li class="flex items-center gap-1.5 text-zinc-400 dark:text-zinc-500">
                <span class="flex size-4 items-center justify-center rounded-full border border-current text-[10px]">3</span>
                {{ __('Verify email') }}
            </li>
        </ol>

        {{-- Heads-up for the signature-enrollment step that follows --}}
        <div class="flex items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
            <flux:icon icon="finger-print" class="mt-0.5 size-5 shrink-0 text-cu-purple" />
            <div class="text-xs text-zinc-600 dark:text-zinc-400">
                <p class="mb-1 text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ __('Next: enroll your signature') }}</p>
                {{ __('After this step you will upload a clear photo of 3 handwritten signatures, stacked vertically on white bond paper. Use an original, unedited photo — it becomes your reference signature for document validation.') }}
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

            <flux:button type="submit" variant="primary" class="w-full">{{ __('Create account') }}</flux:button>
        </form>

        <div class="space-x-1 text-center text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('Already have an account?') }}
            <flux:link :href="route('login')">{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts.auth>
