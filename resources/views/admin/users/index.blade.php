<x-layouts.app.sidebar>
    <flux:main class="flex min-h-svh flex-col">
        <livewire:admin.users.index :role-counts="$roleCounts" :active-count="$activeCount" :total-count="$totalCount" />
    </flux:main>
</x-layouts.app.sidebar>
