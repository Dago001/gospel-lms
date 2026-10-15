<?php

namespace App\Http\Middleware;

use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Rejects suspended accounts and revoked sessions, and records activity. */
class EnsureActiveSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }
        $row = ($id = $request->session()->get('user_session_id')) ? UserSession::find($id) : null;
        if ($user->isSuspended() || ($row && $row->revoked_at)) {
            $code = $user->isSuspended() ? 'account_suspended' : 'session_revoked';
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return response()->json(['message' => 'Your session is no longer valid.', 'code' => $code], 401);
        }
        if ($row && (! $row->last_active_at || $row->last_active_at->lt(now()->subMinute()))) {
            $row->forceFill(['last_active_at' => now()])->save();
        }

        return $next($request);
    }
}
