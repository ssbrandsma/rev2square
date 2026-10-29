<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suspended accounts lose access on every request — existing sessions
 * included — not just at the next login.
 */
class CheckAccountStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->isSuspended()) {
            auth()->guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(403, 'This account has been suspended.');
            }

            return redirect()->route('suspended')->with('error', 'This account has been suspended.');
        }

        return $next($request);
    }
}
