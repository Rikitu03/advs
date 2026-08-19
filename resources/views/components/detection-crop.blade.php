@props(['url', 'box', 'label' => 'Detected region'])

@php
    [$x1, $y1, $x2, $y2] = $box;
    $boxWidth = max(1.0, $x2 - $x1);
    $boxHeight = max(1.0, $y2 - $y1);
    $scale = round(min(220 / $boxWidth, 160 / $boxHeight), 4);
    $displayWidth = max(1, (int) round($boxWidth * $scale));
    $displayHeight = max(1, (int) round($boxHeight * $scale));
    $offsetX = round(-$x1 * $scale, 2);
    $offsetY = round(-$y1 * $scale, 2);
@endphp

<div class="flex flex-col gap-2">
    <div class="relative overflow-hidden rounded-lg border border-cu-border bg-black/[0.03] dark:bg-white/[0.03]"
         style="width: {{ $displayWidth }}px; height: {{ $displayHeight }}px;"
         x-data="{ ready: false, valid: false, natW: 0 }">
        <img src="{{ $url }}"
             alt="{{ $label }}"
             x-on:load="natW = $event.target.naturalWidth; valid = {{ $x2 }} <= $event.target.naturalWidth && {{ $y2 }} <= $event.target.naturalHeight; ready = true"
             class="absolute max-w-none transition-opacity"
             :class="ready && valid ? 'opacity-100' : 'opacity-0'"
             :style="`width:${natW * {{ $scale }}}px; left:{{ $offsetX }}px; top:{{ $offsetY }}px;`" />
        <div x-show="!ready" class="absolute inset-0 flex items-center justify-center text-xs text-cu-muted">
            Loading…
        </div>
        <div x-show="ready && !valid" x-cloak class="absolute inset-0 flex items-center justify-center px-3 text-center text-xs text-cu-muted">
            Preview unavailable
        </div>
    </div>
    <p class="text-xs text-cu-muted">{{ $label }}</p>
</div>
