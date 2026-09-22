<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Phase 8, Step 70 — Hospital admission and transfer lifecycle.
 * Stub: implementation pending full Phase 8 infrastructure.
 */
class AdmissionController extends Controller
{
    public function index(Request $request): Response
    {
        abort(501, 'Admissions (Phase 8, Step 70) are not yet implemented.');
    }

    public function create(Request $request): Response
    {
        abort(501, 'Admissions (Phase 8, Step 70) are not yet implemented.');
    }

    public function store(Request $request): RedirectResponse
    {
        abort(501, 'Admissions (Phase 8, Step 70) are not yet implemented.');
    }

    public function show(Request $request, int $admission): Response
    {
        abort(501, 'Admissions (Phase 8, Step 70) are not yet implemented.');
    }

    public function confirm(Request $request, int $admission): RedirectResponse
    {
        abort(501, 'Admissions (Phase 8, Step 70) are not yet implemented.');
    }

    public function discharge(Request $request, int $admission): RedirectResponse
    {
        abort(501, 'Admissions (Phase 8, Step 70) are not yet implemented.');
    }

    public function cancel(Request $request, int $admission): RedirectResponse
    {
        abort(501, 'Admissions (Phase 8, Step 70) are not yet implemented.');
    }

    public function storeTransfer(Request $request, int $admission): RedirectResponse
    {
        abort(501, 'Admission transfers (Phase 8, Step 70) are not yet implemented.');
    }

    public function completeTransfer(Request $request, int $admission, int $transfer): RedirectResponse
    {
        abort(501, 'Admission transfers (Phase 8, Step 70) are not yet implemented.');
    }

    public function submitNhif(Request $request, int $admission): RedirectResponse
    {
        abort(501, 'NHIF admission submission (Phase 8, Step 70) is not yet implemented.');
    }

    public function submitTransferNhif(Request $request, int $admission, int $transfer): RedirectResponse
    {
        abort(501, 'NHIF transfer submission (Phase 8, Step 70) is not yet implemented.');
    }
}
