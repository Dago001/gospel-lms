<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        foreach ($permissions as $p) {
            if ($user && $user->hasPermission($p)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'You do not have permission to perform this action.', 'code' => 'forbidden'], 403);
    }
}
