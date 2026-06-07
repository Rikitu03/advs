<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @php($title = config('app.name', 'ADVS').' — Automated Document Validation System')
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900 dark:text-white">
        <div class="mx-auto flex min-h-svh max-w-5xl flex-col px-6 py-6">
            <!-- Nav -->
            <header class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <x-app-logo-icon class="size-7 fill-current text-black dark:text-white" />
                    <span class="text-lg font-semibold">ADVS</span>
                </div>

                <nav class="flex items-center gap-3 text-sm">
                    @auth
                        <flux:button :href="route('dashboard')" variant="primary" size="sm">{{ __('Dashboard') }}</flux:button>
                    @else
                        <flux:button :href="route('login')" variant="ghost" size="sm">{{ __('Log in') }}</flux:button>
                        <flux:button :href="route('register')" variant="primary" size="sm">{{ __('Register') }}</flux:button>
                    @endauth
                </nav>
            </header>

            <!-- Hero -->
            <main class="flex flex-1 flex-col items-center justify-center text-center">
                <span class="mb-4 inline-flex items-center rounded-full border border-zinc-200 px-3 py-1 text-xs font-medium text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">
                    Vendor Accreditation · AI-Assisted Compliance
                </span>

                <h1 class="max-w-3xl text-4xl font-bold tracking-tight sm:text-5xl">
                    Automated Document Validation System
                </h1>

                <p class="mt-5 max-w-2xl text-base text-zinc-600 dark:text-zinc-400">
                    Submit vendor accreditation documents and let ADVS classify them, extract text with OCR,
                    and verify signatures and official stamps through a multi-model ML pipeline —
                    producing a risk score that compliance officers review before approval.
                </p>

                <div class="mt-8 flex flex-col items-center gap-3 sm:flex-row">
                    @auth
                        <flux:button :href="route('dashboard')" variant="primary">{{ __('Go to dashboard') }}</flux:button>
                    @else
                        <flux:button :href="route('register')" variant="primary">{{ __('Register as a vendor') }}</flux:button>
                        <flux:button :href="route('login')" variant="ghost">{{ __('Officer / Admin login') }}</flux:button>
                    @endauth
                </div>

                <div class="mt-14 grid w-full gap-4 text-left sm:grid-cols-3">
                    @foreach ([
                        ['Classify & OCR', 'ResNet-50 document classification with pytesseract text extraction.'],
                        ['Verify authenticity', 'YOLOv8 + Siamese CNN signatures and EfficientNet stamp matching.'],
                        ['Score & review', 'Risk scores surfaced on an officer dashboard for the final decision.'],
                    ] as [$cardTitle, $cardBody])
                        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                            <h3 class="font-semibold">{{ $cardTitle }}</h3>
                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $cardBody }}</p>
                        </div>
                    @endforeach
                </div>
            </main>

            <footer class="pt-10 text-center text-xs text-zinc-500">
                ADVS v1.0 · Pamantasan ng Lungsod ng Pasig — College of Computer Studies
            </footer>
        </div>
        @fluxScripts
    </body>
</html>
