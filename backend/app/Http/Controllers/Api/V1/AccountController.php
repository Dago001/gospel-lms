<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthUserResource;
use App\Models\UserSession;
use App\Notifications\SecurityNotice;
use App\Services\AuditLogger;
use App\Services\SessionTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AccountController extends Controller
{
    public function __construct(private SessionTracker $sessions, private AuditLogger $audit) {}

    public function updateProfile(Request $request): AuthUserResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s]+$/'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['sometimes', 'timezone:all'],
        ]);
        $user = $request->user();
        if (isset($data['name'])) {
            $user->update(['name' => $data['name']]);
        }
        $user->profile()->updateOrCreate([], collect($data)->except('name')->all());

        return new AuthUserResource($user->load('profile'));
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::defaults(), 'different:current_password'],
        ]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Your current password is incorrect.', 'code' => 'invalid_password'], 422);
        }
        $user->update(['password' => $data['password']]);
        $this->sessions->revokeAll($user, $request->session()->get('user_session_id'));
        $this->audit->log('auth.password_changed', $user);
        $user->notify(new SecurityNotice('Your password was changed', 'The password on your account was just changed. Other devices were signed out.'));

        return response()->json(['message' => 'Password updated. Other devices have been signed out.']);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=100,min_height=100,max_width=4000,max_height=4000']]);
        $profile = $request->user()->profile()->firstOrCreate([]);
        if ($profile->photo_path) {
            Storage::disk('private')->delete($profile->photo_path);
        }
        // Re-encode-safe name; never trust the client filename.
        $path = $request->file('photo')->storeAs('profile-photos', $request->user()->id.'-'.bin2hex(random_bytes(6)).'.'.$request->file('photo')->extension(), 'private');
        $profile->update(['photo_path' => $path]);

        return response()->json(['message' => 'Photo updated.']);
    }

    public function photo(Request $request)
    {
        $path = $request->user()->profile?->photo_path;
        abort_unless($path && Storage::disk('private')->exists($path), 404);

        return Storage::disk('private')->response($path, null, ['Cache-Control' => 'private, max-age=300']);
    }

    public function sessions(Request $request): JsonResponse
    {
        $current = $request->session()->get('user_session_id');
        $rows = UserSession::where('user_id', $request->user()->id)->whereNull('revoked_at')->latest('last_active_at')->get()
            ->map(fn ($s) => ['id' => $s->id, 'ip_address' => $s->ip_address, 'user_agent' => $s->user_agent,
                'last_active_at' => $s->last_active_at?->toIso8601String(), 'current' => $s->id === $current]);

        return response()->json(['data' => $rows]);
    }

    public function revokeSession(Request $request, string $session): JsonResponse
    {
        $row = UserSession::where('user_id', $request->user()->id)->whereNull('revoked_at')->findOrFail($session); // owner-scoped: no IDOR
        $this->sessions->revoke($row);

        return response()->json(['message' => 'Session signed out.']);
    }
}
