<?php

use App\Http\Middleware\EnsureUserIsStaff;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'staff' => EnsureUserIsStaff::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Wrap every API error in the standard { success, data, message } envelope.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error($e->getMessage(), 422, $e->errors()),
                $e instanceof AuthenticationException => ApiResponse::error('Unauthenticated.', 401),
                $e instanceof AuthorizationException => ApiResponse::error($e->getMessage() ?: 'Forbidden.', 403),
                // Laravel wraps ModelNotFoundException in a NotFoundHttpException; never leak model class names.
                $e instanceof ModelNotFoundException,
                $e->getPrevious() instanceof ModelNotFoundException => ApiResponse::error('Resource not found.', 404),
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    $e->getMessage() ?: (Response::$statusTexts[$e->getStatusCode()] ?? 'Error'),
                    $e->getStatusCode(),
                )->withHeaders($e->getHeaders()),
                default => config('app.debug') ? null : ApiResponse::error('Server error.', 500),
            };
        });
    })->create();
