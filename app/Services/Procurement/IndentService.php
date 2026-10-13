<?php

namespace App\Services\Procurement;

use App\Models\ApprovalHistory;
use App\Models\Indent;
use App\Models\IndentItem;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IndentService
{
    public function __construct(protected AuditLogService $auditLog) {}

    public function generateIndentNumber(): string
    {
        $prefix = 'IND-'.date('Ym').'-';
        $latest = Indent::where('indent_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->value('indent_number');

        if ($latest) {
            $seq = (int) substr($latest, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function create(array $data, Request $request): Indent
    {
        return DB::transaction(function () use ($data, $request) {
            $user = $request->user();
            $indentNumber = $this->generateIndentNumber();

            $indent = Indent::create([
                'indent_number' => $indentNumber,
                'hospital_id' => $data['hospital_id'] ?? $user->hospital_id,
                'department_id' => $data['department_id'],
                'requested_by' => $user->id,
                'target_store_id' => $data['target_store_id'] ?? null,
                'indent_date' => $data['indent_date'] ?? now()->toDateString(),
                'required_by_date' => $data['required_by_date'] ?? null,
                'priority' => $data['priority'] ?? Indent::PRIORITY_NORMAL,
                'status' => Indent::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($data['items'] as $item) {
                $qty = (int) $item['quantity_requested'];
                $cost = isset($item['estimated_unit_cost']) ? (float) $item['estimated_unit_cost'] : null;
                $total = $cost !== null ? $qty * $cost : null;

                IndentItem::create([
                    'indent_id' => $indent->id,
                    'procurement_item_id' => $item['procurement_item_id'],
                    'item_name' => $item['item_name'],
                    'unit_of_measure' => $item['unit_of_measure'] ?? 'piece',
                    'quantity_requested' => $qty,
                    'quantity_approved' => 0,
                    'estimated_unit_cost' => $cost,
                    'estimated_total_cost' => $total,
                    'purpose' => $item['purpose'] ?? null,
                    'specification' => $item['specification'] ?? null,
                ]);
            }

            ApprovalHistory::create([
                'approvable_type' => Indent::class,
                'approvable_id' => $indent->id,
                'action' => 'created',
                'user_id' => $user->id,
                'decision' => 'draft',
                'comments' => 'Indent drafted',
            ]);

            $this->auditLog->record('indent_created', $indent, null, $indent->toArray(), $request);

            return $indent->load('items.procurementItem', 'department', 'targetStore', 'requestedBy');
        });
    }

    public function update(Indent $indent, array $data, Request $request): Indent
    {
        if ($indent->status !== Indent::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Only draft indents can be updated.',
            ]);
        }

        return DB::transaction(function () use ($indent, $data, $request) {
            $oldValues = $indent->toArray();

            $indent->update([
                'department_id' => $data['department_id'] ?? $indent->department_id,
                'target_store_id' => $data['target_store_id'] ?? $indent->target_store_id,
                'indent_date' => $data['indent_date'] ?? $indent->indent_date,
                'required_by_date' => $data['required_by_date'] ?? $indent->required_by_date,
                'priority' => $data['priority'] ?? $indent->priority,
                'notes' => $data['notes'] ?? $indent->notes,
                'updated_by' => $request->user()->id,
            ]);

            if (isset($data['items'])) {
                $indent->items()->delete();
                foreach ($data['items'] as $item) {
                    $qty = (int) $item['quantity_requested'];
                    $cost = isset($item['estimated_unit_cost']) ? (float) $item['estimated_unit_cost'] : null;
                    $total = $cost !== null ? $qty * $cost : null;

                    IndentItem::create([
                        'indent_id' => $indent->id,
                        'procurement_item_id' => $item['procurement_item_id'],
                        'item_name' => $item['item_name'],
                        'unit_of_measure' => $item['unit_of_measure'] ?? 'piece',
                        'quantity_requested' => $qty,
                        'quantity_approved' => 0,
                        'estimated_unit_cost' => $cost,
                        'estimated_total_cost' => $total,
                        'purpose' => $item['purpose'] ?? null,
                        'specification' => $item['specification'] ?? null,
                    ]);
                }
            }

            $this->auditLog->record('indent_updated', $indent, $oldValues, $indent->toArray(), $request);

            return $indent->fresh()->load('items.procurementItem', 'department', 'targetStore', 'requestedBy');
        });
    }

    public function submit(Indent $indent, Request $request): Indent
    {
        if ($indent->status !== Indent::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Only draft indents can be submitted.',
            ]);
        }

        $oldStatus = $indent->status;
        $indent->update([
            'status' => Indent::STATUS_SUBMITTED,
            'updated_by' => $request->user()->id,
        ]);

        ApprovalHistory::create([
            'approvable_type' => Indent::class,
            'approvable_id' => $indent->id,
            'action' => 'submitted',
            'user_id' => $request->user()->id,
            'decision' => 'submitted',
            'comments' => 'Submitted for review and approval',
        ]);

        $this->auditLog->record('indent_submitted', $indent, ['status' => $oldStatus], ['status' => Indent::STATUS_SUBMITTED], $request);

        return $indent->fresh();
    }

    public function approve(Indent $indent, array $itemApprovals, ?string $comments, Request $request): Indent
    {
        if ($indent->status !== Indent::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => 'Only submitted indents can be approved.',
            ]);
        }

        return DB::transaction(function () use ($indent, $itemApprovals, $comments, $request) {
            $user = $request->user();

            foreach ($itemApprovals as $approval) {
                IndentItem::where('id', $approval['id'])
                    ->where('indent_id', $indent->id)
                    ->update([
                        'quantity_approved' => (int) $approval['quantity_approved'],
                    ]);
            }

            $oldStatus = $indent->status;
            $indent->update([
                'status' => Indent::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'updated_by' => $user->id,
            ]);

            ApprovalHistory::create([
                'approvable_type' => Indent::class,
                'approvable_id' => $indent->id,
                'action' => 'approved',
                'user_id' => $user->id,
                'decision' => 'approved',
                'comments' => $comments ?? 'Approved requisition items',
            ]);

            $this->auditLog->record('indent_approved', $indent, ['status' => $oldStatus], ['status' => Indent::STATUS_APPROVED], $request);

            return $indent->fresh()->load('items.procurementItem');
        });
    }

    public function reject(Indent $indent, string $reason, Request $request): Indent
    {
        if ($indent->status !== Indent::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => 'Only submitted indents can be rejected.',
            ]);
        }

        $oldStatus = $indent->status;
        $indent->update([
            'status' => Indent::STATUS_REJECTED,
            'rejection_reason' => $reason,
            'updated_by' => $request->user()->id,
        ]);

        ApprovalHistory::create([
            'approvable_type' => Indent::class,
            'approvable_id' => $indent->id,
            'action' => 'rejected',
            'user_id' => $request->user()->id,
            'decision' => 'rejected',
            'comments' => $reason,
        ]);

        $this->auditLog->record('indent_rejected', $indent, ['status' => $oldStatus], ['status' => Indent::STATUS_REJECTED, 'reason' => $reason], $request);

        return $indent->fresh();
    }

    public function cancel(Indent $indent, string $reason, Request $request): Indent
    {
        if (! in_array($indent->status, [Indent::STATUS_DRAFT, Indent::STATUS_SUBMITTED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or submitted indents can be cancelled.',
            ]);
        }

        $oldStatus = $indent->status;
        $indent->update([
            'status' => Indent::STATUS_CANCELLED,
            'rejection_reason' => $reason,
            'updated_by' => $request->user()->id,
        ]);

        ApprovalHistory::create([
            'approvable_type' => Indent::class,
            'approvable_id' => $indent->id,
            'action' => 'cancelled',
            'user_id' => $request->user()->id,
            'decision' => 'cancelled',
            'comments' => $reason,
        ]);

        $this->auditLog->record('indent_cancelled', $indent, ['status' => $oldStatus], ['status' => Indent::STATUS_CANCELLED, 'reason' => $reason], $request);

        return $indent->fresh();
    }
}
