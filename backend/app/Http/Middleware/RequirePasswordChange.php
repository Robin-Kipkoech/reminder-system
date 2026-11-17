<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = ($request->isMethod('GET') && $request->is('api/auth/me'))
            || ($request->isMethod('PUT') && $request->is('api/auth/password'))
            || ($request->isMethod('POST') && $request->is('api/auth/logout'));

        if ($request->user()?->must_change_password && ! $allowed) {
            return response()->json([
                'code' => 'PASSWORD_CHANGE_REQUIRED',
                'message' => 'You must change your password before continuing.',
            ], 403);
        }

        return $next($request);
    }
}
