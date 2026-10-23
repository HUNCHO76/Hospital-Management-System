<?php

namespace App\Http\Controllers;

use App\Http\Requests\Inpatient\RecordBedsideProcedureRequest;
use App\Http\Requests\Inpatient\RecordIntraOpRequest;
use App\Http\Requests\Inpatient\RecordPacuRecoveryRequest;
use App\Http\Requests\Inpatient\ScheduleSurgicalCaseRequest;
use App\Http\Requests\Inpatient\StoreProcedureOrderRequest;
use App\Http\Requests\Inpatient\UpdateChecklistRequest;
use App\Models\Admission;
use App\Models\Practitioner;
use App\Models\ProcedureOrder;
use App\Models\Service;
use App\Models\SurgicalCase;
use App\Services\NursingAccessService;
use App\Services\ProcedureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for Doctor-Ordered Procedures and Operating Theatre Surgical Cases.
 */
class InpatientProcedureController extends Controller
{
    public function __construct(
        protected NursingAccessService $accessService,
        protected ProcedureService $procedureService,
    ) {}

    /**
     * Display the procedures and surgical workspace for an admission.
     */
    public function index(Request $request, Admission $admission): Response
    {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);

        $admission->load([
            'patient',
            'department:id,name',
            'practitioner.staff:id,first_name,last_name',
        ]);

        $orders = ProcedureOrder::query()
            ->where('admission_id', $admission->id)
            ->with([
                'prescribedBy:id,UserName',
                'service:id,code,name,price',
                'surgicalCase.leadSurgeon:id,UserName',
                'surgicalCase.assistantSurgeon:id,UserName',
                'surgicalCase.anesthetist:id,UserName',
                'surgicalCase.scrubNurse:id,UserName',
                'surgicalCase.circulatingNurse:id,UserName',
                'bedsideEvents.performedBy:id,UserName',
            ])
            ->orderByDesc('created_at')
            ->get();

        $practitioners = Practitioner::query()
            ->where('is_active', true)
            ->with('staff:id,first_name,last_name,job_title')
            ->get(['id', 'staff_id']);

        $procedureServices = Service::query()
            ->where('status', 'active')
            ->get(['id', 'code', 'name', 'category', 'price']);

        return Inertia::render('inpatient/procedures-workspace', [
            'admission' => $admission,
            'orders' => $orders,
            'practitioners' => $practitioners,
            'procedureServices' => $procedureServices,
            'constants' => [
                'procedure_types' => ProcedureOrder::TYPES,
                'urgencies' => ProcedureOrder::URGENCIES,
                'anesthesia_types' => SurgicalCase::ANESTHESIA_TYPES,
            ],
        ]);
    }

    /**
     * Prescribe a new procedure order.
     */
    public function storeOrder(StoreProcedureOrderRequest $request, Admission $admission): RedirectResponse
    {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);

        $this->procedureService->createOrder($admission, $request->validated(), $request);

        return back()->with('success', 'Procedure ordered successfully.');
    }

    /**
     * Book/schedule a surgical theatre case for a procedure order.
     */
    public function scheduleCase(
        ScheduleSurgicalCaseRequest $request,
        Admission $admission,
        ProcedureOrder $order
    ): RedirectResponse {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);
        abort_unless((int) $order->admission_id === (int) $admission->id, 404);

        $this->procedureService->scheduleSurgicalCase($order, $request->validated(), $request);

        return back()->with('success', 'Surgical theatre case scheduled successfully.');
    }

    /**
     * Update the WHO Surgical Safety Checklist.
     */
    public function updateChecklist(
        UpdateChecklistRequest $request,
        Admission $admission,
        SurgicalCase $case
    ): RedirectResponse {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);
        abort_unless((int) $case->admission_id === (int) $admission->id, 404);

        $this->procedureService->updateChecklist($case, $request->validated(), $request);

        return back()->with('success', 'Surgical safety checklist updated.');
    }

    /**
     * Record intra-operative surgical documentation.
     */
    public function recordIntraOp(
        RecordIntraOpRequest $request,
        Admission $admission,
        SurgicalCase $case
    ): RedirectResponse {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);
        abort_unless((int) $case->admission_id === (int) $admission->id, 404);

        $this->procedureService->recordIntraOp($case, $request->validated(), $request);

        return back()->with('success', 'Intra-operative notes saved.');
    }

    /**
     * Record PACU recovery monitoring and discharge readiness.
     */
    public function recordPacu(
        RecordPacuRecoveryRequest $request,
        Admission $admission,
        SurgicalCase $case
    ): RedirectResponse {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);
        abort_unless((int) $case->admission_id === (int) $admission->id, 404);

        $this->procedureService->recordPacuRecovery($case, $request->validated(), $request);

        return back()->with('success', 'PACU recovery record documented.');
    }

    /**
     * Record a ward-based bedside procedure.
     */
    public function recordBedside(
        RecordBedsideProcedureRequest $request,
        Admission $admission,
        ProcedureOrder $order
    ): RedirectResponse {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);
        abort_unless((int) $order->admission_id === (int) $admission->id, 404);

        $this->procedureService->recordBedsideProcedure($order, $request->validated(), $request);

        return back()->with('success', 'Bedside procedure event documented.');
    }
}
