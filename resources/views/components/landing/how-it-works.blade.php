@php
    // The frame alternates a 288px description square with a 512px media panel,
    // four times. Numbering is earned here: this is a genuine sequence, and the
    // order is what the reader needs. Media panels stay reserved for real captures.
    $stages = [
        [
            'step' => '01',
            'title' => 'Submit',
            'body' => 'A vendor uploads a permit, registration or financial statement. PDF, JPEG or PNG, up to 10 MB.',
        ],
        [
            'step' => '02',
            'title' => 'Read',
            'body' => 'OpenCV cleans the scan, Tesseract lifts the text, and ResNet-50 names the document type.',
        ],
        [
            'step' => '03',
            'title' => 'Verify',
            'body' => 'YOLOv8 finds the signature and the stamp. A Siamese network compares the signature to the one enrolled at registration; EfficientNet matches the stamp to the issuer’s reference seal.',
        ],
        [
            'step' => '04',
            'title' => 'Decide',
            'body' => 'Every check feeds one risk score. The compliance officer sees the evidence and makes the call.',
        ],
    ];
@endphp

<section id="how-it-works" class="bg-white px-4 pb-16 md:px-6 md:pb-24">
    <div class="mx-auto max-w-[1024px] rounded-[24px] bg-ink px-5 py-14 md:rounded-[50px] md:px-14 md:py-20">
        <h2 class="mx-auto max-w-[747px] text-center font-jakarta text-[2rem] leading-[1.1] font-extrabold tracking-tight text-white sm:text-[2.75rem] md:text-[3.75rem] md:leading-[1.26]">
            Automate your entire workflow with one click.
        </h2>

        <p class="mt-4 text-center font-jakarta text-2xl font-bold text-ink-rail md:mt-6 md:text-[2.1875rem]">
            How it works?
        </p>

        <div class="mt-12 space-y-6 md:mt-16 md:space-y-8">
            @foreach ($stages as $index => $stage)
                <div class="grid gap-6 md:grid-cols-[288px_minmax(0,1fr)] md:gap-8">
                    <div @class([
                        'flex flex-col justify-between rounded-[28px] bg-ink-panel p-7 md:rounded-[50px] md:p-9',
                        'md:order-2' => $index % 2 === 1,
                    ])>
                        <span class="font-jakarta text-[0.6875rem] tracking-[0.2em] text-white/35 tabular-nums">
                            {{ $stage['step'] }}
                        </span>

                        <div class="mt-6 md:mt-10">
                            <h3 class="font-jakarta text-2xl font-extrabold text-white">{{ $stage['title'] }}</h3>
                            <p class="mt-2.5 font-jakarta text-[0.875rem] leading-relaxed text-white/60">
                                {{ $stage['body'] }}
                            </p>
                        </div>
                    </div>

                    {{-- Reserved for a screen capture of this stage. Deliberately left as a
                         labelled surface rather than a stock image, so nothing on the page
                         depicts a screen that does not exist. --}}
                    <div @class([
                        'flex aspect-[16/9] items-end rounded-[20px] bg-ink-rail p-6 md:aspect-auto md:min-h-[288px]',
                        'md:order-1' => $index % 2 === 1,
                    ])>
                        <span class="font-jakarta text-[0.6875rem] tracking-[0.18em] text-ink/40 uppercase">
                            {{ $stage['title'] }} &mdash; capture pending
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
