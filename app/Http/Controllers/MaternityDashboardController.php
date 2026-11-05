<?php

namespace App\Http\Controllers;

use App\Models\DeliveryRecord;
use App\Models\Department;
use App\Models\MaternityProfile;
use App\Models\ReceptionVisit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MaternityDashboardController extends Controller
{
    /**
     * Display the Maternity Dashboard (Phase 9, Item 1).
     */
    public function index(Request $request): Response
    {
        abort_unless(
            $request->user()->can('maternity.view')
            || $request->user()->can('maternity.manage')
            || $request->user()->hasAnyRole(['midwife', 'nurse', 'doctor', 'receptionist', 'super-admin']),
            403,
            'Unauthorized to access maternity dashboard.'
        );

        $today = now()->toDateString();
        $startOfWeek = now()->startOfWeek()->toDateTimeString();

        $maternityDeptIds = Department::query()
            ->where('code', 'MATERNITY')
            ->orWhere('name', 'like', '%maternity%')
            ->pluck('id')
            ->all();

        $visitsTodayQuery = ReceptionVisit::query()
            ->whereDate('created_at', $today)
            ->where(function ($q) use ($maternityDeptIds) {
                $q->whereIn('department_id', $maternityDeptIds)
                    ->orWhereNotNull('visit_purpose')
                    ->orWhereNotNull('maternity_status');
            });

        $totalVisitsToday = (clone $visitsTodayQuery)->count();
        $waitingAssessment = (clone $visitsTodayQuery)
            ->whereIn('maternity_status', [ReceptionVisit::MATERNITY_STATUS_WAITING, ReceptionVisit::MATERNITY_STATUS_ASSESSMENT_IN_PROGRESS])
            ->count();
        $inLabour = (clone $visitsTodayQuery)
            ->where('maternity_status', ReceptionVisit::MATERNITY_STATUS_TREATMENT_IN_PROGRESS)
            ->count();
        $admissionRecommended = (clone $visitsTodayQuery)
            ->where('maternity_status', ReceptionVisit::MATERNITY_STATUS_ADMISSION_RECOMMENDED)
            ->count();
        $admittedToday = (clone $visitsTodayQuery)
            ->where('maternity_status', ReceptionVisit::MATERNITY_STATUS_ADMITTED)
            ->count();
        $completedToday = (clone $visitsTodayQuery)
            ->where('maternity_status', ReceptionVisit::MATERNITY_STATUS_COMPLETED)
            ->count();

        // High risk pregnancies active
        $highRiskCount = MaternityProfile::query()
            ->where('status', 'active')
            ->where('risk_classification', MaternityProfile::RISK_HIGH)
            ->count();

        // Deliveries this week
        $deliveriesThisWeek = DeliveryRecord::query()
            ->where('delivered_at', '>=', $startOfWeek)
            ->count();

        // Active Queue List (top 8 waiting / active)
        $activeQueue = ReceptionVisit::query()
            ->where(function ($q) use ($maternityDeptIds) {
                $q->whereIn('department_id', $maternityDeptIds)
                    ->orWhereNotNull('visit_purpose')
                    ->orWhereNotNull('maternity_status');
            })
            ->whereNotIn('maternity_status', [ReceptionVisit::MATERNITY_STATUS_COMPLETED, 'cancelled'])
            ->with([
                'patient:id,patient_number,first_name,middle_name,last_name,phone,gender,date_of_birth',
                'practitioner:id,practitioner_number,staff_id',
                'practitioner.staff:id,first_name,last_name',
            ])
            ->latest('created_at')
            ->take(8)
            ->get();

        // Recent deliveries with newborn information
        $recentDeliveries = DeliveryRecord::query()
            ->with([
                'patient:id,patient_number,first_name,last_name',
                'babyPatient:id,patient_number,first_name,last_name,gender',
                'conductedBy:id,UserName',
            ])
            ->latest('delivered_at')
            ->take(5)
            ->get();

        // Upcoming / Due follow-ups
        $upcomingFollowUps = MaternityProfile::query()
            ->whereNotNull('follow_up_date')
            ->whereDate('follow_up_date', '>=', $today)
            ->with(['patient:id,patient_number,first_name,last_name,phone', 'receptionVisit:id,visit_number'])
            ->orderBy('follow_up_date')
            ->take(5)
            ->get();

        return Inertia::render('maternity/dashboard', [
            'metrics' => [
                'total_visits_today' => $totalVisitsToday,
                'waiting_assessment' => $waitingAssessment,
                'in_labour' => $inLabour,
                'admission_recommended' => $admissionRecommended,
                'admitted_today' => $admittedToday,
                'completed_today' => $completedToday,
                'high_risk_count' => $highRiskCount,
                'deliveries_this_week' => $deliveriesThisWeek,
            ],
            'activeQueue' => $activeQueue,
            'recentDeliveries' => $recentDeliveries,
            'upcomingFollowUps' => $upcomingFollowUps,
            'maternityStatuses' => ReceptionVisit::MATERNITY_STATUSES,
            'visitPurposes' => ReceptionVisit::VISIT_PURPOSES,
        ]);
    }
}

