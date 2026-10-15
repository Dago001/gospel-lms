<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Privileged roles may only use protected routes after completing multifactor authentication this session. */
class EnsureMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->requiresMfa() && ! $request->session()->get('mfa_verified')) {
            $enrolled = $user->hasTwoFactorEnabled();

            return response()->json([
                'message' => $enrolled ? 'Multifactor verification required.' : 'Multifactor authentication must be set up for your role.',
                'code' => $enrolled ? 'mfa_required' : 'mfa_setup_required',
            ], 403);
        }

        return $next($request);
    }
}
