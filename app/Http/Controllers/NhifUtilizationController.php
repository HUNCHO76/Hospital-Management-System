<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Phase 8, Step 71 — NHIF clinical utilization monitoring and recovery.
 * Stub: implementation pending full Phase 8 infrastructure.
 */
class NhifUtilizationController extends Controller
{
    public function index(Request $request): Response
    {
        abort(501, 'NHIF utilization monitoring (Phase 8, Step 71) is not yet implemented.');
    }

    public function show(Request $request, int $nhifUtilization): Response
    {
        abort(501, 'NHIF utilization monitoring (Phase 8, Step 71) is not yet implemented.');
    }

    public function submit(Request $request, int $nhifUtilization): RedirectResponse
    {
        abort(501, 'NHIF utilization submission (Phase 8, Step 71) is not yet implemented.');
    }

    public function attachAuthorization(Request $request, int $nhifUtilization): RedirectResponse
    {
        abort(501, 'NHIF utilization authorization (Phase 8, Step 71) is not yet implemented.');
    }

    public function reconcile(Request $request, int $nhifUtilization): RedirectResponse
    {
        abort(501, 'NHIF utilization reconciliation (Phase 8, Step 71) is not yet implemented.');
    }
}
