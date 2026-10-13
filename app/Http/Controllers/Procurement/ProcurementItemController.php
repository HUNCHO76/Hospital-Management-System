<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\ProcurementItemRequest;
use App\Models\Medication;
use App\Models\ProcurementItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProcurementItemController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('procurement-items.view'), 403);

        $category = $request->string('category')->toString();
        $status = $request->string('status')->toString();
        $search = $request->string('search')->toString();

        $query = ProcurementItem::query();

        if ($category) {
            $query->where('category', $category);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('specification', 'like', "%{$search}%");
            });
        }

        $items = $query->orderBy('name')->paginate(15)->withQueryString();

        return Inertia::render('procurement/items/index', [
            'items' => $items,
            'categories' => ProcurementItem::CATEGORIES,
            'filters' => [
                'category' => $category,
                'status' => $status,
                'search' => $search,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('procurement-items.create'), 403);

        $medications = Medication::where('status', 'active')->get(['id', 'generic_name', 'brand_name', 'form']);

        return Inertia::render('procurement/items/create', [
            'categories' => ProcurementItem::CATEGORIES,
            'medications' => $medications,
        ]);
    }

    public function store(ProcurementItemRequest $request): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-items.create'), 403);

        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        $item = ProcurementItem::create($data);

        return redirect()->route('procurement.items.index')
            ->with('success', 'Procurement catalog item created successfully.');
    }

    public function edit(ProcurementItem $item, Request $request): Response
    {
        abort_unless($request->user()->can('procurement-items.update'), 403);

        $medications = Medication::where('status', 'active')->get(['id', 'generic_name', 'brand_name', 'form']);

        return Inertia::render('procurement/items/edit', [
            'item' => $item,
            'categories' => ProcurementItem::CATEGORIES,
            'medications' => $medications,
        ]);
    }

    public function update(ProcurementItem $item, ProcurementItemRequest $request): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-items.update'), 403);

        $data = $request->validated();
        $data['updated_by'] = $request->user()->id;

        $item->update($data);

        return redirect()->route('procurement.items.index')
            ->with('success', 'Procurement catalog item updated successfully.');
    }
}
