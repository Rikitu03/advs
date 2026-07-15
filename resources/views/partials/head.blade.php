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
            // Light-locked surfaces (the landing page and the auth flow) opt out of
            // theming entirely: they are drawn ink-on-white with no `dark:` variants,
            // so letting a dark-preferring visitor stamp `.dark` would only recolour
            // the Flux controls inside them and split the page in two.
            if (document.documentElement.dataset.themeLock === 'light') {
                document.documentElement.classList.remove('dark');
                return;
            }

            document.documentElement.classList.toggle('dark', isDark(value));
        }

        function set(value) {
            if (VALID.indexOf(value) === -1) { value = 'system'; }
            // 7-day expiry (604800 = 60 * 60 * 24 * 7). Literal so the rendered
            // <head> source carries `max-age=604800` (Blade does not evaluate JS).
            document.cookie = KEY + '=' + encodeURIComponent(value) + ';path=/;max-age=604800;SameSite=Lax';
            apply(value);
        }

        apply(read());

        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            if (read() === 'system') { apply('system'); }
        });

        // Re-assert the saved theme after Livewire SPA navigations: wire:navigate
        // swaps in a fresh <html> and this <head> script does not re-run, so
        // without this the .dark class is lost in `system` mode and the page
        // reverts to light. (Explicit light/dark are already guarded server-side.)
        document.addEventListener('livewire:navigated', function () {
            apply(read());
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
