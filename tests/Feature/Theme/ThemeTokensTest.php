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

        $this->assertStringContainsString('--color-cu-bg: #f8f8fb;', $css);
        $this->assertStringContainsString('--color-cu-surface: #ffffff;', $css);
        $this->assertStringContainsString('--color-cu-text: #18181b;', $css);
        $this->assertStringContainsString('--color-cu-muted: #6b7280;', $css);
        $this->assertStringContainsString('--color-cu-border: #e5e7eb;', $css);
    }

    public function test_cu_tokens_have_dark_overrides(): void
    {
        $css = $this->css();

        // All five dark surfaces must live under the .dark override so today's
        // dark look is preserved exactly.
        $this->assertMatchesRegularExpression('/\.dark\s*\{[^}]*--color-cu-bg:\s*#0d0d0f;/s', $css);
        $this->assertMatchesRegularExpression('/\.dark\s*\{[^}]*--color-cu-surface:\s*#1a1a2e;/s', $css);
        $this->assertMatchesRegularExpression('/\.dark\s*\{[^}]*--color-cu-text:\s*#ffffff;/s', $css);
        $this->assertMatchesRegularExpression('/\.dark\s*\{[^}]*--color-cu-muted:\s*#a0a0b0;/s', $css);
        $this->assertMatchesRegularExpression('/\.dark\s*\{[^}]*--color-cu-border:\s*rgb\(255 255 255 \/ 0\.08\);/s', $css);
    }
}
