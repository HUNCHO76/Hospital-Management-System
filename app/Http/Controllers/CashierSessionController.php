<?php

namespace App\Http\Controllers;

use App\Http\Requests\Billing\CloseCashierSessionRequest;
use App\Http\Requests\Billing\OpenCashierSessionRequest;
use App\Http\Resources\CashierSessionResource;
use App\Models\CashierSession;
use App\Models\Hospital;
use App\Models\Payment;
use App\Services\CashierSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cashier session lifecycle (Phase 7, Step 59) — kept thin: every write
 * lives in CashierSessionService, every authorization check in
 * CashierSessionPolicy, mirroring every other billing controller.
 */
class CashierSessionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CashierSession::class);

        $query = CashierSession::query()
            ->with(['hospital', 'cashier'])
            ->orderByDesc('opened_at');

        if ($request->user()->hasRole('hospital-admin')) {
            $query->where('hospital_id', $request->user()->staff?->hospital_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $sessions = $query->paginate(20)->withQueryString()
            ->through(fn (CashierSession $session) => (new CashierSessionResource($session))->resolve($request));

        $openSession = CashierSession::query()
            ->where('cashier_id', $request->user()->id)
            ->where('status', CashierSession::STATUS_OPEN)
            ->first();

        return Inertia::render('billing/cashier/index', [
            'sessions' => $sessions,
            'filters' => $request->only(['status']),
            'statuses' => CashierSession::STATUSES,
            'openSession' => $openSession
                ? (new CashierSessionResource($openSession->load(['hospital', 'cashier'])))->resolve($request)
                : null,
            'canOpen' => $request->user()->can('billing-cashier.open'),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('billing-cashier.open'), 403);

        $hospitals = collect();

        if ($request->user()->hasRole('hospital-admin')) {
            $hospitalId = $request->user()->staff?->hospital_id;
            if ($hospitalId) {
                $hospitals = Hospital::query()
                    ->where('id', $hospitalId)
                    ->get(['id', 'name']);
            }
        } else {
            $hospitals = Hospital::query()->get(['id', 'name']);
        }

        return Inertia::render('billing/cashier/create', [
            'hospitals' => $hospitals->map(fn ($h) => ['id' => $h->id, 'name' => $h->name])->values(),
        ]);
    }

    public function store(OpenCashierSessionRequest $request, CashierSessionService $service): RedirectResponse
    {
        $data = $request->validated();

        $this->authorize('open', [CashierSession::class, (int) $data['hospital_id']]);

        $session = $service->open($data, $request);

        return to_route('billing.cashier.show', $session)->with('success', 'Session opened.');
    }

    public function show(Request $request, CashierSession $cashierSession, CashierSessionService $service): Response
    {
        $this->authorize('view', $cashierSession);

        $cashierSession->load(['hospital', 'cashier']);

        $summary = $service->getCollectionSummary($cashierSession);

        return Inertia::render('billing/cashier/show', [
            'session' => (new CashierSessionResource($cashierSession))->resolve($request),
            'summary' => $summary,
            'canClose' => $request->user()->can('close', $cashierSession),
            'paymentMethods' => Payment::METHODS,
        ]);
    }

    public function close(CloseCashierSessionRequest $request, CashierSession $cashierSession, CashierSessionService $service): RedirectResponse
    {
        $this->authorize('close', $cashierSession);

        $service->close($cashierSession, $request->validated(), $request);

        return to_route('billing.cashier.show', $cashierSession)->with('success', 'Session closed and reconciled.');
    }
}
