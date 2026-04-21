<?php

use App\Http\Middleware\AdminMiddleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'dev.only' => \App\Http\Middleware\DevOnlyMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $shouldReturnJson = static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(function (ValidationException $exception, Request $request) use ($shouldReturnJson) {
            if (! $shouldReturnJson($request)) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($shouldReturnJson) {
            if (! $shouldReturnJson($request)) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage() ?: 'Unauthenticated.',
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) use ($shouldReturnJson) {
            if (! $shouldReturnJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Neturite teisių atlikti šį veiksmą',
            ], 403);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request) use ($shouldReturnJson) {
            if (! $shouldReturnJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Resursas nerastas',
            ], 404);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) use ($shouldReturnJson) {
            if (! $shouldReturnJson($request)) {
                return null;
            }

            $statusCode = $exception->getStatusCode();

            if ($statusCode === 403) {
                return response()->json([
                    'message' => 'Neturite teisių atlikti šį veiksmą',
                ], 403);
            }

            if ($statusCode === 404) {
                return response()->json([
                    'message' => 'Resursas nerastas',
                ], 404);
            }

            return null;
        });
    })->create();
