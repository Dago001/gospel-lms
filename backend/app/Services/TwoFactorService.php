<?php

namespace App\Services;

use App\Models\TwoFactorAuthentication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TwoFactorService
{
    public function __construct(private TotpService $totp) {}

    /** Start (or restart) enrolment; the factor is inactive until confirmed. */
    public function begin(User $user): array
    {
        $secret = $this->totp->generateSecret();
        TwoFactorAuthentication::updateOrCreate(
            ['user_id' => $user->id],
            ['secret' => $secret, 'recovery_codes' => [], 'confirmed_at' => null, 'last_used_step' => null],
        );

        return ['secret' => $secret, 'otpauth_uri' => $this->totp->provisioningUri($secret, $user->email)];
    }

    /** @return string[]|null plain recovery codes (shown once) or null if the code was wrong */
    public function confirm(User $user, string $code): ?array
    {
        $tf = $user->twoFactor()->whereNull('confirmed_at')->first();
        if (! $tf || ($step = $this->totp->verify($tf->secret, $code)) === null) {
            return null;
        }
        $plain = collect(range(1, 8))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
        $tf->update([
            'confirmed_at' => now(),
            'last_used_step' => $step,
            'recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $plain),
        ]);

        return $plain;
    }

    /** Verifies a TOTP code (single use per time-step) or burns a recovery code. */
    public function verifyChallenge(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code) {
            $tf = $user->twoFactor()->whereNotNull('confirmed_at')->lockForUpdate()->first();
            if (! $tf) {
                return false;
            }
            $step = $this->totp->verify($tf->secret, $code);
            if ($step !== null) {
                if ($tf->last_used_step !== null && $step <= $tf->last_used_step) {
                    return false; // replayed code
                }
                $tf->update(['last_used_step' => $step]);

                return true;
            }
            $hash = hash('sha256', Str::lower(trim($code)));
            $codes = $tf->recovery_codes ?? [];
            $idx = array_search($hash, $codes, true);
            if ($idx === false) {
                return false;
            }
            unset($codes[$idx]);
            $tf->update(['recovery_codes' => array_values($codes)]);

            return true;
        });
    }

    public function disable(User $user): void
    {
        $user->twoFactor()->delete();
    }
}
