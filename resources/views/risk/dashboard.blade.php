<x-layouts.app>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-1">
            <flux:heading size="xl">Risk Overview</flux:heading>
            <flux:subheading>Aggregate submission trends and compliance status, {{ auth()->user()->name }}.</flux:subheading>
        </div>

        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:text class="text-sm text-zinc-500">Total submissions</flux:text>
                <p class="mt-1 text-3xl font-semibold">0</p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:text class="text-sm text-zinc-500">Avg. risk score</flux:text>
                <p class="mt-1 text-3xl font-semibold">—</p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:text class="text-sm text-zinc-500">High-risk vendors</flux:text>
                <p class="mt-1 text-3xl font-semibold text-red-600">0</p>
            </div>
        </div>

        <div class="relative h-64 rounded-xl border border-dashed border-neutral-300 p-8 dark:border-neutral-700">
            <flux:heading size="lg">Submission & risk trends</flux:heading>
            <flux:subheading class="mt-1">Charts and aggregate analytics will render here as data accumulates.</flux:subheading>
        </div>
    </div>
</x-layouts.app>
