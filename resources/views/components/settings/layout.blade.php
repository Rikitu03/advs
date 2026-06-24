<div class="flex items-start gap-8 max-md:flex-col">
    <div class="w-full pb-4 md:w-[220px]">
        <flux:navlist>
            <flux:navlist.item href="{{ route('settings.profile') }}" wire:navigate>Profile</flux:navlist.item>
            <flux:navlist.item href="{{ route('settings.password') }}" wire:navigate>Password</flux:navlist.item>
            <flux:navlist.item href="{{ route('settings.preference') }}" wire:navigate>Preference</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
