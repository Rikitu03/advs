@php($user = auth()->user())

<!DOCTYPE html>
{{-- Server-side theme guard: render `class="dark"` from the 7-day `theme`
     cookie so the choice holds on the first paint AND on every wire:navigate
     response (the <head> runtime only runs once). `system` is resolved
     client-side. See partials/head.blade.php + bootstrap/app.php (cookie). --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => request()->cookie('theme') === 'dark'])>
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white text-ink dark:bg-cu-bg dark:text-cu-text">
        <flux:sidebar sticky stashable class="border-r border-ink-mute bg-white text-ink dark:border-white/10 dark:bg-cu-surface dark:text-cu-text">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('dashboard') }}" class="mr-5 flex items-center space-x-2" wire:navigate>
                <x-app-logo class="size-8" href="#"></x-app-logo>
            </a>

            <flux:navlist variant="outline">
                @if ($user->hasRole(\App\Models\User::ROLE_VENDOR))
                    <flux:navlist.group heading="Vendor" class="grid">
                        <flux:navlist.item icon="home" :href="route('vendor.dashboard')" :current="request()->routeIs('vendor.dashboard')" wire:navigate>Dashboard</flux:navlist.item>
                        <flux:navlist.item icon="arrow-up-tray" :href="route('vendor.submit')" :current="request()->routeIs('vendor.submit')" wire:navigate>Submit Documents</flux:navlist.item>
                        <flux:navlist.item icon="document-text" :href="route('vendor.submissions')" :current="request()->routeIs('vendor.submissions')" wire:navigate>My Submissions</flux:navlist.item>
                        <flux:navlist.item icon="bell" :href="route('vendor.notifications')" :current="request()->routeIs('vendor.notifications')" :badge="$user->notifications()->unread()->count() ?: null" wire:navigate>Notifications</flux:navlist.item>
                        <flux:navlist.item icon="user-circle" :href="route('vendor.profile')" :current="request()->routeIs('vendor.profile')" wire:navigate>Profile</flux:navlist.item>
                    </flux:navlist.group>
                @else
                    <flux:navlist.group heading="Review" class="grid">
                        <flux:navlist.item icon="home" :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard')" wire:navigate>Dashboard</flux:navlist.item>
                        <flux:navlist.item icon="inbox-stack" :href="route('admin.pending')" :current="request()->routeIs('admin.pending') || request()->routeIs('admin.submissions.*')" wire:navigate>Pending Submissions</flux:navlist.item>
                        <flux:navlist.item icon="archive-box" :href="route('admin.archived')" :current="request()->routeIs('admin.archived')" wire:navigate>Archived Reports</flux:navlist.item>
                        <flux:navlist.item icon="identification" :href="route('admin.vendors')" :current="request()->routeIs('admin.vendors') || request()->routeIs('admin.vendors.*')" wire:navigate>Vendor Profiles</flux:navlist.item>
                        <flux:navlist.item icon="clipboard-document-list" :href="route('admin.risk-logs')" :current="request()->routeIs('admin.risk-logs')" wire:navigate>Risk Logs</flux:navlist.item>
                        <flux:navlist.item icon="bell" :href="route('admin.notifications')" :current="request()->routeIs('admin.notifications')" :badge="$user->notifications()->unread()->count() ?: null" wire:navigate>Notifications</flux:navlist.item>
                    </flux:navlist.group>

                    @if ($user->hasRole(\App\Models\User::ROLE_ADMIN))
                        <flux:navlist.group heading="Administration" class="mt-2 grid">
                            <flux:navlist.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>User Management</flux:navlist.item>
                            <flux:navlist.item icon="cog-6-tooth" :href="route('admin.settings.index')" :current="request()->routeIs('admin.settings.*')" wire:navigate>System Settings</flux:navlist.item>
                            <flux:navlist.item icon="clock" :href="route('admin.retention.index')" :current="request()->routeIs('admin.retention.*')" wire:navigate>Data Retention</flux:navlist.item>
                            <flux:navlist.item icon="cpu-chip" :href="route('admin.models.index')" :current="request()->routeIs('admin.models.*')" wire:navigate>ML Models</flux:navlist.item>
                            <flux:navlist.item icon="shield-check" :href="route('admin.audit.index')" :current="request()->routeIs('admin.audit.*')" wire:navigate>Audit Trail</flux:navlist.item>
                        </flux:navlist.group>
                    @endif
                @endif
            </flux:navlist>

            <flux:spacer />

            <!-- Desktop User Menu -->
            <flux:dropdown position="bottom" align="start">
                <flux:profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevrons-up-down"
                />

                <flux:menu class="w-[220px]">
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-left text-sm leading-tight">
                                    <span class="truncate font-semibold text-cu-text">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs text-cu-muted">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item href="/settings/profile" icon="cog" wire:navigate>Settings</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-left text-sm leading-tight">
                                    <span class="truncate font-semibold text-cu-text">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs text-cu-muted">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item href="/settings/profile" icon="cog" wire:navigate>Settings</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @fluxScripts
    </body>
</html>
