<?php

namespace App\Services;

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 s), the profile supported by all authenticator apps. */
class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function currentStep(?int $time = null): int
    {
        return intdiv($time ?? time(), 30);
    }

    public function codeAt(string $secret, int $step): string
    {
        $key = $this->base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $bin = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($bin % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Returns the matched time-step (for replay protection) or null. Accepts +/- 1 step of drift. */
    public function verify(string $secret, string $code, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $now = $this->currentStep($time);
        foreach ([0, -1, 1] as $drift) {
            if (hash_equals($this->codeAt($secret, $now + $drift), $code)) {
                return $now + $drift;
            }
        }

        return null;
    }

    public function provisioningUri(string $secret, string $account, string $issuer = 'GOSPEL LMS'): string
    {
        return sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer), rawurlencode($account), $secret, rawurlencode($issuer));
    }

    private function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private function base32Decode(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($b32, '='))) as $c) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
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
