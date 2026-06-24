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
