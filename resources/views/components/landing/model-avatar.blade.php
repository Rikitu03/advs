@props(['model'])

{{-- One model's portrait. Falls back to a lettered disc until `avatar` is set in
     config/landing.php, so the roster reads as deliberate rather than broken. --}}
@if ($model['avatar'])
    <img
        src="{{ asset($model['avatar']) }}"
        alt="{{ $model['name'] }}"
        {{ $attributes->merge(['class' => 'rounded-full object-cover ring-1 ring-black/10']) }}
    >
@else
    <span
        {{ $attributes->merge(['class' => 'flex items-center justify-center rounded-full bg-ink-mute font-jakarta font-extrabold text-ink/40 ring-1 ring-black/5']) }}
        aria-hidden="true"
    >
        {{ $model['initials'] }}
    </span>
@endif
