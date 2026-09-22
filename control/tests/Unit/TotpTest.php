<?php

namespace Tests\Unit;

use App\Security\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** RFC 6238 Appendix B: SHA-1, seed "12345678901234567890", 8 digits. */
    public function test_rfc_6238_test_vectors(): void
    {
        $seed = '12345678901234567890';
        foreach ([
            59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471',
            1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130',
        ] as $time => $expected) {
            $this->assertSame($expected, Totp::hotp($seed, intdiv($time, 30), 8), "RFC vector at T=$time");
        }
    }

    /** RFC 4226 Appendix D: HOTP values for counters 0-9. */
    public function test_rfc_4226_test_vectors(): void
    {
        $expected = ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'];
        foreach ($expected as $counter => $code) {
            $this->assertSame($code, Totp::hotp('12345678901234567890', $counter));
        }
    }

    public function test_base32_round_trips_and_matches_the_rfc_4648_vector(): void
    {
        $this->assertSame('MZXW6YTBOI', Totp::base32Encode('foobar'));
        $bytes = random_bytes(20);
        $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
    }

    public function test_verify_accepts_one_step_of_drift_and_no_more(): void
    {
        $secret = Totp::newSecret();
        $now = 1_800_000_000;
        $step = intdiv($now, 30);

        $this->assertSame($step, Totp::verify($secret, Totp::code($secret, $step), $now));
        $this->assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $step - 1), $now));
        $this->assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $step + 1), $now));
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $step - 2), $now));
        $this->assertNull(Totp::verify($secret, 'abcdef', $now));
        $this->assertNull(Totp::verify($secret, '12345', $now));
    }
}
