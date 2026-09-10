<?php

namespace App\Http\Controllers;

use App\Http\Requests\LaboratoryProcessing\AssignProcessingRequest;
use App\Http\Requests\LaboratoryProcessing\CompleteProcessingRequest;
use App\Http\Requests\LaboratoryProcessing\StartProcessingRequest;
use App\Http\Requests\LaboratoryProcessing\TransferProcessingRequest;
use App\Http\Requests\LaboratorySample\RejectLaboratorySampleRequest;
use App\Http\Resources\LaboratorySampleResource;
use App\Models\LaboratoryBench;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryProcessing;
use App\Models\LaboratorySample;
use App\Models\LaboratoryTest;
use App\Models\User;
use App\Services\LaboratoryProcessingService;
use App\Services\LaboratoryResultService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The bench-side laboratory processing workflow (Phase 4, Step 32) — its
 * own standalone surface under `laboratory/processing`, parallel to
 * `laboratory/collection` (Step 31). Lists one row per specimen (spec
 * §32's own example table), since accept/reject are whole-specimen
 * decisions; the detail page breaks a specimen down into its own
 * per-test LaboratoryProcessing records for assign/start/complete/transfer.
 */
class LaboratoryProcessingController extends Controller
{
    protected function sampleEagerLoads(): array
    {
        return [
            // The worklist table renders each specimen's own tests
            // (LaboratorySampleResource's `items`) directly, independently
            // of its per-test processing records below.
            'items',
            'processingRecords.bench:id,name,code',
            'processingRecords.assignedTo:id,UserName',
            'processingRecords.assignedBy:id,UserName',
            'processingRecords.acceptedBy:id,UserName',
            'processingRecords.startedBy:id,UserName',
            'processingRecords.completedBy:id,UserName',
            'processingRecords.item:id,laboratory_order_id,test_code,test_name',
            'rejectedBy:id,UserName',
            'patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender',
            'laboratoryOrder:id,order_number,priority',
        ];
    }

    /**
     * The processing dashboard/worklist: every specimen that has ever
     * entered processing (spec §8/§31) — real status-distribution counts
     * plus a filterable, paginated table. A specimen drops off the
     * "actionable" filters once every one of its tests has reached
     * RESULT_PENDING or REJECTED, but always remains visible/searchable
     * here for traceability.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LaboratoryProcessing::class);

        $filters = $request->only(['search', 'status', 'priority', 'specimen_type', 'assigned', 'bench_id', 'date_from', 'date_to']);

        $query = $this->baseQuery($filters);
        $this->scopeToHospital($query, $this->scopedHospitalId($request));

        $statusCounts = $this->statusCounts(clone $query);

        $samples = (clone $query)->with($this->sampleEagerLoads())
            ->latest('received_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (LaboratorySample $sample) => (new LaboratorySampleResource($sample))->resolve($request));

        return Inertia::render('laboratory/processing/index', [
            'samples' => $samples,
            'filters' => $filters,
            'statusCounts' => $statusCounts,
            'statuses' => LaboratoryProcessing::STATUSES,
            'priorities' => LaboratoryOrder::PRIORITIES,
            'specimenTypes' => LaboratoryTest::SPECIMEN_TYPES,
            'benches' => LaboratoryBench::active()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    /**
     * The processing detail for a single specimen: every test it carries,
     * each with its own processing record, actions, and timeline (spec
     * §33).
     */
    public function show(Request $request, LaboratorySample $sample): Response
    {
        $this->authorize('viewSample', [LaboratoryProcessing::class, $sample]);

        $sample->load($this->sampleEagerLoads());

        return Inertia::render('laboratory/processing/show', [
            'sample' => (new LaboratorySampleResource($sample))->resolve($request),
            'benches' => LaboratoryBench::active()->orderBy('name')->get(['id', 'name', 'code']),
            'technicians' => User::role('laboratory-technician')->orderBy('UserName')->get(['id', 'UserName'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->UserName]),

        ]);
    }

    public function accept(Request $request, LaboratorySample $sample, LaboratoryProcessingService $service): RedirectResponse
    {
        $this->authorize('accept', [LaboratoryProcessing::class, $sample]);

        $service->acceptSample($sample, $request);

        return back()->with('success', 'Specimen accepted for processing.');
    }

    public function reject(RejectLaboratorySampleRequest $request, LaboratorySample $sample, LaboratoryProcessingService $service): RedirectResponse
    {
        $this->authorize('reject', [LaboratoryProcessing::class, $sample]);

        $service->rejectSample($sample, $request->validated(), $request);

        return back()->with('success', 'Specimen rejected during processing.');
    }

    public function assign(AssignProcessingRequest $request, LaboratoryProcessing $processing, LaboratoryProcessingService $service): RedirectResponse
    {
        $this->authorize('assign', $processing);

        $service->assign($processing, $request->validated(), $request);

        return back()->with('success', 'Test assigned.');
    }

    public function start(StartProcessingRequest $request, LaboratoryProcessing $processing, LaboratoryProcessingService $service): RedirectResponse
    {
        $this->authorize('start', $processing);

        $service->start($processing, $request->validated(), $request);

        return back()->with('success', 'Processing started.');
    }

    public function complete(CompleteProcessingRequest $request, LaboratoryProcessing $processing, LaboratoryProcessingService $service, LaboratoryResultService $results): RedirectResponse
    {
        $this->authorize('complete', $processing);

        $processing = $service->complete($processing, $request->validated(), $request);
        $results->initializeForItem($processing->item, $processing->sample, $request);

        return back()->with('success', 'Processing completed — result pending.');
    }

    public function transfer(TransferProcessingRequest $request, LaboratoryProcessing $processing, LaboratoryProcessingService $service): RedirectResponse
    {
        $this->authorize('transfer', $processing);

        $service->transfer($processing, $request->validated(), $request);

        return back()->with('success', 'Test transferred to another bench.');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<LaboratorySample>
     */
    protected function baseQuery(array $filters): Builder
    {
        $query = LaboratorySample::query()->whereHas('processingRecords');

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('sample_number', 'like', "%{$search}%")
                    ->orWhereHas('laboratoryOrder', fn ($q) => $q->where('order_number', 'like', "%{$search}%"))
                    ->orWhereHas('patient', function ($q) use ($search) {
                        $q->where('patient_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        if (filled($filters['status'] ?? null)) {
            $query->whereHas('processingRecords', fn ($q) => $q->where('status', $filters['status']));
        }

        if (filled($filters['priority'] ?? null)) {
            $query->whereHas('laboratoryOrder', fn ($q) => $q->where('priority', $filters['priority']));
        }

        if (filled($filters['specimen_type'] ?? null)) {
            $query->where('specimen_type', $filters['specimen_type']);
        }

        if (filled($filters['bench_id'] ?? null)) {
            $query->whereHas('processingRecords', fn ($q) => $q->where('bench_id', $filters['bench_id']));
        }

        if (($filters['assigned'] ?? null) === 'unassigned') {
            $query->whereHas('processingRecords', fn ($q) => $q->whereNull('assigned_to')->whereNotIn('status', [LaboratoryProcessing::STATUS_RESULT_PENDING, LaboratoryProcessing::STATUS_REJECTED]));
        } elseif (($filters['assigned'] ?? null) === 'assigned') {
            $query->whereHas('processingRecords', fn ($q) => $q->whereNotNull('assigned_to'));
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->where('received_at', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->where('received_at', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * Real status-distribution counts across every processing record
     * (never fabricated) — counted at the record level (one per test),
     * matching the dashboard's own "how many tests are in each stage"
     * intent rather than the coarser per-specimen rollup.
     *
     * @param  Builder<LaboratorySample>  $query
     * @return Collection<string, int>
     */
    protected function statusCounts(Builder $query): Collection
    {
        $sampleIds = $query->pluck('id');

        return LaboratoryProcessing::query()
            ->whereIn('laboratory_sample_id', $sampleIds)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->map(fn ($count) => (int) $count);
    }

    /**
     * Restrict a query to specimens booked under the given hospital's
     * departments. Used to enforce hospital-admin scoping — never trust a
     * client-supplied hospital_id, only the authenticated user's own.
     *
     * @param  Builder<LaboratorySample>  $query
     */
    protected function scopeToHospital(Builder $query, ?int $hospitalId): void
    {
        if ($hospitalId === null) {
            return;
        }

        $query->whereHas('clinicalEncounter.department', fn ($q) => $q->where('hospital_id', $hospitalId));
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
