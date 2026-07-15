@php
    // A stylised diagram of a validation report — the same vocabulary an officer
    // sees on the real review screen, not a screenshot. Values are illustrative.
    $checks = [
        ['Document type', 'BIR Permit', '96%'],
        ['Text extraction', 'Complete', '0.94'],
        ['Signature', 'Matched', '0.91'],
        ['Official stamp', 'Verified', '0.95'],
        ['Expiry date', 'Valid until 31 Dec 2026', '—'],
    ];
@endphp

{{-- `min-w-0` is load-bearing: the truncating cells below are `white-space: nowrap`,
     so without it their full text width becomes this figure's min-content and inflates
     the parent grid's mobile column past the viewport. --}}
<figure class="w-full min-w-0">
    <div class="bg-ink-mute p-4 sm:p-8 lg:min-h-[563px] lg:flex lg:items-center">
        <div class="w-full rounded-[12px] bg-white p-5 shadow-sm sm:p-7">
            <div class="flex items-start justify-between gap-4 border-b border-ink-hair/40 pb-5">
                <div>
                    <p class="font-jakarta text-[0.6875rem] tracking-[0.14em] text-ink/45 uppercase">
                        Validation report
                    </p>
                    <p class="mt-1 font-jakarta text-lg font-extrabold text-black">
                        Northwind Foods Inc.
                    </p>
                </div>

                <div class="shrink-0 text-right">
                    <p class="font-jakarta text-[0.6875rem] tracking-[0.14em] text-ink/45 uppercase">
                        Risk
                    </p>
                    <p class="font-jakarta text-3xl leading-none font-extrabold text-black">12</p>
                </div>
            </div>

            <dl class="divide-y divide-ink-hair/30">
                @foreach ($checks as [$label, $result, $score])
                    <div class="flex items-center gap-3 py-3">
                        <svg class="size-[15px] shrink-0 text-ink" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m3 8.5 3 3 7-8" />
                        </svg>
                        <dt class="w-28 shrink-0 font-jakarta text-[0.8125rem] text-ink/55">{{ $label }}</dt>
                        <dd class="min-w-0 flex-1 truncate font-jakarta text-[0.8125rem] font-semibold text-black">{{ $result }}</dd>
                        <dd class="shrink-0 font-jakarta text-[0.8125rem] tabular-nums text-ink/45">{{ $score }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-4 rounded-lg bg-ink px-4 py-3 font-jakarta text-[0.8125rem] font-semibold text-white">
                Awaiting compliance officer decision
            </p>
        </div>
    </div>

    <figcaption class="mt-3 text-center font-jakarta text-xs text-ink/45">
        Illustrative report. Every figure on the real screen comes from the pipeline.
    </figcaption>
</figure>
