<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    /**
     * @return array<int, array{0: int, 1: string, 2: string}>
     */
    public static function errorPages(): array
    {
        return [
            [400, 'Bad Request', 'Back to Home'],
            [401, 'Authentication Required', 'Sign In'],
            [403, 'Access Denied', 'Back to Home'],
            [404, 'Page Not Found', 'Back to Home'],
            [405, 'Method Not Allowed', 'Back to Home'],
            [408, 'Request Timeout', 'Try Again'],
            [419, 'Session Expired', 'Sign In'],
            [422, 'Unable to Process Request', 'Go Back'],
            [429, 'Too Many Requests', 'Try Again'],
            [500, 'Something Went Wrong', 'Try Again'],
            [502, 'Bad Gateway', 'Try Again'],
            [503, 'Service Unavailable', 'Try Again'],
            [504, 'Gateway Timeout', 'Try Again'],
        ];
    }

    public function test_laravel_renders_the_custom_not_found_page_for_missing_routes(): void
    {
        config()->set('app.debug', false);

        $this->get('/missing-accreditation-page')
            ->assertNotFound()
            ->assertSee('Page Not Found')
            ->assertSee('Back to Home')
            ->assertSee('HTTP 404 &mdash; PAGE_NOT_FOUND', false)
            ->assertSee(route('home'), false);
    }

    #[DataProvider('errorPages')]
    public function test_common_error_pages_share_the_custom_advs_shell(int $statusCode, string $heading, string $primaryAction): void
    {
        $html = View::make("errors.{$statusCode}")->render();

        $this->assertStringContainsString((string) $statusCode, $html);
        $this->assertStringContainsString($heading, $html);
        $this->assertStringContainsString($primaryAction, $html);
        $this->assertStringContainsString(route('home'), $html);
        $this->assertStringContainsString('Error recovery summary', $html);
        $this->assertStringContainsString('font-jakarta', $html);
        $this->assertStringContainsString('data-theme-lock="light"', $html);
        $this->assertStringContainsString('<x-app-logo', file_get_contents(resource_path('views/errors/layout.blade.php')));
        $this->assertStringContainsString('text-ink', $html);
        $this->assertStringContainsString('bg-ink', $html);
        $this->assertStringContainsString('text-flame', $html);
        $this->assertStringContainsString('bg-flame', $html);
        $this->assertStringContainsString('Error details', $html);
        $this->assertStringContainsString('Next step', $html);
        $this->assertStringContainsString('Error code', $html);
        $this->assertStringContainsString('lg:grid-cols-[minmax(0,480px)_310px]', $html);
        $this->assertStringContainsString('lg:min-h-[292px]', $html);
        $this->assertStringNotContainsString("Error {$statusCode}</p>", $html);
        $this->assertStringNotContainsString('cu-gradient', $html);
        $this->assertStringNotContainsString('cu-purple', $html);
        $this->assertStringNotContainsString('cu-pink', $html);
        $this->assertStringNotContainsString('cu-blue', $html);
    }
}
