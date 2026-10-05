<?php

namespace App\Http\Controllers;

use App\Models\NhifPackagePrice;
use App\Models\NhifPriceSyncLog;
use App\Services\NHIF\NhifCatalogSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NhifPackagePriceController extends Controller
{
    /**
     * Display the package price catalog with the latest sync history.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', NhifPackagePrice::class);

        $schemeId = $request->integer('scheme_id') ?: null;

        $prices = NhifPackagePrice::query()
            ->when($schemeId, fn ($q) => $q->where('scheme_id', $schemeId))
            ->when($request->input('search'), function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('item_code', 'like', "%{$search}%")
                        ->orWhere('item_name', 'like', "%{$search}%");
                });
            })
            ->orderBy('item_code')
            ->paginate(50)
            ->withQueryString();

        $latestSync = NhifPriceSyncLog::query()
            ->where('sync_type', 'package_prices')
            ->latest('started_at')
            ->first(['id', 'status', 'total_records', 'new_records', 'updated_records', 'started_at', 'completed_at', 'error_message']);

        return Inertia::render('nhif/package-prices/index', [
            'prices' => $prices,
            'latestSync' => $latestSync,
            'filters' => $request->only(['scheme_id', 'search']),
        ]);
    }

    /**
     * Trigger a package price synchronization from NHIF GetPricePackage.
     */
    public function sync(Request $request, NhifCatalogSyncService $syncService): RedirectResponse
    {
        $this->authorize('sync', NhifPackagePrice::class);

        $schemeId = $request->integer('scheme_id') ?: null;
        $packageId = $request->integer('package_id') ?: null;

        $log = $syncService->syncPackagePrices($schemeId, $packageId);

        if ($log->status === 'failed') {
            return back()->with('error', "Package price sync failed: {$log->error_message}");
        }

        return back()->with('success', sprintf(
            'Package prices synced: %d total, %d new, %d updated.',
            $log->total_records ?? 0,
            $log->new_records ?? 0,
            $log->updated_records ?? 0,
        ));
    }
}
