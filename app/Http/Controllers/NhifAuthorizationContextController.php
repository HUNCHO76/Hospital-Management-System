<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Phase 8 refactor — NHIF authorization-number lookup context.
 * Stub: implementation pending full Phase 8 infrastructure.
 */
class NhifAuthorizationContextController extends Controller
{
    public function index(Request $request): Response
    {
        abort(501, 'NHIF authorization context (Phase 8) is not yet implemented.');
    }

    public function lookup(Request $request): JsonResponse
    {
        abort(501, 'NHIF authorization context (Phase 8) is not yet implemented.');
    }
}
