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
