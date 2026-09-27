<?php

namespace App\Http\Controllers;

use App\Models\Hospital;
use App\Models\NhifPriceSyncLog;
use App\Services\NHIF\NhifCatalogSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NhifPriceSyncController extends Controller
{
    public function index(Request $request): Response
    {
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

        return Inertia::render('nhif/price-sync/index', [
            'logs' => $logs,
            'hospitals' => Hospital::orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function store(Request $request, NhifCatalogSyncService $syncService): RedirectResponse
    {
        $data = $request->validate([
            'sync_type' => ['required', 'in:items,packages'],
            'hospital_id' => ['nullable', 'integer', 'exists:hospitals,id'],
        ]);

        $hospital = isset($data['hospital_id'])
            ? Hospital::findOrFail($data['hospital_id'])
            : null;

        if ($data['sync_type'] === 'items') {
            $log = $syncService->syncServices($hospital);
        } else {
            $log = $syncService->syncPackages();
        }

        $message = $log->isCompleted()
            ? "Sync completed: {$log->new_records} new, {$log->updated_records} updated."
            : 'Sync failed: '.($log->error_message ?? 'Unknown error.');

        return back()->with($log->isCompleted() ? 'success' : 'error', $message);
    }
}
