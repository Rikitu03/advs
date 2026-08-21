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

        <form
            method="POST"
            action="{{ route('register.store') }}"
            class="flex flex-col gap-6"
            x-data="{
                pwd: '',
                submitted: false,
                get hasLength() {
                    return Array.from(this.pwd).length >= 8;
                },
                get hasMixedCase() {
                    return /\p{Lu}/u.test(this.pwd) && /\p{Ll}/u.test(this.pwd);
                },
                get hasSymbol() {
                    return /[\p{Z}\p{S}\p{P}]/u.test(this.pwd);
                },
                get isValid() {
                    return this.hasLength && this.hasMixedCase && this.hasSymbol;
                }
            }"
            x-on:submit="pwd = $el.elements.password.value; if (!isValid) { submitted = true; $event.preventDefault(); }"
        >
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

            <div>
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="new-password"
                    :placeholder="__('Password')"
                    viewable
                    x-model="pwd"
                    x-on:input="submitted = false"
                />

                <ul class="mt-2 flex flex-col gap-1 font-inter text-xs">
                    <li
                        class="flex items-center gap-1.5 transition-colors"
                        :class="{
                            'text-cu-success': hasLength,
                            'text-cu-pink': !hasLength && submitted,
                            'text-ink/40': !hasLength && !submitted
                        }"
                    >
                        <template x-if="hasLength">
                            <svg class="size-3 shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.25" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m3.5 8.5 3 3 6-7" />
                            </svg>
                        </template>
                        <template x-if="!hasLength">
                            <svg class="size-3 shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="8" cy="8" r="6" />
                            </svg>
                        </template>
                        <span>{{ __('At least 8 characters') }}</span>
                    </li>

                    <li
                        class="flex items-center gap-1.5 transition-colors"
                        :class="{
                            'text-cu-success': hasMixedCase,
                            'text-cu-pink': !hasMixedCase && submitted,
                            'text-ink/40': !hasMixedCase && !submitted
                        }"
                    >
                        <template x-if="hasMixedCase">
                            <svg class="size-3 shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.25" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m3.5 8.5 3 3 6-7" />
                            </svg>
                        </template>
                        <template x-if="!hasMixedCase">
                            <svg class="size-3 shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="8" cy="8" r="6" />
                            </svg>
                        </template>
                        <span>{{ __('Contains Uppercase and Lowercase letters') }}</span>
                    </li>

                    <li
                        class="flex items-center gap-1.5 transition-colors"
                        :class="{
                            'text-cu-success': hasSymbol,
                            'text-cu-pink': !hasSymbol && submitted,
                            'text-ink/40': !hasSymbol && !submitted
                        }"
                    >
                        <template x-if="hasSymbol">
                            <svg class="size-3 shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.25" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m3.5 8.5 3 3 6-7" />
                            </svg>
                        </template>
                        <template x-if="!hasSymbol">
                            <svg class="size-3 shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="8" cy="8" r="6" />
                            </svg>
                        </template>
                        <span>{{ __('Contains a special symbol') }}</span>
                    </li>
                </ul>

                <p
                    x-cloak
                    x-show="submitted && !isValid"
                    x-transition
                    role="alert"
                    aria-live="polite"
                    class="mt-2 font-inter text-xs text-cu-pink"
                >
                    {{ __('Please satisfy all password requirements to continue.') }}
                </p>
            </div>

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
