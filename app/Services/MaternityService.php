<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\ClinicalEncounter;
use App\Models\DeliveryRecord;
use App\Models\MaternityProfile;
use App\Models\ObservationEscalation;
use App\Models\PartographEntry;
use App\Models\Patient;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Domain service for Maternity Nursing, Labour Monitoring & WHO-compliant Partograph.
 */
class MaternityService
{
    public function __construct(
        protected AuditLogService $auditLog,
        protected MaternityEligibilityService $eligibilityService,
    ) {}

    /**
     * Create or update a patient's maternity and labour profile under an active admission or encounter.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function saveProfile(Admission $admission, array $data, Request $request): MaternityProfile
    {
        if ($admission->status !== Admission::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'admission_id' => 'Maternity profile can only be documented for an active admission.',
            ]);
        }

        $patient = $admission->patient;

        // Check if updating an existing active profile for this admission
        $existingProfile = MaternityProfile::query()
            ->where('admission_id', $admission->id)
            ->where('status', 'active')
            ->first();

        // Enforce centralized maternity eligibility
        $exceptionReason = $data['clinical_exception_reason'] ?? null;
        $this->eligibilityService->assertEligibleForInitiation(
            $patient,
            $exceptionReason,
            $request->user(),
            $existingProfile?->id
        );

        // Automatic EDD & Gestational Age calculation from LMP if not explicitly provided
        if (! empty($data['last_menstrual_period'])) {
            $lmp = Carbon::parse($data['last_menstrual_period']);
            if (empty($data['estimated_date_of_delivery'])) {
                // Naegele's rule: LMP + 280 days
                $data['estimated_date_of_delivery'] = $lmp->copy()->addDays(280)->toDateString();
            }
            if (empty($data['gestational_age_weeks'])) {
                $days = $lmp->diffInDays(now());
                $data['gestational_age_weeks'] = round($days / 7, 1);
            }
        }

        if (filled($exceptionReason)) {
            $data['clinical_exception_reason'] = $exceptionReason;
            $data['exception_authorized_by'] = $request->user()->id;
        }

        $profile = MaternityProfile::updateOrCreate(
            ['admission_id' => $admission->id, 'status' => 'active'],
            [
                ...$data,
                'patient_id' => $admission->patient_id,
                'recorded_by' => $existingProfile ? $existingProfile->recorded_by : $request->user()->id,
            ]
        );

        if (filled($exceptionReason) && (! $existingProfile || empty($existingProfile->clinical_exception_reason))) {
            $this->auditLog->record(
                'maternity_eligibility_exception_granted',
                $profile,
                null,
                [
                    'patient_id' => $patient->id,
                    'patient_gender' => $patient->gender,
                    'reason' => $exceptionReason,
                    'authorized_by' => $request->user()->id,
                ],
                $request,
            );
        }

        $this->auditLog->record(
            'maternity_profile_saved',
            $profile,
            null,
            [
                'admission_id' => $admission->id,
                'episode_number' => $profile->episode_number,
                'care_stage' => $profile->care_stage,
                'gravida' => $profile->gravida,
                'para' => $profile->para,
            ],
            $request,
        );

        return $profile;
    }

    /**
     * Transition the maternity care stage (e.g. awaiting_assessment -> in_labour -> delivery_in_progress -> postpartum_care).
     *
     * @throws ValidationException
     */
    public function transitionStage(MaternityProfile $profile, string $targetStage, Request $request, ?string $notes = null): MaternityProfile
    {
        if (! array_key_exists($targetStage, MaternityProfile::CARE_STAGES)) {
            throw ValidationException::withMessages([
                'care_stage' => "Invalid maternity care stage \"{$targetStage}\".",
            ]);
        }

        if (! $profile->canTransitionToStage($targetStage)) {
            $currentName = MaternityProfile::CARE_STAGES[$profile->care_stage] ?? $profile->care_stage;
            $targetName = MaternityProfile::CARE_STAGES[$targetStage] ?? $targetStage;
            throw ValidationException::withMessages([
                'care_stage' => "Cannot transition maternity episode directly from \"{$currentName}\" to \"{$targetName}\".",
            ]);
        }

        $oldStage = $profile->care_stage;
        $updates = [
            'care_stage' => $targetStage,
            'stage_updated_at' => now(),
            'stage_updated_by' => $request->user()->id,
        ];

        if (in_array($targetStage, [MaternityProfile::CARE_STAGE_COMPLETED, MaternityProfile::CARE_STAGE_DISCHARGED, MaternityProfile::CARE_STAGE_TRANSFERRED], true)) {
            $updates['status'] = 'completed';
        }

        if ($notes) {
            $updates['notes'] = ($profile->notes ? $profile->notes . "\n" : '') . "[Stage change to {$targetStage}]: " . $notes;
        }

        $profile->update($updates);

        $this->auditLog->record(
            'maternity_stage_transitioned',
            $profile,
            ['care_stage' => $oldStage],
            [
                'care_stage' => $targetStage,
                'notes' => $notes,
                'updated_by' => $request->user()->id,
            ],
            $request,
        );

        return $profile;
    }

    /**
     * Record a WHO-compliant Partograph observation entry.
     *
     * Computes alert/action lines during the active phase of labour (cervical dilation >= 4 cm).
     * Automatically raises an ObservationEscalation when action line is crossed or fetal distress is flagged.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function recordPartograph(MaternityProfile $profile, array $data, Request $request): PartographEntry
    {
        if ($profile->status !== 'active') {
            throw ValidationException::withMessages([
                'maternity_profile_id' => 'Partograph entries cannot be added to an inactive or completed maternity profile.',
            ]);
        }

        $recordedAt = isset($data['recorded_at']) ? Carbon::parse($data['recorded_at']) : now();
        $dilation = isset($data['cervical_dilation_cm']) ? (float) $data['cervical_dilation_cm'] : null;

        $alertLineDilation = null;
        $actionLineDilation = null;
        $actionLineCrossed = false;

        // Active phase partograph calculation (WHO guideline: active phase starts at 4 cm)
        if ($dilation !== null) {
            // Find the initial active phase entry (first entry where dilation >= 4 cm)
            $firstActiveEntry = $profile->partographEntries()
                ->where('cervical_dilation_cm', '>=', 4.0)
                ->orderBy('recorded_at')
                ->first();

            $activeStartAt = $firstActiveEntry ? $firstActiveEntry->recorded_at : ($dilation >= 4.0 ? $recordedAt : null);

            if ($activeStartAt !== null) {
                $hoursElapsed = max(0, $activeStartAt->diffInMinutes($recordedAt) / 60);

                // Alert line starts at 4 cm and advances at 1 cm per hour up to 10 cm
                $alertLineDilation = min(10.0, 4.0 + (1.0 * $hoursElapsed));

                // Action line is 4 hours to the right of the alert line
                if ($hoursElapsed >= 4.0) {
                    $actionLineDilation = min(10.0, 4.0 + (1.0 * ($hoursElapsed - 4.0)));
                    if ($dilation < $actionLineDilation) {
                        $actionLineCrossed = true;
                    }
                }
            }
        }

        $entry = PartographEntry::create([
            ...$data,
            'maternity_profile_id' => $profile->id,
            'admission_id' => $profile->admission_id,
            'patient_id' => $profile->patient_id,
            'recorded_at' => $recordedAt,
            'alert_line_dilation_cm' => $alertLineDilation ? round($alertLineDilation, 1) : null,
            'action_line_dilation_cm' => $actionLineDilation ? round($actionLineDilation, 1) : null,
            'action_line_crossed' => $actionLineCrossed,
            'recorded_by' => $request->user()->id,
        ]);

        // Evaluate critical obstetrics triggers for auto-escalation
        $criticalTriggers = [];
        if ($actionLineCrossed) {
            $criticalTriggers[] = 'Partograph action line crossed (delayed labour progress)';
        }
        if (! empty($entry->fetal_heart_rate) && ($entry->fetal_heart_rate < 110 || $entry->fetal_heart_rate > 160)) {
            $criticalTriggers[] = "Abnormal FHR ({$entry->fetal_heart_rate} bpm)";
        }
        if ($entry->amniotic_fluid === 'M') {
            $criticalTriggers[] = 'Meconium-stained liquor detected';
        }
        if ($entry->moulding === '+++') {
            $criticalTriggers[] = 'Severe moulding (+++) suspected CPD';
        }
        if (! empty($entry->systolic_bp) && $entry->systolic_bp >= 160) {
            $criticalTriggers[] = "Severe maternal hypertension ({$entry->systolic_bp}/{$entry->diastolic_bp} mmHg)";
        }

        if (! empty($criticalTriggers)) {
            $reasonSummary = implode('; ', $criticalTriggers);
            ObservationEscalation::create([
                'admission_id' => $profile->admission_id,
                'patient_id' => $profile->patient_id,
                'source' => ObservationEscalation::SOURCE_AUTO,
                'reason' => "Maternity/Labour Alert: {$reasonSummary}",
                'flags' => $criticalTriggers,
                'raised_by' => $request->user()->id,
                'raised_at' => now(),
                'status' => ObservationEscalation::STATUS_OPEN,
            ]);
        }

        $this->auditLog->record(
            'partograph_entry_recorded',
            $entry,
            null,
            ['profile_id' => $profile->id, 'dilation' => $entry->cervical_dilation_cm, 'action_line_crossed' => $actionLineCrossed],
            $request,
        );

        return $entry;
    }

    /**
     * Document delivery outcome for mother and newborn.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function recordDelivery(MaternityProfile $profile, array $data, Request $request): DeliveryRecord
    {
        $deliveredAt = isset($data['delivered_at']) ? Carbon::parse($data['delivered_at']) : now();
        $bloodLoss = isset($data['blood_loss_ml']) ? (int) $data['blood_loss_ml'] : 0;
        $mode = $data['delivery_mode'] ?? 'spontaneous_vaginal';

        // PPH threshold: >= 500ml for vaginal delivery, >= 1000ml for C-Section
        $isPph = $bloodLoss >= 500;
        if (str_contains($mode, 'c_section') && $bloodLoss < 1000) {
            $isPph = false;
        }
        if (! empty($data['postpartum_hemorrhage'])) {
            $isPph = true;
        }

        $record = DeliveryRecord::create([
            ...$data,
            'maternity_profile_id' => $profile->id,
            'admission_id' => $profile->admission_id,
            'patient_id' => $profile->patient_id,
            'delivered_at' => $deliveredAt,
            'blood_loss_ml' => $bloodLoss,
            'postpartum_hemorrhage' => $isPph,
            'recorded_by' => $request->user()->id,
        ]);

        // Transition maternity profile labour stage to delivered and care_stage to postpartum_care
        $profile->update([
            'labour_stage' => 'delivered',
            'care_stage' => MaternityProfile::CARE_STAGE_POSTPARTUM_CARE,
            'status' => 'completed',
        ]);

        if ($isPph) {
            ObservationEscalation::create([
                'admission_id' => $profile->admission_id,
                'patient_id' => $profile->patient_id,
                'source' => ObservationEscalation::SOURCE_AUTO,
                'reason' => "Postpartum Hemorrhage documented (EBL: {$bloodLoss} ml)",
                'flags' => ['PPH', "EBL: {$bloodLoss} ml"],
                'raised_by' => $request->user()->id,
                'raised_at' => now(),
                'status' => ObservationEscalation::STATUS_OPEN,
            ]);
        }

        $this->auditLog->record(
            'delivery_record_created',
            $record,
            null,
            ['admission_id' => $profile->admission_id, 'mode' => $record->delivery_mode, 'outcome' => $record->baby_outcome],
            $request,
        );

        return $record;
    }
}
