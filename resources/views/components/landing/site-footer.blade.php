@php
    $team = [
        ['Lopez, Marvin S.', 'ML'],
        ['Inocencio, Ron Alexander A.', 'RI'],
        ['Recto, Jason Jay M.', 'JR'],
    ];

    $quickLinks = [['Home', '#top'], ['Features', '#features'], ['About', '#how-it-works']];

    $support = ['Document submission', 'AI-powered validation', 'Report viewing', 'Workflow automation'];

    $socials = [
        ['Facebook', 'M14 8.5h2.5V5.5H14c-2 0-3.5 1.5-3.5 3.5v1.5H8.5v3h2v6h3v-6h2.2l.3-3h-2.5V9c0-.3.2-.5.5-.5Z'],
        ['Instagram', 'M8 3.5h8A4.5 4.5 0 0 1 20.5 8v8a4.5 4.5 0 0 1-4.5 4.5H8A4.5 4.5 0 0 1 3.5 16V8A4.5 4.5 0 0 1 8 3.5Zm4 4.75a3.75 3.75 0 1 0 0 7.5 3.75 3.75 0 0 0 0-7.5Zm5-1.25a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z'],
        ['X', 'M4 4h4.3l3.9 5.3L16.9 4H20l-6.3 7.4L20.4 20h-4.3l-4.2-5.7L6.8 20H3.6l6.6-7.7L4 4Z'],
    ];
@endphp

<footer id="contact" class="landing-wordmark-crop relative bg-ink text-white">
    <div class="mx-auto max-w-[1440px] px-6 pt-20 md:px-12 lg:px-[3.25rem]">
        <div class="grid gap-12 lg:grid-cols-[minmax(0,1.15fr)_repeat(3,minmax(0,1fr))] lg:gap-8">
            <div>
                <h2 class="max-w-[390px] font-jakarta text-[2rem] leading-[1.26] font-extrabold tracking-tight md:text-[2.8125rem]">
                    Smarter Vendor Accreditation powered by AI.
                </h2>

                <p class="mt-12 font-jakarta text-[0.9375rem] font-semibold">Follow us on:</p>

                <ul class="mt-4 flex items-center gap-5">
                    @foreach ($socials as [$network, $path])
                        <li>
                            <a
                                href="#contact"
                                class="inline-flex size-10 items-center justify-center rounded-full text-white transition hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                            >
                                <span class="sr-only">{{ $network }}</span>
                                <svg class="size-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <path d="{{ $path }}" />
                                </svg>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div>
                <h3 class="font-jakarta text-xl font-bold">Contact</h3>
                <a
                    href="mailto:advs_support@gmail.com"
                    class="mt-5 inline-block font-jakarta text-lg text-white/80 transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                >
                    advs_support@gmail.com
                </a>

                <p class="mt-12 font-jakarta text-[2rem] font-semibold md:text-[2.5rem]">Meet the team.</p>

                <ul class="mt-5 flex -space-x-3">
                    @foreach ($team as [$name, $initials])
                        <li
                            class="flex size-[68px] items-center justify-center rounded-full bg-ink-panel font-jakarta text-sm font-bold text-white ring-2 ring-ink md:size-[84px] md:text-base"
                            title="{{ $name }}"
                        >
                            <span class="sr-only">{{ $name }}</span>
                            <span aria-hidden="true">{{ $initials }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div>
                <h3 class="font-jakarta text-xl font-bold">Quick links</h3>
                <ul class="mt-5 space-y-2">
                    @foreach ($quickLinks as [$label, $anchor])
                        <li>
                            <a
                                href="{{ $anchor }}"
                                class="font-jakarta text-[0.9375rem] text-white/80 transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                            >
                                {{ $label }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div>
                <h3 class="font-jakarta text-xl font-bold">Support</h3>
                <ul class="mt-5 space-y-2">
                    @foreach ($support as $item)
                        <li class="font-jakarta text-[0.9375rem] text-white/80">{{ $item }}</li>
                    @endforeach
                </ul>
            </div>
        </div>

        <p class="mt-20 pb-24 font-jakarta text-xs text-white/35">
            ADVS v1.0 &middot; Pamantasan ng Lungsod ng Pasig &mdash; College of Computer Studies
        </p>
    </div>

    <x-landing.demo-request />
</footer>
