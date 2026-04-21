<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DevOnlyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(
            app()->isProduction(),
            403,
            'This endpoint is not available in production.'
        );

        return $next($request);
    }
}
