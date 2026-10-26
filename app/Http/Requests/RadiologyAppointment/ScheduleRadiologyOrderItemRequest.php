<?php

namespace App\Http\Requests\RadiologyAppointment;

use App\Models\RadiologyOrderItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ScheduleRadiologyOrderItemRequest extends FormRequest
{
    /**
     * The base 'radiology-scheduling.create' permission is checked here;
     * since no RadiologyAppointment instance exists yet, the hospital-
     * scoping restriction for hospital-admins is enforced against the
     * hospital of the order item's own encounter, mirroring
     * StoreAppointmentRequest's identical pre-creation authorization shape.
     */
    public function authorize(): bool
    {
        if (! $this->user()->can('radiology-scheduling.create')) {
            return false;
        }

        if (! $this->user()->hasRole('hospital-admin')) {
            return true;
        }

        $item = RadiologyOrderItem::query()
            ->with('radiologyOrder.clinicalEncounter.department')
            ->find($this->input('radiology_order_item_id'));

        return $item !== null
            && $this->user()->staff?->hospital_id === $item->radiologyOrder->clinicalEncounter->department->hospital_id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'radiology_order_item_id' => ['required', 'integer', 'exists:radiology_order_items,id'],
            'resource_name' => ['required', 'string', 'max:255'],
            'scheduled_start' => ['required', 'date', 'after_or_equal:today'],
            'scheduled_end' => ['required', 'date', 'after:scheduled_start'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
