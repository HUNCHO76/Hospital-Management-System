<?php

namespace App\Http\Controllers;

use App\Http\Requests\Billing\CancelChargeRequest;
use App\Http\Requests\Billing\CreateChargeRequest;
use App\Http\Requests\Billing\VoidChargeRequest;
use App\Models\Charge;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Service;
use App\Services\ChargeCaptureService;
use App\Services\ChargeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manual charge entry and lifecycle management (Phase 7, Step 52, manual
 * creation upgraded in Step 54 to route through ChargeCaptureService::
 * captureManual() — spec §26-27's "select a priced catalog service, never a
 * free-typed amount"). Mirrors StockController's receive/adjust-style
 * shape.
 */
class ChargeController extends Controller
{
    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('billing-charges.create'), 403);

        $hospitals = $request->user()->hasRole('hospital-admin')
            ? Hospital::query()->whereKey($this->scopedHospitalId($request))->select('id', 'name')->get()
            : Hospital::query()->where('status', Hospital::STATUS_ACTIVE)->select('id', 'name')->orderBy('name')->get();

        // Optional prefill when reached from an account's own "Record
        // Charge" link (?patient_id=X&hospital_id=Y) — a plain query param,
        // re-validated by CreateChargeRequest on submit regardless, so a
        // tampered value here can only prefill the form, never bypass
        // authorization.
        $initialPatient = null;

        if ($request->filled('patient_id')) {
            $patient = Patient::query()->find($request->integer('patient_id'), ['id', 'patient_number', 'first_name', 'middle_name', 'last_name']);
            $initialPatient = $patient?->only(['id', 'patient_number', 'first_name', 'middle_name', 'last_name']);
        }

        $services = Service::query()
            ->where('status', Service::STATUS_ACTIVE)
            ->where('is_billable', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'category']);

        return Inertia::render('billing/charges/create', [
            'hospitals' => $hospitals,
            'services' => $services,
            'initialPatient' => $initialPatient,
            'initialHospitalId' => $request->integer('hospital_id') ?: null,
        ]);
    }

    public function store(CreateChargeRequest $request, ChargeCaptureService $chargeCapture): RedirectResponse
    {
        $data = $request->validated();

        $this->authorize('createInto', [Charge::class, (int) $data['hospital_id']]);

        $charge = $chargeCapture->captureManual($data, $request);

        return redirect()->route('billing.accounts.show', $charge->patient_account_id)->with('success', 'Charge recorded.');
    }

    public function post(Request $request, Charge $charge, ChargeService $charges): RedirectResponse
    {
        $this->authorize('manage', $charge);

        $charges->post($charge, $request);

        return back()->with('success', 'Charge posted.');
    }

    public function void(VoidChargeRequest $request, Charge $charge, ChargeService $charges): RedirectResponse
    {
        $this->authorize('manage', $charge);

        $charges->void($charge, $request->validated()['reason'], $request);

        return back()->with('success', 'Charge voided.');
    }

    public function cancel(CancelChargeRequest $request, Charge $charge, ChargeService $charges): RedirectResponse
    {
        $this->authorize('manage', $charge);

        $charges->cancel($charge, $request->validated()['reason'], $request);

        return back()->with('success', 'Charge cancelled.');
    }

    /**
     * The hospital a hospital-admin is restricted to, or null when the user
     * is unrestricted (super-admin and any other unscoped role).
     */
    protected function scopedHospitalId(Request $request): ?int
    {
        if (! $request->user()->hasRole('hospital-admin')) {
            return null;
        }

        return $request->user()->staff?->hospital_id;
    }
}
