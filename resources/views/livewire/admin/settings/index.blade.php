<?php

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin "System Settings" page.
 *
 * Renders every tunable parameter declared in {@see SystemSetting::schema()},
 * grouped by category, with one live-editable input per row. A single
 * "Save changes" submit validates and persists the entire batch through
 * {@see SystemSettingsService::updateMany()}. Each row also has a
 * "Reset" button that restores the schema default in one click.
 *
 * Authorization is enforced by the `role:admin` route middleware + the
 * `SystemSettingPolicy`, so non-admins can never mount this page.
 */
new #[Layout('components.layouts.app'), Title('System Settings')]
class extends Component
{
    /**
     * Live-editable value for every known setting. Keyed by setting key.
     *
     * @var array<string, string>
     */
    public array $values = [];

    /**
     * Tracks which rows the user has touched so we can show
     * "Unsaved changes" hints.
     *
     * @var array<string, bool>
     */
    public array $dirty = [];

    public function mount(SystemSettingsService $service): void
    {
        // Mirror the controller-level guard so a non-admin cannot mount.
        abort_unless(auth()->user()?->can('viewAny', SystemSetting::class), 403);

        // Seed the form with the persisted value (or schema default if the
        // row hasn't been written yet — happens on a fresh database).
        $flat = $service->flat();

        foreach (SystemSetting::schema() as $rows) {
            foreach ($rows as $field) {
                $key = $field['key'];
                $this->values[$key] = array_key_exists($key, $flat)
                    ? (string) $flat[$key]
                    : SystemSetting::stringify($field['default']);
            }
        }
    }

    /**
     * Mark a field as dirty whenever it changes so the UI can hint at
     * pending changes.
     */
    public function updatedValues(string $value, string $key): void
    {
        $this->dirty[$key] = true;
    }

    /**
     * Reset the dirty flag (called after a successful save).
     */
    public function clearDirty(): void
    {
        $this->dirty = [];
    }

    /**
     * Persist every changed setting inside a single transaction. Validation
     * uses the same rule set the FormRequest would apply so the UI shows
     * the exact same errors as a server-side submit.
     */
    public function save(SystemSettingsService $service): void
    {
        /** @var User|null $actor */
        $actor = auth()->user();
        abort_unless($actor?->can('update', new SystemSetting), 403);

        try {
            // Service throws ValidationException on failure; Livewire
            // automatically populates $errors from that bag.
            $service->updateMany($this->values, $actor);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->clearDirty();

        session()->flash('status', 'System settings updated successfully.');
    }

    /**
     * Restore a single key to its schema default — wired to each row's
     * "Reset" button.
     */
    public function resetKey(string $key, SystemSettingsService $service): void
    {
        /** @var User|null $actor */
        $actor = auth()->user();
        abort_unless($actor?->can('update', new SystemSetting), 403);

        try {
            $service->reset($key, $actor);
        } catch (\InvalidArgumentException $e) {
            $this->addError('values.'.$key, $e->getMessage());

            return;
        }

        $default = $service->defaultFor($key);
        $this->values[$key] = SystemSetting::stringify($default);
        unset($this->dirty[$key]);

        session()->flash('status', "Setting \"{$key}\" reset to its default value.");
    }

    /**
     * Reload the persisted values into the form. Useful after a destructive
     * action or to abort unsaved edits.
     */
    public function reload(SystemSettingsService $service): void
    {
        abort_unless(auth()->user()?->can('viewAny', SystemSetting::class), 403);

        $flat = $service->flat();

        foreach (SystemSetting::schema() as $rows) {
            foreach ($rows as $field) {
                $key = $field['key'];
                $this->values[$key] = array_key_exists($key, $flat)
                    ? (string) $flat[$key]
                    : SystemSetting::stringify($field['default']);
            }
        }

        $this->clearDirty();
    }

    /**
     * The grouped schema with persisted values overlaid, used by the view
     * to render every section and row. Cached for the request lifetime.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    #[Computed]
    public function grouped(): array
    {
        return app(SystemSettingsService::class)->all();
    }

    /**
     * Lookup of the most recent change timestamp + actor per key, for the
     * "Last updated" hint on each row.
     *
     * @return array<string, array{at: ?Carbon, by: ?string}>
     */
    #[Computed]
    public function audit(): array
    {
        return SystemSetting::query()
            ->with('updater:id,name,email')
            ->get()
            ->mapWithKeys(function (SystemSetting $s): array {
                return [
                    $s->key => [
                        'at' => $s->updated_at,
                        'by' => $s->updater?->name,
                    ],
                ];
            })
            ->all();
    }

    public function render(): View
    {
        return view('livewire.admin.settings.index');
    }
}; ?>

<x-page>
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6">

        {{-- Header --}}
        <div class="cu-animate-in flex flex-col gap-2">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>System Settings</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-cu-text">System Settings</h1>
                    <p class="text-sm text-cu-muted">
                        Tune validation thresholds, file constraints, and risk-score weights. Changes apply immediately to new submissions.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="reload" wire:loading.attr="disabled" wire:target="reload"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-cu-border bg-cu-surface px-3 py-2 text-sm font-medium text-cu-muted transition hover:border-cu-border hover:text-cu-text">
                        <flux:icon.loading wire:loading wire:target="reload" variant="micro" class="size-4" />
                        <flux:icon icon="arrow-path" wire:loading.remove wire:target="reload" class="size-4" />
                        Reload
                    </button>
                    <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save"
                            class="inline-flex items-center gap-2 rounded-xl cu-gradient px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cu-purple/20 transition hover:opacity-90">
                        <flux:icon.loading wire:loading wire:target="save" variant="micro" class="size-4" />
                        <flux:icon icon="check" wire:loading.remove wire:target="save" class="size-4" />
                        Save changes
                    </button>
                </div>
            </div>
        </div>

        {{-- Flash messages --}}
        @if (session('status'))
            <div x-data="{ show: true }" x-show="show" x-transition
                 class="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-200"
                 role="status">
                {{ session('status') }}
            </div>
        @endif

        @error('general')
            <div class="rounded-xl border border-rose-500/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-200" role="alert">
                {{ $message }}
            </div>
        @enderror

        {{-- Settings form --}}
        <form wire:submit="save" class="flex flex-col gap-6" novalidate>

            @php($groupIndex = 0)
            @foreach ($this->grouped as $category => $fields)
                @php($groupIndex++)
                <section class="cu-animate-in overflow-hidden rounded-2xl border border-cu-border bg-cu-surface"
                         style="animation-delay: {{ $groupIndex * 60 }}ms">
                    <header class="flex items-center justify-between gap-3 border-b border-cu-border px-5 py-4">
                        <div class="flex items-center gap-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-cu-purple/15 text-cu-purple">
                                <flux:icon icon="adjustments-horizontal" class="size-5" />
                            </span>
                            <div>
                                <h2 class="text-base font-semibold text-cu-text">{{ $category }}</h2>
                                <p class="text-xs text-cu-muted">{{ count($fields) }} setting{{ count($fields) === 1 ? '' : 's' }}</p>
                            </div>
                        </div>
                    </header>

                    <div class="divide-y divide-cu-border">
                        @foreach ($fields as $field)
                            @php($key = $field['key'])
                            @php($audit = $this->audit[$key] ?? null)
                            @php($hasError = $errors->has($key) || $errors->has("values.$key"))
                            @php($errorMessage = $errors->first($key) ?: $errors->first("values.$key"))
                            <div class="grid gap-4 px-5 py-4 lg:grid-cols-[1fr_minmax(0,420px)] lg:items-start">
                                <div class="min-w-0">
                                    <label for="setting-{{ $key }}" class="flex flex-wrap items-center gap-2 text-sm font-medium text-cu-text">
                                        <span>{{ $field['label'] }}</span>
                                        @if (! empty($field['default']) && (string) $this->values[$key] !== SystemSetting::stringify($field['default']))
                                            <flux:badge size="sm" color="amber">Modified</flux:badge>
                                        @endif
                                        @if (! empty($dirty[$key]))
                                            <span class="inline-flex items-center gap-1 text-xs text-cu-yellow">
                                                <span class="size-1.5 rounded-full bg-cu-yellow"></span>
                                                Unsaved
                                            </span>
                                        @endif
                                    </label>
                                    <p class="mt-1 text-xs text-cu-muted">{{ $field['hint'] }}</p>
                                    <p class="mt-1 text-[11px] uppercase tracking-wider text-cu-muted/70">
                                        Key: <code class="font-mono text-cu-blue">{{ $key }}</code>
                                        @if ($audit && $audit['at'])
                                            · Last updated {{ $audit['at']->diffForHumans() }}
                                            @if ($audit['by'])
                                                by {{ $audit['by'] }}
                                            @endif
                                        @endif
                                    </p>
                                </div>

                                <div class="flex flex-col gap-2">
                                    @if ($field['type'] === 'string')
                                        <input
                                            type="text"
                                            id="setting-{{ $key }}"
                                            wire:model.live.defer="values.{{ $key }}"
                                            @class([
                                                'w-full rounded-xl border bg-black/5 dark:bg-white/5 px-3 py-2 text-sm text-cu-text placeholder:text-cu-muted focus:outline-none focus:ring-2',
                                                'border-cu-border focus:border-cu-purple focus:ring-cu-purple/40' => ! $hasError,
                                                'border-rose-500/40 focus:border-rose-500 focus:ring-rose-500/30' => $hasError,
                                            ])
                                            autocomplete="off"
                                            spellcheck="false"
                                        />
                                    @else
                                        <div class="flex items-center gap-2">
                                            <input
                                                type="number"
                                                id="setting-{{ $key }}"
                                                wire:model.live.defer="values.{{ $key }}"
                                                @class([
                                                    'w-full rounded-xl border bg-black/5 dark:bg-white/5 px-3 py-2 text-sm text-cu-text placeholder:text-cu-muted focus:outline-none focus:ring-2',
                                                    'border-cu-border focus:border-cu-purple focus:ring-cu-purple/40' => ! $hasError,
                                                    'border-rose-500/40 focus:border-rose-500 focus:ring-rose-500/30' => $hasError,
                                                ])
                                                step="{{ $field['step'] ?? ($field['type'] === 'float' ? '0.01' : '1') }}"
                                                min="{{ $field['min'] ?? '' }}"
                                                max="{{ $field['max'] ?? '' }}"
                                                inputmode="{{ $field['type'] === 'float' ? 'decimal' : 'numeric' }}"
                                                @if (isset($field['default']))
                                                    placeholder="Default: {{ $field['default'] }}"
                                                @endif
                                                aria-describedby="setting-{{ $key }}-hint"
                                            />
                                            @if (in_array($key, ['risk_weight_text','risk_weight_classification','risk_weight_signature','risk_weight_stamp'], true))
                                                <span class="shrink-0 rounded-md bg-black/5 dark:bg-white/5 px-2 py-1 text-xs text-cu-muted">× weight</span>
                                            @endif
                                        </div>
                                    @endif

                                    <div class="flex items-center justify-between gap-2">
                                        <p id="setting-{{ $key }}-hint" class="text-xs">
                                            @if ($hasError)
                                                <span class="text-rose-700 dark:text-rose-300">{{ $errorMessage }}</span>
                                            @else
                                                <span class="text-cu-muted">
                                                    Default: <span class="text-cu-text">{{ $field['default'] }}</span>
                                                    @if (isset($field['min']) || isset($field['max']))
                                                        · Range: <span class="text-cu-text">{{ $field['min'] ?? '−∞' }} – {{ $field['max'] ?? '∞' }}</span>
                                                    @endif
                                                </span>
                                            @endif
                                        </p>
                                        <button type="button" wire:click="resetKey('{{ $key }}')"
                                                wire:loading.attr="disabled" wire:target="resetKey('{{ $key }}')"
                                                class="inline-flex items-center gap-1 rounded-md border border-cu-border px-2 py-1 text-xs font-medium text-cu-muted transition hover:border-cu-border hover:text-cu-text">
                                            <flux:icon.loading wire:loading wire:target="resetKey('{{ $key }}')" variant="micro" class="size-3" />
                                            <flux:icon icon="arrow-uturn-left" wire:loading.remove wire:target="resetKey('{{ $key }}')" class="size-3" />
                                            Reset
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <div class="flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-end">
                <button type="button" wire:click="reload" wire:loading.attr="disabled" wire:target="reload"
                        class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-cu-border bg-cu-surface px-4 py-2.5 text-sm font-medium text-cu-muted transition hover:border-cu-border hover:text-cu-text">
                    Discard changes
                </button>
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="inline-flex items-center justify-center gap-2 rounded-xl cu-gradient px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cu-purple/20 transition hover:opacity-90">
                    <flux:icon.loading wire:loading wire:target="save" variant="micro" class="size-4" />
                    <flux:icon icon="check" wire:loading.remove wire:target="save" class="size-4" />
                    Save all changes
                </button>
            </div>
        </form>
    </div>
</x-page>
