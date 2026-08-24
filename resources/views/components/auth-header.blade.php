@props([
    'title',
    'description',
])

<div class="flex w-full flex-col gap-2 text-center">
    <h1 class="font-jakarta text-2xl leading-[1.15] font-extrabold tracking-tight text-black">{{ $title }}</h1>
    <p class="font-jakarta text-sm leading-[1.5] text-ink/60">{{ $description }}</p>
</div>
