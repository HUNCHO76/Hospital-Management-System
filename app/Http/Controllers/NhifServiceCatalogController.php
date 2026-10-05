<?php

namespace App\Http\Controllers;

use App\Models\NhifPackage;
use App\Models\NhifSchemeService;
use App\Models\NhifService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NhifServiceCatalogController extends Controller
{
    public function services(Request $request): Response
    {
        $services = NhifService::query()
            ->when($request->search, fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('nhif_item_code', 'like', "%{$s}%")
                    ->orWhere('nhif_item_name', 'like', "%{$s}%")
                    ->orWhere('nhif_item_type_name', 'like', "%{$s}%");
            }))
            ->when($request->active_only, fn ($q) => $q->where('is_active', true))
            ->orderBy('nhif_item_name')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('nhif/catalog/services', [
            'services' => $services,
            'filters' => $request->only('search', 'active_only'),
            'lastSync' => NhifService::max('synced_at'),
        ]);
    }

    /**
     * Search the schemes_services catalog dump for NHIF item code autocomplete.
     */
    public function searchItemsJson(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $query = NhifSchemeService::catalog()
            ->where(function ($builder) use ($q) {
                $builder->where('item_name', 'like', "%{$q}%")
                    ->orWhere('item_code', 'like', "%{$q}%");
            });

        if ($request->filled('type_id')) {
            $query->where('item_type_id', (int) $request->query('type_id'));
        }

        $results = $query
            ->selectRaw('DISTINCT item_code, item_name, type_name')
            ->orderBy('item_name')
            ->limit(20)
            ->get();

        return response()->json($results);
    }

    public function packages(Request $request): Response
    {
        $packages = NhifPackage::query()
            ->when($request->search, fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('nhif_package_code', 'like', "%{$s}%")
                    ->orWhere('nhif_package_name', 'like', "%{$s}%")
                    ->orWhere('scheme_name', 'like', "%{$s}%");
            }))
            ->orderBy('nhif_package_name')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('nhif/catalog/packages', [
            'packages' => $packages,
            'filters' => $request->only('search'),
            'lastSync' => NhifPackage::max('synced_at'),
        ]);
    }
}
