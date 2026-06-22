{{-- Light / Dark / System segmented control. Persists to a 7-day `theme`
     cookie and applies the `.dark` class via window.advsTheme (see
     resources/views/partials/head.blade.php). Pass `label="…"` to label it. --}}
<flux:radio.group
    x-data="{ theme: window.advsTheme.read() }"
    x-init="$watch('theme', value => window.advsTheme.set(value))"
    x-model="theme"
    variant="segmented"
    {{ $attributes }}
>
    <flux:radio value="light" icon="sun">Light</flux:radio>
    <flux:radio value="dark" icon="moon">Dark</flux:radio>
    <flux:radio value="system" icon="computer-desktop">System</flux:radio>
</flux:radio.group>
