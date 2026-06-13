@props([
    'heading' => '',
    'text' => '',
    'variant' => 'info',
    'duration' => 6000,
])

{{-- Fires a Flux toast from server-rendered Blade (no Livewire component needed).
     Polls briefly for window.Flux because @fluxScripts boots after this markup. --}}
<script>
    (function () {
        const payload = {
            heading: @js($heading),
            text: @js($text),
            variant: @js($variant),
            duration: {{ (int) $duration }},
        };

        let attempts = 0;

        (function show() {
            if (window.Flux && typeof window.Flux.toast === 'function') {
                window.Flux.toast(payload);
            } else if (attempts++ < 50) {
                setTimeout(show, 100);
            }
        })();
    })();
</script>
