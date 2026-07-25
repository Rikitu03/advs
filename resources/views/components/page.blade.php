{{-- Themed, viewport-filling page wrapper. As a flex-1 child of the flex-column
     <flux:main> (min-h-svh), it always fills the viewport so the background never
     collapses to content height when a search/filter returns few or no rows.
     The -m-6/lg:-m-8 bleed + matching padding paints edge-to-edge under the
     layout's own padding. --}}
<div {{ $attributes->class('-m-6 flex min-h-full flex-1 flex-col bg-white p-6 text-ink lg:-m-8 lg:p-8 dark:bg-cu-bg dark:text-cu-text') }}>
    {{ $slot }}
</div>
