<x-layouts.app>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-1">
            <flux:heading size="xl">Vendor Portal</flux:heading>
            <flux:subheading>Welcome back, {{ auth()->user()->name }}. Submit and track your accreditation documents.</flux:subheading>
        </div>

        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:text class="text-sm text-zinc-500">Submitted</flux:text>
                <p class="mt-1 text-3xl font-semibold">0</p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:text class="text-sm text-zinc-500">Under review</flux:text>
                <p class="mt-1 text-3xl font-semibold">0</p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:text class="text-sm text-zinc-500">Approved</flux:text>
                <p class="mt-1 text-3xl font-semibold">0</p>
            </div>
        </div>

        <div class="flex flex-col items-start gap-4 rounded-xl border border-dashed border-neutral-300 p-8 dark:border-neutral-700">
            <flux:heading size="lg">Submit accreditation documents</flux:heading>
            <flux:subheading>Upload BIR permits, financial statements, or business registration certificates (PDF/JPG/PNG, max 10&nbsp;MB).</flux:subheading>
            <flux:button variant="primary" icon="arrow-up-tray" disabled>Upload documents</flux:button>
            <flux:text class="text-xs text-zinc-500">Document submission is wired up in a later phase (Phase 7).</flux:text>
        </div>
    </div>
</x-layouts.app>
