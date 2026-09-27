<?php

namespace App\Http\Resources;

use App\Models\LaboratoryResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LaboratoryResult
 */
class LaboratoryResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'laboratory_order_item_id' => $this->laboratory_order_item_id,
            'laboratory_sample_id' => $this->laboratory_sample_id,
            'laboratory_test_id' => $this->laboratory_test_id,
            'status' => $this->status,
            'result_source' => $this->result_source,
            'result_type' => $this->result_type,
            'unit' => $this->unit,
            'reference_range' => $this->reference_range,
            'result_options' => $this->result_options,
            'value_numeric' => $this->value_numeric,
            'value_text' => $this->value_text,
            'value_code' => $this->value_code,
            'abnormal_flag' => $this->abnormal_flag,
            'result_comment' => $this->result_comment,
            'item' => $this->whenLoaded('item', fn () => $this->item ? [
                'id' => $this->item->id,
                'test_code' => $this->item->test_code,
                'test_name' => $this->item->test_name,
            ] : null),
            'sample' => $this->whenLoaded('sample', fn () => $this->sample ? [
                'id' => $this->sample->id,
                'sample_number' => $this->sample->sample_number,
                'specimen_type' => $this->sample->specimen_type,
                'container_type' => $this->sample->container_type,
                'received_at' => optional($this->sample->received_at)->toIso8601String(),
            ] : null),
            'laboratory_order' => $this->whenLoaded('item', fn () => $this->item?->laboratoryOrder ? [
                'id' => $this->item->laboratoryOrder->id,
                'order_number' => $this->item->laboratoryOrder->order_number,
                'priority' => $this->item->laboratoryOrder->priority,
            ] : null),
            'patient' => $this->whenLoaded('item', fn () => $this->patientSummary()),
            'entered_by' => $this->whenLoaded('enteredBy', fn () => $this->enteredBy ? [
                'id' => $this->enteredBy->id,
                'name' => $this->enteredBy->UserName,
            ] : null),
            'entered_at' => optional($this->entered_at)->toIso8601String(),
            'submitted_by' => $this->whenLoaded('submittedBy', fn () => $this->submittedBy ? [
                'id' => $this->submittedBy->id,
                'name' => $this->submittedBy->UserName,
            ] : null),
            'submitted_at' => optional($this->submitted_at)->toIso8601String(),
            'amended_by' => $this->whenLoaded('amendedBy', fn () => $this->amendedBy ? [
                'id' => $this->amendedBy->id,
                'name' => $this->amendedBy->UserName,
            ] : null),
            'amended_at' => optional($this->amended_at)->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy ? [
                'id' => $this->reviewedBy->id,
                'name' => $this->reviewedBy->UserName,
            ] : null),
            'reviewed_at' => optional($this->reviewed_at)->toIso8601String(),
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy ? [
                'id' => $this->verifiedBy->id,
                'name' => $this->verifiedBy->UserName,
            ] : null),
            'verified_at' => optional($this->verified_at)->toIso8601String(),
            'verification_comment' => $this->verification_comment,
            'authorized_by' => $this->whenLoaded('authorizedBy', fn () => $this->authorizedBy ? [
                'id' => $this->authorizedBy->id,
                'name' => $this->authorizedBy->UserName,
            ] : null),
            'authorized_at' => optional($this->authorized_at)->toIso8601String(),
            'returned_by' => $this->whenLoaded('returnedBy', fn () => $this->returnedBy ? [
                'id' => $this->returnedBy->id,
                'name' => $this->returnedBy->UserName,
            ] : null),
            'returned_at' => optional($this->returned_at)->toIso8601String(),
            'return_reason' => $this->return_reason,
            'return_notes' => $this->return_notes,
            'released_by' => $this->whenLoaded('releasedBy', fn () => $this->releasedBy ? [
                'id' => $this->releasedBy->id,
                'name' => $this->releasedBy->UserName,
            ] : null),
            'released_at' => optional($this->released_at)->toIso8601String(),
            'release_comment' => $this->release_comment,
            'is_ready_for_release' => $this->isReadyForRelease(),
            'is_released' => $this->isReleased(),
            'active_report' => $this->whenLoaded('item', fn () => $this->activeReportSummary()),
            'result_values' => $this->whenLoaded('resultValues', fn () => $this->resultValues->map(fn ($rv) => [
                'id' => $rv->id,
                'laboratory_test_parameter_id' => $rv->laboratory_test_parameter_id,
                'parameter' => $rv->relationLoaded('parameter') && $rv->parameter ? [
                    'id' => $rv->parameter->id,
                    'code' => $rv->parameter->code,
                    'name' => $rv->parameter->name,
                    'data_type' => $rv->parameter->data_type,
                    'unit' => $rv->parameter->unit,
                    'decimal_places' => $rv->parameter->decimal_places,
                    'options_json' => $rv->parameter->options_json,
                    'display_order' => $rv->parameter->display_order,
                ] : null,
                'value_numeric' => $rv->value_numeric,
                'value_text' => $rv->value_text,
                'value_code' => $rv->value_code,
                'unit' => $rv->unit,
                'reference_low' => $rv->reference_low,
                'reference_high' => $rv->reference_high,
                'reference_critical_low' => $rv->reference_critical_low,
                'reference_critical_high' => $rv->reference_critical_high,
                'abnormal_flag' => $rv->abnormal_flag,
                'result_comment' => $rv->result_comment,
            ])->sortBy(fn ($rv) => $rv['parameter']['display_order'] ?? 0)->values()->all()),
            'laboratory_test' => $this->whenLoaded('laboratoryTest', fn () => $this->laboratoryTest ? [
                'id' => $this->laboratoryTest->id,
                'code' => $this->laboratoryTest->code,
                'name' => $this->laboratoryTest->name,
                'active_parameters' => $this->laboratoryTest->relationLoaded('activeParameters')
                    ? $this->laboratoryTest->activeParameters->map(fn ($p) => [
                        'id' => $p->id,
                        'code' => $p->code,
                        'name' => $p->name,
                        'data_type' => $p->data_type,
                        'unit' => $p->unit,
                        'decimal_places' => $p->decimal_places,
                        'options_json' => $p->options_json,
                        'display_order' => $p->display_order,
                        'reference_ranges' => $p->relationLoaded('referenceRanges')
                            ? $p->referenceRanges->map(fn ($rr) => [
                                'id' => $rr->id,
                                'sex' => $rr->sex,
                                'age_min_days' => $rr->age_min_days,
                                'age_max_days' => $rr->age_max_days,
                                'low' => $rr->low,
                                'high' => $rr->high,
                                'critical_low' => $rr->critical_low,
                                'critical_high' => $rr->critical_high,
                            ])->all()
                            : [],
                    ])->all()
                    : [],
            ] : null),
            'documents' => $this->whenLoaded('resultDocuments', fn () => $this->resultDocuments->map(fn ($doc) => [
                'id' => $doc->id,
                'original_filename' => $doc->original_filename,
                'mime_type' => $doc->mime_type,
                'size_bytes' => $doc->size_bytes,
                'uploaded_by' => $doc->uploadedBy ? ['id' => $doc->uploadedBy->id, 'name' => $doc->uploadedBy->UserName] : null,
                'uploaded_at' => optional($doc->created_at)->toIso8601String(),
            ])->all()),
            'history' => $this->history(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }

    /**
     * The order's currently active report version, if one has ever been
     * generated — used to link straight from a released result to its
     * printable document (spec Part 37).
     *
     * @return array<string, mixed>|null
     */
    protected function activeReportSummary(): ?array
    {
        $order = $this->item?->laboratoryOrder;

        if (! $order || ! $order->relationLoaded('activeReport') || ! $order->activeReport) {
            return null;
        }

        return [
            'id' => $order->activeReport->id,
            'report_number' => $order->activeReport->report_number,
            'version' => $order->activeReport->version,
        ];
    }

    /**
     * Minimum-necessary patient identification (spec §30) — never the
     * unrelated diagnoses/medications/encounters a full patient record
     * would carry.
     *
     * @return array<string, mixed>|null
     */
    protected function patientSummary(): ?array
    {
        $patient = $this->item?->laboratoryOrder?->clinicalEncounter?->patient;

        if (! $patient) {
            return null;
        }

        return [
            'id' => $patient->id,
            'patient_number' => $patient->patient_number,
            'first_name' => $patient->first_name,
            'middle_name' => $patient->middle_name,
            'last_name' => $patient->last_name,
            'date_of_birth' => optional($patient->date_of_birth)->toDateString(),
            'gender' => $patient->gender,
        ];
    }

    /**
     * A simple derived timeline from this result's own lifecycle timestamps
     * (spec §31) — the full forensic audit trail lives in the existing
     * Audit Log viewer.
     *
     * @return list<array{event: string, at: string, actor: string|null}>
     */
    protected function history(): array
    {
        $entries = [
            ['event' => 'created', 'at' => $this->created_at, 'actor' => null],
            ['event' => 'entered', 'at' => $this->entered_at, 'actor' => $this->enteredBy?->UserName],
            ['event' => 'submitted', 'at' => $this->submitted_at, 'actor' => $this->submittedBy?->UserName],
            ['event' => 'returned', 'at' => $this->returned_at, 'actor' => $this->returnedBy?->UserName],
            ['event' => 'reviewed', 'at' => $this->reviewed_at, 'actor' => $this->reviewedBy?->UserName],
            ['event' => 'verified', 'at' => $this->verified_at, 'actor' => $this->verifiedBy?->UserName],
            ['event' => 'authorized', 'at' => $this->authorized_at, 'actor' => $this->authorizedBy?->UserName],
            ['event' => 'released', 'at' => $this->released_at, 'actor' => $this->releasedBy?->UserName],
            ['event' => 'amended', 'at' => $this->amended_at, 'actor' => $this->amendedBy?->UserName],
        ];

        return collect($entries)
            ->filter(fn ($entry) => $entry['at'] !== null)
            ->sortBy('at')
            ->map(fn ($entry) => [...$entry, 'at' => $entry['at']->toIso8601String()])
            ->values()
            ->all();
    }
}
