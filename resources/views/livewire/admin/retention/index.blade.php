<?php

use App\Models\RetentionPolicy;
use App\Models\User;
use App\Services\RetentionPolicyService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.app'), Title('Data Retention Configuration')]
class extends Component
{
    /**
     * Form buffer keyed by policy key.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $policies = [];

    public function mount(RetentionPolicyService $service): void
    {
        abort_unless(auth()->user()?->can('viewAny', RetentionPolicy::class), 403);

        foreach ($service->all() as $policy) {
            $this->policies[$policy['key']] = $policy['value'];
        }
    }

    #[Computed]
    public function definitions(): array
    {
        return RetentionPolicy::schema();
    }

    #[Computed]
    public function existingCount(): int
    {
        return RetentionPolicy::query()->count();
    }

    public function save(RetentionPolicyService $service): void
    {
        /** @var User|null $actor */
        $actor = auth()->user();
        abort_unless($actor?->can('update', new RetentionPolicy), 403);

        try {
            $result = $service->save($this->policies, $actor);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        session()->flash('status', $result['saved'] === 1
            ? 'Saved 1 retention policy.'
            : sprintf('Saved %d retention policies.', $result['saved']));
    }

    public function resetDefaults(RetentionPolicyService $service): void
    {
        abort_unless(auth()->user()?->can('update', new RetentionPolicy), 403);

        $this->policies = [];

        foreach ($service->all() as $policy) {
            $this->policies[$policy['key']] = $policy['value'];
        }

        session()->flash('status', 'Retention settings reset to the last saved values.');
    }

    public function render(): View
    {
        return view('livewire.admin.retention.index');
    }
}; ?>

<x-page>
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
        <div class="cu-animate-in overflow-hidden rounded-3xl border border-cu-border bg-cu-surface shadow-sm">
            <div class="border-b border-cu-border bg-gradient-to-r from-cu-purple/10 via-transparent to-cu-blue/10 px-5 py-5 sm:px-6">
                <flux:breadcrumbs>
                    <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                    <flux:breadcrumbs.item>Data Retention</flux:breadcrumbs.item>
                </flux:breadcrumbs>
                <div class="mt-4 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-3xl">
                        <div class="inline-flex items-center gap-2 rounded-full border border-cu-border bg-cu-surface/80 px-3 py-1 text-xs font-medium text-cu-muted backdrop-blur">
                            <flux:icon icon="clock" class="size-3.5 text-cu-purple" />
                            Compliance controls
                        </div>
                        <h1 class="mt-3 text-2xl font-semibold tracking-tight text-cu-text sm:text-3xl">Data Retention Configuration</h1>
                        <p class="mt-2 text-sm leading-6 text-cu-muted">
                            Set retention windows for each data type, decide when records can be archived or deleted, and keep the policy surface ready for future compliance rules.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="resetDefaults" wire:loading.attr="disabled" wire:target="resetDefaults"
                            class="inline-flex items-center gap-2 rounded-xl border border-cu-border bg-cu-surface px-4 py-2.5 text-sm font-medium text-cu-muted transition hover:text-cu-text">
                        <flux:icon.loading wire:loading wire:target="resetDefaults" variant="micro" class="size-4" />
                        <flux:icon icon="arrow-uturn-left" wire:loading.remove wire:target="resetDefaults" class="size-4" />
                        Reload saved values
                    </button>
                    <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save"
                            class="inline-flex items-center gap-2 rounded-xl cu-gradient px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cu-purple/20 transition hover:opacity-90">
                        <flux:icon.loading wire:loading wire:target="save" variant="micro" class="size-4" />
                        <flux:icon icon="check" wire:loading.remove wire:target="save" class="size-4" />
                        Save policies
                    </button>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-6 px-5 py-5 sm:px-6">
                @if (session('status'))
                    <div x-data="{ show: true }" x-show="show" x-transition
                         class="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-200"
                         role="status">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="rounded-xl border border-rose-500/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-200" role="alert">
                        {{ $errors->first() }}
                    </div>
                @endif

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-2xl border border-cu-border bg-black/[0.02] p-4 dark:bg-white/[0.02]">
                        <p class="text-xs uppercase tracking-wider text-cu-muted">Policy rows</p>
                        <p class="mt-1 text-2xl font-semibold text-cu-text">{{ number_format($this->existingCount) }}</p>
                    </div>
                    <div class="rounded-2xl border border-cu-border bg-black/[0.02] p-4 dark:bg-white/[0.02]">
                        <p class="text-xs uppercase tracking-wider text-cu-muted">Enabled</p>
                        <p class="mt-1 text-2xl font-semibold text-emerald-600 dark:text-emerald-300">{{ number_format(collect($this->policies)->where('enabled', true)->count()) }}</p>
                    </div>
                    <div class="rounded-2xl border border-cu-border bg-black/[0.02] p-4 dark:bg-white/[0.02]">
                        <p class="text-xs uppercase tracking-wider text-cu-muted">Archival on</p>
                        <p class="mt-1 text-2xl font-semibold text-cu-text">{{ number_format(collect($this->policies)->where('archive_enabled', true)->count()) }}</p>
                    </div>
                    <div class="rounded-2xl border border-cu-border bg-black/[0.02] p-4 dark:bg-white/[0.02]">
                        <p class="text-xs uppercase tracking-wider text-cu-muted">Deletion on</p>
                        <p class="mt-1 text-2xl font-semibold text-cu-text">{{ number_format(collect($this->policies)->where('deletion_enabled', true)->count()) }}</p>
                    </div>
                </div>

                <div class="rounded-2xl border border-cu-border bg-black/[0.02] px-4 py-3 dark:bg-white/[0.02]">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm font-medium text-cu-text">Rules at a glance</p>
                        <p class="text-xs text-cu-muted">Expand each policy below to edit its retention window and actions.</p>
                    </div>
                </div>

        <form wire:submit="save" class="flex flex-col gap-6" novalidate>
            @foreach ($this->definitions as $definition)
                @php($key = $definition['key'])
                @php($value = $this->policies[$key] ?? [])
                @php($enabled = (bool) ($value['enabled'] ?? true))
                <section class="cu-animate-in overflow-hidden rounded-2xl border border-cu-border bg-cu-surface">
                    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-cu-border px-5 py-4">
                        <div>
                            <h2 class="text-base font-semibold text-cu-text">{{ $definition['label'] }}</h2>
                            <p class="text-xs text-cu-muted">
                                Applies to <span class="font-medium text-cu-text">{{ ucfirst($definition['data_type']) }}</span> data
                                · key <code class="font-mono text-cu-blue">{{ $key }}</code>
                            </p>
                        </div>
                        <label class="inline-flex items-center gap-2 text-sm text-cu-muted">
                            <input type="checkbox" wire:model.live="policies.{{ $key }}.enabled"
                                   class="size-4 rounded border-cu-border bg-black/5 text-cu-purple focus:ring-cu-purple/40 dark:bg-white/5" />
                            Enabled
                        </label>
                    </header>

                    <div class="grid gap-4 px-5 py-5 lg:grid-cols-[1fr_minmax(0,360px)]">
                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="grid gap-2">
                                <span class="text-xs font-medium uppercase tracking-wider text-cu-muted">Retention period (days)</span>
                                <input type="number" min="1" step="1" wire:model.live="policies.{{ $key }}.retention_days"
                                       class="w-full rounded-xl border border-cu-border bg-black/5 px-3 py-2 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40 dark:bg-white/5" />
                                @error("policies.$key.retention_days")<span class="text-xs text-rose-700 dark:text-rose-300">{{ $message }}</span>@enderror
                            </label>

                            <label class="grid gap-2">
                                <span class="text-xs font-medium uppercase tracking-wider text-cu-muted">Notes</span>
                                <input type="text" wire:model.live="policies.{{ $key }}.notes"
                                       class="w-full rounded-xl border border-cu-border bg-black/5 px-3 py-2 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40 dark:bg-white/5"
                                       placeholder="Compliance note or business rule" />
                                @error("policies.$key.notes")<span class="text-xs text-rose-700 dark:text-rose-300">{{ $message }}</span>@enderror
                            </label>
                        </div>

                        <div class="rounded-2xl border border-cu-border bg-black/[0.02] p-4 dark:bg-white/[0.02]">
                            <p class="text-xs font-medium uppercase tracking-wider text-cu-muted">Automatic actions</p>
                            <div class="mt-3 grid gap-3">
                                <label class="flex items-start gap-3 rounded-xl border border-cu-border bg-cu-surface p-3">
                                    <input type="checkbox" wire:model.live="policies.{{ $key }}.archive_enabled"
                                           class="mt-1 size-4 rounded border-cu-border bg-black/5 text-cu-purple focus:ring-cu-purple/40 dark:bg-white/5" />
                                    <span class="grid gap-1">
                                        <span class="text-sm font-medium text-cu-text">Archive automatically</span>
                                        <span class="text-xs text-cu-muted">Move records to archival storage after the threshold is reached.</span>
                                    </span>
                                </label>

                                <label class="grid gap-2">
                                    <span class="text-xs font-medium uppercase tracking-wider text-cu-muted">Archive after (days)</span>
                                    <input type="number" min="1" step="1" wire:model.live="policies.{{ $key }}.archive_after_days"
                                           @disabled(! $enabled)
                                           class="w-full rounded-xl border border-cu-border bg-black/5 px-3 py-2 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white/5" />
                                    @error("policies.$key.archive_after_days")<span class="text-xs text-rose-700 dark:text-rose-300">{{ $message }}</span>@enderror
                                </label>

                                <label class="flex items-start gap-3 rounded-xl border border-cu-border bg-cu-surface p-3">
                                    <input type="checkbox" wire:model.live="policies.{{ $key }}.deletion_enabled"
                                           class="mt-1 size-4 rounded border-cu-border bg-black/5 text-cu-purple focus:ring-cu-purple/40 dark:bg-white/5" />
                                    <span class="grid gap-1">
                                        <span class="text-sm font-medium text-cu-text">Delete automatically</span>
                                        <span class="text-xs text-cu-muted">Permanently remove records after the configured retention window.</span>
                                    </span>
                                </label>

                                <label class="grid gap-2">
                                    <span class="text-xs font-medium uppercase tracking-wider text-cu-muted">Delete after (days)</span>
                                    <input type="number" min="1" step="1" wire:model.live="policies.{{ $key }}.deletion_after_days"
                                           @disabled(! $enabled)
                                           class="w-full rounded-xl border border-cu-border bg-black/5 px-3 py-2 text-sm text-cu-text focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white/5" />
                                    @error("policies.$key.deletion_after_days")<span class="text-xs text-rose-700 dark:text-rose-300">{{ $message }}</span>@enderror
                                </label>
                            </div>
                        </div>
                    </div>
                </section>
            @endforeach

            <div class="flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-end">
                <button type="button" wire:click="resetDefaults" class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-cu-border bg-cu-surface px-4 py-2.5 text-sm font-medium text-cu-muted transition hover:text-cu-text">
                    Discard changes
                </button>
                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl cu-gradient px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cu-purple/20 transition hover:opacity-90">
                    <flux:icon icon="check" class="size-4" />
                    Save all changes
                </button>
            </div>
        </form>
    </div>
</x-page>
