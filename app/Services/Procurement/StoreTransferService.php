<?php

namespace App\Services\Procurement;

use App\Models\ApprovalHistory;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Models\StoreTransfer;
use App\Models\StoreTransferItem;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreTransferService
{
    public function __construct(protected AuditLogService $auditLog) {}

    public function generateTransferNumber(): string
    {
        $prefix = 'TRN-'.date('Ym').'-';
        $latest = StoreTransfer::where('transfer_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->value('transfer_number');

        if ($latest) {
            $seq = (int) substr($latest, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function create(array $data, Request $request): StoreTransfer
    {
        return DB::transaction(function () use ($data, $request) {
            $user = $request->user();
            $transferNumber = $this->generateTransferNumber();

            if ($data['from_store_id'] === $data['to_store_id']) {
                throw ValidationException::withMessages([
                    'to_store_id' => 'Destination store must be different from source store.',
                ]);
            }

            $transfer = StoreTransfer::create([
                'transfer_number' => $transferNumber,
                'hospital_id' => $data['hospital_id'] ?? $user->hospital_id,
                'from_store_id' => $data['from_store_id'],
                'to_store_id' => $data['to_store_id'],
                'requested_by' => $user->id,
                'status' => StoreTransfer::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $batch = StockBatch::findOrFail($item['from_stock_batch_id']);
                $qty = (int) $item['quantity_transferred'];

                if ($batch->quantity < $qty) {
                    throw ValidationException::withMessages([
                        'quantity' => "Insufficient stock in batch {$batch->batch_number}. Available: {$batch->quantity}, Requested: {$qty}",
                    ]);
                }

                StoreTransferItem::create([
                    'store_transfer_id' => $transfer->id,
                    'procurement_item_id' => $item['procurement_item_id'],
                    'from_stock_batch_id' => $batch->id,
                    'item_name' => $item['item_name'],
                    'batch_number' => $batch->batch_number,
                    'expiry_date' => $batch->expiry_date,
                    'quantity_transferred' => $qty,
                    'remarks' => $item['remarks'] ?? null,
                ]);
            }

            ApprovalHistory::create([
                'approvable_type' => StoreTransfer::class,
                'approvable_id' => $transfer->id,
                'action' => 'created',
                'user_id' => $user->id,
                'decision' => 'draft',
                'comments' => 'Store transfer drafted',
            ]);

            $this->auditLog->record('store_transfer_created', $transfer, null, $transfer->toArray(), $request);

            return $transfer->load('items.procurementItem', 'fromStore', 'toStore', 'requestedBy');
        });
    }

    public function submit(StoreTransfer $transfer, Request $request): StoreTransfer
    {
        if ($transfer->status !== StoreTransfer::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Only draft transfers can be submitted.',
            ]);
        }

        $transfer->update(['status' => StoreTransfer::STATUS_SUBMITTED]);

        ApprovalHistory::create([
            'approvable_type' => StoreTransfer::class,
            'approvable_id' => $transfer->id,
            'action' => 'submitted',
            'user_id' => $request->user()->id,
            'decision' => 'submitted',
            'comments' => 'Store transfer submitted for approval',
        ]);

        return $transfer->fresh();
    }

    public function dispatch(StoreTransfer $transfer, Request $request): StoreTransfer
    {
        if (! in_array($transfer->status, [StoreTransfer::STATUS_SUBMITTED, StoreTransfer::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Transfer must be submitted or approved to be dispatched.',
            ]);
        }

        return DB::transaction(function () use ($transfer, $request) {
            $user = $request->user();
            $transfer->load('items.fromStockBatch');

            foreach ($transfer->items as $item) {
                $batch = StockBatch::lockForUpdate()->find($item->from_stock_batch_id);
                $qty = $item->quantity_transferred;

                if ($batch->quantity < $qty) {
                    throw ValidationException::withMessages([
                        'quantity' => "Insufficient stock in batch {$batch->batch_number} at dispatch time. Available: {$batch->quantity}, Needed: {$qty}",
                    ]);
                }

                $before = $batch->quantity;
                $after = $before - $qty;
                $batch->update(['quantity' => $after]);

                StockTransaction::create([
                    'stock_item_id' => $batch->stock_item_id,
                    'stock_batch_id' => $batch->id,
                    'transaction_type' => StockTransaction::TYPE_ADJUSTMENT_OUT,
                    'quantity' => -$qty,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'reason' => "Transfer dispatch {$transfer->transfer_number} to {$transfer->toStore?->name}",
                    'performed_by' => $user->id,
                    'occurred_at' => now(),
                ]);
            }

            $transfer->update([
                'status' => StoreTransfer::STATUS_IN_TRANSIT,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            ApprovalHistory::create([
                'approvable_type' => StoreTransfer::class,
                'approvable_id' => $transfer->id,
                'action' => 'dispatched',
                'user_id' => $user->id,
                'decision' => 'in_transit',
                'comments' => 'Supplies dispatched and in transit',
            ]);

            return $transfer->fresh();
        });
    }

    public function receive(StoreTransfer $transfer, Request $request): StoreTransfer
    {
        if ($transfer->status !== StoreTransfer::STATUS_IN_TRANSIT) {
            throw ValidationException::withMessages([
                'status' => 'Only in-transit transfers can be received.',
            ]);
        }

        return DB::transaction(function () use ($transfer, $request) {
            $user = $request->user();
            $transfer->load(['items.fromStockBatch.stockItem', 'items.procurementItem']);

            foreach ($transfer->items as $item) {
                $sourceBatch = $item->fromStockBatch;
                $sourceStockItem = $sourceBatch->stockItem;
                $qty = $item->quantity_transferred;

                // Locate or create StockItem at destination store
                $destStockItem = null;
                if ($sourceStockItem->medication_id) {
                    $destStockItem = StockItem::firstOrCreate(
                        [
                            'hospital_id' => $transfer->hospital_id,
                            'medication_id' => $sourceStockItem->medication_id,
                        ],
                        [
                            'store_id' => $transfer->to_store_id,
                            'procurement_item_id' => $sourceStockItem->procurement_item_id,
                            'reorder_level' => $sourceStockItem->reorder_level,
                            'maximum_level' => $sourceStockItem->maximum_level,
                            'status' => StockItem::STATUS_ACTIVE,
                            'created_by' => $user->id,
                        ]
                    );
                } else {
                    $destStockItem = StockItem::firstOrCreate(
                        [
                            'hospital_id' => $transfer->hospital_id,
                            'procurement_item_id' => $item->procurement_item_id,
                            'store_id' => $transfer->to_store_id,
                        ],
                        [
                            'medication_id' => null,
                            'reorder_level' => 10,
                            'maximum_level' => 500,
                            'status' => StockItem::STATUS_ACTIVE,
                            'created_by' => $user->id,
                        ]
                    );
                }

                // Locate or create destination StockBatch
                $destBatch = StockBatch::where('stock_item_id', $destStockItem->id)
                    ->where('batch_number', $sourceBatch->batch_number)
                    ->lockForUpdate()
                    ->first();

                if ($destBatch) {
                    $before = $destBatch->quantity;
                    $after = $before + $qty;
                    $destBatch->update(['quantity' => $after]);
                } else {
                    $before = 0;
                    $after = $qty;
                    $destBatch = StockBatch::create([
                        'stock_item_id' => $destStockItem->id,
                        'store_id' => $transfer->to_store_id,
                        'vendor_id' => $sourceBatch->vendor_id,
                        'storage_location' => null,
                        'batch_number' => $sourceBatch->batch_number,
                        'manufacture_date' => $sourceBatch->manufacture_date,
                        'expiry_date' => $sourceBatch->expiry_date,
                        'quantity' => $after,
                        'unit_cost' => $sourceBatch->unit_cost,
                        'supplier_name' => $sourceBatch->supplier_name,
                        'status' => StockBatch::STATUS_ACTIVE,
                        'created_by' => $user->id,
                    ]);
                }

                StockTransaction::create([
                    'stock_item_id' => $destStockItem->id,
                    'stock_batch_id' => $destBatch->id,
                    'transaction_type' => StockTransaction::TYPE_RECEIPT,
                    'quantity' => $qty,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'reason' => "Transfer received {$transfer->transfer_number} from {$transfer->fromStore?->name}",
                    'performed_by' => $user->id,
                    'occurred_at' => now(),
                ]);

                $item->update(['to_stock_batch_id' => $destBatch->id]);
            }

            $transfer->update([
                'status' => StoreTransfer::STATUS_COMPLETED,
                'received_by' => $user->id,
                'received_at' => now(),
            ]);

            ApprovalHistory::create([
                'approvable_type' => StoreTransfer::class,
                'approvable_id' => $transfer->id,
                'action' => 'received',
                'user_id' => $user->id,
                'decision' => 'completed',
                'comments' => 'Store transfer received and added to destination inventory',
            ]);

            return $transfer->fresh()->load('items.toStockBatch', 'receivedBy');
        });
    }
}
