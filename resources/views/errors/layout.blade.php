@php
    use Illuminate\Support\Facades\Route;

    $statusCode = (int) $statusCode;
    $title = "{$statusCode} - {$heading} | ".config('app.name', 'ADVS');
    $homeUrl = Route::has('home') ? route('home') : url('/');
    $loginUrl = Route::has('login') ? route('login') : null;
    $errorKey = $errorKey ?? strtoupper((string) str($heading)->replace([' ', '-'], '_'));

    $primaryAction = $primaryAction ?? [
        'label' => 'Back to Home',
        'href' => $homeUrl,
        'icon' => 'home',
    ];

    $secondaryAction = $secondaryAction ?? ($loginUrl ? [
        'label' => 'Sign In',
        'href' => $loginUrl,
        'icon' => 'arrow-right-end-on-rectangle',
    ] : null);

    $icon = $icon ?? 'document-magnifying-glass';
    $primaryIcon = $primaryAction['icon'] ?? 'home';
    $secondaryIcon = $secondaryAction['icon'] ?? 'arrow-uturn-left';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme-lock="light">
    <head>
        @include('partials.head')

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
        <main class="flex min-h-svh items-center justify-center overflow-hidden px-4 py-6 sm:px-6 lg:px-8">
            <section class="relative w-full max-w-[900px] rounded-[30px] bg-white/90 p-5 shadow-[0_22px_70px_-42px_rgb(30_30_30/0.32)] ring-1 ring-black/5 backdrop-blur-xl sm:p-6 lg:p-8" aria-labelledby="error-title">
                <div class="pointer-events-none absolute -top-20 right-10 h-40 w-40 rounded-full bg-flame/10 blur-3xl" aria-hidden="true"></div>

                <a href="{{ $homeUrl }}" class="relative flex w-fit items-center gap-2 text-ink transition hover:text-flame focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ink">
                    <x-app-logo />
                    <span class="sr-only">{{ config('app.name', 'ADVS') }}</span>
                </a>

                <div class="relative mt-7 grid justify-center gap-6 lg:grid-cols-[minmax(0,480px)_310px] lg:items-center">
                    <div class="flex max-w-[520px] flex-col gap-4">
                        <div class="flex size-14 items-center justify-center rounded-full bg-flame/10 text-flame ring-1 ring-flame/15" aria-hidden="true">
                            <flux:icon :icon="$icon" class="size-6" />
                        </div>

                        <div class="flex flex-col gap-3">
                            <p class="text-[3.35rem] font-extrabold leading-none tracking-tight text-ink sm:text-[4rem]">{{ $statusCode }}</p>
                            <h1 id="error-title" class="text-3xl font-extrabold leading-tight tracking-tight text-ink sm:text-4xl">{{ $heading }}</h1>
                            <p class="max-w-[46ch] text-base leading-7 text-[#6B6B6B]">{{ $message }}</p>
                        </div>

                        <div class="flex flex-col gap-3 pt-1 sm:flex-row">
                            @if (($primaryAction['kind'] ?? 'link') === 'reload')
                                <button
                                    type="button"
                                    onclick="window.location.reload()"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-full bg-ink px-5 py-2.5 text-[0.9375rem] font-semibold text-white shadow-[0_14px_35px_-18px_rgb(30_30_30/0.45)] transition hover:-translate-y-0.5 hover:bg-flame focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink"
                                >
                                    <flux:icon :icon="$primaryIcon" class="size-4" />
                                    {{ $primaryAction['label'] }}
                                </button>
                            @elseif (($primaryAction['kind'] ?? 'link') === 'back')
                                <button
                                    type="button"
                                    onclick="window.history.length > 1 ? window.history.back() : window.location.assign(@js($homeUrl))"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-full bg-ink px-5 py-2.5 text-[0.9375rem] font-semibold text-white shadow-[0_14px_35px_-18px_rgb(30_30_30/0.45)] transition hover:-translate-y-0.5 hover:bg-flame focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink"
                                >
                                    <flux:icon :icon="$primaryIcon" class="size-4" />
                                    {{ $primaryAction['label'] }}
                                </button>
                            @else
                                <a
                                    href="{{ $primaryAction['href'] ?? $homeUrl }}"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-full bg-ink px-5 py-2.5 text-[0.9375rem] font-semibold text-white shadow-[0_14px_35px_-18px_rgb(30_30_30/0.45)] transition hover:-translate-y-0.5 hover:bg-flame focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink"
                                >
                                    <flux:icon :icon="$primaryIcon" class="size-4" />
                                    {{ $primaryAction['label'] }}
                                </a>
                            @endif

                            @if ($secondaryAction)
                                <a
                                    href="{{ $secondaryAction['href'] }}"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-ink-mute bg-white px-5 py-2.5 text-[0.9375rem] font-semibold text-ink transition hover:-translate-y-0.5 hover:border-flame hover:text-flame focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink"
                                >
                                    <flux:icon :icon="$secondaryIcon" class="size-4" />
                                    {{ $secondaryAction['label'] }}
                                </a>
                            @endif
                        </div>
                    </div>

                    <aside class="relative rounded-[22px] bg-[#FFFCF9]/80 p-4 ring-1 ring-black/5 lg:min-h-[292px] lg:self-center" aria-label="Error recovery summary">
                        <div class="mb-4 h-1 w-8 rounded-full bg-flame" aria-hidden="true"></div>

                        <div class="divide-y divide-ink-mute">
                            <div class="pb-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#6B6B6B]">Error details</p>
                                <p class="mt-2 text-sm font-semibold text-ink">{{ $heading }}</p>
                                <p class="mt-1 text-sm leading-6 text-[#6B6B6B]">{{ $details ?? 'The requested page or action could not be completed.' }}</p>
                            </div>

                            <div class="py-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#6B6B6B]">Next step</p>
                                <p class="mt-2 text-sm leading-6 text-ink">{{ $nextStep ?? 'Return to the ADVS homepage to continue.' }}</p>
                            </div>

                            <div class="pt-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#6B6B6B]">Error code</p>
                                <p class="mt-2 font-mono text-sm text-ink">HTTP {{ $statusCode }} &mdash; {{ $errorKey }}</p>
                            </div>
                        </div>
                    </aside>
                </div>
            </section>
        </main>

        @fluxScripts
    </body>
</html>
