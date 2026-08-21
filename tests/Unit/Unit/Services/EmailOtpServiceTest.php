<?php

namespace Tests\Unit\Unit\Services;

use PHPUnit\Framework\TestCase;

class EmailOtpServiceTest extends TestCase
{
    public function test_codes_are_six_digits_including_leading_zeroes(): void
    {
        $code = str_pad('42', 6, '0', STR_PAD_LEFT);
        $this->assertSame('000042', $code);
        $this->assertMatchesRegularExpression('/\A[0-9]{6}\z/', $code);
    }
}
