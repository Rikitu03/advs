<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-cu-bg">
        <flux:sidebar sticky stashable class="border-r border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('dashboard') }}" class="mr-5 flex items-center space-x-2" wire:navigate>
                <x-app-logo class="size-8" href="#"></x-app-logo>
            </a>

            @php($user = auth()->user())

            <flux:navlist variant="outline">
                @if ($user->hasRole(\App\Models\User::ROLE_VENDOR))
                    <flux:navlist.group heading="Vendor" class="grid">
                        <flux:navlist.item icon="home" :href="route('vendor.dashboard')" :current="request()->routeIs('vendor.dashboard')" wire:navigate>Dashboard</flux:navlist.item>
                        <x-nav-soon icon="arrow-up-tray" label="Submit Documents" />
                        <x-nav-soon icon="document-text" label="My Submissions" />
                        <x-nav-soon icon="bell" label="Notifications" />
                    </flux:navlist.group>
                @else
                    <flux:navlist.group heading="Review" class="grid">
                        <flux:navlist.item icon="home" :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard')" wire:navigate>Dashboard</flux:navlist.item>
                        <flux:navlist.item icon="inbox-stack" :href="route('admin.pending')" :current="request()->routeIs('admin.pending') || request()->routeIs('admin.submissions.*')" wire:navigate>Pending Submissions</flux:navlist.item>
                        <flux:navlist.item icon="archive-box" :href="route('admin.archived')" :current="request()->routeIs('admin.archived')" wire:navigate>Archived Reports</flux:navlist.item>
                        <flux:navlist.item icon="identification" :href="route('admin.vendors')" :current="request()->routeIs('admin.vendors') || request()->routeIs('admin.vendors.*')" wire:navigate>Vendor Profiles</flux:navlist.item>
                        <flux:navlist.item icon="clipboard-document-list" :href="route('admin.risk-logs')" :current="request()->routeIs('admin.risk-logs')" wire:navigate>Risk Logs</flux:navlist.item>
                        <flux:navlist.item icon="bell" :href="route('admin.notifications')" :current="request()->routeIs('admin.notifications')" :badge="\App\Support\DemoStore::unreadCount() ?: null" wire:navigate>Notifications</flux:navlist.item>
                    </flux:navlist.group>

                    @if ($user->hasRole(\App\Models\User::ROLE_ADMIN))
                        <flux:navlist.group heading="Administration" class="mt-2 grid">
                            <flux:navlist.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>User Management</flux:navlist.item>
                            <x-nav-soon icon="cog-6-tooth" label="System Settings" />
                            <x-nav-soon icon="cpu-chip" label="ML Models" />
                            <x-nav-soon icon="shield-check" label="Audit Trail" />
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
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
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
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
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
