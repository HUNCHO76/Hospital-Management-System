<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a correlation id to every API request and logs a safe technical
 * summary once the response is sent. Never logs credentials, tokens, auth
 * headers, or request bodies.
 */
class ApiRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->headers->get('X-Request-ID') ?: (string) Str::uuid();
        $request->attributes->set('api_request_id', $requestId);

        $this->startedAt = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }

    /**
     * Monotonic start time, captured so terminate() can report a duration.
     */
    protected float $startedAt = 0.0;

    /**
     * Log a safe summary after the response has been sent to the client.
     */
    public function terminate(Request $request, Response $response): void
    {
        Log::channel(config('logging.default'))->info('api.request', [
            'request_id' => $request->attributes->get('api_request_id'),
            'method' => $request->getMethod(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $this->startedAt) * 1000, 1),
            'user_id' => $request->user()?->id,
            'hospital_id' => $request->user()?->staff?->hospital_id,
            'ip' => $request->ip(),
        ]);
    }
}
