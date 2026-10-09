<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Practitioner;
use App\Models\Staff;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the HMS dashboard: real system statistics plus, for authorized
     * users, recent audited activity. Figures are live counts of data that
     * already exists in Phase 1 — no fabricated clinical numbers.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $hospitalId = $user->hasRole('super-admin') ? null : $user->staff?->hospital_id;

        $staffQuery = Staff::query()->where('status', Staff::STATUS_ACTIVE);
        $practitionersQuery = Practitioner::query();
        $auditQuery = AuditLog::query();

        if ($hospitalId) {
            $staffQuery->where('hospital_id', $hospitalId);
            $practitionersQuery->whereHas('staff', fn ($q) => $q->where('hospital_id', $hospitalId));
            $auditQuery->whereHas('user.staff', fn ($q) => $q->where('hospital_id', $hospitalId));
        }

        return Inertia::render('dashboard', [
            'stats' => [
                'patients' => Patient::query()->count(),
                'staff' => $staffQuery->count(),
                'practitioners' => $practitionersQuery->count(),
                'activeInsurance' => PatientInsurance::query()->where('status', PatientInsurance::STATUS_ACTIVE)->count(),
            ],
            'recentActivity' => $user->can('audit-logs.view')
                ? $auditQuery
                    ->with('user:id,UserName,email')
                    ->latest('created_at')
                    ->latest('id')
                    ->limit(6)
                    ->get()
                    ->map(fn (AuditLog $log) => [
                        'id' => $log->id,
                        'action' => $log->action,
                        'module' => $log->moduleName(),
                        'user' => $log->user?->UserName,
                        'created_at' => $log->created_at,
                    ])
                : null,
        ]);
    }
}
