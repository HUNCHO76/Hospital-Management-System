<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Phase 8, Steps 72–73 — NHIF claim/folio preparation and submission.
 * Stub: implementation pending full Phase 8 infrastructure.
 */
class NhifClaimController extends Controller
{
    public function index(Request $request): Response
    {
        abort(501, 'NHIF claims (Phase 8, Steps 72–73) are not yet implemented.');
    }

    public function store(Request $request): RedirectResponse
    {
        abort(501, 'NHIF claims (Phase 8, Steps 72–73) are not yet implemented.');
    }

    public function show(Request $request, int $nhifClaim): Response
    {
        abort(501, 'NHIF claims (Phase 8, Steps 72–73) are not yet implemented.');
    }

    public function regenerate(Request $request, int $nhifClaim): RedirectResponse
    {
        abort(501, 'NHIF claims (Phase 8, Steps 72–73) are not yet implemented.');
    }

    public function validateClaim(Request $request, int $nhifClaim): RedirectResponse
    {
        abort(501, 'NHIF claims (Phase 8, Steps 72–73) are not yet implemented.');
    }

    public function finalize(Request $request, int $nhifClaim): RedirectResponse
    {
        abort(501, 'NHIF claims (Phase 8, Steps 72–73) are not yet implemented.');
    }

    public function submit(Request $request, int $nhifClaim): RedirectResponse
    {
        abort(501, 'NHIF claims (Phase 8, Steps 72–73) are not yet implemented.');
    }

    public function cancel(Request $request, int $nhifClaim): RedirectResponse
    {
        abort(501, 'NHIF claims (Phase 8, Steps 72–73) are not yet implemented.');
    }

    public function addItem(Request $request, int $nhifClaim): RedirectResponse
    {
        abort(501, 'NHIF claims (Phase 8, Steps 72–73) are not yet implemented.');
    }

    public function removeItem(Request $request, int $nhifClaim, int $nhifClaimItem): RedirectResponse
    {
        abort(501, 'NHIF claims (Phase 8, Steps 72–73) are not yet implemented.');
    }

    public function uploadDocument(Request $request, int $nhifClaim): RedirectResponse
    {
        abort(501, 'NHIF claims (Phase 8, Steps 72–73) are not yet implemented.');
    }

    public function downloadDocument(Request $request, int $nhifClaim, int $nhifClaimDocument): BinaryFileResponse
    {
        abort(501, 'NHIF claims (Phase 8, Steps 72–73) are not yet implemented.');
    }

    public function destroyDocument(Request $request, int $nhifClaim, int $nhifClaimDocument): RedirectResponse
    {
        abort(501, 'NHIF claims (Phase 8, Steps 72–73) are not yet implemented.');
    }
}
