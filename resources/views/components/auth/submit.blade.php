{{-- The one thing we want the visitor to do, so it carries the page's only fill of
     flame — the same accent, and the same pill, the landing page gives its CTA. --}}
<button
    {{ $attributes->merge([
        'type' => 'submit',
        'class' => 'inline-flex h-13 w-full items-center justify-center gap-2 rounded-full bg-flame px-8 font-jakarta text-[0.9375rem] font-semibold text-white transition hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-flame disabled:cursor-not-allowed disabled:opacity-60',
    ]) }}
>
    {{ $slot }}
</button>
