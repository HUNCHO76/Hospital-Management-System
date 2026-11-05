<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\ReceptionVisit;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaternityQueueController extends Controller
{
    public function __construct(
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Display the dedicated maternity outpatient queue.
     */
    public function index(Request $request): Response
    {
        abort_unless(
            $request->user()->can('maternity.view')
            || $request->user()->can('maternity.manage')
            || $request->user()->hasAnyRole(['midwife', 'nurse', 'doctor', 'receptionist', 'super-admin']),
            403,
            'Unauthorized to access maternity queue.'
        );

        $date = $request->query('date', now()->toDateString());
        $maternityStatus = $request->query('maternity_status');
        $visitPurpose = $request->query('visit_purpose');
        $search = $request->query('search');

        $maternityDeptIds = Department::query()
            ->where('code', 'MATERNITY')
            ->orWhere('name', 'like', '%maternity%')
            ->pluck('id')
            ->all();

        $baseQuery = ReceptionVisit::query()
            ->where(function ($query) use ($maternityDeptIds) {
                $query->whereIn('department_id', $maternityDeptIds)
                    ->orWhereNotNull('visit_purpose')
                    ->orWhereNotNull('maternity_status');
            });

        // Compute counts across all maternity statuses for current date (or unfiltered date if date='all')
        $countsQuery = (clone $baseQuery);
        if ($date && $date !== 'all') {
            $countsQuery->whereDate('created_at', $date);
        }
        $rawCounts = (clone $countsQuery)
            ->selectRaw('COALESCE(maternity_status, "waiting_for_assessment") as m_status, count(*) as count')
            ->groupBy('m_status')
            ->pluck('count', 'm_status')
            ->all();

        $statusCounts = [
            'all' => array_sum($rawCounts),
        ];
        foreach (array_keys(ReceptionVisit::MATERNITY_STATUSES) as $st) {
            $statusCounts[$st] = $rawCounts[$st] ?? 0;
        }

        $visits = (clone $baseQuery)
            ->when($date && $date !== 'all', fn ($q) => $q->whereDate('created_at', $date))
            ->when($maternityStatus, function ($q, $status) {
                if ($status === ReceptionVisit::MATERNITY_STATUS_WAITING) {
                    $q->where(fn ($sub) => $sub->where('maternity_status', $status)->orWhereNull('maternity_status'));
                } else {
                    $q->where('maternity_status', $status);
                }
            })
            ->when($visitPurpose, fn ($q, $p) => $q->where('visit_purpose', $p))
            ->when($search, function ($q, $term) {
                $q->where(function ($sub) use ($term) {
                    $sub->where('visit_number', 'like', "%{$term}%")
                        ->orWhereHas('patient', function ($pSub) use ($term) {
                            $pSub->where('patient_number', 'like', "%{$term}%")
                                ->orWhere('first_name', 'like', "%{$term}%")
                                ->orWhere('last_name', 'like', "%{$term}%")
                                ->orWhere('phone', 'like', "%{$term}%");
                        });
                });
            })
            ->with([
                'patient:id,patient_number,first_name,middle_name,last_name,phone,date_of_birth,gender',
                'department:id,name,code',
                'practitioner.staff:id,first_name,last_name,job_title',
                'clinicalEncounter',
            ])
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('maternity/queue', [
            'visits' => $visits,
            'filters' => [
                'date' => $date,
                'maternity_status' => $maternityStatus,
                'visit_purpose' => $visitPurpose,
                'search' => $search,
            ],
            'statusCounts' => $statusCounts,
            'maternityStatuses' => ReceptionVisit::MATERNITY_STATUSES,
            'visitPurposes' => ReceptionVisit::VISIT_PURPOSES,
        ]);
    }

    /**
     * Update the maternity queue workflow status for an outpatient visit.
     */
    public function updateStatus(Request $request, ReceptionVisit $visit): RedirectResponse
    {
        abort_unless(
            $request->user()->can('maternity.manage')
            || $request->user()->can('maternity.view')
            || $request->user()->hasAnyRole(['midwife', 'nurse', 'doctor', 'super-admin']),
            403,
            'Unauthorized to update maternity status.'
        );

        $validated = $request->validate([
            'maternity_status' => ['required', 'string', Rule::in(array_keys(ReceptionVisit::MATERNITY_STATUSES))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $oldStatus = $visit->maternity_status ?? ReceptionVisit::MATERNITY_STATUS_WAITING;
        $newStatus = $validated['maternity_status'];

        $updates = [
            'maternity_status' => $newStatus,
        ];

        if ($newStatus === ReceptionVisit::MATERNITY_STATUS_COMPLETED && $visit->status !== ReceptionVisit::STATUS_COMPLETED) {
            $updates['status'] = ReceptionVisit::STATUS_COMPLETED;
            $updates['completed_at'] = now();
        }

        $visit->update($updates);

        $this->auditLog->record(
            'maternity_queue_status_updated',
            $visit,
            ['maternity_status' => $oldStatus],
            [
                'maternity_status' => $newStatus,
                'notes' => $validated['notes'] ?? null,
                'updated_by' => $request->user()->id,
            ],
            $request,
        );

        return back()->with('success', 'Maternity status updated to "'.(ReceptionVisit::MATERNITY_STATUSES[$newStatus] ?? $newStatus).'".');
    }
}
