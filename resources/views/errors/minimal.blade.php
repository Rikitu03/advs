<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        @php($home = auth()->check() ? route('dashboard') : route('home'))
        @php($title = ($code ?? 'Error').' — '.config('app.name', 'ADVS'))
        @include('partials.head')
    </head>

    <body class="min-h-svh bg-white text-zinc-900 antialiased dark:bg-cu-bg dark:text-cu-text">
        <main class="relative flex min-h-svh flex-col items-center justify-center overflow-hidden px-6 py-16">
            {{-- Soft ambient glow behind the card --}}
            <div class="pointer-events-none absolute inset-0 -z-10 flex items-center justify-center" aria-hidden="true">
                <div class="size-[36rem] rounded-full bg-cu-purple/10 blur-3xl dark:bg-cu-purple/15"></div>
            </div>

            <div class="cu-animate-in w-full max-w-md rounded-3xl border border-cu-border bg-cu-surface p-8 text-center shadow-sm sm:p-10">
                <span class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-cu-purple/10 text-cu-purple">
                    <flux:icon :icon="$icon ?? 'exclamation-triangle'" class="size-7" />
                </span>

                <p class="mt-6 cu-gradient-text text-6xl font-bold tracking-tight">
                    @yield('code', $code ?? 'Error')
                </p>

                <h1 class="mt-4 text-xl font-semibold tracking-tight text-cu-text">
                    @yield('title', 'Something went wrong')
                </h1>

                <p class="mx-auto mt-2 max-w-sm text-sm text-cu-muted">
                    @yield('message', 'An unexpected error occurred. Please try again.')
                </p>

                <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    {{-- Returns the user to their previous state; falls back to home when
                         there is no meaningful history (e.g. a fresh tab). --}}
                    <button
                        type="button"
                        onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href = @js($home); }"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl cu-gradient px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 sm:w-auto"
                    >
                        <flux:icon icon="arrow-left" class="size-4" />
                        Go back
                    </button>

                    <a
                        href="{{ $home }}"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-cu-border bg-cu-surface px-4 py-2.5 text-sm font-medium text-cu-text transition hover:border-cu-purple hover:bg-cu-purple/10 hover:text-cu-purple sm:w-auto"
                    >
                        <flux:icon icon="home" class="size-4" />
                        {{ auth()->check() ? 'Dashboard' : 'Home' }}
                    </a>
                </div>
            </div>
        </main>

        @fluxScripts
    </body>
</html>
