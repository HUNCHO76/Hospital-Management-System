<?php

namespace App\Services\Procurement;

use App\Models\ApprovalHistory;
use App\Models\GoodsReceiptNote;
use App\Models\PurchaseOrder;
use App\Models\VendorInvoice;
use App\Models\VendorInvoiceItem;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VendorInvoiceMatchingService
{
    public function __construct(protected AuditLogService $auditLog) {}

    public function generateSystemInvoiceNumber(): string
    {
        $prefix = 'VINV-'.date('Ym').'-';
        $latest = VendorInvoice::where('system_invoice_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->value('system_invoice_number');

        if ($latest) {
            $seq = (int) substr($latest, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function create(array $data, Request $request): VendorInvoice
    {
        return DB::transaction(function () use ($data, $request) {
            $user = $request->user();
            $po = PurchaseOrder::with('items')->findOrFail($data['purchase_order_id']);
            $grn = isset($data['goods_receipt_note_id']) ? GoodsReceiptNote::with('items')->find($data['goods_receipt_note_id']) : null;

            // Check duplicate vendor invoice number
            $existing = VendorInvoice::where('hospital_id', $po->hospital_id)
                ->where('vendor_id', $po->vendor_id)
                ->where('invoice_number', $data['invoice_number'])
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'invoice_number' => "Invoice number '{$data['invoice_number']}' already exists for this vendor.",
                ]);
            }

            $systemInvoiceNumber = $this->generateSystemInvoiceNumber();
            $hasAnyDiscrepancy = false;
            $discrepancyReasons = [];
            $subtotal = 0;
            $itemsData = [];

            foreach ($data['items'] as $item) {
                $qty = (int) $item['quantity'];
                $price = (float) $item['unit_price'];
                $taxAmount = isset($item['tax_amount']) ? (float) $item['tax_amount'] : 0.0;
                $lineTotal = ($qty * $price) + $taxAmount;
                $subtotal += ($qty * $price);

                // Fetch matching PO item
                $poItem = $po->items->firstWhere('id', $item['purchase_order_item_id'] ?? null)
                    ?? $po->items->firstWhere('procurement_item_id', $item['procurement_item_id'] ?? null);

                // Fetch matching GRN item
                $grnItem = null;
                if ($grn) {
                    $grnItem = $grn->items->firstWhere('id', $item['goods_receipt_note_item_id'] ?? null)
                        ?? $grn->items->firstWhere('procurement_item_id', $item['procurement_item_id'] ?? null);
                }

                $matchedPoQty = $poItem ? $poItem->quantity_ordered : 0;
                $matchedPoPrice = $poItem ? (float) $poItem->unit_price : 0.0;
                $matchedGrnQty = $grnItem ? (int) $grnItem->quantity_accepted : ($poItem ? $poItem->quantity_received : 0);

                // Discrepancy checks:
                // 1. Quantity billed exceeds quantity accepted on GRN
                // 2. Unit price billed exceeds PO agreed unit price
                $itemDiscrepancy = false;
                $reasons = [];

                if ($qty > $matchedGrnQty) {
                    $itemDiscrepancy = true;
                    $reasons[] = "Quantity billed ({$qty}) exceeds GRN accepted quantity ({$matchedGrnQty})";
                }

                if (abs($price - $matchedPoPrice) > 0.01) {
                    $itemDiscrepancy = true;
                    $reasons[] = "Price billed ({$price}) differs from PO agreed price ({$matchedPoPrice})";
                }

                if ($itemDiscrepancy) {
                    $hasAnyDiscrepancy = true;
                    $discrepancyReasons[] = "Item {$item['item_name']}: ".implode('; ', $reasons);
                }

                $itemsData[] = [
                    'purchase_order_item_id' => $poItem?->id,
                    'goods_receipt_note_item_id' => $grnItem?->id,
                    'procurement_item_id' => $item['procurement_item_id'] ?? $poItem?->procurement_item_id,
                    'item_name' => $item['item_name'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'tax_amount' => $taxAmount,
                    'total_price' => $lineTotal,
                    'matched_po_qty' => $matchedPoQty,
                    'matched_grn_qty' => $matchedGrnQty,
                    'matched_po_price' => $matchedPoPrice,
                    'has_discrepancy' => $itemDiscrepancy,
                    'discrepancy_reason' => $itemDiscrepancy ? implode('; ', $reasons) : null,
                ];
            }

            $taxTotal = isset($data['tax_amount']) ? (float) $data['tax_amount'] : 0.0;
            $discountTotal = isset($data['discount_amount']) ? (float) $data['discount_amount'] : 0.0;
            $grandTotal = ($subtotal - $discountTotal) + $taxTotal;

            $matchStatus = $hasAnyDiscrepancy ? VendorInvoice::MATCH_STATUS_DISCREPANCY : VendorInvoice::MATCH_STATUS_MATCHED;
            $status = $hasAnyDiscrepancy ? VendorInvoice::STATUS_DISCREPANCY : VendorInvoice::STATUS_MATCHED;
            $discrepancyNotes = $hasAnyDiscrepancy ? implode("\n", $discrepancyReasons) : null;

            $invoice = VendorInvoice::create([
                'system_invoice_number' => $systemInvoiceNumber,
                'invoice_number' => $data['invoice_number'],
                'hospital_id' => $po->hospital_id,
                'vendor_id' => $po->vendor_id,
                'purchase_order_id' => $po->id,
                'goods_receipt_note_id' => $grn?->id,
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'subtotal' => $subtotal,
                'tax_amount' => $taxTotal,
                'discount_amount' => $discountTotal,
                'grand_total' => $grandTotal,
                'paid_amount' => 0,
                'match_status' => $matchStatus,
                'status' => $status,
                'discrepancy_notes' => $discrepancyNotes,
                'verified_by' => $user->id,
                'verified_at' => now(),
                'attachment_path' => $data['attachment_path'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($itemsData as $row) {
                VendorInvoiceItem::create(array_merge($row, [
                    'vendor_invoice_id' => $invoice->id,
                ]));
            }

            ApprovalHistory::create([
                'approvable_type' => VendorInvoice::class,
                'approvable_id' => $invoice->id,
                'action' => 'verified',
                'user_id' => $user->id,
                'decision' => $matchStatus,
                'comments' => $hasAnyDiscrepancy ? "3-Way Match discrepancy detected: {$discrepancyNotes}" : '3-Way Match successfully verified',
            ]);

            $this->auditLog->record('vendor_invoice_created', $invoice, null, $invoice->toArray(), $request);

            return $invoice->load('items.procurementItem', 'vendor', 'purchaseOrder', 'goodsReceiptNote');
        });
    }

    public function approveForPayment(VendorInvoice $invoice, ?string $resolutionNotes, Request $request): VendorInvoice
    {
        if ($invoice->match_status === VendorInvoice::MATCH_STATUS_DISCREPANCY && empty($resolutionNotes)) {
            throw ValidationException::withMessages([
                'resolution_notes' => 'Resolution notes are required when approving an invoice with matching discrepancies.',
            ]);
        }

        $user = $request->user();
        $oldStatus = $invoice->status;

        $invoice->update([
            'status' => VendorInvoice::STATUS_APPROVED,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'updated_by' => $user->id,
        ]);

        ApprovalHistory::create([
            'approvable_type' => VendorInvoice::class,
            'approvable_id' => $invoice->id,
            'action' => 'approved',
            'user_id' => $user->id,
            'decision' => 'approved',
            'comments' => $resolutionNotes ?? 'Invoice approved for payment',
        ]);

        $this->auditLog->record('vendor_invoice_approved', $invoice, ['status' => $oldStatus], ['status' => VendorInvoice::STATUS_APPROVED, 'resolution' => $resolutionNotes], $request);

        return $invoice->fresh()->load('items', 'vendor', 'purchaseOrder', 'approvedBy');
    }
}
