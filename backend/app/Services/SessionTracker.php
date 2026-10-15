<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class SessionTracker
{
    public function start(User $user, Request $request): UserSession
    {
        $request->session()->regenerate();
        $row = UserSession::create([
            'user_id' => $user->id,
            'session_id' => $request->session()->getId(),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'last_active_at' => now(),
        ]);
        $request->session()->put('user_session_id', $row->id);
        $user->forceFill(['last_login_at' => now()])->save();

        return $row;
    }

    public function revoke(UserSession $s): void
    {
        if ($s->session_id) {
            Session::getHandler()->destroy($s->session_id);
        }
        $s->update(['revoked_at' => now()]);
    }

    public function revokeAll(User $user, ?string $exceptId = null): int
    {
        $n = 0;
        UserSession::where('user_id', $user->id)->whereNull('revoked_at')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->each(function (UserSession $s) use (&$n) {
                $this->revoke($s);
                $n++;
            });

        return $n;
    }
}
