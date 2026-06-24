<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="flex flex-col items-start">
    @include('partials.settings-heading')

    <x-settings.layout heading="Preference" subheading="Choose how the interface looks to you">
        <x-theme-toggle label="Interface theme" />

        <flux:text class="mt-3">
            System follows your device setting and defaults to light.
        </flux:text>
    </x-settings.layout>
</div>
