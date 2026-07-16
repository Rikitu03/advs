@php
    /*
     * ─────────────────────────────────────────────────────────────────────────
     * PROVISIONAL FIGURES — replace before this page is made public.
     *
     * This section is headlined "NOT MARKETED. MEASURED.", so every number here
     * has to survive that claim. Only `< 60 seconds` and `ISO 25010` are
     * defensible today: both are stated evaluation targets (CLAUDE.md §10). The
     * accuracy and recall values are placeholders carried over from the design
     * frame and must be replaced with figures from the actual evaluation run.
     * ─────────────────────────────────────────────────────────────────────────
     */
    $metrics = [
        ['label' => 'Stamp detection accuracy', 'value' => '98.7', 'provisional' => true],
        ['label' => 'Full pipeline document', 'value' => '< 60 seconds', 'provisional' => false],
        ['label' => 'Siamese recall score', 'value' => '0.92', 'provisional' => true],
        ['label' => 'Compliance', 'value' => 'ISO 25010', 'provisional' => false],
    ];
@endphp

<section class="landing-glow-left relative bg-white py-16 md:py-24">
    <div class="mx-auto grid max-w-[1440px] gap-12 px-6 lg:grid-cols-[minmax(0,1fr)_616px] lg:items-start lg:gap-10">
        {{-- The panel beside this is ~1900px tall. Pinning the heading keeps the claim
             in view while the evidence for it scrolls past, instead of leaving a column
             of dead white. --}}
        <div class="min-w-0 lg:sticky lg:top-40 lg:pt-12 lg:pl-[9%]">
            <h2 class="max-w-[492px] font-jakarta text-[2.5rem] leading-[1.1] font-extrabold tracking-tight text-black sm:text-5xl lg:text-[3.75rem] lg:leading-[1.26]">
                Not marketed.<br>Measured.
            </h2>

            <p class="mt-5 max-w-[503px] font-jakarta text-base leading-[1.26] text-ink md:text-lg">
                Every performance figure comes from controlled testing on real vendor documents.
            </p>
        </div>

        <div
            class="relative overflow-hidden rounded-[40px] bg-ink px-7 py-14 sm:px-12 md:rounded-[50px] md:py-20 lg:pl-[7.5rem]"
            x-data="{
                progress: 0,
                reduceMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
                onScroll() {
                    if (this.reduceMotion || ! window.matchMedia('(min-width: 1024px)').matches) {
                        this.progress = 1;
                        return;
                    }
                    const rect = this.$el.getBoundingClientRect();
                    const total = rect.height + window.innerHeight;
                    this.progress = Math.min(1, Math.max(0, (window.innerHeight - rect.top) / total));
                },
            }"
            x-init="onScroll()"
            @scroll.window.passive="onScroll()"
            @resize.window.passive="onScroll()"
        >
            {{-- The scanner rail. This is the frame's own `light_source` node, but rather
                 than travelling on a timer, it reads as evidence filling in behind the
                 claim: the white fill's height tracks how far this panel has scrolled
                 through the viewport, so the rail is full exactly when the last metric is. --}}
            <div class="pointer-events-none absolute top-14 bottom-14 left-7 hidden w-2 overflow-hidden rounded-full bg-white/8 sm:left-12 lg:block" aria-hidden="true">
                <span
                    class="absolute inset-x-0 top-0 rounded-full bg-white transition-[height] duration-150 ease-out"
                    :style="{ height: (progress * 100) + '%' }"
                >
                    <span class="absolute inset-x-0 bottom-0 h-6 rounded-full bg-white blur-md"></span>
                </span>
            </div>

            <dl class="space-y-16 md:space-y-24">
                @foreach ($metrics as $metric)
                    <div>
                        <dt class="font-jakarta text-sm font-bold tracking-[0.06em] text-white uppercase md:text-xl">
                            {{ $metric['label'] }}
                        </dt>

                        <dd class="mt-2 font-jakarta text-[2.5rem] leading-[1.26] font-bold text-white md:text-[3.75rem]">
                            {{ $metric['value'] }}
                            @if ($metric['provisional'])
                                <span class="mt-1 block font-jakarta text-[0.625rem] font-semibold tracking-[0.14em] text-flame uppercase">
                                    Provisional
                                </span>
                            @endif
                        </dd>

                        {{-- Reserved for this metric's chart. Left as a labelled surface rather
                             than a stock graphic, so the panel never depicts data that does not exist. --}}
                        <dd class="mt-6 flex aspect-[395/224] items-end rounded-[36px] bg-[#fdfdfd] p-6 md:rounded-[50px]">
                            <span class="font-jakarta text-[0.625rem] tracking-[0.18em] text-ink/35 uppercase">
                                Chart pending
                            </span>
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>
</section>
