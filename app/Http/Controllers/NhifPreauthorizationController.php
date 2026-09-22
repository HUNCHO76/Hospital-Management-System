<?php

namespace App\Http\Controllers;

use App\Models\NhifPreauthorization;
use App\Models\PatientInsurance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Phase 8, Step 69 — NHIF preauthorization lifecycle.
 * Stub: implementation pending full Phase 8 infrastructure.
 */
class NhifPreauthorizationController extends Controller
{
    public function index(Request $request, PatientInsurance $patientInsurance): Response
    {
        abort(501, 'NHIF preauthorization (Phase 8, Step 69) is not yet implemented.');
    }

    public function store(Request $request, PatientInsurance $patientInsurance): RedirectResponse
    {
        abort(501, 'NHIF preauthorization (Phase 8, Step 69) is not yet implemented.');
    }

    public function show(Request $request, NhifPreauthorization $nhifPreauthorization): Response
    {
        abort(501, 'NHIF preauthorization (Phase 8, Step 69) is not yet implemented.');
    }

    public function submit(Request $request, NhifPreauthorization $nhifPreauthorization): RedirectResponse
    {
        abort(501, 'NHIF preauthorization (Phase 8, Step 69) is not yet implemented.');
    }

    public function cancel(Request $request, NhifPreauthorization $nhifPreauthorization): RedirectResponse
    {
        abort(501, 'NHIF preauthorization (Phase 8, Step 69) is not yet implemented.');
    }
}
