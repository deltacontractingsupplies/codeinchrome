<?php

namespace App\Security;

/**
 * Time-based one-time passwords, RFC 6238 (SHA-1, 30-second steps, 6 digits):
 * what every authenticator app speaks.
 *
 * Implemented here rather than pulled in, because it is thirty lines and it is
 * the one thing in sign-in that must be exactly right - and it is checked
 * against the RFC's own published test vectors (tests/Unit/TotpTest.php).
 */
final class Totp
{
    public const STEP = 30;

    public const DIGITS = 6;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new random secret: 160 bits, the size RFC 4226 recommends for SHA-1. */
    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function code(string $secret, int $step): string
    {
        return self::hotp(self::base32Decode($secret), $step);
    }

    /**
     * The time step a code matches, within one step either side for clock
     * drift - or null. The caller records the step and refuses any step at or
     * before it, so an observed code cannot be replayed within its window.
     */
    public static function verify(string $secret, string $code, ?int $now = null, int $window = 1): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $key = self::base32Decode($secret);
        $current = intdiv($now ?? time(), self::STEP);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($key, $current + $i), $code)) {
                return $current + $i;
            }
        }

        return null;
    }

    /** RFC 4226 HOTP with a raw key. Also used directly by the RFC 6238 test vectors. */
    public static function hotp(string $key, int $counter, int $digits = self::DIGITS, string $algo = 'sha1'): string
    {
        $hash = hash_hmac($algo, pack('J', $counter), $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function provisioningUri(string $secret, string $account, string $issuer = 'codeinchrome'): string
    {
        return sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer), rawurlencode($account), $secret, rawurlencode($issuer), self::DIGITS, self::STEP);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=-]/', '', $text));
        $bits = '';
        foreach (str_split($text) as $c) {
            $v = strpos(self::BASE32, $c);
            if ($v === false) {
                throw new \InvalidArgumentException('not base32');
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
