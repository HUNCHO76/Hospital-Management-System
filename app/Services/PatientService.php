<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientInsurance;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PatientService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * The columns returned for a duplicate reference — enough for staff to
     * identify the existing patient, never sensitive clinical data.
     *
     * @var list<string>
     */
    protected array $duplicateColumns = ['id', 'patient_number', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'phone', 'national_id', 'passport_number'];

    /**
     * Find a CONFIRMED duplicate: an existing patient sharing a strong,
     * government-issued identifier (national ID or passport). A confirmed match
     * blocks registration — the same identifier cannot belong to two people.
     *
     * @param  array<string, mixed>  $data
     */
    public function findConfirmedDuplicate(array $data): ?Patient
    {
        $nationalId = filled($data['national_id'] ?? null) ? $data['national_id'] : null;
        $passport = filled($data['passport_number'] ?? null) ? $data['passport_number'] : null;

        if (! $nationalId && ! $passport) {
            return null;
        }

        return Patient::query()
            ->where(function ($query) use ($nationalId, $passport) {
                if ($nationalId) {
                    $query->orWhere('national_id', $nationalId);
                }

                if ($passport) {
                    $query->orWhere('passport_number', $passport);
                }
            })
            ->first($this->duplicateColumns);
    }

    /**
     * Find POSSIBLE duplicates: existing patients whose weaker demographic
     * signals resemble the person being registered (same phone, or same name +
     * date of birth). This is an assistive warning staff can override — a
     * shared name or phone is never proof of a duplicate. Strong identifiers
     * are handled by findConfirmedDuplicate() instead.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, Patient>
     */
    public function findPossibleDuplicates(array $data): Collection
    {
        $phone = filled($data['phone'] ?? null) ? $data['phone'] : null;
        $hasNameAndDob = ! empty($data['first_name']) && ! empty($data['last_name']) && ! empty($data['date_of_birth']);

        if (! $phone && ! $hasNameAndDob) {
            return new Collection;
        }

        return Patient::query()
            ->where(function ($query) use ($phone, $hasNameAndDob, $data) {
                if ($phone) {
                    $query->orWhere('phone', $phone);
                }

                if ($hasNameAndDob) {
                    $query->orWhere(function ($query) use ($data) {
                        $query->where('first_name', $data['first_name'])
                            ->where('last_name', $data['last_name'])
                            ->whereDate('date_of_birth', $data['date_of_birth']);
                    });
                }
            })
            ->limit(5)
            ->get($this->duplicateColumns);
    }

    /**
     * Create a patient and their contacts in a single transaction, and
     * record the change in the audit log.
     *
     * When $insuranceData is provided the insurance record is created in the
     * same transaction so patient + insurance are always atomic. NHIF
     * verification (an external API call) must be done by the caller AFTER
     * this method returns — never inside a database transaction.
     *
     * findConfirmedDuplicate() is only a check-then-insert — it cannot by
     * itself prevent two concurrent requests for the same national ID/
     * passport from both passing the check before either commits. The
     * `patients.national_id`/`patients.passport_number` unique indexes are
     * the actual concurrency-safe guarantee; a violation here means a
     * second request lost that race, and is translated into the same
     * clean validation error the non-raced case already produces, never a
     * raw database exception.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $insuranceData
     *
     * @throws ValidationException
     */
    public function create(array $data, Request $request, ?array $insuranceData = null): Patient
    {
        $contacts = $data['contacts'] ?? [];
        unset($data['contacts']);

        try {
            $patient = DB::transaction(function () use ($data, $contacts, $insuranceData) {
                $patient = Patient::create($data);

                foreach ($contacts as $contact) {
                    $patient->contacts()->create($contact);
                }

                if ($insuranceData !== null) {
                    $patient->insurances()->create(array_merge($insuranceData, ['is_primary' => true]));
                }

                return $patient;
            });
        } catch (QueryException $e) {
            // SQLSTATE 23000 (integrity constraint violation) is portable
            // across the MySQL driver used in production and the SQLite
            // driver used in tests — unlike the MySQL-specific 1062 error
            // code, which SQLite never reports.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            throw ValidationException::withMessages([
                'national_id' => 'A patient with this national ID or passport number was just registered by another request. Please search again before retrying.',
            ]);
        }

        $this->auditLog->record('patient_created', $patient, null, $data, $request);

        if ($insuranceData !== null) {
            /** @var PatientInsurance $insurance */
            $insurance = $patient->insurances()->latest('id')->first();
            $this->auditLog->record('patient_insurance_created', $insurance, null, $insuranceData, $request);
        }

        return $patient;
    }

    /**
     * Update a patient and replace their contacts in a single transaction,
     * and record the change in the audit log.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Patient $patient, array $data, Request $request): Patient
    {
        $contacts = $data['contacts'] ?? [];
        $correctionReason = $data['correction_reason'] ?? null;
        unset($data['contacts'], $data['correction_reason']);

        $demographicKeys = ['gender', 'date_of_birth', 'national_id', 'passport_number'];
        $demographicChanged = false;
        foreach ($demographicKeys as $key) {
            if (array_key_exists($key, $data) && (string) $data[$key] !== (string) $patient->{$key}) {
                $demographicChanged = true;
                break;
            }
        }

        if ($demographicChanged) {
            if (! $request->user()->can('patients.correct-demographics') && ! $request->user()->hasRole(['hospital-admin', 'super-admin', 'doctor'])) {
                throw ValidationException::withMessages([
                    'gender' => 'You do not have authorization to correct core patient demographics.',
                ]);
            }

            if (empty($correctionReason) || mb_strlen(trim($correctionReason)) < 10) {
                throw ValidationException::withMessages([
                    'correction_reason' => 'A documented correction reason (at least 10 characters) is required to correct patient demographics.',
                ]);
            }
        }

        $oldValues = $patient->only(array_keys($data));

        DB::transaction(function () use ($patient, $data, $contacts) {
            $patient->update($data);

            $keepIds = collect($contacts)->pluck('id')->filter()->all();
            $patient->contacts()->whereNotIn('id', $keepIds)->delete();

            foreach ($contacts as $contact) {
                if (! empty($contact['id'])) {
                    $patient->contacts()->whereKey($contact['id'])->update(collect($contact)->except('id')->all());
                } else {
                    $patient->contacts()->create(collect($contact)->except('id')->all());
                }
            }
        });

        $this->auditLog->record('patient_updated', $patient, $oldValues, $patient->only(array_keys($data)), $request);

        if ($demographicChanged) {
            $this->auditLog->record(
                'patient_demographics_corrected',
                $patient,
                $oldValues,
                [
                    ...$patient->only($demographicKeys),
                    'correction_reason' => $correctionReason,
                    'corrected_by' => $request->user()->id,
                ],
                $request
            );
        }

        return $patient;
    }

    /**
     * Dedicated demographic correction action with explicit justification and audit logging.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function correctDemographics(Patient $patient, array $data, string $reason, Request $request): Patient
    {
        if (! $request->user()->can('patients.correct-demographics') && ! $request->user()->hasRole(['hospital-admin', 'super-admin', 'doctor'])) {
            throw ValidationException::withMessages([
                'gender' => 'You do not have authorization to correct core patient demographics.',
            ]);
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages([
                'correction_reason' => 'A documented correction reason (at least 10 characters) is required.',
            ]);
        }

        $allowedKeys = ['gender', 'date_of_birth', 'national_id', 'passport_number', 'first_name', 'last_name'];
        $updateData = array_intersect_key($data, array_flip($allowedKeys));
        $oldValues = $patient->only(array_keys($updateData));

        $patient->update($updateData);

        $this->auditLog->record(
            'patient_demographics_corrected',
            $patient,
            $oldValues,
            [
                ...$updateData,
                'correction_reason' => $reason,
                'corrected_by' => $request->user()->id,
            ],
            $request
        );

        return $patient;
    }

    /**
     * Deactivate a patient: mark them inactive and soft-delete the record.
     * This is the only "removal" path for a patient — historical data is
     * never hard-deleted, and status/soft-delete state are kept in sync so
     * an inactive patient never appears active, or vice versa.
     */
    public function deactivate(Patient $patient, Request $request): void
    {
        $oldValues = $patient->only(['status']);

        DB::transaction(function () use ($patient) {
            $patient->update(['status' => Patient::STATUS_INACTIVE]);
            $patient->delete();
        });

        $this->auditLog->record('patient_deactivated', $patient, $oldValues, ['status' => Patient::STATUS_INACTIVE], $request);
    }
}
