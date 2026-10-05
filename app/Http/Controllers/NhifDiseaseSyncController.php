<?php

namespace App\Http\Controllers;

use App\Models\NhifDisease;
use App\Models\NhifPriceSyncLog;
use App\Services\NHIF\NhifCatalogSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NhifDiseaseSyncController extends Controller
{
    public function index(Request $request): Response
    {
        $logs = NhifPriceSyncLog::where('sync_type', 'diseases')
            ->orderByDesc('started_at')
            ->paginate(20)
            ->through(fn (NhifPriceSyncLog $log) => [
                'id' => $log->id,
                'status' => $log->status,
                'total_records' => $log->total_records,
                'new_records' => $log->new_records,
                'updated_records' => $log->updated_records,
                'skipped_records' => $log->skipped_records,
                'error_message' => $log->error_message,
                'started_at' => $log->started_at?->toDateTimeString(),
                'completed_at' => $log->completed_at?->toDateTimeString(),
            ]);

        $diseaseCount = NhifDisease::count();
        $lastSyncedAt = NhifDisease::max('synced_at');

        return Inertia::render('nhif/disease-sync/index', [
            'logs' => $logs,
            'diseaseCount' => $diseaseCount,
            'lastSyncedAt' => $lastSyncedAt,
        ]);
    }

    public function store(Request $request, NhifCatalogSyncService $syncService): RedirectResponse
    {
        $log = $syncService->syncDiseases();

        $message = $log->isCompleted()
            ? "Sync completed: {$log->new_records} new, {$log->updated_records} updated out of {$log->total_records} diseases."
            : 'Sync failed: '.($log->error_message ?? 'Unknown error.');

        return back()->with($log->isCompleted() ? 'success' : 'error', $message);
    }
}
