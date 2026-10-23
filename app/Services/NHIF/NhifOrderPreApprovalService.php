<?php

namespace App\Services\NHIF;

use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Models\RadiologyOrder;
use App\Models\RadiologyOrderItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class NhifOrderPreApprovalService
{
    public const STATUS_SENT_NOT_ISSUED = 'nhif_sent_not_issued';

    public const STATUS_PENDING_REQUEST = 'nhif_pending_request';

    public const STATUS_AUTHORIZED = 'authorized';

    /**
     * Determine whether an order belongs to an NHIF-covered patient/encounter.
     */
    public function isOrderNhifCovered(LaboratoryOrder|PrescriptionOrder|RadiologyOrder $order): bool
    {
        $patient = $order->clinicalEncounter?->patient;
        if ($patient?->currentNhifInsurance() !== null) {
            return true;
        }

        if ($order->clinical_encounter_id && NhifPreApprovalSession::where('clinical_encounter_id', $order->clinical_encounter_id)->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Resolve the NHIF pre-approval status for a single laboratory order.
     * Returns null if the patient is not covered by an active NHIF insurance.
     *
     * @return array{status: string, label: string, blocked: bool, message: string}|null
     */
    public function statusForLaboratoryOrder(LaboratoryOrder $order): ?array
    {
        $activeItems = $order->items->where('status', '!=', LaboratoryOrderItem::STATUS_CANCELLED);
        $itemIds = $activeItems->pluck('id')->all();

        $sessionItems = empty($itemIds)
            ? collect()
            : NhifPreApprovalSessionItem::query()
                ->where('source_type', NhifPreApprovalSessionItem::SOURCE_LAB)
                ->whereIn('source_id', $itemIds)
                ->get();

        if (! $this->isOrderNhifCovered($order) && $sessionItems->isEmpty()) {
            return null;
        }

        if ($activeItems->isEmpty()) {
            return [
                'status' => self::STATUS_AUTHORIZED,
                'label' => 'Cleared',
                'blocked' => false,
                'message' => 'No active laboratory tests.',
            ];
        }

        if ($sessionItems->isEmpty()) {
            return [
                'status' => self::STATUS_PENDING_REQUEST,
                'label' => 'Pre-approval Required',
                'blocked' => true,
                'message' => 'NHIF pre-approval has not been requested for these laboratory tests. Pre-approval must be requested and issued before sample collection.',
            ];
        }

        $unissued = $sessionItems->where('approval_status', '!=', NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED);
        $missingItemIds = array_diff($itemIds, $sessionItems->pluck('source_id')->all());

        if ($unissued->isNotEmpty() || ! empty($missingItemIds)) {
            return [
                'status' => self::STATUS_SENT_NOT_ISSUED,
                'label' => 'Request sent but not issued',
                'blocked' => true,
                'message' => 'NHIF pre-approval request has been sent but is not yet issued. Please issue the pre-approval request before collecting samples.',
            ];
        }

        return [
            'status' => self::STATUS_AUTHORIZED,
            'label' => 'Issued & Cleared',
            'blocked' => false,
            'message' => 'NHIF pre-approval has been issued.',
        ];
    }

    /**
     * Resolve the NHIF pre-approval status for a single prescription order.
     * Returns null if the patient is not covered by an active NHIF insurance.
     *
     * @return array{status: string, label: string, blocked: bool, message: string, session_id?: int|null, can_issue?: bool}|null
     */
    public function statusForPrescriptionOrder(PrescriptionOrder $order): ?array
    {
        $activeItems = $order->items->where('status', '!=', PrescriptionOrderItem::STATUS_CANCELLED);
        $itemIds = $activeItems->pluck('id')->all();

        $sessionItems = empty($itemIds)
            ? collect()
            : NhifPreApprovalSessionItem::query()
                ->where('source_type', NhifPreApprovalSessionItem::SOURCE_MEDICATION)
                ->whereIn('source_id', $itemIds)
                ->with('session')
                ->get();

        if (! $this->isOrderNhifCovered($order) && $sessionItems->isEmpty()) {
            return null;
        }

        if ($activeItems->isEmpty()) {
            return [
                'status' => self::STATUS_AUTHORIZED,
                'label' => 'Cleared',
                'blocked' => false,
                'message' => 'No active prescription items.',
                'session_id' => null,
                'can_issue' => false,
            ];
        }

        $requestedItemIds = $sessionItems->pluck('source_id')->unique()->all();
        $unrequestedItemIds = array_diff($itemIds, $requestedItemIds);

        if ($sessionItems->isEmpty() || ! empty($unrequestedItemIds)) {
            return [
                'status' => self::STATUS_PENDING_REQUEST,
                'label' => 'Pre-approval Required',
                'blocked' => true,
                'message' => 'NHIF pre-approval has not been requested for these medications. Pre-approval must be requested and issued before dispensing.',
                'session_id' => null,
                'can_issue' => false,
            ];
        }

        $issuedItemIds = $sessionItems
            ->where('approval_status', NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED)
            ->pluck('source_id')
            ->unique()
            ->all();

        $unissuedItemIds = array_diff($itemIds, $issuedItemIds);

        if (! empty($unissuedItemIds)) {
            $activeUnissuedSessionItem = $sessionItems
                ->whereIn('source_id', $unissuedItemIds)
                ->where('approval_status', '!=', NhifPreApprovalSessionItem::APPROVAL_STATUS_REJECTED)
                ->sortByDesc('id')
                ->first();

            $sessionId = $activeUnissuedSessionItem?->nhif_pre_approval_session_id;
            $session = $activeUnissuedSessionItem?->session;
            $canIssue = $session && in_array($session->status, [
                NhifPreApprovalSession::STATUS_ACCEPTED,
                NhifPreApprovalSession::STATUS_APPROVED,
                NhifPreApprovalSession::STATUS_PARTIALLY_APPROVED,
            ], true);

            return [
                'status' => self::STATUS_SENT_NOT_ISSUED,
                'label' => 'Request sent but not issued',
                'blocked' => true,
                'message' => 'NHIF pre-approval request has been sent but is not yet issued. Please issue the pre-approval request before dispensing medications.',
                'session_id' => $sessionId,
                'can_issue' => $canIssue,
            ];
        }

        $latestSessionId = $sessionItems->sortByDesc('id')->first()?->nhif_pre_approval_session_id;

        return [
            'status' => self::STATUS_AUTHORIZED,
            'label' => 'Issued & Cleared',
            'blocked' => false,
            'message' => 'NHIF pre-approval has been issued.',
            'session_id' => $latestSessionId,
            'can_issue' => false,
        ];
    }

    /**
     * Batch resolve statuses for a collection of laboratory orders.
     *
     * @param  Collection<int, LaboratoryOrder>  $orders
     * @return array<int, array{status: string, label: string, blocked: bool, message: string}|null> order_id => result
     */
    public function batchLaboratoryOrderStatuses(Collection $orders): array
    {
        $nhifOrders = [];
        $allItemIds = [];
        $itemIdsByOrderId = [];

        foreach ($orders as $order) {
            if ($this->isOrderNhifCovered($order)) {
                $nhifOrders[$order->id] = $order;
                $itemIds = $order->items
                    ->where('status', '!=', LaboratoryOrderItem::STATUS_CANCELLED)
                    ->pluck('id')
                    ->all();
                $itemIdsByOrderId[$order->id] = $itemIds;
                $allItemIds = array_merge($allItemIds, $itemIds);
            }
        }

        if (empty($nhifOrders)) {
            return [];
        }

        $sessionItems = NhifPreApprovalSessionItem::query()
            ->where('source_type', NhifPreApprovalSessionItem::SOURCE_LAB)
            ->whereIn('source_id', array_unique($allItemIds))
            ->get()
            ->groupBy('source_id');

        $result = [];
        foreach ($nhifOrders as $orderId => $order) {
            $itemIds = $itemIdsByOrderId[$orderId] ?? [];
            if (empty($itemIds)) {
                $result[$orderId] = [
                    'status' => self::STATUS_AUTHORIZED,
                    'label' => 'Cleared',
                    'blocked' => false,
                    'message' => 'No active laboratory tests.',
                ];

                continue;
            }

            $orderSessionItems = collect();
            $missingItems = false;
            foreach ($itemIds as $id) {
                $found = $sessionItems->get($id);
                if (! $found || $found->isEmpty()) {
                    $missingItems = true;
                } else {
                    $orderSessionItems = $orderSessionItems->merge($found);
                }
            }

            if ($orderSessionItems->isEmpty()) {
                $result[$orderId] = [
                    'status' => self::STATUS_PENDING_REQUEST,
                    'label' => 'Pre-approval Required',
                    'blocked' => true,
                    'message' => 'NHIF pre-approval has not been requested.',
                ];

                continue;
            }

            $unissued = $orderSessionItems->where('approval_status', '!=', NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED);
            if ($unissued->isNotEmpty() || $missingItems) {
                $result[$orderId] = [
                    'status' => self::STATUS_SENT_NOT_ISSUED,
                    'label' => 'Request sent but not issued',
                    'blocked' => true,
                    'message' => 'NHIF pre-approval request has been sent but is not yet issued.',
                ];

                continue;
            }

            $result[$orderId] = [
                'status' => self::STATUS_AUTHORIZED,
                'label' => 'Issued & Cleared',
                'blocked' => false,
                'message' => 'NHIF pre-approval has been issued.',
            ];
        }

        return $result;
    }

    /**
     * Batch resolve statuses for a collection of prescription orders.
     *
     * @param  Collection<int, PrescriptionOrder>  $orders
     * @return array<int, array{status: string, label: string, blocked: bool, message: string}|null> order_id => result
     */
    public function batchPrescriptionOrderStatuses(Collection $orders): array
    {
        $nhifOrders = [];
        $allItemIds = [];
        $itemIdsByOrderId = [];

        foreach ($orders as $order) {
            if ($this->isOrderNhifCovered($order)) {
                $nhifOrders[$order->id] = $order;
                $itemIds = $order->items
                    ->where('status', '!=', PrescriptionOrderItem::STATUS_CANCELLED)
                    ->pluck('id')
                    ->all();
                $itemIdsByOrderId[$order->id] = $itemIds;
                $allItemIds = array_merge($allItemIds, $itemIds);
            }
        }

        if (empty($nhifOrders)) {
            return [];
        }

        $sessionItems = NhifPreApprovalSessionItem::query()
            ->where('source_type', NhifPreApprovalSessionItem::SOURCE_MEDICATION)
            ->whereIn('source_id', array_unique($allItemIds))
            ->get()
            ->groupBy('source_id');

        $result = [];
        foreach ($nhifOrders as $orderId => $order) {
            $itemIds = $itemIdsByOrderId[$orderId] ?? [];
            if (empty($itemIds)) {
                $result[$orderId] = [
                    'status' => self::STATUS_AUTHORIZED,
                    'label' => 'Cleared',
                    'blocked' => false,
                    'message' => 'No active prescription items.',
                ];

                continue;
            }

            $orderSessionItems = collect();
            $missingItems = false;
            foreach ($itemIds as $id) {
                $found = $sessionItems->get($id);
                if (! $found || $found->isEmpty()) {
                    $missingItems = true;
                } else {
                    $orderSessionItems = $orderSessionItems->merge($found);
                }
            }

            if ($orderSessionItems->isEmpty() || $missingItems) {
                $result[$orderId] = [
                    'status' => self::STATUS_PENDING_REQUEST,
                    'label' => 'Pre-approval Required',
                    'blocked' => true,
                    'message' => 'NHIF pre-approval has not been requested.',
                ];

                continue;
            }

            $issuedItemIds = $orderSessionItems
                ->where('approval_status', NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED)
                ->pluck('source_id')
                ->unique()
                ->all();

            $unissuedItemIds = array_diff($itemIds, $issuedItemIds);

            if (! empty($unissuedItemIds)) {
                $result[$orderId] = [
                    'status' => self::STATUS_SENT_NOT_ISSUED,
                    'label' => 'Request sent but not issued',
                    'blocked' => true,
                    'message' => 'NHIF pre-approval request has been sent but is not yet issued.',
                ];

                continue;
            }

            $result[$orderId] = [
                'status' => self::STATUS_AUTHORIZED,
                'label' => 'Issued & Cleared',
                'blocked' => false,
                'message' => 'NHIF pre-approval has been issued.',
            ];
        }

        return $result;
    }

    /**
     * Assert that sample collection can proceed for the laboratory order.
     * Throws ValidationException if blocked.
     *
     * @throws ValidationException
     */
    public function assertLaboratoryOrderCanCollect(LaboratoryOrder $order): void
    {
        $status = $this->statusForLaboratoryOrder($order);
        if ($status !== null && $status['blocked']) {
            throw ValidationException::withMessages([
                'nhif' => $status['message'],
            ]);
        }
    }

    /**
     * Assert that dispensing can proceed for the prescription order.
     * Throws ValidationException if blocked.
     *
     * @throws ValidationException
     */
    public function assertPrescriptionOrderCanDispense(PrescriptionOrder $order): void
    {
        $status = $this->statusForPrescriptionOrder($order);
        if ($status !== null && $status['blocked']) {
            throw ValidationException::withMessages([
                'nhif' => $status['message'],
            ]);
        }
    }

    /**
     * Assert that verification can proceed for the prescription order.
     * Throws ValidationException if blocked.
     *
     * @throws ValidationException
     */
    public function assertPrescriptionOrderCanVerify(PrescriptionOrder $order): void
    {
        $status = $this->statusForPrescriptionOrder($order);
        if ($status !== null && $status['blocked']) {
            throw ValidationException::withMessages([
                'nhif' => $status['message'],
            ]);
        }
    }

    /**
     * Resolve the NHIF pre-approval status for a single radiology order.
     * Returns null if the patient is not covered by an active NHIF insurance.
     *
     * @return array{status: string, label: string, blocked: bool, message: string, session_id?: int|null, can_issue?: bool}|null
     */
    public function statusForRadiologyOrder(RadiologyOrder $order): ?array
    {
        $activeItems = $order->items->where('status', '!=', RadiologyOrderItem::STATUS_CANCELLED);
        $itemIds = $activeItems->pluck('id')->all();

        $sessionItems = empty($itemIds)
            ? collect()
            : NhifPreApprovalSessionItem::query()
                ->where('source_type', NhifPreApprovalSessionItem::SOURCE_RADIOLOGY)
                ->whereIn('source_id', $itemIds)
                ->with('session')
                ->get();

        if (! $this->isOrderNhifCovered($order) && $sessionItems->isEmpty()) {
            return null;
        }

        if ($activeItems->isEmpty()) {
            return [
                'status' => self::STATUS_AUTHORIZED,
                'label' => 'Cleared',
                'blocked' => false,
                'message' => 'No active radiology procedures.',
                'session_id' => null,
                'can_issue' => false,
            ];
        }

        $requestedItemIds = $sessionItems->pluck('source_id')->unique()->all();
        $unrequestedItemIds = array_diff($itemIds, $requestedItemIds);

        if ($sessionItems->isEmpty() || ! empty($unrequestedItemIds)) {
            return [
                'status' => self::STATUS_PENDING_REQUEST,
                'label' => 'Pre-approval Required',
                'blocked' => true,
                'message' => 'NHIF pre-approval has not been requested for these radiology procedures. Pre-approval must be requested and issued before scheduling and examination.',
                'session_id' => null,
                'can_issue' => false,
            ];
        }

        $issuedItemIds = $sessionItems
            ->where('approval_status', NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED)
            ->pluck('source_id')
            ->unique()
            ->all();

        $unissuedItemIds = array_diff($itemIds, $issuedItemIds);

        if (! empty($unissuedItemIds)) {
            $activeUnissuedSessionItem = $sessionItems
                ->whereIn('source_id', $unissuedItemIds)
                ->where('approval_status', '!=', NhifPreApprovalSessionItem::APPROVAL_STATUS_REJECTED)
                ->sortByDesc('id')
                ->first();

            $sessionId = $activeUnissuedSessionItem?->nhif_pre_approval_session_id;
            $session = $activeUnissuedSessionItem?->session;
            $canIssue = $session && in_array($session->status, [
                NhifPreApprovalSession::STATUS_ACCEPTED,
                NhifPreApprovalSession::STATUS_APPROVED,
                NhifPreApprovalSession::STATUS_PARTIALLY_APPROVED,
            ], true);

            return [
                'status' => self::STATUS_SENT_NOT_ISSUED,
                'label' => 'Request sent but not issued',
                'blocked' => true,
                'message' => 'NHIF pre-approval request has been sent but is not yet issued. Please issue the pre-approval request before scheduling and examination.',
                'session_id' => $sessionId,
                'can_issue' => $canIssue,
            ];
        }

        $latestSessionId = $sessionItems->sortByDesc('id')->first()?->nhif_pre_approval_session_id;

        return [
            'status' => self::STATUS_AUTHORIZED,
            'label' => 'Issued & Cleared',
            'blocked' => false,
            'message' => 'NHIF pre-approval has been issued.',
            'session_id' => $latestSessionId,
            'can_issue' => false,
        ];
    }

    /**
     * Resolve the NHIF pre-approval status for a single radiology order item.
     * Returns null if the patient is not covered by an active NHIF insurance.
     *
     * @return array{status: string, label: string, blocked: bool, message: string, session_id?: int|null, can_issue?: bool}|null
     */
    public function statusForRadiologyOrderItem(RadiologyOrderItem $item): ?array
    {
        if ($item->status === RadiologyOrderItem::STATUS_CANCELLED) {
            return [
                'status' => self::STATUS_AUTHORIZED,
                'label' => 'Cancelled',
                'blocked' => false,
                'message' => 'Radiology item is cancelled.',
                'session_id' => null,
                'can_issue' => false,
            ];
        }

        $order = $item->radiologyOrder;
        if (! $order) {
            return null;
        }

        $sessionItem = NhifPreApprovalSessionItem::query()
            ->where('source_type', NhifPreApprovalSessionItem::SOURCE_RADIOLOGY)
            ->where('source_id', $item->id)
            ->with('session')
            ->latest('id')
            ->first();

        if (! $this->isOrderNhifCovered($order) && ! $sessionItem) {
            return null;
        }

        if (! $sessionItem) {
            return [
                'status' => self::STATUS_PENDING_REQUEST,
                'label' => 'Pre-approval Required',
                'blocked' => true,
                'message' => "NHIF pre-approval has not been requested for procedure '{$item->procedure_name}'.",
                'session_id' => null,
                'can_issue' => false,
            ];
        }

        if ($sessionItem->approval_status !== NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED) {
            $session = $sessionItem->session;
            $canIssue = $session && in_array($session->status, [
                NhifPreApprovalSession::STATUS_ACCEPTED,
                NhifPreApprovalSession::STATUS_APPROVED,
                NhifPreApprovalSession::STATUS_PARTIALLY_APPROVED,
            ], true);

            return [
                'status' => self::STATUS_SENT_NOT_ISSUED,
                'label' => 'Request sent but not issued',
                'blocked' => true,
                'message' => "NHIF pre-approval request for '{$item->procedure_name}' is not yet issued.",
                'session_id' => $sessionItem->nhif_pre_approval_session_id,
                'can_issue' => $canIssue,
            ];
        }

        return [
            'status' => self::STATUS_AUTHORIZED,
            'label' => 'Issued & Cleared',
            'blocked' => false,
            'message' => 'NHIF pre-approval has been issued.',
            'session_id' => $sessionItem->nhif_pre_approval_session_id,
            'can_issue' => false,
        ];
    }

    /**
     * Batch resolve statuses for a collection of radiology orders.
     *
     * @param  Collection<int, RadiologyOrder>  $orders
     * @return array<int, array{status: string, label: string, blocked: bool, message: string, session_id?: int|null, can_issue?: bool}|null> order_id => result
     */
    public function batchRadiologyOrderStatuses(Collection $orders): array
    {
        $nhifOrders = [];
        $allItemIds = [];
        $itemIdsByOrderId = [];

        foreach ($orders as $order) {
            if ($this->isOrderNhifCovered($order)) {
                $nhifOrders[$order->id] = $order;
                $itemIds = $order->items
                    ->where('status', '!=', RadiologyOrderItem::STATUS_CANCELLED)
                    ->pluck('id')
                    ->all();
                $itemIdsByOrderId[$order->id] = $itemIds;
                $allItemIds = array_merge($allItemIds, $itemIds);
            }
        }

        if (empty($nhifOrders)) {
            return [];
        }

        $sessionItems = NhifPreApprovalSessionItem::query()
            ->where('source_type', NhifPreApprovalSessionItem::SOURCE_RADIOLOGY)
            ->whereIn('source_id', array_unique($allItemIds))
            ->with('session')
            ->get()
            ->groupBy('source_id');

        $result = [];
        foreach ($nhifOrders as $orderId => $order) {
            $itemIds = $itemIdsByOrderId[$orderId] ?? [];
            if (empty($itemIds)) {
                $result[$orderId] = [
                    'status' => self::STATUS_AUTHORIZED,
                    'label' => 'Cleared',
                    'blocked' => false,
                    'message' => 'No active radiology procedures.',
                    'session_id' => null,
                    'can_issue' => false,
                ];

                continue;
            }

            $orderSessionItems = collect();
            $missingItems = false;
            foreach ($itemIds as $id) {
                $found = $sessionItems->get($id);
                if (! $found || $found->isEmpty()) {
                    $missingItems = true;
                } else {
                    $orderSessionItems = $orderSessionItems->merge($found);
                }
            }

            if ($orderSessionItems->isEmpty()) {
                $result[$orderId] = [
                    'status' => self::STATUS_PENDING_REQUEST,
                    'label' => 'Pre-approval Required',
                    'blocked' => true,
                    'message' => 'NHIF pre-approval has not been requested for these radiology procedures.',
                    'session_id' => null,
                    'can_issue' => false,
                ];

                continue;
            }

            if ($missingItems) {
                $result[$orderId] = [
                    'status' => self::STATUS_PENDING_REQUEST,
                    'label' => 'Pre-approval Required',
                    'blocked' => true,
                    'message' => 'One or more procedures in this order do not have a pre-approval request.',
                    'session_id' => null,
                    'can_issue' => false,
                ];

                continue;
            }

            $issuedItemIds = $orderSessionItems
                ->where('approval_status', NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED)
                ->pluck('source_id')
                ->unique()
                ->all();

            $unissuedItemIds = array_diff($itemIds, $issuedItemIds);

            if (! empty($unissuedItemIds)) {
                $activeUnissued = $orderSessionItems
                    ->whereIn('source_id', $unissuedItemIds)
                    ->where('approval_status', '!=', NhifPreApprovalSessionItem::APPROVAL_STATUS_REJECTED)
                    ->sortByDesc('id')
                    ->first();
                $sessionId = $activeUnissued?->nhif_pre_approval_session_id;
                $session = $activeUnissued?->session;
                $canIssue = $session && in_array($session->status, [
                    NhifPreApprovalSession::STATUS_ACCEPTED,
                    NhifPreApprovalSession::STATUS_APPROVED,
                    NhifPreApprovalSession::STATUS_PARTIALLY_APPROVED,
                ], true);

                $result[$orderId] = [
                    'status' => self::STATUS_SENT_NOT_ISSUED,
                    'label' => 'Request sent but not issued',
                    'blocked' => true,
                    'message' => 'NHIF pre-approval request has been sent but is not yet issued.',
                    'session_id' => $sessionId,
                    'can_issue' => $canIssue,
                ];

                continue;
            }

            $latestSessionId = $orderSessionItems->sortByDesc('id')->first()?->nhif_pre_approval_session_id;

            $result[$orderId] = [
                'status' => self::STATUS_AUTHORIZED,
                'label' => 'Issued & Cleared',
                'blocked' => false,
                'message' => 'NHIF pre-approval has been issued.',
                'session_id' => $latestSessionId,
                'can_issue' => false,
            ];
        }

        return $result;
    }

    /**
     * Assert that scheduling can proceed for the radiology order.
     * Throws ValidationException if blocked.
     *
     * @throws ValidationException
     */
    public function assertRadiologyOrderCanSchedule(RadiologyOrder $order): void
    {
        $status = $this->statusForRadiologyOrder($order);
        if ($status !== null && $status['blocked']) {
            throw ValidationException::withMessages([
                'nhif' => $status['message'],
            ]);
        }
    }

    /**
     * Assert that scheduling can proceed for the individual radiology order item.
     * Throws ValidationException if blocked.
     *
     * @throws ValidationException
     */
    public function assertRadiologyOrderItemCanSchedule(RadiologyOrderItem $item): void
    {
        $status = $this->statusForRadiologyOrderItem($item);
        if ($status !== null && $status['blocked']) {
            throw ValidationException::withMessages([
                'nhif' => $status['message'],
            ]);
        }
    }

    /**
     * Assert that examination can proceed for the radiology order item.
     * Throws ValidationException if blocked.
     *
     * @throws ValidationException
     */
    public function assertRadiologyOrderItemCanExamine(RadiologyOrderItem $item): void
    {
        $status = $this->statusForRadiologyOrderItem($item);
        if ($status !== null && $status['blocked']) {
            throw ValidationException::withMessages([
                'nhif' => $status['message'],
            ]);
        }
    }
}
