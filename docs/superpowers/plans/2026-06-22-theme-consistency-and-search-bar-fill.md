# Theme Consistency, 7-Day Cookie Persistence & Search-Bar Background Fill — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the interface theme (Light/Dark/System) apply consistently across every page for every role, persist the choice in a 7-day client cookie, and fix the page background so it always fills the viewport regardless of how many rows a filter/search returns.

**Architecture:** Three layers. (1) **Tokens** — the existing semantic `cu-*` CSS variables become theme-flipping (light values by default, dark values under `.dark`), so every `bg-cu-*` / `text-cu-*` / `border-cu-*` utility already in the views switches automatically. (2) **Persistence** — replace Flux's localStorage appearance script with a tiny inline `window.advsTheme` runtime that reads/writes a 7-day `theme` cookie and toggles the `.dark` class before first paint (no flash). (3) **Layout** — a shared `<x-page>` wrapper paints a theme-responsive, viewport-filling background using a flex column, replacing the brittle `min-h-full` wrapper duplicated across 19 views. After the infrastructure lands, a deterministic class-mapping sweep converts the per-view neutral literals (`text-white`, `bg-white/5`, `bg-zinc-50`, `text-zinc-950`, …) to the semantic tokens so cards/text/borders flip too.

**Tech Stack:** Laravel 12, Livewire 4 + Volt 1, Flux UI 2, Tailwind CSS v4 (config-in-CSS via `@theme`), Alpine (bundled with Flux), PHPUnit 11.

## Global Constraints

- **Persistence mechanism (verbatim from the requester):** client-only cookie, **7-day expiry** (`max-age = 60 * 60 * 24 * 7 = 604800` seconds). Cookie name: `theme`. Values: `light` | `dark` | `system`. Default when absent/invalid: `system`. No server/Laravel changes for applying the theme; no database column.
- **Scope (verbatim from the requester):** Full light + dark, **all pages, all user types** (vendor, compliance_officer, admin). The toggle must drive the whole page (shell *and* content), not just the chrome.
- **No new dependencies.** No JS test runner is installed and none is to be added (CLAUDE.md: Vitest is "optional"; Boost rules: do not change dependencies without approval). Programmatic verification of view/CSS/JS changes is done with PHPUnit feature tests that assert rendered markup, plus file-content assertions for the CSS/JS files. State this limitation honestly in test docblocks.
- **Brand & status colors never flip.** Leave `cu-purple`, `cu-pink`, `cu-blue`, `cu-yellow`, the `cu-gradient`/`cu-gradient-text` utilities, all `rose-*`/`emerald-*`/`amber-*`/`red-*`/`green-*` status colors, and any `white/x` overlay that sits **on** a gradient/brand background exactly as-is. Only neutral page/surface/text/border literals are converted.
- **Formatting:** run `vendor/bin/pint --dirty --format agent` before every commit that touches PHP.
- **Tests:** PHPUnit class-based tests only (`php artisan make:test --phpunit`). Run the minimal filtered set after each change: `php artisan test --compact --filter=<name>`.
- **Tailwind rebuild:** CSS/Blade class changes only appear after `npm run build` (or `npm run dev`). Tests assert source markup/CSS, so they do not require a build, but any manual visual check does.

---

## Theme Class-Mapping Reference (canonical — used by every Phase B task)

"Apply the mapping" = in the named file, replace each literal on the left with the token/variant on the right; leave the keep-list untouched. The `cu-*` tokens flip automatically (Task 1), so the right-hand side is the **post-conversion** form. Conversions in section **B** intentionally retain a `dark:…white/x` variant — the repo-wide guard (Task 10) therefore scans only for the `zinc-*` literals from section **A**, which have no surviving form.

**A. Neutral surfaces / text / borders**

| Find | Replace with |
|---|---|
| `text-white` as body/heading/value text (not on a gradient/accent bg) | `text-cu-text` |
| `text-zinc-950`, `text-zinc-900` (primary text) | `text-cu-text` |
| `text-zinc-200` | `text-cu-text` |
| `text-zinc-500`, `text-zinc-400`, `text-zinc-300` (muted/secondary) | `text-cu-muted` |
| `text-zinc-700` used as a neutral chip label (vendor-status default) | `text-cu-text` |
| `bg-white` as a card/panel surface | `bg-cu-surface` |
| `bg-zinc-50` as an inner subtle surface (segmented control, chip) | `bg-black/5 dark:bg-white/5` |
| `border-zinc-200`, `border-zinc-100` | `border-cu-border` |
| `border-white/5`, `border-white/10` | `border-cu-border` |
| `hover:border-white/10` | `hover:border-cu-border` |
| `divide-white/5`, `divide-white/10` | `divide-cu-border` |
| `shadow-sm` | unchanged |
| any `bg-cu-*` / `text-cu-*` / `border-cu-border` / `text-cu-muted/60` | unchanged (token flips) |

**B. Neutral white-opacity overlays (retain a `dark:` form)**

| Find | Replace with |
|---|---|
| `bg-white/5` (subtle chip/surface, not on a gradient) | `bg-black/5 dark:bg-white/5` |
| `bg-white/[0.03]` | `bg-black/[0.03] dark:bg-white/[0.03]` |
| `hover:bg-white/[0.03]` | `hover:bg-black/[0.03] dark:hover:bg-white/[0.03]` |
| `text-white/10` (e.g. gauge track) | `text-black/10 dark:text-white/10` |
| `hover:text-white` / `group-hover:text-white` on a **neutral** surface (a `cu-surface`/`cu-bg`/black-or-white-opacity element, e.g. an inactive segmented/filter button or a table-row link) | `hover:text-cu-text` / `group-hover:text-cu-text` |

> Why: on a light surface, `hover:text-white` turns the label invisible (white-on-white) on hover. Convert it only when the hovered element's own background is neutral. **Keep** `hover:text-white` when the hovered element sits on an accent — e.g. the active filter button is `bg-cu-purple text-white`; a `text-cu-muted hover:text-white` segmented control whose hover background is an accent stays. In the existing pages the inactive segmented/filter buttons hover on a neutral container, so they convert.

**C. Status-color tints — keep the hue, fix light-mode contrast**

Translucent status backgrounds/borders/rings/dots read on **both** themes — **keep** `bg-{c}-500/15`, `bg-{c}-400/5`, `ring-{c}-500/30`, `border-{c}-500/40`, and dots `bg-{c}-400`. Only the pale **text tints** (the `-200`/`-300` shades) are illegible on a light surface, and the solid light chips (`-50` backgrounds) are wrong on a dark surface:

| Find (`c` ∈ `rose`, `amber`, `emerald`, `sky`, `yellow`) | Replace with |
|---|---|
| `text-{c}-300` | `text-{c}-700 dark:text-{c}-300` |
| `text-{c}-200` | `text-{c}-700 dark:text-{c}-200` |
| `bg-{c}-50 text-{c}-700` (solid light chip) | `bg-{c}-500/15 text-{c}-700 dark:text-{c}-300` |
| `border-{c}-300` (light chip border) | `border-{c}-500/40` |

> `-400` icon tints (`text-rose-400`, `text-emerald-400`, …) are saturated enough to read on both themes — **leave them**. Rule C only touches `-200`/`-300` text and `-50` chips.

**Keep-list (never change):**
- Page-wrapper backgrounds (`bg-zinc-50` / `bg-cu-bg` on the `-m-6` wrapper) — removed by the `<x-page>` swap in Task 4, not mapped here.
- Anything inside an element whose own background is `cu-gradient`, `bg-cu-purple`, `bg-cu-pink`, or a solid accent: keep its `text-white`, `text-white/80`, `bg-white/15`, `bg-white/20`, `ring-white/30`, and white-on-gradient buttons (`bg-white … text-cu-purple`).
- Brand/accent utilities (`cu-purple`, `cu-pink`, `cu-blue`, `cu-yellow`, `cu-gradient*`) and the translucent accent backgrounds/borders/dots named in rule C.

---

## File Structure

**Created:**
- `resources/views/components/page.blade.php` — `<x-page>`: the single themed, viewport-filling page wrapper (replaces 19 duplicated wrappers; carries the search-bar background fix).
- `resources/views/components/theme-toggle.blade.php` — `<x-theme-toggle>`: the Light/Dark/System segmented control wired to `window.advsTheme` (shared by the Preference and Appearance settings pages).
- `tests/Feature/Theme/ThemeTokensTest.php` — guards the flipping `cu-*` token definitions in `app.css`.
- `tests/Feature/Theme/ThemeBootstrapTest.php` — guards the inline cookie runtime in the page `<head>` and the removal of `@fluxAppearance`.
- `tests/Feature/Theme/PageWrapperTest.php` — guards that no view uses the old brittle wrappers and that `<x-page>` renders the fill/theme classes.
- `tests/Feature/Theme/ThemeComponentSweepTest.php` — guards the 9 shared display components are theme-responsive (Task 5).
- `tests/Feature/Theme/ContentThemeSweepTest.php` — per-page-group `assertViewConverted()` scans for the admin + vendor views (created Task 6, extended Tasks 7–9).
- `tests/Feature/Theme/ViewThemeGuardTest.php` — repo-wide catch-all guard against theme-locked neutral literals (Task 10).

**Modified:**
- `resources/css/app.css` — `cu-*` tokens flip per theme; add `--color-cu-border`.
- `resources/views/partials/head.blade.php` — inline `window.advsTheme` runtime; remove `@fluxAppearance`.
- `resources/views/components/layouts/app.blade.php` — make `flux:main` a flex column so `<x-page>` can fill height.
- `resources/views/livewire/settings/preference.blade.php` and `resources/views/livewire/settings/appearance.blade.php` — use `<x-theme-toggle>` instead of `x-model="$flux.appearance"`.
- 19 content views (the `-m-6 min-h-{full,svh} …` wrappers) — swap wrapper for `<x-page>`, then apply the mapping. Enumerated in Tasks 4–9.

---

## Phase A — Infrastructure (no visual regression in dark mode; ships the search-bar fix and a working, persistent toggle)

### Task 1: Flip the `cu-*` theme tokens

**Files:**
- Modify: `resources/css/app.css:30-42` (the `cu-*` block in `@theme`) and `resources/css/app.css:56-62` (the `@layer theme { .dark { … } }` block)
- Test: `tests/Feature/Theme/ThemeTokensTest.php`

**Interfaces:**
- Produces: light defaults for `--color-cu-bg`, `--color-cu-surface`, `--color-cu-text`, `--color-cu-muted`, and a new `--color-cu-border`; dark overrides for all five under `.dark`. Every existing `bg-cu-bg` / `bg-cu-surface` / `text-cu-text` / `text-cu-muted` utility and the new `border-cu-border` utility now flip with the `.dark` class.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Theme;

use Tests\TestCase;

class ThemeTokensTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    public function test_cu_tokens_have_light_defaults_in_theme_block(): void
    {
        $css = $this->css();

        // Light page background is no longer near-black.
        $this->assertStringContainsString('--color-cu-bg: #f8f8fb;', $css);
        $this->assertStringContainsString('--color-cu-surface: #ffffff;', $css);
        $this->assertStringContainsString('--color-cu-text: #18181b;', $css);
        $this->assertStringContainsString('--color-cu-border: #e5e7eb;', $css);
    }

    public function test_cu_tokens_have_dark_overrides(): void
    {
        $css = $this->css();

        // The original dark surfaces now live under the .dark override.
        $this->assertMatchesRegularExpression(
            '/\.dark\s*\{[^}]*--color-cu-bg:\s*#0d0d0f;[^}]*--color-cu-surface:\s*#1a1a2e;/s',
            $css,
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ThemeTokensTest`
Expected: FAIL — `app.css` currently defines the dark values directly in `@theme` and has no `--color-cu-border`.

- [ ] **Step 3: Edit `resources/css/app.css`**

Replace the existing `cu-*` surface block (currently lines 37-41):

```css
    /* ClickUp dark theme surfaces. */
    --color-cu-bg: #0d0d0f;
    --color-cu-surface: #1a1a2e;
    --color-cu-text: #ffffff;
    --color-cu-muted: #a0a0b0;
```

with theme-neutral **light defaults** (dark values move to the `.dark` layer below):

```css
    /* ClickUp semantic surfaces — light defaults; dark overrides live in
       `@layer theme { .dark { … } }` below so every `cu-*` utility flips
       with the `.dark` class set by window.advsTheme. */
    --color-cu-bg: #f8f8fb;
    --color-cu-surface: #ffffff;
    --color-cu-text: #18181b;
    --color-cu-muted: #6b7280;
    --color-cu-border: #e5e7eb;
```

Then extend the existing `.dark` block (currently lines 56-62) to add the dark surface overrides:

```css
@layer theme {
    .dark {
        --color-accent: var(--color-white);
        --color-accent-content: var(--color-white);
        --color-accent-foreground: var(--color-neutral-800);

        /* Dark ClickUp surfaces (original values). */
        --color-cu-bg: #0d0d0f;
        --color-cu-surface: #1a1a2e;
        --color-cu-text: #ffffff;
        --color-cu-muted: #a0a0b0;
        --color-cu-border: rgb(255 255 255 / 0.08);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ThemeTokensTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/css/app.css tests/Feature/Theme/ThemeTokensTest.php
git commit -m "feat(theme): make cu-* surface tokens flip between light and dark"
```

---

### Task 2: Client-only 7-day cookie runtime in `<head>`

**Files:**
- Modify: `resources/views/partials/head.blade.php` (add inline script; remove `@fluxAppearance` on line 14)
- Test: `tests/Feature/Theme/ThemeBootstrapTest.php`

**Interfaces:**
- Produces: a global `window.advsTheme` object with `read(): string`, `set(value: string): void`, `apply(value: string): void`. `set` writes the `theme` cookie (`max-age=604800`, `path=/`, `SameSite=Lax`) and toggles `document.documentElement.classList` `dark`. The runtime applies the saved theme synchronously on load (no flash) and live-updates when the OS theme changes while in `system` mode. Consumed by `<x-theme-toggle>` (Task 3).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Theme;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_head_renders_the_cookie_theme_runtime(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_VENDOR]);

        $response = $this->actingAs($user)->get(route('vendor.dashboard'));

        $response->assertOk()
            ->assertSee('window.advsTheme', false)
            ->assertSee('max-age=604800', false)
            ->assertSee("theme=", false);
    }

    public function test_flux_localStorage_appearance_directive_is_removed(): void
    {
        // @fluxAppearance injects Flux's localStorage-based applier, which we
        // replace with the cookie runtime. Its absence is asserted via the head partial.
        $head = (string) file_get_contents(resource_path('views/partials/head.blade.php'));

        $this->assertStringNotContainsString('@fluxAppearance', $head);
        $this->assertStringContainsString('window.advsTheme', $head);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ThemeBootstrapTest`
Expected: FAIL — head still contains `@fluxAppearance` and no `window.advsTheme`.

- [ ] **Step 3: Edit `resources/views/partials/head.blade.php`**

Replace the file's contents with:

```blade
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $title ?? 'Laravel' }}</title>

{{-- Theme runtime: client-only, 7-day `theme` cookie (light|dark|system).
     Runs synchronously before first paint to avoid a flash of the wrong theme,
     and live-updates with the OS setting while in `system` mode. The settings
     toggle (<x-theme-toggle>) drives this via window.advsTheme.set(). --}}
<script>
    window.advsTheme = (function () {
        var KEY = 'theme';
        var MAX_AGE = 60 * 60 * 24 * 7; // 7 days in seconds
        var VALID = ['light', 'dark', 'system'];

        function read() {
            var match = document.cookie.match(/(?:^|;\s*)theme=([^;]+)/);
            var value = match ? decodeURIComponent(match[1]) : 'system';
            return VALID.indexOf(value) === -1 ? 'system' : value;
        }

        function isDark(value) {
            if (value === 'system') {
                return window.matchMedia('(prefers-color-scheme: dark)').matches;
            }
            return value === 'dark';
        }

        function apply(value) {
            document.documentElement.classList.toggle('dark', isDark(value));
        }

        function set(value) {
            if (VALID.indexOf(value) === -1) { value = 'system'; }
            document.cookie = KEY + '=' + encodeURIComponent(value) + ';path=/;max-age=' + MAX_AGE + ';SameSite=Lax';
            apply(value);
        }

        apply(read());

        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            if (read() === 'system') { apply('system'); }
        });

        return { read: read, set: set, apply: apply };
    })();
</script>

<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
{{-- Load the webfont stylesheet without blocking first paint; swap in once loaded. --}}
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" media="print" onload="this.media='all'" />
<noscript>
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
</noscript>

@vite(['resources/css/app.css', 'resources/js/app.js'])
```

(Note: `@fluxAppearance` is removed; `@fluxScripts` in the layout `<body>` is **kept** — Flux components still style off the `.dark` class.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ThemeBootstrapTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/views/partials/head.blade.php tests/Feature/Theme/ThemeBootstrapTest.php
git commit -m "feat(theme): apply theme from a 7-day cookie before paint, drop Flux localStorage applier"
```

---

### Task 3: `<x-theme-toggle>` component + rewire the settings pages

**Files:**
- Create: `resources/views/components/theme-toggle.blade.php`
- Modify: `resources/views/livewire/settings/preference.blade.php:13-21`
- Modify: `resources/views/livewire/settings/appearance.blade.php:13-17`
- Test: extend `tests/Feature/Settings/PreferenceTest.php` (existing file)

**Interfaces:**
- Consumes: `window.advsTheme.read()` / `.set()` from Task 2.
- Produces: `<x-theme-toggle>` — a Flux segmented radio group bound to a local Alpine `theme` value, persisting via `window.advsTheme.set()` on change. Forwards arbitrary attributes (e.g. `label="Interface theme"`).

- [ ] **Step 1: Add a failing assertion to `tests/Feature/Settings/PreferenceTest.php`**

Add this method inside the existing `PreferenceTest` class:

```php
    public function test_preference_toggle_is_wired_to_the_cookie_runtime(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.preference'))
            ->assertOk()
            ->assertSee('advsTheme.set', false)
            ->assertDontSee('$flux.appearance', false);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PreferenceTest`
Expected: FAIL on the new method — the page still uses `x-model="$flux.appearance"` and has no `advsTheme.set`.

- [ ] **Step 3: Create `resources/views/components/theme-toggle.blade.php`**

```blade
{{-- Light / Dark / System segmented control. Persists to a 7-day `theme`
     cookie and applies the `.dark` class via window.advsTheme (see
     resources/views/partials/head.blade.php). Pass `label="…"` to label it. --}}
<flux:radio.group
    x-data="{ theme: window.advsTheme.read() }"
    x-init="$watch('theme', value => window.advsTheme.set(value))"
    x-model="theme"
    variant="segmented"
    {{ $attributes }}
>
    <flux:radio value="light" icon="sun">Light</flux:radio>
    <flux:radio value="dark" icon="moon">Dark</flux:radio>
    <flux:radio value="system" icon="computer-desktop">System</flux:radio>
</flux:radio.group>
```

- [ ] **Step 4: Rewire `resources/views/livewire/settings/preference.blade.php`**

Replace the `<flux:radio.group …>…</flux:radio.group>` block (lines 13-17) with:

```blade
        <x-theme-toggle label="Interface theme" />
```

The surrounding `<x-settings.layout …>`, the `<flux:text>` helper line, and the component PHP block stay unchanged.

- [ ] **Step 5: Rewire `resources/views/livewire/settings/appearance.blade.php`**

Replace the `<flux:radio.group …>…</flux:radio.group>` block (lines 13-17) with:

```blade
        <x-theme-toggle />
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter=PreferenceTest`
Expected: PASS (4 tests — the 3 original + the new wiring test).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/theme-toggle.blade.php resources/views/livewire/settings/preference.blade.php resources/views/livewire/settings/appearance.blade.php tests/Feature/Settings/PreferenceTest.php
git commit -m "feat(theme): share <x-theme-toggle> across settings pages, drop \$flux.appearance"
```

---

### Task 4: `<x-page>` wrapper + viewport-filling background (the search-bar fix)

**Files:**
- Create: `resources/views/components/page.blade.php`
- Modify: `resources/views/components/layouts/app.blade.php:2`
- Modify (wrapper swap only — inner markup untouched): all 19 views listed below
- Test: `tests/Feature/Theme/PageWrapperTest.php`

**Interfaces:**
- Consumes: the `flux:main` flex column from `app.blade.php`.
- Produces: `<x-page>` — a slot wrapper rendering `-m-6 flex min-h-full flex-1 flex-col bg-white p-6 text-zinc-900 lg:-m-8 lg:p-8 dark:bg-cu-bg dark:text-cu-text`. Because it is a `flex-1` child of a flex-column `flux:main` (which is `min-h-svh`), it always fills the viewport height — the background no longer collapses to content height when a filter returns few/no rows.

**Why this fixes the bug:** the old wrapper used `min-h-full` (`min-height:100%`), which cannot resolve against `flux:main`'s `min-h-svh` (a *min-height*, not a definite height), so it collapsed to content height. Making `flux:main` a flex column and the wrapper `flex-1` forces the wrapper to consume the full column height deterministically.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Theme;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageWrapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_page_uses_filling_themed_wrapper(): void
    {
        $officer = User::factory()->create(['role' => User::ROLE_COMPLIANCE_OFFICER]);

        $response = $this->actingAs($officer)->get(route('admin.pending'));

        $response->assertOk()
            // New wrapper fills height and is theme-responsive.
            ->assertSee('flex-1', false)
            ->assertSee('dark:bg-cu-bg', false)
            // Old brittle wrapper is gone.
            ->assertDontSee('min-h-full bg-cu-bg', false);
    }

    public function test_no_view_uses_the_old_collapsing_wrappers(): void
    {
        $offenders = [];
        $needles = [
            '-m-6 min-h-full bg-cu-bg',
            '-m-6 min-h-svh bg-zinc-50',
        ];

        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($dir as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            foreach ($needles as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'Views still using the old collapsing wrapper: '.implode(', ', $offenders));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PageWrapperTest`
Expected: FAIL — `<x-page>` does not exist and 19 views still use the old wrappers.

- [ ] **Step 3: Create `resources/views/components/page.blade.php`**

```blade
{{-- Themed, viewport-filling page wrapper. As a flex-1 child of the flex-column
     <flux:main> (min-h-svh), it always fills the viewport so the background never
     collapses to content height when a search/filter returns few or no rows.
     The -m-6/lg:-m-8 bleed + matching padding paints edge-to-edge under the
     layout's own padding. --}}
<div {{ $attributes->class('-m-6 flex min-h-full flex-1 flex-col bg-white p-6 text-zinc-900 lg:-m-8 lg:p-8 dark:bg-cu-bg dark:text-cu-text') }}>
    {{ $slot }}
</div>
```

- [ ] **Step 4: Make `flux:main` a flex column in `resources/views/components/layouts/app.blade.php`**

Replace line 2:

```blade
    <flux:main class="min-h-svh bg-white text-zinc-950 dark:bg-cu-bg dark:text-cu-text">
```

with:

```blade
    <flux:main class="flex min-h-svh flex-col bg-white text-zinc-950 dark:bg-cu-bg dark:text-cu-text">
```

- [ ] **Step 5: Swap the wrapper in all 19 views (inner markup unchanged)**

In each file below, replace the **opening** wrapper `<div class="-m-6 min-h-full bg-cu-bg p-6 text-cu-text lg:-m-8 lg:p-8">` (admin) or `<div class="-m-6 min-h-svh bg-zinc-50 p-6 text-zinc-950 lg:-m-8 lg:p-8">` (vendor) with `<x-page>`, and replace its matching **closing** `</div>` with `</x-page>`. Leave everything inside untouched (this task is structural only; the inner literals are converted in Phase B).

Admin (open tag = `-m-6 min-h-full bg-cu-bg p-6 text-cu-text lg:-m-8 lg:p-8`):
- `resources/views/livewire/admin/dashboard.blade.php`
- `resources/views/livewire/admin/pending.blade.php`
- `resources/views/livewire/admin/archived.blade.php`
- `resources/views/livewire/admin/risk-logs.blade.php`
- `resources/views/livewire/admin/notifications.blade.php`
- `resources/views/livewire/admin/settings/index.blade.php`
- `resources/views/livewire/admin/vendors/index.blade.php`
- `resources/views/livewire/admin/vendors/show.blade.php`
- `resources/views/livewire/admin/submissions/show.blade.php`
- `resources/views/livewire/admin/users/index.blade.php`
- `resources/views/livewire/admin/users/create.blade.php`
- `resources/views/livewire/admin/users/edit.blade.php`
- `resources/views/livewire/admin/audit/index.blade.php`
- `resources/views/admin/audit/show.blade.php` (this file indents the wrapper inside an `<x-layouts.app>` — match its exact indentation when swapping)

Vendor (open tag = `-m-6 min-h-svh bg-zinc-50 p-6 text-zinc-950 lg:-m-8 lg:p-8`):
- `resources/views/livewire/vendor/dashboard.blade.php`
- `resources/views/livewire/vendor/submit.blade.php`
- `resources/views/livewire/vendor/submissions.blade.php`
- `resources/views/livewire/vendor/notifications.blade.php`
- `resources/views/livewire/vendor/profile.blade.php`

> Each file has exactly one such wrapper as its content root; its matching close is the file's last `</div>` (for Volt views) or the `</div>` before `</x-layouts.app>` (for `admin/audit/show.blade.php`). Verify the open/close pair by reading the file before editing.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=PageWrapperTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Manual visual check of the search-bar fix**

Run `npm run build`, then as a compliance officer visit `/admin/pending`, type a search term that returns 0 rows, and confirm the page background fills the full viewport (no short colored panel with a different background below it). Repeat with the theme set to Light via Settings → Preference.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/page.blade.php resources/views/components/layouts/app.blade.php resources/views/livewire resources/views/admin/audit/show.blade.php tests/Feature/Theme/PageWrapperTest.php
git commit -m "fix(ui): fill page background via <x-page> flex wrapper, fixing search-bar collapse"
```

---

## Phase B — Per-view light/dark sweep (apply the Class-Mapping Reference)

After Phase A, dark mode is unchanged and the page background + shell already flip. Phase B converts the **inner** neutral literals so cards, text, and borders flip too. Every task applies the **Theme Class-Mapping Reference** at the top of this plan and honors the keep-list.

> Procedure for each file in a Phase B task: (1) read the file; (2) replace each neutral literal per the mapping table; (3) leave brand/status colors and gradient-overlay whites untouched; (4) run the task's test. Because the mapping is a fixed find→replace table, the conversions are deterministic — there is no per-file judgement beyond the documented keep-list.

### Task 5: Shared display components

**Files (Modify):**
- `resources/views/components/kpi-card.blade.php`
- `resources/views/components/risk-badge.blade.php`
- `resources/views/components/risk-gauge.blade.php`
- `resources/views/components/signature-compare.blade.php`
- `resources/views/components/stamp-compare.blade.php`
- `resources/views/components/stamp-mark.blade.php`
- `resources/views/components/pass-fail.blade.php`
- `resources/views/components/activity-icon.blade.php`
- `resources/views/components/vendor-status-badge.blade.php`
- Test: `tests/Feature/Theme/ThemeComponentSweepTest.php`

**Interfaces:**
- Consumes: the flipping `cu-*` tokens (Task 1).
- Produces: shared components whose neutral surfaces/text/borders are token-based and whose status text tints carry a `dark:` variant; brand/accent colors preserved. These components render inside the pages converted in Tasks 6–9.

> This is a self-contained task: its test scans only these 9 component files, so it goes RED before and GREEN after this task's edits (it does not depend on later tasks).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Theme;

use Tests\TestCase;

class ThemeComponentSweepTest extends TestCase
{
    /**
     * Shared display components must be theme-responsive. Verified by scanning
     * source for the post-conversion classes (no JS/visual test runner exists;
     * see the plan's Global Constraints). Each needle is absent before the
     * conversion and present after it.
     */
    public function test_shared_components_are_theme_responsive(): void
    {
        $expectations = [
            'kpi-card' => ['text-cu-text', 'border-cu-border'],
            'risk-badge' => ['dark:text-rose-300'],
            'signature-compare' => ['border-cu-border', 'text-cu-text', 'dark:bg-white/[0.03]'],
            'stamp-compare' => ['border-cu-border', 'text-cu-text', 'dark:bg-white/[0.03]'],
            'pass-fail' => ['dark:text-emerald-300'],
            'activity-icon' => ['bg-black/5', 'dark:text-sky-300'],
            'risk-gauge' => ['dark:text-white/10', 'dark:text-rose-300'],
            'vendor-status-badge' => ['border-cu-border', 'dark:text-emerald-300'],
        ];

        foreach ($expectations as $name => $needles) {
            $contents = (string) file_get_contents(resource_path("views/components/{$name}.blade.php"));
            foreach ($needles as $needle) {
                $this->assertStringContainsString(
                    $needle,
                    $contents,
                    "{$name}.blade.php is missing theme-responsive class '{$needle}'",
                );
            }
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ThemeComponentSweepTest`
Expected: FAIL — e.g. `kpi-card.blade.php is missing theme-responsive class 'text-cu-text'` (it currently uses `text-white`).

- [ ] **Step 3: Apply the exact edits**

These are the complete conversions for each component (`stamp-mark.blade.php` is pure SVG using `currentColor` and needs **no change**):

**`kpi-card.blade.php`** — line 20 and line 24:

```blade
{{-- line 20: border-white/5 → border-cu-border, hover:border-white/10 → hover:border-cu-border --}}
<div {{ $attributes->merge(['class' => 'relative overflow-hidden rounded-2xl border border-cu-border bg-cu-surface p-5 transition hover:border-cu-border']) }}>
```
```blade
{{-- line 24: text-white → text-cu-text --}}
            <p class="mt-2 text-3xl font-semibold tracking-tight text-cu-text">{{ $value }}</p>
```

**`risk-badge.blade.php`** — the `$map` tints (lines 9-11):

```php
        'high' => 'bg-rose-500/15 text-rose-700 ring-rose-500/30 dark:text-rose-300',
        'medium' => 'bg-amber-400/15 text-amber-700 ring-amber-400/30 dark:text-amber-300',
        'low' => 'bg-emerald-500/15 text-emerald-700 ring-emerald-500/30 dark:text-emerald-300',
```

**`signature-compare.blade.php`:**
- line 7 `$queryInk`: `text-emerald-300`/`text-rose-300` → `text-emerald-700 dark:text-emerald-300` / `text-rose-700 dark:text-rose-300`
- line 12 amber icon: `text-amber-300` → `text-amber-700 dark:text-amber-300`
- line 13: `text-zinc-200` → `text-cu-text`
- line 19: `border border-white/10` → `border border-cu-border`
- line 24: `bg-white/[0.03] text-zinc-300` → `bg-black/[0.03] text-cu-muted dark:bg-white/[0.03]`
- line 37: `bg-white/[0.03] {{ $queryInk }}` → `bg-black/[0.03] dark:bg-white/[0.03] {{ $queryInk }}`
- lines 47, 51, 55 (3×): `rounded-lg bg-white/[0.03] px-3 py-2` → `rounded-lg bg-black/[0.03] px-3 py-2 dark:bg-white/[0.03]`
- lines 53, 57: `text-lg font-semibold text-white` → `text-lg font-semibold text-cu-text`

**`stamp-compare.blade.php`:**
- line 7 `$queryInk`: same split as signature-compare
- line 12 amber icon: `text-amber-300` → `text-amber-700 dark:text-amber-300`
- line 13: `text-zinc-200` → `text-cu-text`
- line 19: `border border-white/10` → `border border-cu-border`
- line 24: `bg-white/[0.03] text-cu-blue` → `bg-black/[0.03] dark:bg-white/[0.03] text-cu-blue`
- line 35: `bg-white/[0.03] {{ $queryInk }}` → `bg-black/[0.03] dark:bg-white/[0.03] {{ $queryInk }}`
- lines 43, 47, 51 (3×): `rounded-lg bg-white/[0.03] px-3 py-2` → `rounded-lg bg-black/[0.03] px-3 py-2 dark:bg-white/[0.03]`
- lines 49, 53: `text-lg font-semibold text-white` → `text-lg font-semibold text-cu-text`

**`pass-fail.blade.php`** — lines 7 and 12:

```blade
{{-- line 7 --}}
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300']) }}>
```
```blade
{{-- line 12 --}}
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full bg-rose-500/15 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:text-rose-300']) }}>
```

**`activity-icon.blade.php`** — the `$colors` map (lines 9-13):

```php
        'rose' => 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
        'amber' => 'bg-amber-400/15 text-amber-700 dark:text-amber-300',
        'sky' => 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
        'emerald' => 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
        'zinc' => 'bg-black/5 text-cu-muted dark:bg-white/5',
```

**`risk-gauge.blade.php`:**
- line 11 `$textClass`: `text-rose-300`/`text-amber-300`/`text-emerald-300` → `text-rose-700 dark:text-rose-300` / `text-amber-700 dark:text-amber-300` / `text-emerald-700 dark:text-emerald-300` (both the map entries and the `?? 'text-emerald-700 dark:text-emerald-300'` default)
- line 16: track circle `class="text-white/10"` → `class="text-black/10 dark:text-white/10"`

**`vendor-status-badge.blade.php`** — the `$classes` match (lines 5-9):

```php
        'Processing' => 'border-cu-blue/30 bg-cu-blue/10 text-sky-700 dark:text-sky-300',
        'Pending Review' => 'border-cu-yellow/60 bg-cu-yellow/20 text-yellow-700 dark:text-yellow-300',
        'Approved' => 'border-emerald-500/40 bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
        'Rejected' => 'border-rose-500/40 bg-rose-500/15 text-rose-700 dark:text-rose-300',
        default => 'border-cu-border bg-black/5 text-cu-text dark:bg-white/5',
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ThemeComponentSweepTest`
Expected: PASS (1 test).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components tests/Feature/Theme/ThemeComponentSweepTest.php
git commit -m "feat(theme): make shared display components theme-responsive"
```

---

### Task 6: Admin dashboard, pending & archived

**Files (Modify):**
- `resources/views/livewire/admin/dashboard.blade.php`
- `resources/views/livewire/admin/pending.blade.php`
- `resources/views/livewire/admin/archived.blade.php`
- Create: `tests/Feature/Theme/ContentThemeSweepTest.php`

**Interfaces:** Consumes flipping tokens + `<x-page>`. Produces three theme-responsive officer pages and the reusable `ContentThemeSweepTest::assertViewConverted()` helper that Tasks 7–9 extend.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Theme;

use Tests\TestCase;

class ContentThemeSweepTest extends TestCase
{
    /**
     * A content view is "converted" when it no longer carries theme-locked
     * neutral literals (these have no surviving form after the mapping — unlike
     * the `dark:…white/x` overlays, which are intentionally kept) and shows at
     * least one semantic cu-* token. Verified by scanning source; no JS/visual
     * runner exists (see the plan's Global Constraints).
     */
    private function assertViewConverted(string $relative): void
    {
        $contents = (string) file_get_contents(resource_path("views/{$relative}"));

        $banned = [
            'border-white/5', 'border-white/10', 'divide-white/5', 'divide-white/10',
            'text-zinc-950', 'text-zinc-900', 'text-zinc-700',
            'text-zinc-500', 'text-zinc-400', 'text-zinc-300', 'text-zinc-200',
            'border-zinc-200', 'border-zinc-100', 'bg-zinc-50',
        ];

        foreach ($banned as $literal) {
            $this->assertStringNotContainsString($literal, $contents, "{$relative} still uses theme-locked '{$literal}'");
        }

        $this->assertTrue(
            str_contains($contents, 'border-cu-border')
                || str_contains($contents, 'text-cu-text')
                || str_contains($contents, 'bg-cu-surface'),
            "{$relative} shows no sign of semantic-token conversion",
        );
    }

    public function test_dashboard_pending_archived_converted(): void
    {
        $this->assertViewConverted('livewire/admin/dashboard.blade.php');
        $this->assertViewConverted('livewire/admin/pending.blade.php');
        $this->assertViewConverted('livewire/admin/archived.blade.php');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ContentThemeSweepTest`
Expected: FAIL — e.g. `livewire/admin/pending.blade.php still uses theme-locked 'border-white/5'`.

- [ ] **Step 3: Apply the mapping to `dashboard.blade.php`**

Apply the Class-Mapping Reference. Specific conversions in this file:
- Table/heading/value `text-white` (e.g. the "Welcome back" heading is on the gradient hero — **keep** its `text-white`; the KPI/section headings and activity text **outside** the gradient → `text-cu-text`).
- `border-white/5`, `border-white/10` → `border-cu-border`; `divide-white/5` → `divide-cu-border`.
- `bg-white/5` chips not on the gradient → `bg-black/5 dark:bg-white/5`.
- `hover:bg-white/[0.03]` → `hover:bg-black/[0.03] dark:hover:bg-white/[0.03]`.
- **Keep** the entire `cu-gradient` hero block's inner `text-white`, `text-white/80`, `bg-white/15`, `bg-white/20`, `ring-white/30`, and the `bg-white … text-cu-purple` "Review queue" button (white button on gradient is intentional in both themes).

- [ ] **Step 4: Apply the mapping to `pending.blade.php`**

Specific conversions in this file (see lines 47-160 as read):
- `text-white` on the `<h1>` (line 58), the table cell company name (line 110), and the "Review" link (line 133) → `text-cu-text`.
- `border-white/5` (filter bar line 70, table container line 92, thead border line 96) → `border-cu-border`; `divide-white/5` (line 105) → `divide-cu-border`.
- `bg-white/5` (status pill line 61) → `bg-black/5 dark:bg-white/5`.
- `bg-cu-bg` on the search `<input>` (line 77) and the segmented control container (line 80) → `bg-black/5 dark:bg-white/5` (so the input/segmented control reads as a subtle inset in both themes; `bg-cu-bg` would match the page and disappear). The input's `text-white` → `text-cu-text`; `placeholder:text-cu-muted` stays.
- `hover:bg-white/[0.03]` (row, line 107) → `hover:bg-black/[0.03] dark:hover:bg-white/[0.03]`.
- **Keep:** `bg-cu-purple text-white` active filter button (line 85), `text-cu-purple`, `text-rose-400`, `text-emerald-400`, `text-cu-muted*` (token flips).

- [ ] **Step 5: Apply the mapping to `archived.blade.php`**

Apply the same conversions as `pending.blade.php` for its equivalent header/filter/table/search-input markup (this page has the same search-bar + filter layout). Convert `text-white` (non-gradient), `border-white/{5,10}`, `divide-white/5`, the search input's `bg-cu-bg`→`bg-black/5 dark:bg-white/5` and `text-white`→`text-cu-text`, and the row `hover:bg-white/[0.03]`. Keep brand/status colors.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=ContentThemeSweepTest`
Expected: PASS (`test_dashboard_pending_archived_converted`). If it still fails, the message names the file and the literal still present — fix that occurrence.

- [ ] **Step 7: Manual visual check** — visit `/admin/dashboard`, `/admin/pending`, `/admin/archived` in both Light and Dark; confirm headings/tables/search inputs are readable in both, and the gradient hero is unchanged.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/livewire/admin/dashboard.blade.php resources/views/livewire/admin/pending.blade.php resources/views/livewire/admin/archived.blade.php tests/Feature/Theme/ContentThemeSweepTest.php
git commit -m "feat(theme): theme officer dashboard, pending and archived pages"
```

---

### Task 7: Admin vendors, risk-logs, notifications & submission detail

**Files (Modify):**
- `resources/views/livewire/admin/vendors/index.blade.php`
- `resources/views/livewire/admin/vendors/show.blade.php`
- `resources/views/livewire/admin/risk-logs.blade.php`
- `resources/views/livewire/admin/notifications.blade.php`
- `resources/views/livewire/admin/submissions/show.blade.php`
- Test: extend `tests/Feature/Theme/ContentThemeSweepTest.php` with the method below

**Interfaces:** Consumes flipping tokens + `<x-page>` + the `assertViewConverted()` helper from Task 6. Produces five theme-responsive officer pages. `submissions/show.blade.php` is the largest (32 hardcoded literals) and embeds the shared compare components from Task 5.

- [ ] **Step 1: Add a failing test method to `ContentThemeSweepTest`**

```php
    public function test_vendors_risklogs_notifications_submission_converted(): void
    {
        $this->assertViewConverted('livewire/admin/vendors/index.blade.php');
        $this->assertViewConverted('livewire/admin/vendors/show.blade.php');
        $this->assertViewConverted('livewire/admin/risk-logs.blade.php');
        $this->assertViewConverted('livewire/admin/notifications.blade.php');
        $this->assertViewConverted('livewire/admin/submissions/show.blade.php');
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=test_vendors_risklogs_notifications_submission_converted`
Expected: FAIL — the named file still carries a theme-locked literal (e.g. `border-white/5`).

- [ ] **Step 3: Apply the mapping to all five files**

For each file apply the Class-Mapping Reference: `text-white`(non-accent)→`text-cu-text`; `border-white/{5,10}`→`border-cu-border`; `divide-white/{5,10}`→`divide-cu-border`; `bg-white/5`(subtle)→`bg-black/5 dark:bg-white/5`; `bg-white/[0.03]`→`bg-black/[0.03] dark:bg-white/[0.03]`; any search `<input>`/segmented control using `bg-cu-bg`→`bg-black/5 dark:bg-white/5` and its `text-white`→`text-cu-text`; `hover:bg-white/[0.03]`→`hover:bg-black/[0.03] dark:hover:bg-white/[0.03]`; status text tints `text-{c}-300`/`text-{c}-200`→`text-{c}-700 dark:text-{c}-{300,200}` (rule C). Keep gradient-hero whites, `bg-cu-purple text-white` buttons, translucent accent backgrounds/dots, and `-400` icon tints.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ContentThemeSweepTest`
Expected: PASS (both Task 6 and Task 7 methods green).

- [ ] **Step 5: Manual visual check** of `/admin/vendors`, a vendor detail page, `/admin/risk-logs`, `/admin/notifications`, and a submission detail page in both themes.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/livewire/admin/vendors resources/views/livewire/admin/risk-logs.blade.php resources/views/livewire/admin/notifications.blade.php resources/views/livewire/admin/submissions/show.blade.php tests/Feature/Theme/ContentThemeSweepTest.php
git commit -m "feat(theme): theme vendor profiles, risk logs, notifications and submission detail"
```

---

### Task 8: Admin settings, user management & audit

**Files (Modify):**
- `resources/views/livewire/admin/settings/index.blade.php`
- `resources/views/livewire/admin/users/index.blade.php`
- `resources/views/livewire/admin/users/create.blade.php`
- `resources/views/livewire/admin/users/edit.blade.php`
- `resources/views/livewire/admin/audit/index.blade.php`
- `resources/views/admin/audit/show.blade.php`
- Test: extend `tests/Feature/Theme/ContentThemeSweepTest.php` with the method below

**Interfaces:** Consumes flipping tokens + `<x-page>` + the `assertViewConverted()` helper from Task 6. Produces six theme-responsive admin pages. (Some `users/*` and `audit/show` are controller-rendered Blade, not Volt — the mapping is identical.)

- [ ] **Step 1: Add a failing test method to `ContentThemeSweepTest`**

```php
    public function test_settings_users_audit_converted(): void
    {
        $this->assertViewConverted('livewire/admin/settings/index.blade.php');
        $this->assertViewConverted('livewire/admin/users/index.blade.php');
        $this->assertViewConverted('livewire/admin/users/create.blade.php');
        $this->assertViewConverted('livewire/admin/users/edit.blade.php');
        $this->assertViewConverted('livewire/admin/audit/index.blade.php');
        $this->assertViewConverted('admin/audit/show.blade.php');
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=test_settings_users_audit_converted`
Expected: FAIL — the named file still carries a theme-locked literal.

- [ ] **Step 3: Apply the mapping to all six files**

Apply the Class-Mapping Reference to each (same neutral + rule-C conversions as Tasks 6–7). The `users/*` forms and the audit views use the same `text-white` / `border-white/{5,10}` / `bg-white/5` vocabulary; convert per table. Keep status colors (audit action `-400` tints, role badges) and any `bg-cu-purple text-white` actions.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ContentThemeSweepTest`
Expected: PASS (Tasks 6–8 methods green).

- [ ] **Step 5: Manual visual check** of `/admin/settings`, `/admin/users`, the create/edit user forms, `/admin/audit`, and an audit detail page in both themes.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/livewire/admin/settings resources/views/livewire/admin/users resources/views/livewire/admin/audit resources/views/admin/audit/show.blade.php tests/Feature/Theme/ContentThemeSweepTest.php
git commit -m "feat(theme): theme admin settings, user management and audit pages"
```

---

### Task 9: Vendor portal pages

**Files (Modify):**
- `resources/views/livewire/vendor/dashboard.blade.php`
- `resources/views/livewire/vendor/submit.blade.php`
- `resources/views/livewire/vendor/submissions.blade.php`
- `resources/views/livewire/vendor/notifications.blade.php`
- `resources/views/livewire/vendor/profile.blade.php`
- Test: extend `tests/Feature/Theme/ContentThemeSweepTest.php` with the method below

**Interfaces:** Consumes flipping tokens + `<x-page>` + the `assertViewConverted()` helper from Task 6. Produces five theme-responsive vendor pages. These are **light-authored**, so they use the light→semantic half of the mapping (`bg-white`→`bg-cu-surface`, `text-zinc-950`→`text-cu-text`, `text-zinc-500`→`text-cu-muted`, `border-zinc-200`→`border-cu-border`, inner `bg-zinc-50`→`bg-black/5 dark:bg-white/5`).

- [ ] **Step 1: Add a failing test method to `ContentThemeSweepTest`**

```php
    public function test_vendor_pages_converted(): void
    {
        $this->assertViewConverted('livewire/vendor/dashboard.blade.php');
        $this->assertViewConverted('livewire/vendor/submit.blade.php');
        $this->assertViewConverted('livewire/vendor/submissions.blade.php');
        $this->assertViewConverted('livewire/vendor/notifications.blade.php');
        $this->assertViewConverted('livewire/vendor/profile.blade.php');
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=test_vendor_pages_converted`
Expected: FAIL — the named file still carries a theme-locked literal (e.g. `text-zinc-950`).

- [ ] **Step 3: Apply the mapping to all five files**

For each file apply the light-authored half of the Class-Mapping Reference. Specific conversions (confirmed in `submissions.blade.php` lines 41-60, representative of the set):
- Card panel `bg-white` → `bg-cu-surface`.
- Heading `text-zinc-950` / `text-zinc-900` → `text-cu-text`.
- Sub-text `text-zinc-500` / `text-zinc-400` → `text-cu-muted`.
- `border-zinc-200` / `border-zinc-100` → `border-cu-border`.
- Inner segmented-control / chip `bg-zinc-50` → `bg-black/5 dark:bg-white/5`.
- Status text tints per rule C; keep `shadow-sm`, `cu-gradient` buttons (`cu-gradient … text-white` "New submission" stays), and all brand colors. (The `<x-vendor-status-badge>` itself was converted in Task 5 — don't touch its invocations.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ContentThemeSweepTest`
Expected: PASS (all Phase B methods green).

- [ ] **Step 5: Manual visual check** — as a vendor, set the theme to Dark in Settings → Preference and confirm the dashboard, submit, submissions (with search), notifications and profile pages all render dark consistently; switch to Light and confirm they revert. Confirm the choice survives a hard refresh (7-day cookie) and applies on the very first paint (no flash).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/livewire/vendor tests/Feature/Theme/ContentThemeSweepTest.php
git commit -m "feat(theme): make vendor portal pages theme-responsive"
```

---

### Task 10: Repo-wide guard + full-suite regression pass

**Files:**
- Create: `tests/Feature/Theme/ViewThemeGuardTest.php`

**Interfaces:** A catch-all guard asserting no content view (admin + vendor livewire, controller-rendered admin views, shared display components) retains a no-survivor neutral literal. Goes green only after Tasks 5–9.

- [ ] **Step 1: Write the repo-wide guard test**

```php
<?php

namespace Tests\Feature\Theme;

use Tests\TestCase;

class ViewThemeGuardTest extends TestCase
{
    /**
     * No content view may keep a theme-locked neutral literal that has no
     * surviving form after the mapping (zinc shades, solid white borders/
     * dividers). The `dark:…white/x` overlays are intentionally retained and
     * are NOT scanned. Source scan — no JS/visual runner (Global Constraints).
     *
     * Excluded: the `<x-page>` wrapper and the app/auth layout shells, which
     * deliberately pair a light literal (e.g. `text-zinc-900`/`bg-white`) with
     * a `dark:` token — that IS theme-responsive and must not be flagged.
     */
    public function test_no_content_view_keeps_theme_locked_neutral_literals(): void
    {
        $roots = [
            resource_path('views/livewire/admin'),
            resource_path('views/livewire/vendor'),
            resource_path('views/admin'),
            resource_path('views/components'),
        ];

        $banned = [
            'border-white/5', 'border-white/10', 'divide-white/5', 'divide-white/10',
            'text-zinc-950', 'text-zinc-900', 'text-zinc-700',
            'text-zinc-500', 'text-zinc-400', 'text-zinc-300', 'text-zinc-200',
            'border-zinc-200', 'border-zinc-100', 'bg-zinc-50',
        ];

        // The deliberate light/dark-pair shells carry light literals on purpose.
        $isExcluded = static fn (string $path): bool => str_contains(
            str_replace('\\', '/', $path),
            '/views/components/layouts/',
        ) || str_ends_with(str_replace('\\', '/', $path), '/views/components/page.blade.php');

        $offenders = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php' || $isExcluded($file->getPathname())) {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                foreach ($banned as $literal) {
                    // Precise token match: `(?![0-9])` stops `bg-zinc-50` from
                    // matching `bg-zinc-500`, `border-white/5` from `border-white/50`, etc.
                    if (preg_match('/'.preg_quote($literal, '/').'(?![0-9])/', $contents) === 1) {
                        $offenders[] = $file->getPathname().' :: '.$literal;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Theme-locked literals remain:\n".implode("\n", $offenders));
    }
}
```

- [ ] **Step 2: Run the guard**

Run: `php artisan test --compact --filter=ViewThemeGuardTest`
Expected: PASS. If it lists offenders, convert each named `file :: literal` per the mapping (or, if it's a deliberate light/dark pair in a shell, confirm the exclusion covers it), then re-run.

- [ ] **Step 3: Run the whole theme suite**

Run: `php artisan test --compact --filter=Theme`
Expected: PASS (ThemeTokensTest, ThemeBootstrapTest, PageWrapperTest, ThemeComponentSweepTest, ContentThemeSweepTest, ViewThemeGuardTest).

- [ ] **Step 4: Run the full test suite**

Run: `php artisan test --compact`
Expected: PASS — no regressions in Auth/Settings/Dashboard/Admin tests. (If `AuditTrailTest` or `AuditLogFactory` — already modified in the working tree before this plan — interact with `audit/show.blade.php`, confirm those tests still pass after the Task 8 markup swap.)

- [ ] **Step 5: Production build sanity**

Run: `npm run build`
Expected: builds with no errors; `bg-cu-*`, `text-cu-*`, `border-cu-border`, and `bg-black/5` utilities are present in the compiled CSS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Feature/Theme/ViewThemeGuardTest.php
git commit -m "test(theme): repo-wide guard against theme-locked neutral literals"
```

> Do not `git add -A` — the working tree carries unrelated pre-existing edits (`database/factories/AuditLogFactory.php`, `tests/Feature/Admin/AuditTrailTest.php`). Stage only this task's files.

---

## Self-Review

**1. Spec coverage:**
- "fix preference theme on all user type / consistent throughout tab" → Tasks 1 (tokens flip), 4 (shell+page wrapper), 5–9 (every admin + vendor view converted). ✅ Covered for vendor, compliance_officer, admin.
- "store user preference in a cache or cookies with expiry of 7 days" → Task 2 (`theme` cookie, `max-age=604800`, client-only) + Task 3 (toggle writes it). ✅
- "UI breaking on tabs that has search bar … background fills the whole background … stretches depending on filter result" → Task 4 (`<x-page>` flex-1 in flex-column `flux:main`, replacing the collapsing `min-h-full`), with explicit empty-result manual checks in Tasks 4, 6, 9. ✅

**2. Placeholder scan:** Infrastructure tasks (1–4) and the shared component logic carry complete, exact code. Phase B tasks reference the single canonical **Theme Class-Mapping Reference** (a fixed find→replace table, not "add appropriate classes") plus per-file enumerations of the literals that occur in each file; the keep-list removes ambiguity. No "TBD/handle edge cases/similar to Task N" placeholders.

**3. Type/name consistency:**
- `window.advsTheme` with `read()`/`set()`/`apply()` — defined in Task 2, consumed identically in Task 3's `<x-theme-toggle>`.
- Token names `--color-cu-bg/-surface/-text/-muted/-border` — defined in Task 1, used as `bg-cu-bg`/`bg-cu-surface`/`text-cu-text`/`text-cu-muted`/`border-cu-border` throughout Phase B and `<x-page>`.
- `<x-page>` (Task 4) and `<x-theme-toggle>` (Task 3) — component file names (`page.blade.php`, `theme-toggle.blade.php`) match their `<x-…>` invocations.
- Cookie name `theme` and `max-age=604800` are identical across the head runtime (Task 2) and the `ThemeBootstrapTest` assertions.

**Known limitation (stated honestly):** No JS test runner exists, so the cookie-write behavior and `.dark` toggling are not unit-tested in a DOM; they are guarded by asserting the rendered `<head>` runtime markup and the toggle wiring, plus the documented manual visual checks. Visual/CSS correctness of each converted page is guarded by markup assertions (semantic tokens present, old literals absent) and explicit manual light/dark walkthroughs — not pixel snapshots.
