<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthUserResource;
use App\Models\ConsentRecord;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SessionTracker;
use App\Services\TwoFactorService;
use App\Notifications\SecurityNotice;
use App\Support\Roles;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public const POLICY_VERSION = '1.0';

    public function __construct(private SessionTracker $sessions, private TwoFactorService $twoFactor, private AuditLogger $audit) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'accept_terms' => ['accepted'],
        ]);

        $user = DB::transaction(function () use ($data, $request) {
            $user = User::create(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => $data['password']]);
            $user->roles()->attach(Role::where('slug', Roles::STUDENT)->firstOrFail()->id);
            $user->profile()->create([]);
            foreach (['terms', 'privacy'] as $type) {
                ConsentRecord::create(['user_id' => $user->id, 'type' => $type, 'version' => self::POLICY_VERSION,
                    'ip_address' => $request->ip(), 'granted_at' => now()]);
            }

            return $user;
        });

        event(new Registered($user)); // queues the verification email
        Auth::guard('web')->login($user);
        $this->sessions->start($user, $request);

        return (new AuthUserResource($user->load('profile')))->response()->setStatusCode(201);
    }

    public function login(Request $request): JsonResponse
    {
        $creds = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', Str::lower($creds['email']))->first();

        // Constant-ish behaviour for unknown users to limit account enumeration.
        if (! $user || ! Hash::check($creds['password'], $user->password)) {
            return response()->json(['message' => 'These credentials do not match our records.', 'code' => 'invalid_credentials'], 422);
        }
        if ($user->isSuspended()) {
            return response()->json(['message' => 'This account has been suspended. Please contact support.', 'code' => 'account_suspended'], 403);
        }

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->regenerate();
            $request->session()->put('mfa.pending', ['user_id' => $user->id, 'expires' => now()->addMinutes(5)->timestamp]);

            return response()->json(['status' => 'two_factor_required']);
        }

        Auth::guard('web')->login($user);
        $this->sessions->start($user, $request);

        return response()->json(['status' => 'authenticated', 'user' => new AuthUserResource($user->load('profile'))]);
    }

    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $pending = $request->session()->get('mfa.pending');
        if (! $pending || $pending['expires'] < now()->timestamp || ! ($user = User::find($pending['user_id']))) {
            return response()->json(['message' => 'Your sign-in attempt expired. Please sign in again.', 'code' => 'mfa_expired'], 422);
        }
        if ($user->isSuspended() || ! $this->twoFactor->verifyChallenge($user, $data['code'])) {
            return response()->json(['message' => 'The verification code is invalid.', 'code' => 'invalid_code'], 422);
        }

        $request->session()->forget('mfa.pending');
        Auth::guard('web')->login($user);
        $this->sessions->start($user, $request);
        $request->session()->put('mfa_verified', true);

        return response()->json(['status' => 'authenticated', 'user' => new AuthUserResource($user->load('profile'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($id = $request->session()->get('user_session_id')) {
            \App\Models\UserSession::where('id', $id)->update(['revoked_at' => now()]);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): AuthUserResource
    {
        return new AuthUserResource($request->user()->load('profile'));
    }

    /** Like me(), but answers 200 with null for guests so the SPA's start-up probe is not an error. */
    public function session(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();
        if (! $user || $user->isSuspended()) {
            return response()->json(['data' => null]);
        }

        return (new AuthUserResource($user->load('profile')))->response();
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        // Same response whether or not the account exists.
        return response()->json(['message' => 'If an account exists for that address, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'], 'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified(); // proved control of the mailbox
            }
            $this->sessions->revokeAll($user);
            $user->notify(new SecurityNotice('Your password was reset', 'The password on your account was reset using an emailed link.'));
            $this->audit->log('auth.password_reset', $user, actorId: $user->id);
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'This reset link is invalid or has expired.', 'code' => 'invalid_reset_token'], 422);
        }

        return response()->json(['message' => 'Your password has been reset. You can now sign in.']);
    }

    /** Signed link from the verification email. Redirects to the SPA. */
    public function verifyEmail(Request $request, string $id, string $hash)
    {
        $front = rtrim(config('app.frontend_url'), '/');
        $user = User::find($id);
        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect("$front/verify-email?status=invalid");
        }
        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect("$front/verify-email?status=success");
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Your email is already verified.']);
        }
        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification email sent.']);
    }
}
