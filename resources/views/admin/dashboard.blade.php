<x-layouts.app>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-1">
            <flux:heading size="xl">Compliance Dashboard</flux:heading>
            <flux:subheading>
                Signed in as {{ auth()->user()->name }}
                <flux:badge size="sm" color="zinc">{{ str(auth()->user()->role)->headline() }}</flux:badge>
            </flux:subheading>
        </div>

        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:text class="text-sm text-zinc-500">Pending review</flux:text>
                <p class="mt-1 text-3xl font-semibold">0</p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:text class="text-sm text-zinc-500">Flagged (risk ≥ 70)</flux:text>
                <p class="mt-1 text-3xl font-semibold text-red-600">0</p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:text class="text-sm text-zinc-500">Approval rate</flux:text>
                <p class="mt-1 text-3xl font-semibold">—</p>
            </div>
        </div>

        <div class="rounded-xl border border-dashed border-neutral-300 p-8 dark:border-neutral-700">
            <flux:heading size="lg">Validation reports</flux:heading>
            <flux:subheading class="mt-1">Review AI-generated risk scores, OCR text, and signature/stamp matches, then issue the final accreditation decision.</flux:subheading>
            <flux:text class="mt-4 text-xs text-zinc-500">The reports table and review screens are built in Phase 8.</flux:text>
        </div>
    </div>
</x-layouts.app>
