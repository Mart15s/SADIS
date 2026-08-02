<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isActive()) {
            $request->user()?->tokens()->delete();
            if ($request->hasSession()) {
                auth('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            abort(403, 'This account is inactive.');
        }

        return $next($request);
    }
}
