<?php

namespace App\Services\Procurement;

use App\Models\ApprovalHistory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(protected AuditLogService $auditLog) {}

    public function generatePoNumber(): string
    {
        $prefix = 'PO-'.date('Ym').'-';
        $latest = PurchaseOrder::where('po_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->value('po_number');

        if ($latest) {
            $seq = (int) substr($latest, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function create(array $data, Request $request): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $request) {
            $user = $request->user();
            $poNumber = $this->generatePoNumber();

            $subtotal = 0;
            $itemsData = [];

            foreach ($data['items'] as $item) {
                $qty = (int) $item['quantity_ordered'];
                $unitPrice = (float) $item['unit_price'];
                $taxRate = isset($item['tax_rate']) ? (float) $item['tax_rate'] : 0.0;
                $lineTax = ($qty * $unitPrice) * ($taxRate / 100);
                $discount = isset($item['discount_amount']) ? (float) $item['discount_amount'] : 0.0;
                $lineTotal = ($qty * $unitPrice) - $discount + $lineTax;

                $subtotal += ($qty * $unitPrice);

                $itemsData[] = [
                    'procurement_item_id' => $item['procurement_item_id'],
                    'indent_item_id' => $item['indent_item_id'] ?? null,
                    'item_name' => $item['item_name'],
                    'unit_of_measure' => $item['unit_of_measure'] ?? 'piece',
                    'quantity_ordered' => $qty,
                    'quantity_received' => 0,
                    'unit_price' => $unitPrice,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $lineTax,
                    'discount_amount' => $discount,
                    'total_amount' => $lineTotal,
                    'specifications' => $item['specifications'] ?? null,
                ];
            }

            $taxAmount = isset($data['tax_amount']) ? (float) $data['tax_amount'] : 0.0;
            $discountAmount = isset($data['discount_amount']) ? (float) $data['discount_amount'] : 0.0;
            $shippingAmount = isset($data['shipping_amount']) ? (float) $data['shipping_amount'] : 0.0;
            $grandTotal = ($subtotal - $discountAmount) + $taxAmount + $shippingAmount;

            $po = PurchaseOrder::create([
                'po_number' => $poNumber,
                'hospital_id' => $data['hospital_id'] ?? $user->hospital_id,
                'vendor_id' => $data['vendor_id'],
                'receiving_store_id' => $data['receiving_store_id'],
                'indent_id' => $data['indent_id'] ?? null,
                'vendor_quotation_id' => $data['vendor_quotation_id'] ?? null,
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'shipping_amount' => $shippingAmount,
                'grand_total' => $grandTotal,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'terms_conditions' => $data['terms_conditions'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($itemsData as $row) {
                PurchaseOrderItem::create(array_merge($row, [
                    'purchase_order_id' => $po->id,
                ]));
            }

            ApprovalHistory::create([
                'approvable_type' => PurchaseOrder::class,
                'approvable_id' => $po->id,
                'action' => 'created',
                'user_id' => $user->id,
                'decision' => 'draft',
                'comments' => 'Purchase order drafted',
            ]);

            $this->auditLog->record('purchase_order_created', $po, null, $po->toArray(), $request);

            return $po->load('items.procurementItem', 'vendor', 'receivingStore', 'createdBy');
        });
    }

    public function update(PurchaseOrder $po, array $data, Request $request): PurchaseOrder
    {
        if ($po->status !== PurchaseOrder::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Only draft purchase orders can be updated.',
            ]);
        }

        return DB::transaction(function () use ($po, $data, $request) {
            $oldValues = $po->toArray();

            $subtotal = 0;
            $itemsData = [];

            if (isset($data['items'])) {
                foreach ($data['items'] as $item) {
                    $qty = (int) $item['quantity_ordered'];
                    $unitPrice = (float) $item['unit_price'];
                    $taxRate = isset($item['tax_rate']) ? (float) $item['tax_rate'] : 0.0;
                    $lineTax = ($qty * $unitPrice) * ($taxRate / 100);
                    $discount = isset($item['discount_amount']) ? (float) $item['discount_amount'] : 0.0;
                    $lineTotal = ($qty * $unitPrice) - $discount + $lineTax;

                    $subtotal += ($qty * $unitPrice);

                    $itemsData[] = [
                        'procurement_item_id' => $item['procurement_item_id'],
                        'indent_item_id' => $item['indent_item_id'] ?? null,
                        'item_name' => $item['item_name'],
                        'unit_of_measure' => $item['unit_of_measure'] ?? 'piece',
                        'quantity_ordered' => $qty,
                        'quantity_received' => 0,
                        'unit_price' => $unitPrice,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $lineTax,
                        'discount_amount' => $discount,
                        'total_amount' => $lineTotal,
                        'specifications' => $item['specifications'] ?? null,
                    ];
                }

                $po->items()->delete();
                foreach ($itemsData as $row) {
                    PurchaseOrderItem::create(array_merge($row, [
                        'purchase_order_id' => $po->id,
                    ]));
                }
            } else {
                $subtotal = (float) $po->subtotal;
            }

            $taxAmount = isset($data['tax_amount']) ? (float) $data['tax_amount'] : (float) $po->tax_amount;
            $discountAmount = isset($data['discount_amount']) ? (float) $data['discount_amount'] : (float) $po->discount_amount;
            $shippingAmount = isset($data['shipping_amount']) ? (float) $data['shipping_amount'] : (float) $po->shipping_amount;
            $grandTotal = ($subtotal - $discountAmount) + $taxAmount + $shippingAmount;

            $po->update([
                'vendor_id' => $data['vendor_id'] ?? $po->vendor_id,
                'receiving_store_id' => $data['receiving_store_id'] ?? $po->receiving_store_id,
                'order_date' => $data['order_date'] ?? $po->order_date,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? $po->expected_delivery_date,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'shipping_amount' => $shippingAmount,
                'grand_total' => $grandTotal,
                'terms_conditions' => $data['terms_conditions'] ?? $po->terms_conditions,
                'notes' => $data['notes'] ?? $po->notes,
                'updated_by' => $request->user()->id,
            ]);

            $this->auditLog->record('purchase_order_updated', $po, $oldValues, $po->toArray(), $request);

            return $po->fresh()->load('items.procurementItem', 'vendor', 'receivingStore');
        });
    }

    public function submit(PurchaseOrder $po, Request $request): PurchaseOrder
    {
        if ($po->status !== PurchaseOrder::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Only draft purchase orders can be submitted.',
            ]);
        }

        $oldStatus = $po->status;
        $po->update([
            'status' => PurchaseOrder::STATUS_SUBMITTED,
            'updated_by' => $request->user()->id,
        ]);

        ApprovalHistory::create([
            'approvable_type' => PurchaseOrder::class,
            'approvable_id' => $po->id,
            'action' => 'submitted',
            'user_id' => $request->user()->id,
            'decision' => 'submitted',
            'comments' => 'Purchase order submitted for approval',
        ]);

        $this->auditLog->record('purchase_order_submitted', $po, ['status' => $oldStatus], ['status' => PurchaseOrder::STATUS_SUBMITTED], $request);

        return $po->fresh();
    }

    public function approve(PurchaseOrder $po, ?string $comments, Request $request): PurchaseOrder
    {
        if ($po->status !== PurchaseOrder::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => 'Only submitted purchase orders can be approved.',
            ]);
        }

        $user = $request->user();

        // Separation of duties: Creator cannot approve their own PO (unless super-admin bypass)
        if ($po->created_by === $user->id && ! $user->hasRole('super-admin')) {
            throw ValidationException::withMessages([
                'approver' => 'Separation of duties violation: The creator of a purchase order cannot approve it.',
            ]);
        }

        $oldStatus = $po->status;
        $po->update([
            'status' => PurchaseOrder::STATUS_APPROVED,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'updated_by' => $user->id,
        ]);

        ApprovalHistory::create([
            'approvable_type' => PurchaseOrder::class,
            'approvable_id' => $po->id,
            'action' => 'approved',
            'user_id' => $user->id,
            'decision' => 'approved',
            'comments' => $comments ?? 'Purchase order approved',
        ]);

        $this->auditLog->record('purchase_order_approved', $po, ['status' => $oldStatus], ['status' => PurchaseOrder::STATUS_APPROVED], $request);

        return $po->fresh();
    }

    public function sendToVendor(PurchaseOrder $po, Request $request): PurchaseOrder
    {
        if ($po->status !== PurchaseOrder::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => 'Only approved purchase orders can be sent to vendors.',
            ]);
        }

        $oldStatus = $po->status;
        $po->update([
            'status' => PurchaseOrder::STATUS_SENT_TO_VENDOR,
            'updated_by' => $request->user()->id,
        ]);

        ApprovalHistory::create([
            'approvable_type' => PurchaseOrder::class,
            'approvable_id' => $po->id,
            'action' => 'sent',
            'user_id' => $request->user()->id,
            'decision' => 'dispatched',
            'comments' => 'Purchase order sent to vendor',
        ]);

        $this->auditLog->record('purchase_order_sent', $po, ['status' => $oldStatus], ['status' => PurchaseOrder::STATUS_SENT_TO_VENDOR], $request);

        return $po->fresh();
    }

    public function reject(PurchaseOrder $po, string $reason, Request $request): PurchaseOrder
    {
        if ($po->status !== PurchaseOrder::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => 'Only submitted purchase orders can be rejected.',
            ]);
        }

        $oldStatus = $po->status;
        $po->update([
            'status' => PurchaseOrder::STATUS_REJECTED,
            'rejection_reason' => $reason,
            'updated_by' => $request->user()->id,
        ]);

        ApprovalHistory::create([
            'approvable_type' => PurchaseOrder::class,
            'approvable_id' => $po->id,
            'action' => 'rejected',
            'user_id' => $request->user()->id,
            'decision' => 'rejected',
            'comments' => $reason,
        ]);

        $this->auditLog->record('purchase_order_rejected', $po, ['status' => $oldStatus], ['status' => PurchaseOrder::STATUS_REJECTED, 'reason' => $reason], $request);

        return $po->fresh();
    }

    public function cancel(PurchaseOrder $po, string $reason, Request $request): PurchaseOrder
    {
        if (in_array($po->status, [PurchaseOrder::STATUS_CLOSED, PurchaseOrder::STATUS_FULLY_RECEIVED, PurchaseOrder::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot cancel purchase order with status '{$po->status}'.",
            ]);
        }

        $oldStatus = $po->status;
        $po->update([
            'status' => PurchaseOrder::STATUS_CANCELLED,
            'cancellation_reason' => $reason,
            'updated_by' => $request->user()->id,
        ]);

        ApprovalHistory::create([
            'approvable_type' => PurchaseOrder::class,
            'approvable_id' => $po->id,
            'action' => 'cancelled',
            'user_id' => $request->user()->id,
            'decision' => 'cancelled',
            'comments' => $reason,
        ]);

        $this->auditLog->record('purchase_order_cancelled', $po, ['status' => $oldStatus], ['status' => PurchaseOrder::STATUS_CANCELLED, 'reason' => $reason], $request);

        return $po->fresh();
    }
}
