<?php

namespace App\Support\Api;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Str;

/**
 * The single source of truth for the shape of every API JSON response.
 *
 * Every controller and the exception handler build responses through here so
 * clients see one consistent envelope for success and error alike.
 */
class ApiResponse
{
    /**
     * A successful response carrying a single resource or arbitrary data.
     */
    public static function success(mixed $data = null, string $message = 'Request successful.', int $status = 200): JsonResponse
    {
        return self::make([
            'success' => true,
            'message' => $message,
            'data' => self::resolveData($data),
        ], $status);
    }

    /**
     * A 201 response for a newly created resource.
     */
    public static function created(mixed $data = null, string $message = 'Resource created successfully.'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    /**
     * A 204 response for an operation that returns no body.
     */
    public static function noContent(): JsonResponse
    {
        return self::make(null, 204);
    }

    /**
     * A paginated collection response with a normalized `meta` block.
     */
    public static function paginated(AbstractPaginator|ResourceCollection $paginator, string $message = 'Request successful.'): JsonResponse
    {
        // A resource collection wrapping a paginator exposes the underlying
        // paginator via ->resource; a bare paginator is used directly.
        $source = $paginator instanceof ResourceCollection ? $paginator->resource : $paginator;

        $data = $paginator instanceof ResourceCollection
            ? $paginator->resolve()
            : $paginator->items();

        return self::make([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => [
                'current_page' => $source->currentPage(),
                'per_page' => $source->perPage(),
                'total' => method_exists($source, 'total') ? $source->total() : null,
                'last_page' => method_exists($source, 'lastPage') ? $source->lastPage() : null,
            ],
        ], 200);
    }

    /**
     * A generic error response.
     *
     * @param  array<string, mixed>|null  $errors
     */
    public static function error(string $message, int $status = 400, ?array $errors = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return self::make($payload, $status);
    }

    /**
     * A 422 validation error response.
     *
     * @param  array<string, mixed>  $errors
     */
    public static function validation(array $errors, string $message = 'The given data was invalid.'): JsonResponse
    {
        return self::error($message, 422, $errors);
    }

    public static function unauthorized(string $message = 'Unauthenticated.'): JsonResponse
    {
        return self::error($message, 401);
    }

    public static function forbidden(string $message = 'You are not authorized to perform this action.'): JsonResponse
    {
        return self::error($message, 403);
    }

    public static function notFound(string $message = 'The requested resource was not found.'): JsonResponse
    {
        return self::error($message, 404);
    }

    public static function conflict(string $message = 'The request could not be completed due to a conflict.'): JsonResponse
    {
        return self::error($message, 409);
    }

    /**
     * Build the JsonResponse, attaching the correlation id and (for non-empty
     * bodies) folding the request id into the envelope.
     *
     * @param  array<string, mixed>|null  $payload
     */
    protected static function make(?array $payload, int $status): JsonResponse
    {
        $requestId = self::requestId();

        if ($payload !== null) {
            $payload['request_id'] = $requestId;
        }

        return tap(new JsonResponse($payload, $status), function (JsonResponse $response) use ($requestId) {
            $response->headers->set('X-Request-ID', $requestId);
        });
    }

    /**
     * Resolve any resource/responsable into a plain array-friendly value.
     */
    protected static function resolveData(mixed $data): mixed
    {
        if ($data instanceof JsonResource) {
            return $data->resolve();
        }

        if ($data instanceof Responsable) {
            return $data->toResponse(request());
        }

        return $data;
    }

    /**
     * The correlation id for the current request, generated on demand.
     */
    protected static function requestId(): string
    {
        $request = request();

        if (! $request->attributes->has('api_request_id')) {
            $request->attributes->set('api_request_id', (string) Str::uuid());
        }

        return $request->attributes->get('api_request_id');
    }
}
