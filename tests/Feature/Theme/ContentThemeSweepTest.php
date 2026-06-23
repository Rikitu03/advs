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

    public function test_vendors_risklogs_notifications_submission_converted(): void
    {
        $this->assertViewConverted('livewire/admin/vendors/index.blade.php');
        $this->assertViewConverted('livewire/admin/vendors/show.blade.php');
        $this->assertViewConverted('livewire/admin/risk-logs.blade.php');
        $this->assertViewConverted('livewire/admin/notifications.blade.php');
        $this->assertViewConverted('livewire/admin/submissions/show.blade.php');
    }
}
