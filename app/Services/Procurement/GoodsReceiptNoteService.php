<?php

namespace App\Services\Procurement;

use App\Models\ApprovalHistory;
use App\Models\GoodsReceiptNote;
use App\Models\GoodsReceiptNoteItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceiptNoteService
{
    public function __construct(protected AuditLogService $auditLog) {}

    public function generateGrnNumber(): string
    {
        $prefix = 'GRN-'.date('Ym').'-';
        $latest = GoodsReceiptNote::where('grn_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->value('grn_number');

        if ($latest) {
            $seq = (int) substr($latest, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function create(array $data, Request $request): GoodsReceiptNote
    {
        return DB::transaction(function () use ($data, $request) {
            $user = $request->user();
            $po = PurchaseOrder::with('items')->findOrFail($data['purchase_order_id']);

            if (! in_array($po->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_SENT_TO_VENDOR, PurchaseOrder::STATUS_PARTIALLY_RECEIVED], true)) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => "Cannot create GRN for PO in '{$po->status}' status. PO must be APPROVED or in delivery.",
                ]);
            }

            $grnNumber = $this->generateGrnNumber();

            $grn = GoodsReceiptNote::create([
                'grn_number' => $grnNumber,
                'purchase_order_id' => $po->id,
                'hospital_id' => $po->hospital_id,
                'vendor_id' => $po->vendor_id,
                'store_id' => $data['store_id'] ?? $po->receiving_store_id,
                'received_by' => $user->id,
                'received_date' => $data['received_date'] ?? now()->toDateString(),
                'delivery_note_number' => $data['delivery_note_number'] ?? null,
                'invoice_reference' => $data['invoice_reference'] ?? null,
                'status' => GoodsReceiptNote::STATUS_INSPECTION_PENDING,
                'remarks' => $data['remarks'] ?? null,
                'attachment_path' => $data['attachment_path'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($data['items'] as $item) {
                $poItem = $po->items->firstWhere('id', $item['purchase_order_item_id']);
                $qtyOrdered = $poItem ? $poItem->quantity_ordered : (int) $item['quantity_ordered'];
                $qtyDelivered = (int) $item['quantity_delivered'];
                $qtyShort = max(0, $qtyOrdered - $qtyDelivered);

                GoodsReceiptNoteItem::create([
                    'goods_receipt_note_id' => $grn->id,
                    'purchase_order_item_id' => $item['purchase_order_item_id'],
                    'procurement_item_id' => $item['procurement_item_id'],
                    'item_name' => $item['item_name'],
                    'unit_of_measure' => $item['unit_of_measure'] ?? 'piece',
                    'unit_cost' => (float) ($item['unit_cost'] ?? ($poItem ? $poItem->unit_price : 0)),
                    'quantity_ordered' => $qtyOrdered,
                    'quantity_delivered' => $qtyDelivered,
                    'quantity_accepted' => (int) ($item['quantity_accepted'] ?? $qtyDelivered),
                    'quantity_rejected' => (int) ($item['quantity_rejected'] ?? 0),
                    'quantity_short' => $qtyShort,
                    'batch_number' => $item['batch_number'] ?? null,
                    'manufacture_date' => $item['manufacture_date'] ?? null,
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'storage_location' => $item['storage_location'] ?? null,
                    'rejection_reason' => $item['rejection_reason'] ?? null,
                    'remarks' => $item['remarks'] ?? null,
                ]);
            }

            ApprovalHistory::create([
                'approvable_type' => GoodsReceiptNote::class,
                'approvable_id' => $grn->id,
                'action' => 'created',
                'user_id' => $user->id,
                'decision' => 'received',
                'comments' => 'Goods received, inspection pending',
            ]);

            $this->auditLog->record('grn_created', $grn, null, $grn->toArray(), $request);

            return $grn->load('items.procurementItem', 'purchaseOrder', 'vendor', 'store', 'receivedBy');
        });
    }

    public function inspect(GoodsReceiptNote $grn, array $inspectionResults, ?string $inspectionNotes, Request $request): GoodsReceiptNote
    {
        if (in_array($grn->status, [GoodsReceiptNote::STATUS_POSTED, GoodsReceiptNote::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot perform inspection on GRN with status '{$grn->status}'.",
            ]);
        }

        return DB::transaction(function () use ($grn, $inspectionResults, $inspectionNotes, $request) {
            $user = $request->user();

            $totalAccepted = 0;
            $totalRejected = 0;

            foreach ($inspectionResults as $result) {
                $item = GoodsReceiptNoteItem::where('goods_receipt_note_id', $grn->id)
                    ->where('id', $result['id'])
                    ->firstOrFail();

                $accepted = (int) $result['quantity_accepted'];
                $rejected = (int) $result['quantity_rejected'];

                if (($accepted + $rejected) > $item->quantity_delivered) {
                    throw ValidationException::withMessages([
                        'quantity' => "Accepted ({$accepted}) + Rejected ({$rejected}) exceeds Delivered quantity ({$item->quantity_delivered}) for {$item->item_name}.",
                    ]);
                }

                $item->update([
                    'quantity_accepted' => $accepted,
                    'quantity_rejected' => $rejected,
                    'rejection_reason' => $result['rejection_reason'] ?? null,
                    'batch_number' => $result['batch_number'] ?? $item->batch_number,
                    'expiry_date' => $result['expiry_date'] ?? $item->expiry_date,
                    'storage_location' => $result['storage_location'] ?? $item->storage_location,
                ]);

                $totalAccepted += $accepted;
                $totalRejected += $rejected;
            }

            $newStatus = GoodsReceiptNote::STATUS_ACCEPTED;
            if ($totalAccepted === 0 && $totalRejected > 0) {
                $newStatus = GoodsReceiptNote::STATUS_REJECTED;
            } elseif ($totalAccepted > 0 && $totalRejected > 0) {
                $newStatus = GoodsReceiptNote::STATUS_PARTIALLY_ACCEPTED;
            }

            $grn->update([
                'status' => $newStatus,
                'inspection_notes' => $inspectionNotes,
                'inspected_by' => $user->id,
                'inspection_date' => now(),
                'updated_by' => $user->id,
            ]);

            ApprovalHistory::create([
                'approvable_type' => GoodsReceiptNote::class,
                'approvable_id' => $grn->id,
                'action' => 'inspected',
                'user_id' => $user->id,
                'decision' => strtolower($newStatus),
                'comments' => $inspectionNotes ?? "Inspection completed: {$totalAccepted} accepted, {$totalRejected} rejected",
            ]);

            $this->auditLog->record('grn_inspected', $grn, null, ['status' => $newStatus, 'notes' => $inspectionNotes], $request);

            return $grn->fresh()->load('items.procurementItem', 'inspectedBy');
        });
    }

    public function post(GoodsReceiptNote $grn, Request $request): GoodsReceiptNote
    {
        if ($grn->status === GoodsReceiptNote::STATUS_POSTED) {
            throw ValidationException::withMessages([
                'status' => 'This GRN has already been posted to inventory.',
            ]);
        }

        if ($grn->status === GoodsReceiptNote::STATUS_REJECTED) {
            throw ValidationException::withMessages([
                'status' => 'Fully rejected GRN cannot be posted to inventory.',
            ]);
        }

        return DB::transaction(function () use ($grn, $request) {
            $user = $request->user();
            $grn->load(['items.procurementItem', 'purchaseOrder.items']);

            foreach ($grn->items as $grnItem) {
                $acceptedQty = (int) $grnItem->quantity_accepted;
                if ($acceptedQty <= 0) {
                    continue;
                }

                $procItem = $grnItem->procurementItem;
                $stockItem = null;

                // Medicine item linked to Medication
                if ($procItem && $procItem->medication_id) {
                    $stockItem = StockItem::firstOrCreate(
                        [
                            'hospital_id' => $grn->hospital_id,
                            'medication_id' => $procItem->medication_id,
                        ],
                        [
                            'store_id' => $grn->store_id,
                            'procurement_item_id' => $procItem->id,
                            'reorder_level' => $procItem->reorder_level ?? 20,
                            'maximum_level' => 500,
                            'status' => StockItem::STATUS_ACTIVE,
                            'created_by' => $user->id,
                        ]
                    );
                } else {
                    // Non-medication item (consumable, reagent, surgical, etc.)
                    $stockItem = StockItem::firstOrCreate(
                        [
                            'hospital_id' => $grn->hospital_id,
                            'procurement_item_id' => $procItem?->id,
                            'store_id' => $grn->store_id,
                        ],
                        [
                            'medication_id' => null,
                            'reorder_level' => $procItem?->reorder_level ?? 10,
                            'maximum_level' => 500,
                            'status' => StockItem::STATUS_ACTIVE,
                            'created_by' => $user->id,
                        ]
                    );
                }

                // Batch Number determination
                $batchNumber = $grnItem->batch_number ?: ('GRN-'.$grn->id.'-'.$grnItem->id);
                $expiryDate = $grnItem->expiry_date ?: now()->addYears(2)->toDateString();

                // Find or create StockBatch
                $batch = StockBatch::where('stock_item_id', $stockItem->id)
                    ->where('batch_number', $batchNumber)
                    ->lockForUpdate()
                    ->first();

                if ($batch) {
                    $balanceBefore = $batch->quantity;
                    $balanceAfter = $balanceBefore + $acceptedQty;
                    $batch->update([
                        'quantity' => $balanceAfter,
                        'unit_cost' => $grnItem->unit_cost ?: $batch->unit_cost,
                        'store_id' => $grn->store_id,
                        'vendor_id' => $grn->vendor_id,
                        'storage_location' => $grnItem->storage_location ?: $batch->storage_location,
                    ]);
                } else {
                    $balanceBefore = 0;
                    $balanceAfter = $acceptedQty;
                    $batch = StockBatch::create([
                        'stock_item_id' => $stockItem->id,
                        'store_id' => $grn->store_id,
                        'vendor_id' => $grn->vendor_id,
                        'storage_location' => $grnItem->storage_location,
                        'batch_number' => $batchNumber,
                        'manufacture_date' => $grnItem->manufacture_date,
                        'expiry_date' => $expiryDate,
                        'quantity' => $balanceAfter,
                        'unit_cost' => $grnItem->unit_cost,
                        'supplier_name' => $grn->vendor?->name,
                        'status' => StockBatch::STATUS_ACTIVE,
                        'created_by' => $user->id,
                    ]);
                }

                // Create StockTransaction
                StockTransaction::create([
                    'stock_item_id' => $stockItem->id,
                    'stock_batch_id' => $batch->id,
                    'transaction_type' => StockTransaction::TYPE_RECEIPT,
                    'quantity' => $acceptedQty,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'reason' => "Received via GRN {$grn->grn_number} for PO {$grn->purchaseOrder?->po_number}",
                    'performed_by' => $user->id,
                    'occurred_at' => now(),
                ]);

                // Link batch back to GRN item
                $grnItem->update(['stock_batch_id' => $batch->id]);

                // Update PO Item quantity received
                if ($grnItem->purchase_order_item_id) {
                    $poItem = PurchaseOrderItem::find($grnItem->purchase_order_item_id);
                    if ($poItem) {
                        $poItem->increment('quantity_received', $acceptedQty);
                    }
                }
            }

            // Update PO Status (Check if all items on PO are received)
            $po = $grn->purchaseOrder;
            if ($po) {
                $po->load('items');
                $allFullyReceived = true;
                foreach ($po->items as $item) {
                    if ($item->quantity_received < $item->quantity_ordered) {
                        $allFullyReceived = false;
                        break;
                    }
                }

                $po->update([
                    'status' => $allFullyReceived ? PurchaseOrder::STATUS_FULLY_RECEIVED : PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
                    'updated_by' => $user->id,
                ]);
            }

            // Mark GRN as POSTED
            $oldStatus = $grn->status;
            $grn->update([
                'status' => GoodsReceiptNote::STATUS_POSTED,
                'posted_by' => $user->id,
                'posted_at' => now(),
                'updated_by' => $user->id,
            ]);

            ApprovalHistory::create([
                'approvable_type' => GoodsReceiptNote::class,
                'approvable_id' => $grn->id,
                'action' => 'posted',
                'user_id' => $user->id,
                'decision' => 'posted',
                'comments' => 'Posted to store stock ledger atomically',
            ]);

            $this->auditLog->record('grn_posted', $grn, ['status' => $oldStatus], ['status' => GoodsReceiptNote::STATUS_POSTED], $request);

            return $grn->fresh()->load('items.stockBatch', 'postedBy', 'purchaseOrder');
        });
    }
}
