<?php

use App\Http\Middleware\ApiRequestContext;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Baseline security headers on every response (web and API).
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'logout',
        ]);

        $middleware->api(append: [
            ApiRequestContext::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Handle unauthenticated Inertia requests by triggering an external Inertia location redirect.
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->header('X-Inertia')) {
                return Inertia::location(route('login'));
            }

            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::unauthorized();
            }

            return null;
        });

        // Convert exceptions on API/JSON requests into the standard envelope,
        // never leaking stack traces, SQL, or internal details to clients.
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return match (true) {
                $exception instanceof ValidationException => ApiResponse::validation($exception->errors(), $exception->getMessage()),
                $exception instanceof AuthenticationException => ApiResponse::unauthorized(),
                $exception instanceof AuthorizationException => ApiResponse::forbidden($exception->getMessage() ?: 'You are not authorized to perform this action.'),
                $exception instanceof ModelNotFoundException, $exception instanceof NotFoundHttpException => ApiResponse::notFound(),
                $exception instanceof TooManyRequestsHttpException => ApiResponse::error('Too many requests. Please slow down.', 429),
                $exception instanceof HttpExceptionInterface => ApiResponse::error(
                    $exception->getMessage() ?: 'Request could not be completed.',
                    $exception->getStatusCode(),
                ),
                default => ApiResponse::error(
                    config('app.debug') ? $exception->getMessage() : 'An unexpected error occurred.',
                    500,
                ),
            };
        });

        // Render branded Inertia error pages for the web application. API and
        // JSON requests are handled by the standard envelope above.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($response->getStatusCode() === 419) {
                if ($request->header('X-Inertia')) {
                    return Inertia::location(route('login'));
                }

                return redirect()->route('login')->with([
                    'status' => 'Your session has expired. Please log in again.',
                ]);
            }

            if ($exception instanceof AuthenticationException && $request->header('X-Inertia')) {
                return Inertia::location(route('login'));
            }

            // In accordance with RFC 7231 / Inertia requirements, convert 302 redirects on non-GET requests to 303 See Other
            // so browsers do not repeat the non-GET HTTP method (e.g. PUT/PATCH/DELETE) on the redirected target.
            if ($response->getStatusCode() === 302 && in_array($request->method(), ['PUT', 'PATCH', 'DELETE'])) {
                $response->setStatusCode(303);
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                return $response;
            }

            $status = $response->getStatusCode();

            $pages = [
                403 => 'errors/403',
                404 => 'errors/404',
                500 => 'errors/500',
                503 => 'errors/500',
            ];

            // Only intercept real error responses in production; keep Laravel's
            // detailed debug page while developing.
            if (isset($pages[$status]) && (! app()->hasDebugModeEnabled() || $status === 403 || $status === 404)) {
                return Inertia::render($pages[$status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
