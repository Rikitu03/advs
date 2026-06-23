@props([
    'title',
    'description',
])

<div class="flex w-full flex-col gap-2 text-center">
    <h1 class="text-xl font-medium text-cu-text">{{ $title }}</h1>
    <p class="text-center text-sm text-cu-muted">{{ $description }}</p>
</div>
