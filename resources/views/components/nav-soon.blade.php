@props(['icon', 'label'])

{{-- A non-interactive sidebar entry for a section that is specced (see
     ADVS_System_Reference.md §4) but not yet built. Keeps the full information
     architecture visible without dead links. --}}
<div
    {{ $attributes->merge(['class' => 'flex items-center gap-3 rounded-lg px-2.5 py-1.5 text-sm text-cu-muted']) }}
    aria-disabled="true"
    title="Coming soon"
>
    <flux:icon :icon="$icon" class="size-5 shrink-0 opacity-50" />
    <span class="flex-1 truncate">{{ $label }}</span>
    <flux:badge size="sm" color="zinc">Soon</flux:badge>
</div>
