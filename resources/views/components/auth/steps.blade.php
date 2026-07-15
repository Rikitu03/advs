@props(['current'])

@php
    /*
     * The registration pipeline. Order is not decoration — it is enforced:
     * CreateNewUser makes the account, EnsureVendorProfileComplete gates the
     * signature step behind the business details, EnsureSignatureEnrolled gates
     * email verification behind the enrolled signature. This component is the
     * single source of truth for those four stages; the three pages that show
     * the rail (register, business-details, signature-enroll) all read it from
     * here, so a step can never again go missing from one of them.
     */
    // Labels stay short because all four have to sit on one line inside the card
    // without the last one being clipped — "Verify email" is the page's title, not
    // the rail's job.
    $steps = [
        'account' => __('Account'),
        'business' => __('Details'),
        'signature' => __('Signature'),
        'verify' => __('Verify'),
    ];

    $keys = array_keys($steps);
    $currentIndex = array_search($current, $keys, true);
@endphp

<div>
    <p class="sr-only">
        {{ __('Step :number of :total', ['number' => $currentIndex + 1, 'total' => count($steps)]) }}
    </p>

    {{-- The rail reads the way the metrics panel's scanner rail does: the connector
         behind you is filled ink, the road ahead is bare. Every dot is the same size,
         so the row keeps one baseline whatever a dot happens to contain. --}}
    <ol class="flex items-center gap-2">
        @foreach ($steps as $key => $label)
            @php
                $index = array_search($key, $keys, true);
                $isDone = $index < $currentIndex;
                $isCurrent = $index === $currentIndex;
            @endphp

            @if (! $loop->first)
                <li
                    @class([
                        'h-0.5 min-w-3 flex-1 rounded-full',
                        'bg-ink' => $isDone || $isCurrent,
                        'bg-ink-mute' => ! $isDone && ! $isCurrent,
                    ])
                    aria-hidden="true"
                ></li>
            @endif

            <li
                class="flex shrink-0 items-center gap-1.5"
                @if ($isCurrent) aria-current="step" @endif
            >
                <span
                    @class([
                        'flex size-6 shrink-0 items-center justify-center rounded-full font-inter text-[0.6875rem] font-semibold',
                        'bg-ink text-white' => $isDone,
                        'bg-flame text-white' => $isCurrent,
                        'bg-white text-ink/35 ring-1 ring-ink-mute' => ! $isDone && ! $isCurrent,
                    ])
                >
                    @if ($isDone)
                        <svg class="size-3" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.25" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m3.5 8.5 3 3 6-7" />
                        </svg>
                    @else
                        {{ $index + 1 }}
                    @endif
                </span>

                {{-- Only the current step keeps its label on a narrow screen: four
                     labels in a row is what collapsed the rail in the first place. --}}
                <span
                    @class([
                        'font-inter text-[0.6875rem] font-semibold whitespace-nowrap',
                        'text-flame' => $isCurrent,
                        'hidden text-ink/70 sm:inline' => $isDone,
                        'hidden text-ink/35 sm:inline' => ! $isDone && ! $isCurrent,
                    ])
                >
                    {{ $label }}
                </span>
            </li>
        @endforeach
    </ol>
</div>
