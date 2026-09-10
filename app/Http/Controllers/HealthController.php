<?php

namespace App\Http\Controllers;

use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * A public liveness endpoint for uptime monitoring/load balancers — the one
 * deliberate exception to "internal HMS functionality lives in web.php"
 * (see routes/api.php's header comment), since it isn't application
 * functionality at all. Exposes only status and time — never environment,
 * configuration, or internal details.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success([
            'status' => 'ok',
            'time' => now()->toIso8601String(),
        ], 'HMS is healthy.');
    }
}
