<?php

namespace App\Http\Controllers;

use App\Models\NhifClaim;
use App\Models\NhifClaimDiscrepancy;
use App\Models\NhifClaimReconciliation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NhifClaimReconciliationController extends Controller
{
    public function index(Request $request): Response
    {
        $reconciliations = NhifClaimReconciliation::with(['claim', 'hospital', 'reconciledBy'])
            ->when($request->status, fn ($q, $s) => $q->where('hms_status', $s))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (NhifClaimReconciliation $r) => [
                'id' => $r->id,
                'claim' => $r->claim ? ['id' => $r->claim->id, 'claim_number' => $r->claim->claim_number] : null,
                'source' => $r->source,
                'hms_status' => $r->hms_status,
                'amounts_confirmed' => $r->amounts_confirmed,
                'submitted_amount' => $r->submitted_amount,
                'nhif_approved_amount' => $r->nhif_approved_amount,
                'variance_amount' => $r->variance_amount,
                'hospital' => $r->hospital ? ['name' => $r->hospital->name] : null,
                'reconciled_by' => $r->reconciledBy?->name,
                'created_at' => $r->created_at->toDateTimeString(),
            ]);

        return Inertia::render('nhif/reconciliation/index', [
            'reconciliations' => $reconciliations,
            'filters' => $request->only('status'),
        ]);
    }

    public function show(Request $request, NhifClaim $nhifClaim): Response
    {
        $nhifClaim->load(['reconciliations.discrepancies', 'discrepancies', 'hospital', 'patient']);

        return Inertia::render('nhif/reconciliation/show', [
            'claim' => [
                'id' => $nhifClaim->id,
                'claim_number' => $nhifClaim->claim_number,
                'status' => $nhifClaim->status,
                'hms_total' => $nhifClaim->hms_total,
                'nhif_approved_total' => $nhifClaim->nhif_approved_total,
                'reconciliation_status' => $nhifClaim->reconciliation_status,
                'last_reconciled_at' => $nhifClaim->last_reconciled_at?->toDateTimeString(),
                'hospital' => $nhifClaim->hospital ? ['name' => $nhifClaim->hospital->name] : null,
                'patient' => $nhifClaim->patient ? ['name' => $nhifClaim->patient->full_name] : null,
            ],
            'reconciliations' => $nhifClaim->reconciliations->map(fn ($r) => [
                'id' => $r->id,
                'source' => $r->source,
                'hms_status' => $r->hms_status,
                'amounts_confirmed' => $r->amounts_confirmed,
                'submitted_amount' => $r->submitted_amount,
                'nhif_approved_amount' => $r->nhif_approved_amount,
                'variance_amount' => $r->variance_amount,
                'discrepancies_count' => $r->discrepancies->count(),
                'created_at' => $r->created_at->toDateTimeString(),
            ]),
            'discrepancies' => $nhifClaim->discrepancies->map(fn ($d) => [
                'id' => $d->id,
                'category' => $d->category,
                'status' => $d->status,
                'description' => $d->description,
                'submitted_amount' => $d->submitted_amount,
                'nhif_amount' => $d->nhif_amount,
                'variance_amount' => $d->variance_amount,
                'resolution_notes' => $d->resolution_notes,
                'created_at' => $d->created_at->toDateTimeString(),
            ]),
        ]);
    }

    public function reconcile(Request $request, NhifClaim $nhifClaim): RedirectResponse
    {
        $data = $request->validate([
            'nhif_approved_amount' => ['required', 'numeric', 'min:0'],
            'source' => ['required', 'string', 'max:100'],
            'response_reference' => ['nullable', 'string', 'max:100'],
        ]);

        NhifClaimReconciliation::create([
            'nhif_claim_id' => $nhifClaim->id,
            'hospital_id' => $nhifClaim->hospital_id,
            'source' => $data['source'],
            'response_reference' => $data['response_reference'] ?? null,
            'hms_status' => NhifClaimReconciliation::STATUS_CONFIRMED,
            'amounts_confirmed' => true,
            'submitted_amount' => $nhifClaim->hms_total,
            'nhif_approved_amount' => $data['nhif_approved_amount'],
            'variance_amount' => (float) $nhifClaim->hms_total - (float) $data['nhif_approved_amount'],
            'reconciled_by' => $request->user()->id,
        ]);

        $nhifClaim->update([
            'nhif_approved_total' => $data['nhif_approved_amount'],
            'reconciliation_status' => NhifClaim::RECONCILIATION_CONFIRMED,
            'last_reconciled_at' => now(),
            'status' => NhifClaim::STATUS_RECONCILED,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Claim reconciled successfully.');
    }

    public function refreshStatus(Request $request, NhifClaim $nhifClaim): RedirectResponse
    {
        $nhifClaim->update(['last_reconciled_at' => now()]);

        return back()->with('success', 'Reconciliation status refreshed.');
    }

    public function resolveDiscrepancy(Request $request, NhifClaim $nhifClaim, NhifClaimDiscrepancy $nhifClaimDiscrepancy): RedirectResponse
    {
        $data = $request->validate([
            'resolution_notes' => ['required', 'string', 'max:1000'],
            'status' => ['required', 'in:resolved,accepted'],
        ]);

        $nhifClaimDiscrepancy->update([
            'status' => $data['status'],
            'resolution_notes' => $data['resolution_notes'],
            'resolved_at' => now(),
            'resolved_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Discrepancy resolved.');
    }
}
