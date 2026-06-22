<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="flex flex-col items-start">
    @include('partials.settings-heading')

    <x-settings.layout heading="Preference" subheading="Choose how the interface looks to you">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance" label="Interface theme">
            <flux:radio value="light" icon="sun">Light</flux:radio>
            <flux:radio value="dark" icon="moon">Dark</flux:radio>
            <flux:radio value="system" icon="computer-desktop">System</flux:radio>
        </flux:radio.group>

        <flux:text class="mt-3">
            System follows your device setting and defaults to light.
        </flux:text>
    </x-settings.layout>
</div>
