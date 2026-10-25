<?php

namespace App\Http\Controllers;

use App\Models\Hospital;
use App\Models\NhifPriceSyncLog;
use App\Services\NHIF\NhifCatalogSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NhifPriceSyncController extends Controller
{
    public function index(Request $request, NhifCatalogSyncService $syncService): Response
    {
        abort_unless($request->user()->can('nhif-price-sync.view'), 403);

        $logs = NhifPriceSyncLog::with('hospital')
            ->orderByDesc('started_at')
            ->paginate(20)
            ->through(fn (NhifPriceSyncLog $log) => [
                'id' => $log->id,
                'hospital' => $log->hospital ? ['id' => $log->hospital->id, 'name' => $log->hospital->name] : null,
                'sync_type' => $log->sync_type,
                'status' => $log->status,
                'total_records' => $log->total_records,
                'new_records' => $log->new_records,
                'updated_records' => $log->updated_records,
                'skipped_records' => $log->skipped_records,
                'error_message' => $log->error_message,
                'started_at' => $log->started_at?->toDateTimeString(),
                'completed_at' => $log->completed_at?->toDateTimeString(),
            ]);

        $catalogueReport = $syncService->getUnmatchedCatalogueReport();

        return Inertia::render('nhif/price-sync/index', [
            'logs' => $logs,
            'hospitals' => Hospital::orderBy('name')->get(['id', 'name', 'code']),
            'catalogueReport' => $catalogueReport,
        ]);
    }

    public function store(Request $request, NhifCatalogSyncService $syncService): RedirectResponse
    {
        abort_unless($request->user()->can('nhif-price-sync.run'), 403);

        $data = $request->validate([
            'sync_type' => ['required', 'in:items,packages,item_types,diseases'],
            'hospital_id' => ['nullable', 'integer', 'exists:hospitals,id'],
        ]);

        $hospital = isset($data['hospital_id'])
            ? Hospital::findOrFail($data['hospital_id'])
            : null;

        $log = match ($data['sync_type']) {
            'item_types' => $syncService->syncItemTypes(),
            'items' => $syncService->syncItems(),
            'diseases' => $syncService->syncDiseases(),
            'packages' => $syncService->syncPackages(),
        };

        $message = $log->isCompleted()
            ? "Sync completed: {$log->new_records} new, {$log->updated_records} updated, {$log->total_records} total."
            : 'Sync failed: '.($log->error_message ?? 'Unknown error.');

        return back()->with($log->isCompleted() ? 'success' : 'error', $message);
    }

    public function preview(Request $request, NhifCatalogSyncService $syncService): JsonResponse
    {
        abort_unless($request->user()->can('nhif-price-sync.view'), 403);

        $type = $request->query('type', 'items');

        if ($type === 'item_types') {
            return response()->json($syncService->previewItemTypesSync());
        }

        return response()->json($syncService->previewItemsSync());
    }

    public function unmatched(Request $request, NhifCatalogSyncService $syncService): JsonResponse
    {
        abort_unless($request->user()->can('nhif-price-sync.view'), 403);

        return response()->json($syncService->getUnmatchedCatalogueReport());
    }
}
