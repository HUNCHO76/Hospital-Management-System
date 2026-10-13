<?php

namespace App\Services\Procurement;

use App\Models\ApprovalHistory;
use App\Models\VendorQuotation;
use App\Models\VendorQuotationItem;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorQuotationService
{
    public function __construct(protected AuditLogService $auditLog) {}

    public function generateQuotationNumber(): string
    {
        $prefix = 'QUO-'.date('Ym').'-';
        $latest = VendorQuotation::where('quotation_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->value('quotation_number');

        if ($latest) {
            $seq = (int) substr($latest, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function create(array $data, Request $request): VendorQuotation
    {
        return DB::transaction(function () use ($data, $request) {
            $user = $request->user();
            $quotationNumber = $this->generateQuotationNumber();

            $subtotal = 0;
            $itemsData = [];

            foreach ($data['items'] as $item) {
                $qty = (int) $item['quantity'];
                $unitPrice = (float) $item['unit_price'];
                $discountRate = isset($item['discount_rate']) ? (float) $item['discount_rate'] : 0.0;
                $taxRate = isset($item['tax_rate']) ? (float) $item['tax_rate'] : 0.0;

                $lineBase = $qty * $unitPrice;
                $lineDiscount = $lineBase * ($discountRate / 100);
                $afterDiscount = $lineBase - $lineDiscount;
                $lineTax = $afterDiscount * ($taxRate / 100);
                $lineTotal = $afterDiscount + $lineTax;

                $subtotal += $lineBase;

                $itemsData[] = [
                    'procurement_item_id' => $item['procurement_item_id'],
                    'item_name' => $item['item_name'],
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'discount_rate' => $discountRate,
                    'tax_rate' => $taxRate,
                    'total_price' => $lineTotal,
                    'remarks' => $item['remarks'] ?? null,
                ];
            }

            $discountAmount = isset($data['discount_amount']) ? (float) $data['discount_amount'] : 0.0;
            $taxAmount = isset($data['tax_amount']) ? (float) $data['tax_amount'] : 0.0;
            $shippingCost = isset($data['shipping_cost']) ? (float) $data['shipping_cost'] : 0.0;
            $totalAmount = ($subtotal - $discountAmount) + $taxAmount + $shippingCost;

            $quotation = VendorQuotation::create([
                'quotation_number' => $quotationNumber,
                'hospital_id' => $data['hospital_id'] ?? $user->hospital_id,
                'indent_id' => $data['indent_id'] ?? null,
                'vendor_id' => $data['vendor_id'],
                'quotation_date' => $data['quotation_date'] ?? now()->toDateString(),
                'valid_until' => $data['valid_until'] ?? null,
                'lead_time_days' => $data['lead_time_days'] ?? null,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'shipping_cost' => $shippingCost,
                'total_amount' => $totalAmount,
                'payment_terms' => $data['payment_terms'] ?? null,
                'delivery_terms' => $data['delivery_terms'] ?? null,
                'status' => $data['status'] ?? VendorQuotation::STATUS_SUBMITTED,
                'attachment_path' => $data['attachment_path'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($itemsData as $row) {
                VendorQuotationItem::create(array_merge($row, [
                    'vendor_quotation_id' => $quotation->id,
                ]));
            }

            ApprovalHistory::create([
                'approvable_type' => VendorQuotation::class,
                'approvable_id' => $quotation->id,
                'action' => 'created',
                'user_id' => $user->id,
                'decision' => 'submitted',
                'comments' => 'Vendor quotation entered',
            ]);

            $this->auditLog->record('vendor_quotation_created', $quotation, null, $quotation->toArray(), $request);

            return $quotation->load('items.procurementItem', 'vendor', 'indent');
        });
    }

    public function getComparison(int $indentId): array
    {
        $quotations = VendorQuotation::with(['vendor', 'items.procurementItem'])
            ->where('indent_id', $indentId)
            ->get();

        if ($quotations->isEmpty()) {
            return [
                'indent_id' => $indentId,
                'quotations' => [],
                'items_comparison' => [],
            ];
        }

        // Aggregate per-item prices across all quotes
        $itemMap = [];
        foreach ($quotations as $quote) {
            foreach ($quote->items as $item) {
                $itemId = $item->procurement_item_id;
                if (! isset($itemMap[$itemId])) {
                    $itemMap[$itemId] = [
                        'procurement_item_id' => $itemId,
                        'item_name' => $item->item_name,
                        'quotes' => [],
                    ];
                }
                $itemMap[$itemId]['quotes'][$quote->id] = [
                    'vendor_id' => $quote->vendor_id,
                    'vendor_name' => $quote->vendor?->name,
                    'unit_price' => (float) $item->unit_price,
                    'total_price' => (float) $item->total_price,
                    'quantity' => $item->quantity,
                ];
            }
        }

        // Find lowest price per item
        foreach ($itemMap as &$itemSummary) {
            $minPrice = null;
            $bestQuoteId = null;
            foreach ($itemSummary['quotes'] as $qId => $qData) {
                if ($minPrice === null || $qData['unit_price'] < $minPrice) {
                    $minPrice = $qData['unit_price'];
                    $bestQuoteId = $qId;
                }
            }
            $itemSummary['lowest_unit_price'] = $minPrice;
            $itemSummary['best_quotation_id'] = $bestQuoteId;
        }

        return [
            'indent_id' => $indentId,
            'quotations' => $quotations,
            'items_comparison' => array_values($itemMap),
        ];
    }

    public function selectQuotation(VendorQuotation $quotation, string $justification, Request $request): VendorQuotation
    {
        return DB::transaction(function () use ($quotation, $justification, $request) {
            $user = $request->user();

            // Mark this quotation as selected
            $quotation->update([
                'status' => VendorQuotation::STATUS_SELECTED,
                'selection_justification' => $justification,
                'selected_by' => $user->id,
                'selected_at' => now(),
                'updated_by' => $user->id,
            ]);

            ApprovalHistory::create([
                'approvable_type' => VendorQuotation::class,
                'approvable_id' => $quotation->id,
                'action' => 'selected',
                'user_id' => $user->id,
                'decision' => 'selected',
                'comments' => $justification,
            ]);

            // If linked to indent, mark other quotes for this indent as rejected
            if ($quotation->indent_id) {
                VendorQuotation::where('indent_id', $quotation->indent_id)
                    ->where('id', '!=', $quotation->id)
                    ->whereIn('status', [VendorQuotation::STATUS_DRAFT, VendorQuotation::STATUS_SUBMITTED])
                    ->update([
                        'status' => VendorQuotation::STATUS_REJECTED,
                        'selection_justification' => 'Another quotation was selected: '.$quotation->quotation_number,
                        'updated_by' => $user->id,
                    ]);
            }

            $this->auditLog->record('vendor_quotation_selected', $quotation, null, [
                'status' => VendorQuotation::STATUS_SELECTED,
                'justification' => $justification,
            ], $request);

            return $quotation->fresh()->load('vendor', 'indent', 'items');
        });
    }
}
