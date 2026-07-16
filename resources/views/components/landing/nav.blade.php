{{-- Floating rounded nav card. Light-only by design: the landing page never opts
     into the app's `.dark` theme, so no `dark:` variants appear anywhere in it. --}}
<div class="sticky top-0 z-50 px-4 pt-4 md:px-6 md:pt-7">
    <nav
        x-data="{ open: false }"
        class="mx-auto max-w-[1230px] rounded-[28px] bg-white/85 shadow-[0_10px_40px_-12px_rgb(30_30_30/0.18)] ring-1 ring-black/5 backdrop-blur-xl md:rounded-[35px]"
    >
        <div class="flex h-16 items-center justify-between px-5 md:h-[86px] md:px-9">
            <a
                href="#top"
                class="font-jakarta text-2xl font-extrabold tracking-tight text-ink focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ink"
            >
                ADVS
            </a>

            <div class="hidden items-center gap-9 md:flex">
                @foreach ([['Home', '#top'], ['Features', '#features'], ['About', '#how-it-works'], ['Contact', '#contact']] as [$label, $anchor])
                    <a
                        href="{{ $anchor }}"
                        class="font-jakarta text-[0.9375rem] text-ink/65 transition-colors hover:text-flame focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ink"
                    >
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <div class="flex items-center gap-2 md:gap-4">
                @auth
                    <a
                        href="{{ route('dashboard') }}"
                        class="rounded-full bg-ink px-6 py-2.5 font-jakarta text-[0.9375rem] font-semibold text-white transition hover:bg-flame focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink"
                    >
                        Dashboard
                    </a>
                @else
                    <a
                        href="{{ route('login') }}"
                        class="hidden px-2 py-2 font-jakarta text-[0.9375rem] text-ink transition-colors hover:text-flame focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ink sm:block"
                    >
                        Sign in
                    </a>
                    <a
                        href="{{ route('register') }}"
                        class="rounded-full bg-ink px-6 py-2.5 font-jakarta text-[0.9375rem] font-semibold text-white transition hover:bg-flame focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink"
                    >
                        Register
                    </a>
                @endauth

                <button
                    type="button"
                    x-on:click="open = ! open"
                    :aria-expanded="open"
                    aria-controls="landing-mobile-nav"
                    class="-mr-1 p-2 text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink md:hidden"
                >
                    <span class="sr-only">Toggle navigation</span>
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path x-show="! open" stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
                        <path x-show="open" x-cloak stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </button>
            </div>
        </div>

        <div
            id="landing-mobile-nav"
            x-show="open"
            x-cloak
            x-transition.origin.top
            class="border-t border-black/5 px-5 pb-4 md:hidden"
        >
            @foreach ([['Home', '#top'], ['Features', '#features'], ['About', '#how-it-works'], ['Contact', '#contact']] as [$label, $anchor])
                <a
                    href="{{ $anchor }}"
                    x-on:click="open = false"
                    class="block border-b border-black/5 py-3 font-jakarta text-base text-ink last:border-0"
                >
                    {{ $label }}
                </a>
            @endforeach

            @guest
                <a href="{{ route('login') }}" class="mt-3 block font-jakarta text-base font-semibold text-ink sm:hidden">Sign in</a>
            @endguest
        </div>
    </nav>
</div>
