<?php

namespace App\Services\Procurement;

use App\Models\ApprovalHistory;
use App\Models\DepartmentStockRequest;
use App\Models\DepartmentStockRequestItem;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\StoreIssue;
use App\Models\StoreIssueItem;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreIssueService
{
    public function __construct(protected AuditLogService $auditLog) {}

    public function generateRequestNumber(): string
    {
        $prefix = 'DSR-'.date('Ym').'-';
        $latest = DepartmentStockRequest::where('request_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->value('request_number');

        if ($latest) {
            $seq = (int) substr($latest, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function generateIssueNumber(): string
    {
        $prefix = 'ISS-'.date('Ym').'-';
        $latest = StoreIssue::where('issue_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->value('issue_number');

        if ($latest) {
            $seq = (int) substr($latest, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function createStockRequest(array $data, Request $request): DepartmentStockRequest
    {
        return DB::transaction(function () use ($data, $request) {
            $user = $request->user();
            $requestNumber = $this->generateRequestNumber();

            $stockRequest = DepartmentStockRequest::create([
                'request_number' => $requestNumber,
                'hospital_id' => $data['hospital_id'] ?? $user->hospital_id,
                'department_id' => $data['department_id'],
                'from_store_id' => $data['from_store_id'],
                'requested_by' => $user->id,
                'priority' => $data['priority'] ?? DepartmentStockRequest::PRIORITY_NORMAL,
                'status' => DepartmentStockRequest::STATUS_SUBMITTED,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($data['items'] as $item) {
                DepartmentStockRequestItem::create([
                    'department_stock_request_id' => $stockRequest->id,
                    'procurement_item_id' => $item['procurement_item_id'],
                    'item_name' => $item['item_name'],
                    'unit_of_measure' => $item['unit_of_measure'] ?? 'piece',
                    'quantity_requested' => (int) $item['quantity_requested'],
                    'quantity_approved' => (int) ($item['quantity_approved'] ?? $item['quantity_requested']),
                    'quantity_issued' => 0,
                    'remarks' => $item['remarks'] ?? null,
                ]);
            }

            ApprovalHistory::create([
                'approvable_type' => DepartmentStockRequest::class,
                'approvable_id' => $stockRequest->id,
                'action' => 'submitted',
                'user_id' => $user->id,
                'decision' => 'submitted',
                'comments' => 'Department stock request submitted',
            ]);

            $this->auditLog->record('stock_request_created', $stockRequest, null, $stockRequest->toArray(), $request);

            return $stockRequest->load('items.procurementItem', 'department', 'fromStore', 'requestedBy');
        });
    }

    public function approveStockRequest(DepartmentStockRequest $stockRequest, array $approvedItems, ?string $comments, Request $request): DepartmentStockRequest
    {
        if ($stockRequest->status !== DepartmentStockRequest::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => 'Only submitted requests can be approved.',
            ]);
        }

        return DB::transaction(function () use ($stockRequest, $approvedItems, $comments, $request) {
            $user = $request->user();

            foreach ($approvedItems as $itemApproval) {
                DepartmentStockRequestItem::where('id', $itemApproval['id'])
                    ->where('department_stock_request_id', $stockRequest->id)
                    ->update([
                        'quantity_approved' => (int) $itemApproval['quantity_approved'],
                    ]);
            }

            $oldStatus = $stockRequest->status;
            $stockRequest->update([
                'status' => DepartmentStockRequest::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'updated_by' => $user->id,
            ]);

            ApprovalHistory::create([
                'approvable_type' => DepartmentStockRequest::class,
                'approvable_id' => $stockRequest->id,
                'action' => 'approved',
                'user_id' => $user->id,
                'decision' => 'approved',
                'comments' => $comments ?? 'Stock request approved for store issuance',
            ]);

            $this->auditLog->record('stock_request_approved', $stockRequest, ['status' => $oldStatus], ['status' => DepartmentStockRequest::STATUS_APPROVED], $request);

            return $stockRequest->fresh()->load('items.procurementItem');
        });
    }

    /**
     * Issue stock to a department using FEFO allocation
     */
    public function issueStock(array $data, Request $request): StoreIssue
    {
        return DB::transaction(function () use ($data, $request) {
            $user = $request->user();
            $issueNumber = $this->generateIssueNumber();

            $stockRequest = null;
            if (! empty($data['department_stock_request_id'])) {
                $stockRequest = DepartmentStockRequest::with('items')->find($data['department_stock_request_id']);
            }

            $storeId = $data['from_store_id'] ?? ($stockRequest ? $stockRequest->from_store_id : null);
            $deptId = $data['department_id'] ?? ($stockRequest ? $stockRequest->department_id : null);

            $issue = StoreIssue::create([
                'issue_number' => $issueNumber,
                'hospital_id' => $data['hospital_id'] ?? $user->hospital_id,
                'from_store_id' => $storeId,
                'department_id' => $deptId,
                'department_stock_request_id' => $stockRequest?->id,
                'issued_by' => $user->id,
                'issued_at' => now(),
                'status' => StoreIssue::STATUS_ISSUED,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($data['items'] as $itemLine) {
                $procItemId = $itemLine['procurement_item_id'];
                $qtyToIssue = (int) $itemLine['quantity_issued'];

                // FEFO Allocation: find available batches in store
                $batchId = $itemLine['stock_batch_id'] ?? null;
                $allocatedBatches = [];

                if ($batchId) {
                    $batch = StockBatch::lockForUpdate()->findOrFail($batchId);
                    if ($batch->quantity < $qtyToIssue) {
                        throw ValidationException::withMessages([
                            'quantity' => "Insufficient stock in selected batch {$batch->batch_number}. Available: {$batch->quantity}, Requested: {$qtyToIssue}",
                        ]);
                    }
                    $allocatedBatches[] = ['batch' => $batch, 'qty' => $qtyToIssue];
                } else {
                    // Automatically pick batches using FEFO
                    $batches = StockBatch::whereHas('stockItem', function ($query) use ($procItemId, $issue) {
                        $query->where('hospital_id', $issue->hospital_id)
                            ->where(function ($q) use ($procItemId) {
                                $q->where('procurement_item_id', $procItemId)
                                    ->orWhereHas('procurementItem', function ($sub) use ($procItemId) {
                                        $sub->where('id', $procItemId);
                                    });
                            });
                    })
                        ->where(function ($q) use ($storeId) {
                            $q->where('store_id', $storeId)->orWhereNull('store_id');
                        })
                        ->where('status', StockBatch::STATUS_ACTIVE)
                        ->where('quantity', '>', 0)
                        ->where('expiry_date', '>=', now()->toDateString())
                        ->orderBy('expiry_date', 'asc')
                        ->lockForUpdate()
                        ->get();

                    $remaining = $qtyToIssue;
                    foreach ($batches as $b) {
                        if ($remaining <= 0) {
                            break;
                        }
                        $take = min($b->quantity, $remaining);
                        $allocatedBatches[] = ['batch' => $b, 'qty' => $take];
                        $remaining -= $take;
                    }

                    if ($remaining > 0) {
                        throw ValidationException::withMessages([
                            'stock' => "Insufficient active stock for {$itemLine['item_name']}. Short by {$remaining}.",
                        ]);
                    }
                }

                foreach ($allocatedBatches as $alloc) {
                    $b = $alloc['batch'];
                    $q = $alloc['qty'];
                    $before = $b->quantity;
                    $after = $before - $q;

                    $b->update(['quantity' => $after]);

                    StockTransaction::create([
                        'stock_item_id' => $b->stock_item_id,
                        'stock_batch_id' => $b->id,
                        'transaction_type' => StockTransaction::TYPE_DISPENSE,
                        'quantity' => -$q,
                        'balance_before' => $before,
                        'balance_after' => $after,
                        'reason' => "Issued to Department via {$issue->issue_number}",
                        'performed_by' => $user->id,
                        'occurred_at' => now(),
                    ]);

                    StoreIssueItem::create([
                        'store_issue_id' => $issue->id,
                        'procurement_item_id' => $procItemId,
                        'stock_batch_id' => $b->id,
                        'item_name' => $itemLine['item_name'],
                        'batch_number' => $b->batch_number,
                        'expiry_date' => $b->expiry_date,
                        'quantity_issued' => $q,
                        'unit_cost' => $b->unit_cost,
                        'remarks' => $itemLine['remarks'] ?? null,
                    ]);
                }

                // If fulfilling a department request item, update quantity_issued
                if ($stockRequest) {
                    $reqItem = $stockRequest->items->firstWhere('procurement_item_id', $procItemId);
                    if ($reqItem) {
                        $reqItem->increment('quantity_issued', $qtyToIssue);
                    }
                }
            }

            // Update request status if linked
            if ($stockRequest) {
                $stockRequest->load('items');
                $fullyIssued = true;
                foreach ($stockRequest->items as $ri) {
                    if ($ri->quantity_issued < $ri->quantity_approved) {
                        $fullyIssued = false;
                        break;
                    }
                }
                $stockRequest->update([
                    'status' => $fullyIssued ? DepartmentStockRequest::STATUS_ISSUED : DepartmentStockRequest::STATUS_PARTIALLY_ISSUED,
                    'updated_by' => $user->id,
                ]);
            }

            ApprovalHistory::create([
                'approvable_type' => StoreIssue::class,
                'approvable_id' => $issue->id,
                'action' => 'issued',
                'user_id' => $user->id,
                'decision' => 'issued',
                'comments' => 'Stock issued to department',
            ]);

            $this->auditLog->record('store_issue_created', $issue, null, $issue->toArray(), $request);

            return $issue->load('items.stockBatch', 'fromStore', 'department', 'issuedBy');
        });
    }

    public function receiveIssue(StoreIssue $issue, Request $request): StoreIssue
    {
        if ($issue->status === StoreIssue::STATUS_RECEIVED) {
            throw ValidationException::withMessages([
                'status' => 'This issue has already been marked as received.',
            ]);
        }

        $user = $request->user();
        $oldStatus = $issue->status;

        $issue->update([
            'status' => StoreIssue::STATUS_RECEIVED,
            'received_by' => $user->id,
            'received_at' => now(),
            'updated_by' => $user->id,
        ]);

        if ($issue->department_stock_request_id) {
            DepartmentStockRequest::where('id', $issue->department_stock_request_id)
                ->update(['status' => DepartmentStockRequest::STATUS_RECEIVED]);
        }

        ApprovalHistory::create([
            'approvable_type' => StoreIssue::class,
            'approvable_id' => $issue->id,
            'action' => 'received',
            'user_id' => $user->id,
            'decision' => 'received',
            'comments' => 'Department confirmed receipt of supplies',
        ]);

        $this->auditLog->record('store_issue_received', $issue, ['status' => $oldStatus], ['status' => StoreIssue::STATUS_RECEIVED], $request);

        return $issue->fresh()->load('receivedBy');
    }
}
