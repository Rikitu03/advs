@props(['model', 'title', 'detail', 'tone' => 'ink', 'side' => 'left'])

{{-- The frame's testimonial bubble, repurposed: a chip carrying one verdict, with
     the avatar of the model that produced it hanging off the corner nearest the edge
     of the hero it sits on. Tone alternates ink / flame across the hero, exactly as
     drawn. On hover it leans a little further toward that same edge — the same side
     the avatar already hangs off. --}}
<div @class([
    'relative flex min-h-20 flex-col justify-center rounded-[30px] px-6 py-3.5 transition-transform duration-300 ease-out motion-reduce:transition-none',
    'bg-ink' => $tone === 'ink',
    'bg-flame' => $tone === 'flame',
    'hover:-translate-x-1 hover:-rotate-2 motion-reduce:hover:transform-none' => $side === 'left',
    'hover:translate-x-1 hover:rotate-2 motion-reduce:hover:transform-none' => $side === 'right',
])>
    <p class="font-inter text-[0.9375rem] leading-[1.21] font-semibold text-white">
        {{ $title }}
    </p>
    <p @class([
        'mt-0.5 font-inter text-[0.8125rem] leading-[1.21]',
        'text-white/55' => $tone === 'ink',
        'text-white/75' => $tone === 'flame',
    ])>
        {{ $detail }}
    </p>

    <x-landing.model-avatar
        :model="$model"
        @class([
            'absolute -bottom-2.5 size-10 text-[0.6875rem] ring-2 ring-white',
            '-left-2.5' => $side === 'left',
            '-right-2.5' => $side === 'right',
        ])
    />

    <span class="sr-only">Verdict from {{ $model['name'] }}</span>
</div>
