<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxySetting = trim((string) env('TRUSTED_PROXIES', '127.0.0.1,::1'));
        $trustedProxies = in_array($trustedProxySetting, ['*', '**'], true)
            ? $trustedProxySetting
            : array_values(array_filter(array_map('trim', explode(',', $trustedProxySetting))));
        $middleware->trustProxies(at: $trustedProxies);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');
        $middleware->statefulApi();
        $middleware->alias(['admin' => AdminMiddleware::class, 'active' => EnsureUserIsActive::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $json = static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(function (ValidationException $exception, Request $request) use ($json) {
            if (! $json($request)) {
                return null;
            }

            return response()->json(['message' => 'Check the submitted data.', 'errors' => $exception->errors()], $exception->status);
        });
        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($json) {
            if (! $json($request)) {
                return null;
            }

            return response()->json(['message' => $exception->getMessage() ?: 'Authentication is required.'], 401);
        });
        $exceptions->render(function (AuthorizationException $exception, Request $request) use ($json) {
            if (! $json($request)) {
                return null;
            }

            return response()->json(['message' => $exception->getMessage() ?: 'You do not have permission to perform this action.'], 403);
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request) use ($json) {
            if (! $json($request)) {
                return null;
            }

            return response()->json(['message' => 'The requested resource was not found.'], 404);
        });
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) use ($json) {
            if (! $json($request)) {
                return null;
            }

            return match ($exception->getStatusCode()) {
                403 => response()->json(['message' => $exception->getMessage() ?: 'You do not have permission to perform this action.'], 403),
                404 => response()->json(['message' => 'The requested resource was not found.'], 404),
                409 => response()->json(['message' => $exception->getMessage()], 409),
                default => null,
            };
        });
    })->create();
