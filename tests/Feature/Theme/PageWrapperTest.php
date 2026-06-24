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
