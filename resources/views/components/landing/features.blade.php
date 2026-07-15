@php
    // Six cards, two rows of three — mirroring the frame's Group 4 / Group 5.
    // Every one of these maps to a route that exists today (routes/web.php).
    $features = [
        [
            'title' => 'Document submission',
            'body' => 'Vendors upload accreditation documents to private storage. MIME type and size are checked server-side before anything is queued.',
        ],
        [
            'title' => 'Validation pipeline',
            'body' => 'Preprocessing, OCR, classification, signature and stamp verification run as one queued job, with retries and a failure trail.',
        ],
        [
            'title' => 'Risk scoring',
            'body' => 'Each check contributes a weighted signal. One score decides whether a submission is cleared or flagged for a closer look.',
        ],
        [
            'title' => 'Officer review',
            'body' => 'The compliance officer sees the extracted text, the model verdicts and the evidence crops side by side, then approves or rejects.',
        ],
        [
            'title' => 'Renewal monitoring',
            'body' => 'Expiry dates read from the document are tracked. Vendors are reminded before a permit lapses, not after.',
        ],
        [
            'title' => 'Audit trail',
            'body' => 'Every decision, flag and threshold change is written to an append-only log that an administrator can search and export.',
        ],
    ];
@endphp

<section id="features" class="bg-white py-16 md:py-24">
    <div class="mx-auto max-w-[1024px] px-6 lg:px-0">
        <span class="inline-flex h-[52px] items-center rounded-[20px] bg-ink px-8 font-jakarta text-lg font-semibold text-white md:text-xl">
            Core features
        </span>

        <h2 class="mt-8 max-w-[747px] font-jakarta text-[2.25rem] leading-[1.1] font-extrabold tracking-tight text-black sm:text-5xl md:text-[3.75rem] md:leading-[1.17]">
            Every component in the validation engine.
        </h2>

        <ul class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($features as $feature)
                <li class="flex flex-col rounded-[30px] bg-ink-rail/45 p-7 transition hover:bg-ink-rail/75">
                    <h3 class="font-jakarta text-xl font-extrabold text-black">{{ $feature['title'] }}</h3>
                    <p class="mt-3 font-jakarta text-[0.875rem] leading-relaxed text-ink/65">
                        {{ $feature['body'] }}
                    </p>
                </li>
            @endforeach
        </ul>
    </div>
</section>
