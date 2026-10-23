<?php

namespace App\Http\Controllers;

use App\Models\NhifClaim;
use App\Models\NhifClaimDiscrepancy;
use App\Models\NhifEligibilityRecord;
use App\Models\NhifMemberVerification;
use App\Models\NhifPreauthorization;
use App\Models\NhifPriceSyncLog;
use App\Models\NhifUtilization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NhifDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('nhif-dashboard.view'), 403);

        $user = $request->user();
        $hospitalId = $user->hasRole('super-admin') ? null : $user->staff?->hospital_id;

        $stats = [
            'pending_verifications' => NhifMemberVerification::where('verification_status', NhifMemberVerification::STATUS_ERROR)
                ->when($hospitalId, fn ($q) => $q->where('hospital_id', $hospitalId))
                ->count(),
            'today_verifications' => NhifMemberVerification::whereDate('created_at', today())
                ->when($hospitalId, fn ($q) => $q->where('hospital_id', $hospitalId))
                ->count(),
            'pending_eligibility_checks' => NhifEligibilityRecord::where('eligibility_status', NhifEligibilityRecord::STATUS_UNKNOWN)
                ->when($hospitalId, fn ($q) => $q->where('hospital_id', $hospitalId))
                ->count(),
            'today_eligibility_checks' => NhifEligibilityRecord::whereDate('created_at', today())
                ->when($hospitalId, fn ($q) => $q->where('hospital_id', $hospitalId))
                ->count(),
            'pending_preauthorizations' => NhifPreauthorization::where('status', 'pending')
                ->when($hospitalId, fn ($q) => $q->where('hospital_id', $hospitalId))
                ->count(),
            'pending_utilizations' => NhifUtilization::where('status', NhifUtilization::STATUS_PENDING)
                ->when($hospitalId, fn ($q) => $q->where('hospital_id', $hospitalId))
                ->count(),
            'draft_claims' => NhifClaim::where('status', NhifClaim::STATUS_DRAFT)
                ->when($hospitalId, fn ($q) => $q->where('hospital_id', $hospitalId))
                ->count(),
            'submitted_claims' => NhifClaim::where('status', NhifClaim::STATUS_SUBMITTED)
                ->when($hospitalId, fn ($q) => $q->where('hospital_id', $hospitalId))
                ->count(),
            'open_discrepancies' => NhifClaimDiscrepancy::where('status', NhifClaimDiscrepancy::STATUS_OPEN)
                ->when($hospitalId, fn ($q) => $q->whereHas('claim', fn ($c) => $c->where('hospital_id', $hospitalId)))
                ->count(),
            'last_sync_at' => NhifPriceSyncLog::where('status', 'completed')->max('completed_at'),
        ];

        $recentClaims = NhifClaim::with('hospital')
            ->when($hospitalId, fn ($q) => $q->where('hospital_id', $hospitalId))
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (NhifClaim $c) => [
                'id' => $c->id,
                'claim_number' => $c->claim_number,
                'status' => $c->status,
                'claim_type' => $c->claim_type,
                'hms_total' => $c->hms_total,
                'nhif_approved_total' => $c->nhif_approved_total,
                'hospital' => $c->hospital ? ['name' => $c->hospital->name] : null,
                'updated_at' => $c->updated_at->toDateTimeString(),
            ]);

        return Inertia::render('nhif/dashboard', [
            'stats' => $stats,
            'recentClaims' => $recentClaims,
        ]);
    }
}
