<?php

namespace App\Http\Controllers;

use App\Http\Requests\LaboratoryResult\AmendLaboratoryResultRequest;
use App\Http\Requests\LaboratoryResult\AuthorizeLaboratoryResultRequest;
use App\Http\Requests\LaboratoryResult\ReleaseLaboratoryResultRequest;
use App\Http\Requests\LaboratoryResult\ReturnForCorrectionRequest;
use App\Http\Requests\LaboratoryResult\StartReviewLaboratoryResultRequest;
use App\Http\Requests\LaboratoryResult\SubmitLaboratoryResultRequest;
use App\Http\Requests\LaboratoryResult\UpdateLaboratoryResultRequest;
use App\Http\Requests\LaboratoryResult\VerifyLaboratoryResultRequest;
use App\Http\Resources\LaboratoryResultResource;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryReport;
use App\Models\LaboratoryResult;
use App\Models\LaboratoryTest;
use App\Services\LaboratoryReportService;
use App\Services\LaboratoryResultService;
use App\Services\NHIF\NHIFClinicalUtilizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The result-entry, verification, authorization, and release workflow
 * (Phase 4, Steps 33-35) — its own standalone surface under
 * `laboratory/results`, parallel to `laboratory/processing` (Step 32).
 * Lists one row per test's own result (never a whole specimen — one sample
 * may carry several tests, each with its own independent result and
 * independent verification/release lifecycle). Report generation itself
 * lives on the sibling LaboratoryReportController — this controller only
 * orchestrates *when* a report needs to be (re)generated, via
 * LaboratoryReportService, never building report data itself.
 */
class LaboratoryResultController extends Controller
{
    protected function eagerLoads(): array
    {
        return [
            'item.laboratoryOrder.clinicalEncounter.patient',
            'item.laboratoryOrder.activeReport',
            'sample:id,sample_number,specimen_type,container_type,received_at',
            'enteredBy:id,UserName',
            'submittedBy:id,UserName',
            'amendedBy:id,UserName',
            'reviewedBy:id,UserName',
            'verifiedBy:id,UserName',
            'authorizedBy:id,UserName',
            'returnedBy:id,UserName',
            'releasedBy:id,UserName',
        ];
    }

    /**
     * The result-entry/verification dashboard/worklist: every test that has
     * ever reached result entry (spec §9-10) — real status-distribution
     * counts plus a filterable, paginated table.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LaboratoryResult::class);

        $filters = $request->only(['search', 'status', 'priority', 'specimen_type', 'abnormal', 'date_from', 'date_to']);

        $query = $this->baseQuery($filters);
        $this->scopeToHospital($query, $this->scopedHospitalId($request));

        $statusCounts = $this->statusCounts(clone $query);
        $submittedToday = (clone $query)->whereDate('submitted_at', now()->toDateString())->count();
        $verifiedToday = (clone $query)->whereDate('verified_at', now()->toDateString())->count();
        $releasedToday = (clone $query)->whereDate('released_at', now()->toDateString())->count();
        $criticalPending = (clone $query)
            ->where('abnormal_flag', LaboratoryResult::FLAG_CRITICAL)
            ->where('status', '!=', LaboratoryResult::STATUS_AUTHORIZED)
            ->where('status', '!=', LaboratoryResult::STATUS_RELEASED)
            ->count();
        $reportsGeneratedToday = LaboratoryReport::query()->whereDate('created_at', now()->toDateString())->count();
        $amendedReportVersions = LaboratoryReport::query()->where('version', '>', 1)->count();

        $results = (clone $query)->with($this->eagerLoads())
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (LaboratoryResult $result) => (new LaboratoryResultResource($result))->resolve($request));

        return Inertia::render('laboratory/results/index', [
            'results' => $results,
            'filters' => $filters,
            'statusCounts' => $statusCounts,
            'submittedToday' => $submittedToday,
            'verifiedToday' => $verifiedToday,
            'releasedToday' => $releasedToday,
            'criticalPending' => $criticalPending,
            'reportsGeneratedToday' => $reportsGeneratedToday,
            'amendedReportVersions' => $amendedReportVersions,
            'statuses' => LaboratoryResult::STATUSES,
            'priorities' => LaboratoryOrder::PRIORITIES,
            'specimenTypes' => LaboratoryTest::SPECIMEN_TYPES,
        ]);
    }

    /**
     * The result entry/review page for a single test.
     */
    public function show(Request $request, LaboratoryResult $result): Response
    {
        $this->authorize('view', $result);

        $result->load($this->eagerLoads());

        return Inertia::render('laboratory/results/show', [
            'result' => (new LaboratoryResultResource($result))->resolve($request),
            'returnReasons' => LaboratoryResult::RETURN_REASONS,
            // Phase 8, Step 71: NHIF utilization status (empty unless the user may view it).
            'nhifUtilizations' => app(NHIFClinicalUtilizationService::class)->summariesFor(
                $request->user(),
                LaboratoryOrderItem::class,
                [$result->laboratory_order_item_id],
                $request,
            ),
        ]);
    }

    public function update(UpdateLaboratoryResultRequest $request, LaboratoryResult $result, LaboratoryResultService $service): RedirectResponse
    {
        $this->authorize('update', $result);

        $service->saveDraft($result, $request->validated(), $request);

        return back()->with('success', 'Draft saved.');
    }

    public function submit(SubmitLaboratoryResultRequest $request, LaboratoryResult $result, LaboratoryResultService $service): RedirectResponse
    {
        $this->authorize('submit', $result);

        $service->submit($result, $request);

        return back()->with('success', 'Result submitted for verification.');
    }

    /**
     * Amendment is gated by one of two permissions depending on the
     * result's current status (spec §22, extended by Step 35 Part 17) —
     * `amend` for a pre-verification correction (Step 33, unchanged),
     * `amendVerified` for the materially more sensitive controlled
     * amendment of already-verified/authorized/released clinical data
     * (Step 34, extended). Amending an already-released result additionally
     * supersedes the order's current report version immediately (spec Part
     * 17) — orchestrated here rather than inside either service, so neither
     * LaboratoryResultService nor LaboratoryReportService depends on the
     * other.
     */
    public function amend(AmendLaboratoryResultRequest $request, LaboratoryResult $result, LaboratoryResultService $service, LaboratoryReportService $reportService): RedirectResponse
    {
        $amendVerifiedStatuses = [LaboratoryResult::STATUS_VERIFIED, LaboratoryResult::STATUS_AUTHORIZED, LaboratoryResult::STATUS_RELEASED];
        $ability = in_array($result->status, $amendVerifiedStatuses, true) ? 'amendVerified' : 'amend';

        $this->authorize($ability, $result);

        $wasReleased = $result->status === LaboratoryResult::STATUS_RELEASED;

        $result = $service->amend($result, $request->validated(), $request);

        if ($wasReleased) {
            $reportService->supersedeActiveForOrder($result->item->laboratoryOrder, $request);
        }

        return back()->with('success', 'Result amended.');
    }

    public function startReview(StartReviewLaboratoryResultRequest $request, LaboratoryResult $result, LaboratoryResultService $service): RedirectResponse
    {
        $this->authorize('review', $result);

        $service->startReview($result, $request);

        return back()->with('success', 'Review started.');
    }

    public function verify(VerifyLaboratoryResultRequest $request, LaboratoryResult $result, LaboratoryResultService $service): RedirectResponse
    {
        $this->authorize('verify', $result);

        $service->verifyResult($result, $request->validated(), $request);

        return back()->with('success', 'Result verified.');
    }

    public function authorizeResult(AuthorizeLaboratoryResultRequest $request, LaboratoryResult $result, LaboratoryResultService $service): RedirectResponse
    {
        $this->authorize('authorize', $result);

        $service->authorizeResult($result, $request);

        return back()->with('success', 'Result authorized — ready for release.');
    }

    public function returnForCorrection(ReturnForCorrectionRequest $request, LaboratoryResult $result, LaboratoryResultService $service): RedirectResponse
    {
        $this->authorize('returnForCorrection', $result);

        $service->returnForCorrection($result, $request->validated(), $request);

        return back()->with('success', 'Result returned for correction.');
    }

    /**
     * Release an authorized result and (re)generate its order's report in
     * the same request (spec Part 6) — two independently-owned services
     * orchestrated together here, exactly mirroring
     * LaboratoryProcessingController::complete()'s own pairing with
     * LaboratoryResultService::initializeForItem() in Step 33.
     */
    public function release(ReleaseLaboratoryResultRequest $request, LaboratoryResult $result, LaboratoryResultService $service, LaboratoryReportService $reportService): RedirectResponse
    {
        $this->authorize('release', $result);

        $result = $service->release($result, $request->validated(), $request);
        $reportService->generateForOrder($result->item->laboratoryOrder, $request);

        return back()->with('success', 'Result released.');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<LaboratoryResult>
     */
    protected function baseQuery(array $filters): Builder
    {
        $query = LaboratoryResult::query();

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->whereHas('sample', fn ($q) => $q->where('sample_number', 'like', "%{$search}%"))
                    ->orWhereHas('item', function ($q) use ($search) {
                        $q->where('test_code', 'like', "%{$search}%")
                            ->orWhere('test_name', 'like', "%{$search}%")
                            ->orWhereHas('laboratoryOrder', fn ($q) => $q->where('order_number', 'like', "%{$search}%"));
                    })
                    ->orWhereHas('item.laboratoryOrder.clinicalEncounter.patient', function ($q) use ($search) {
                        $q->where('patient_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['priority'] ?? null)) {
            $query->whereHas('item.laboratoryOrder', fn ($q) => $q->where('priority', $filters['priority']));
        }

        if (filled($filters['specimen_type'] ?? null)) {
            $query->whereHas('sample', fn ($q) => $q->where('specimen_type', $filters['specimen_type']));
        }

        if (($filters['abnormal'] ?? null) === 'critical') {
            $query->where('abnormal_flag', LaboratoryResult::FLAG_CRITICAL);
        } elseif (($filters['abnormal'] ?? null) === 'abnormal') {
            $query->whereNotNull('abnormal_flag')->where('abnormal_flag', '!=', LaboratoryResult::FLAG_NORMAL);
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * Real status-distribution counts across every result (never
     * fabricated).
     *
     * @param  Builder<LaboratoryResult>  $query
     * @return Collection<string, int>
     */
    protected function statusCounts(Builder $query): Collection
    {
        return $query->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->map(fn ($count) => (int) $count);
    }

    /**
     * Restrict a query to results booked under the given hospital's
     * departments. Used to enforce hospital-admin scoping — never trust a
     * client-supplied hospital_id, only the authenticated user's own.
     *
     * @param  Builder<LaboratoryResult>  $query
     */
    protected function scopeToHospital(Builder $query, ?int $hospitalId): void
    {
        if ($hospitalId === null) {
            return;
        }

        $query->whereHas('item.laboratoryOrder.clinicalEncounter.department', fn ($q) => $q->where('hospital_id', $hospitalId));
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
