<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Phase 8, Step 74 — NHIF claim reconciliation and response handling.
 * Stub: implementation pending full Phase 8 infrastructure.
 */
class NhifClaimReconciliationController extends Controller
{
    public function index(Request $request): Response
    {
        abort(501, 'NHIF reconciliation (Phase 8, Step 74) is not yet implemented.');
    }

    public function show(Request $request, int $nhifClaim): Response
    {
        abort(501, 'NHIF reconciliation (Phase 8, Step 74) is not yet implemented.');
    }

    public function reconcile(Request $request, int $nhifClaim): RedirectResponse
    {
        abort(501, 'NHIF reconciliation (Phase 8, Step 74) is not yet implemented.');
    }

    public function refreshStatus(Request $request, int $nhifClaim): RedirectResponse
    {
        abort(501, 'NHIF reconciliation (Phase 8, Step 74) is not yet implemented.');
    }

    public function resolveDiscrepancy(Request $request, int $nhifClaim, int $nhifClaimDiscrepancy): RedirectResponse
    {
        abort(501, 'NHIF reconciliation (Phase 8, Step 74) is not yet implemented.');
    }
}
