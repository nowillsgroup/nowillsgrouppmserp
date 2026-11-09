<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    // RFC 6238, Appendix B — SHA-1 seed ("12345678901234567890" as base32).
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_rfc6238_sha1_vector_at_t59(): void
    {
        // T = 59 s → 30-second step 1 — the RFC vector for 8 digits is 94287082,
        // so the last 6 digits must match.
        $this->assertSame('287082', Totp::code(self::RFC_SECRET, 1));
    }

    public function test_base32_roundtrip(): void
    {
        $secret = Totp::generateSecret(20);
        $this->assertSame($secret, Totp::base32Encode(Totp::base32Decode($secret)));
        $this->assertSame(20, strlen(Totp::base32Decode($secret)));
    }

    public function test_generated_secrets_are_rfc4648_base32(): void
    {
        $secret = Totp::generateSecret(16);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function test_verify_accepts_current_code_and_rejects_wrong_ones(): void
    {
        $time = 1_700_000_000;
        $step = Totp::currentStep($time);
        $code = Totp::code(self::RFC_SECRET, $step);

        $this->assertSame($step, Totp::verify(self::RFC_SECRET, $code, 0, $time));
        $this->assertNull(Totp::verify(self::RFC_SECRET, '000000' === $code ? '000001' : '000000', 0, $time));
        $this->assertNull(Totp::verify(self::RFC_SECRET, '12345', 0, $time));   // wrong length
        $this->assertNull(Totp::verify(self::RFC_SECRET, 'abcdef', 0, $time));  // non-numeric
    }

    public function test_verify_tolerates_one_step_of_clock_drift(): void
    {
        $time = 1_700_000_000;
        $step = Totp::currentStep($time);
        $previous = Totp::code(self::RFC_SECRET, $step - 1);

        $this->assertSame($step - 1, Totp::verify(self::RFC_SECRET, $previous, 1, $time));
        $this->assertNull(Totp::verify(self::RFC_SECRET, $previous, 0, $time));
    }

    public function test_base32_decode_rejects_garbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Totp::base32Decode('not!base32!');
    }
}
