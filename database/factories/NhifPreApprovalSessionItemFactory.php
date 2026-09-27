<?php

namespace Database\Factories;

use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NhifPreApprovalSessionItem>
 */
class NhifPreApprovalSessionItemFactory extends Factory
{
    protected $model = NhifPreApprovalSessionItem::class;

    public function definition(): array
    {
        return [
            'nhif_pre_approval_session_id' => NhifPreApprovalSession::factory(),
            'source_type' => $this->faker->randomElement([
                NhifPreApprovalSessionItem::SOURCE_CONSULTATION,
                NhifPreApprovalSessionItem::SOURCE_LAB,
                NhifPreApprovalSessionItem::SOURCE_MEDICATION,
            ]),
            'source_id' => null,
            'nhif_item_code' => strtoupper($this->faker->bothify('NHS####')),
            'nhif_item_name' => $this->faker->words(3, true),
            'nhif_requested_service_id' => null,
            'nhif_request_id' => null,
            'nhif_item_name_returned' => null,
            'nhif_status' => null,
            'nhif_requires_approval' => null,
            'nhif_effective_date' => null,
            'nhif_end_date' => null,
            'nhif_available_after_date' => null,
            'nhif_percent_covered' => null,
            'quantity' => $this->faker->numberBetween(1, 5),
            'unit_price' => $this->faker->randomFloat(2, 500, 50000),
            'approval_reference_no' => null,
            'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_PENDING,
            'quantity_approved' => null,
            'quantity_issued' => null,
            'rejection_reason' => null,
            'nhif_rejection_details' => null,
            'nhif_rejection_reason_code' => null,
        ];
    }

    /** Accepted directly from RequestServices (requiresApproval=false) — no approval flow needed. */
    public function accepted(): static
    {
        return $this->state([
            'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ACCEPTED,
            'nhif_requested_service_id' => (string) Str::uuid(),
            'nhif_status' => 'Accepted',
            'nhif_requires_approval' => false,
            'nhif_available_after_date' => now()->addDays(4),
            'quantity_approved' => 1,
        ]);
    }

    /** Approved via NHIF approval flow (requiresApproval was true). */
    public function approved(): static
    {
        return $this->state([
            'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_APPROVED,
            'approval_reference_no' => strtoupper($this->faker->bothify('REF-####-????')),
            'nhif_status' => 'Accepted',
            'nhif_requires_approval' => true,
            'quantity_approved' => 1,
        ]);
    }

    public function pendingApproval(): static
    {
        return $this->state([
            'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_PENDING_APPROVAL,
            'nhif_requested_service_id' => (string) Str::uuid(),
            'nhif_status' => 'Accepted',
            'nhif_requires_approval' => true,
        ]);
    }

    public function rejected(): static
    {
        return $this->state([
            'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_REJECTED,
            'nhif_status' => 'Rejected',
            'nhif_requires_approval' => false,
            'rejection_reason' => 'Service not covered under patient plan.',
            'nhif_rejection_details' => 'Service not covered under patient plan.',
        ]);
    }

    public function issued(): static
    {
        return $this->state([
            'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED,
            'nhif_requested_service_id' => (string) Str::uuid(),
            'nhif_status' => 'Accepted',
            'nhif_requires_approval' => false,
            'quantity_approved' => 1,
            'quantity_issued' => 1,
        ]);
    }
}
