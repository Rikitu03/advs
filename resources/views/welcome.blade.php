<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        @php($title = config('app.name', 'ADVS').' — Automated Document Validation System')
        @include('partials.head')

        {{-- Landing-only faces. Plus Jakarta Sans carries every heading (incl. the
             ExtraBold Italic used by "Faster."); Inter is used only by the hero chips. --}}
        <link
            href="https://fonts.bunny.net/css?family=plus-jakarta-sans:300,400,500,600,700,800,800i|inter:400,600"
            rel="stylesheet"
            media="print"
            onload="this.media='all'"
        />
        <noscript>
            <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:300,400,500,600,700,800,800i|inter:400,600" rel="stylesheet" />
        </noscript>
    </head>

    {{-- Light-only by design. `window.advsTheme` still stamps `.dark` on <html> for
         a visitor whose preference is dark, so this page deliberately uses no `dark:`
         variants and no self-theming Flux controls — it renders light either way. --}}
    <body class="min-h-screen bg-white font-jakarta text-ink antialiased">
        <x-landing.nav />

        <main>
            <x-landing.hero />
            <x-landing.intro />
            <x-landing.engine />
            <x-landing.metrics />
            <x-landing.how-it-works />
            <x-landing.statement />
            <x-landing.features />
        </main>

        <x-landing.wordmark />
        <x-landing.site-footer />

        @fluxScripts
    </body>
</html>
