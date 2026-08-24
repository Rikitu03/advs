<!DOCTYPE html>
{{-- Light-locked, exactly like the landing page: this flow is the front door to the
     same product, so it is drawn ink-on-white with flame as the only accent and uses
     no `dark:` variants. `data-theme-lock` stops window.advsTheme (partials/head)
     from stamping `.dark` here for a dark-preferring visitor, which would otherwise
     recolour the Flux inputs while the page around them stayed white. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme-lock="light">
    <head>
        @include('partials.head')

        {{-- The landing page's faces. Jakarta carries the wordmark and headings;
             Inter is reserved for the step rail's labels, mirroring the way the
             landing page reserves it for the hero chips. --}}
        <link
            href="https://fonts.bunny.net/css?family=plus-jakarta-sans:300,400,500,600,700,800|inter:400,500,600"
            rel="stylesheet"
            media="print"
            onload="this.media='all'"
        />
        <noscript>
            <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:300,400,500,600,700,800|inter:400,500,600" rel="stylesheet" />
        </noscript>
    </head>

    <body class="landing-glow min-h-svh bg-white font-jakarta text-ink antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-8 px-6 py-12">
            {{-- The wordmark, set exactly as the landing nav sets it. --}}
            <a
                href="{{ route('home') }}"
                class="font-jakarta text-2xl font-extrabold tracking-tight text-ink transition-colors hover:text-flame focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ink"
                wire:navigate
            >
                ADVS
                <span class="sr-only">{{ config('app.name', 'ADVS') }}</span>
            </a>

            {{-- Wide enough to seat the four-stage rail on one line without clipping
                 its last label, which is what the width has to clear. --}}
            <main class="w-full max-w-[540px]">
                {{-- The card is the one raised surface on the page: white on the warm
                     wash, with the landing's soft, wide shadow rather than a border. --}}
                <div class="rounded-[28px] bg-white/90 p-6 shadow-[0_20px_60px_-24px_rgb(30_30_30/0.22)] ring-1 ring-black/5 backdrop-blur-xl sm:p-10">
                    {{ $slot }}
                </div>
            </main>
        </div>

        <flux:toast />

        @if ($toast = session()->pull('toast'))
            <x-flux-toast
                :heading="$toast['heading'] ?? ''"
                :text="$toast['text'] ?? ''"
                :variant="$toast['variant'] ?? 'info'"
            />
        @endif

        @fluxScripts
    </body>
</html>
