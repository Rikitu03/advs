@php
    $models = config('landing.models');

    // The hero's thesis: not testimonials, but the engine's own verdicts — each one
    // attributed to the model that produced it. Tone alternates ink / flame, as drawn.
    $verdicts = [
        ['model' => $models['resnet'], 'title' => 'BIR Permit', 'detail' => 'Classified · 96%', 'tone' => 'ink'],
        ['model' => $models['siamese'], 'title' => 'Signature', 'detail' => 'Matched · 0.91', 'tone' => 'flame'],
        ['model' => $models['efficientnet'], 'title' => 'Official stamp', 'detail' => 'Verified · 0.95', 'tone' => 'ink'],
        ['model' => $models['yolo'], 'title' => 'Risk score 12', 'detail' => 'Cleared for review', 'tone' => 'flame'],
    ];

    // Left/top of each chip on the 1440px frame, measured from the underside of the
    // floating nav. The stagger below makes the verdicts arrive in sequence rather
    // than all at once — the point is that the engine returns them one by one.
    $placements = [
        'left-[12.01%] top-[103px]',
        'left-[63.19%] top-[46px]',
        'left-[70.63%] top-[236px]',
        'left-[14.44%] top-[294px]',
    ];
    $delays = ['[animation-delay:120ms]', '[animation-delay:240ms]', '[animation-delay:360ms]', '[animation-delay:480ms]'];

    // Which half of the hero each chip sits in, so its avatar hangs off the outer
    // corner (away from the headline) instead of always the left — and so its hover
    // tilt leans further into that same corner.
    $sides = ['left', 'right', 'right', 'left'];
@endphp

{{-- The glow has to start above the nav, because the nav floats over it. The section
     is pulled up by exactly the nav's height and pads its own content back down. --}}
<section id="top" class="landing-glow relative -mt-20 overflow-hidden bg-white pt-20 md:-mt-[114px] md:pt-[114px]">
    <div class="relative mx-auto max-w-[1440px] px-6 pt-14 pb-14 md:pt-20 xl:h-[560px] xl:px-0 xl:pt-0 xl:pb-0">
        {{-- Desktop: the verdicts float around the headline, as drawn. They arrive once
             the hero has scrolled into view rather than on page load, so the stagger
             plays every time a visitor reaches it — not just on a cold load. --}}
        <div class="absolute inset-0 hidden xl:block" aria-hidden="true" x-data="{ show: false }" x-intersect.once="show = true">
            @foreach ($verdicts as $index => $verdict)
                <div
                    class="absolute {{ $placements[$index] }} {{ $delays[$index] }} w-[250px]"
                    :class="show ? 'landing-verdict' : 'opacity-0'"
                >
                    <x-landing.verdict-chip
                        :model="$verdict['model']"
                        :title="$verdict['title']"
                        :detail="$verdict['detail']"
                        :tone="$verdict['tone']"
                        :side="$sides[$index]"
                    />
                </div>
            @endforeach
        </div>

        <div class="relative mx-auto max-w-[560px] text-center xl:pt-[127px]">
            <p class="font-jakarta text-base font-light text-ink select-none md:text-lg">
                Built for teams who can&rsquo;t afford a mistake
            </p>

            <h1 class="mt-3 cursor-default font-jakarta text-[2.5rem] leading-[1.06] font-extrabold tracking-tight text-black select-none sm:text-5xl xl:text-[3.75rem] xl:leading-[1.26]">
                Fake documents don&rsquo;t <em class="italic">slip</em> through anymore.
            </h1>

            {{-- The pill and its arrow disc are one control: the disc is drawn as a
                 separate node in the frame, but a split target would be a usability bug.
                 On hover, the disc itself is the fill: a black layer the same size and
                 position as the disc at rest grows leftward to cover the whole pill,
                 and the label turns white just as the fill reaches it. --}}
            <a
                href="#how-it-works"
                class="group relative mt-9 inline-flex items-center gap-2.5 overflow-hidden rounded-full py-1 pr-1 pl-6 ring-1 ring-ink/15 transition hover:ring-ink/35 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink xl:mt-12"
            >
                <span
                    class="absolute inset-y-1 right-1 z-0 w-[51px] rounded-full bg-ink transition-all duration-300 ease-out group-hover:inset-y-0 group-hover:right-0 group-hover:w-full"
                    aria-hidden="true"
                ></span>

                <span class="relative z-10 font-jakarta text-[0.9375rem] font-semibold text-ink transition-colors delay-150 duration-150 ease-out group-hover:text-white">
                    Explore ADVS
                </span>
                <span class="relative z-10 flex size-[51px] shrink-0 items-center justify-center rounded-full text-white">
                    <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8h10m0 0-3.75-3.75M13 8l-3.75 3.75" />
                    </svg>
                </span>
            </a>
        </div>
    </div>

    {{-- Mobile / tablet: the verdicts survive as a scrollable strip rather than being
         hidden — they carry the product's whole argument. --}}
    <div class="xl:hidden" x-data="{ show: false }" x-intersect.once="show = true">
        <div class="flex snap-x snap-mandatory gap-4 overflow-x-auto px-6 pb-16 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            @foreach ($verdicts as $index => $verdict)
                <div
                    class="{{ $delays[$index] }} w-[250px] shrink-0 snap-start"
                    :class="show ? 'landing-verdict' : 'opacity-0'"
                >
                    <x-landing.verdict-chip
                        :model="$verdict['model']"
                        :title="$verdict['title']"
                        :detail="$verdict['detail']"
                        :tone="$verdict['tone']"
                        :side="$sides[$index]"
                    />
                </div>
            @endforeach
        </div>
    </div>
</section>
