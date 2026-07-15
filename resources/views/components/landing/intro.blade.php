<section class="relative bg-white py-16 md:py-24">
    <div class="mx-auto grid max-w-[1192px] grid-cols-[minmax(0,1fr)] gap-12 px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)] lg:items-center lg:gap-16">
        <div class="min-w-0">
            {{-- Twin of the hero pill: the check disc is drawn overlapping the pill's
                 right end, and on hover a black layer the same size and position as the
                 disc at rest grows leftward to cover the whole badge, turning the label
                 white as the fill reaches it. --}}
            <span class="group relative inline-flex h-[57px] items-center gap-3 overflow-hidden rounded-full border border-ink py-1 pr-1 pl-6">
                <span
                    class="absolute inset-y-[10px] right-1 z-0 w-[37px] rounded-full bg-ink transition-all duration-300 ease-out group-hover:inset-y-0 group-hover:right-0 group-hover:w-full"
                    aria-hidden="true"
                ></span>

                <span class="relative z-10 font-jakarta text-base text-ink transition-colors delay-150 duration-150 ease-out group-hover:text-white md:text-lg">
                    The <em class="italic font-semibold">AI</em> that <span class="font-semibold text-flame transition-colors delay-150 duration-150 ease-out group-hover:text-white">works for you</span>
                </span>
                <span class="relative z-10 flex size-[37px] shrink-0 items-center justify-center rounded-full text-white" aria-hidden="true">
                    <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m3.5 8.5 3 3 6-7" />
                    </svg>
                </span>
            </span>

            <h2 class="mt-6 font-jakarta text-[2rem] leading-[1.15] font-extrabold tracking-tight text-black sm:text-[2.5rem] lg:text-[3.4375rem] lg:leading-[1.26]">
                Smarter Vendor Accreditation powered by <em class="italic">AI</em>.
            </h2>

            <ul class="mt-7 space-y-2.5">
                @foreach (['Automate document validation', 'Detect fraudulent submissions', 'Streamline compliance review'] as $claim)
                    <li class="flex items-center gap-3 font-jakarta text-base text-black md:text-lg">
                        <svg class="size-4 shrink-0 text-ink" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m3 8.5 3 3 7-8" />
                        </svg>
                        {{ $claim }}
                    </li>
                @endforeach
            </ul>

            <div class="mt-9 flex flex-col gap-4 sm:flex-row">
                <a
                    href="{{ route('register') }}"
                    class="inline-flex h-16 w-full items-center justify-center rounded-[20px] bg-flame px-8 font-jakarta text-lg font-semibold text-white transition hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-flame sm:w-[244px]"
                >
                    Apply as vendor
                </a>
                <a
                    href="#how-it-works"
                    class="inline-flex h-16 w-full items-center justify-center gap-3 rounded-[20px] border border-[#888888] px-8 font-jakarta text-lg font-semibold text-black transition hover:border-ink hover:bg-ink/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink sm:w-[244px]"
                >
                    <svg class="size-4 shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 4.2v7.6l6-3.8-6-3.8Z" />
                    </svg>
                    See how it works
                </a>
            </div>

            <p class="mt-7 max-w-[512px] font-jakarta text-[0.9375rem] leading-[1.26] text-ink">
                Automate document validation, detect fraudulent submissions, and streamline compliance
                review &mdash; all in <span class="font-bold">one secure platform</span>.
            </p>
        </div>

        <x-landing.report-preview />
    </div>
</section>
