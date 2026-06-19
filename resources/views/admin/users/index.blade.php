<x-layouts.app.sidebar>
    <flux:main>
        <livewire:admin.users.index :role-counts="$roleCounts" :active-count="$activeCount" :total-count="$totalCount" />
    </flux:main>
</x-layouts.app.sidebar>
