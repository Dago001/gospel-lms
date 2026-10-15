<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Notifications\SecurityNotice;
use App\Services\AuditLogger;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorService $service, private AuditLogger $audit) {}

    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor authentication is already enabled.', 'code' => 'already_enabled'], 409);
        }
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Password is incorrect.', 'code' => 'invalid_password'], 422);
        }

        return response()->json($this->service->begin($user));
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $codes = $this->service->confirm($request->user(), $data['code']);
        if ($codes === null) {
            return response()->json(['message' => 'The verification code is invalid.', 'code' => 'invalid_code'], 422);
        }
        $request->session()->put('mfa_verified', true);
        $this->audit->log('auth.2fa_enabled', $request->user());
        $request->user()->notify(new SecurityNotice('Two-factor authentication enabled', 'Two-factor authentication was turned on for your account.'));

        return response()->json(['recovery_codes' => $codes]);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Password is incorrect.', 'code' => 'invalid_password'], 422);
        }
        if ($user->requiresMfa()) {
            return response()->json(['message' => 'Your role requires two-factor authentication.', 'code' => 'mfa_mandatory'], 403);
        }
        $this->service->disable($user);
        $this->audit->log('auth.2fa_disabled', $user);
        $user->notify(new SecurityNotice('Two-factor authentication disabled', 'Two-factor authentication was turned off for your account.'));

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }
}
