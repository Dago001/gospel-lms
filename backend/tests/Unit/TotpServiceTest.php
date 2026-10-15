<?php

use App\Services\TotpService;

it('matches the RFC 6238 SHA-1 test vectors', function () {
    // RFC 6238 appendix B secret "12345678901234567890" (base32 GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ), 8-digit codes truncated to 6.
    $totp = new TotpService;
    $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    expect($totp->codeAt($secret, intdiv(59, 30)))->toBe('287082')
        ->and($totp->codeAt($secret, intdiv(1111111109, 30)))->toBe('081804')
        ->and($totp->codeAt($secret, intdiv(1234567890, 30)))->toBe('005924');
});

it('accepts one step of clock drift but not more', function () {
    $totp = new TotpService;
    $secret = $totp->generateSecret();
    $now = 1_700_000_000;
    $step = $totp->currentStep($now);
    expect($totp->verify($secret, $totp->codeAt($secret, $step - 1), $now))->toBe($step - 1)
        ->and($totp->verify($secret, $totp->codeAt($secret, $step - 3), $now))->toBeNull()
        ->and($totp->verify($secret, 'abcdef', $now))->toBeNull();
});
