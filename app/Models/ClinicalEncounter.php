<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\ClinicalEncounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The clinical episode of care a patient receives from the health service —
 * distinct from ReceptionVisit, which represents only the administrative
 * front-desk arrival and preparation. A reception visit may lead to a
 * clinical encounter; the encounter is the parent/context that future
 * clinical modules (triage, consultation, diagnosis, treatment) will attach
 * to. Step 21 establishes only this foundation — no clinical content is
 * modeled here yet.
 */
class ClinicalEncounter extends Model
{
    /** @use HasFactory<ClinicalEncounterFactory> */
    use GeneratesBusinessNumber, HasFactory;

    /**
     * The prefix used when generating encounter numbers.
     */
    public const NUMBER_PREFIX = 'ENC';

    /**
     * Status values recognized for a clinical encounter.
     */
    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Statuses that count as an "active" encounter — the set that blocks a
     * patient from having a second concurrent clinical encounter created.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
    ];

    /**
     * The controlled set of encounter types recognized by the system.
     */
    public const TYPES = ['opd', 'walk_in', 'scheduled', 'follow_up', 'inpatient'];

    /**
     * The clinical outcome recorded when an encounter is completed (spec
     * §16/42, Step 27) — distinct from `status`, which is a lifecycle state,
     * not an outcome. Each of these is a handoff boundary for a future
     * module (follow-up -> Appointment module, admitted -> a future
     * admission module, referred -> the existing CarePlanItem referral
     * fields) rather than a workflow this step implements itself.
     */
    public const DISPOSITION_COMPLETED = 'completed';

    public const DISPOSITION_FOLLOW_UP = 'follow_up';

    public const DISPOSITION_REFERRED = 'referred';

    public const DISPOSITION_ADMITTED = 'admitted';

    public const DISPOSITION_TRANSFERRED = 'transferred';

    /**
     * @var list<string>
     */
    public const DISPOSITIONS = [
        self::DISPOSITION_COMPLETED,
        self::DISPOSITION_FOLLOW_UP,
        self::DISPOSITION_REFERRED,
        self::DISPOSITION_ADMITTED,
        self::DISPOSITION_TRANSFERRED,
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * ClinicalEncounterService — never trust a status change supplied
     * directly by the frontend. Reopening a completed/cancelled encounter is
     * deliberately not modeled here; a future controlled correction workflow
     * would need to introduce it explicitly.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'patient_id',
        'reception_visit_id',
        'appointment_id',
        'admission_id',
        'department_id',
        'practitioner_id',
        'encounter_type',
        'status',
        'disposition',
        'disposition_notes',
        'started_at',
        'ended_at',
        'created_by',
        'closed_by',
        'cancellation_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $encounter): void {
            if (empty($encounter->encounter_number)) {
                $encounter->encounter_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'encounter_number');
            }
        });
    }

    /**
     * Determine whether a transition from the current status to the given
     * status is allowed.
     */
    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * The patient this encounter is for.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * The reception visit this encounter originated from, if any.
     */
    public function receptionVisit(): BelongsTo
    {
        return $this->belongsTo(ReceptionVisit::class);
    }

    /**
     * The scheduled appointment this encounter originated from, if any.
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * The inpatient admission this encounter belongs to, if it is an
     * inpatient encounter (encounter_type = 'inpatient').
     */
    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    /**
     * The department responsible for this encounter.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The practitioner assigned to this encounter, if known yet — future
     * consultation workflow is responsible for assigning one when the
     * hospital's workflow doesn't require it at creation time.
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    /**
     * The user who created this encounter.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who closed (completed or cancelled) this encounter, if any.
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * This encounter's triage assessment, if one has been started (Step 22).
     * At most one exists per encounter — see TriageAssessment's class docs.
     */
    public function triageAssessment(): HasOne
    {
        return $this->hasOne(TriageAssessment::class);
    }

    /**
     * This encounter's doctor consultation, if one has been started
     * (Step 23). At most one exists per encounter — see Consultation's class
     * docs.
     */
    public function consultation(): HasOne
    {
        return $this->hasOne(Consultation::class);
    }

    /**
     * This encounter's diagnoses (Step 24), oldest first. Multiple may
     * exist — see EncounterDiagnosis's class docs.
     */
    public function diagnoses(): HasMany
    {
        return $this->hasMany(EncounterDiagnosis::class)->orderBy('diagnosed_at');
    }

    /**
     * This encounter's treatment/care plan (Step 25), if one has been
     * started. At most one exists per encounter — see CarePlan's class docs.
     */
    public function carePlan(): HasOne
    {
        return $this->hasOne(CarePlan::class);
    }

    /**
     * The clinical notes authored against this encounter, oldest first.
     */
    public function clinicalNotes(): HasMany
    {
        return $this->hasMany(ClinicalNote::class)->orderBy('created_at');
    }

    /**
     * This encounter's laboratory orders (Phase 4, Step 30), oldest first.
     * Laboratory is its own sibling branch off the encounter, alongside
     * Diagnosis/Treatment/Clinical Notes — never nested under a
     * CarePlanItem.
     */
    public function laboratoryOrders(): HasMany
    {
        return $this->hasMany(LaboratoryOrder::class)->orderBy('ordered_at');
    }

    /**
     * This encounter's radiology orders (Phase 5, Step 36), oldest first.
     * Radiology is its own sibling branch off the encounter, alongside
     * Prescription/Laboratory/Diagnosis/Clinical Notes — never nested under
     * a CarePlanItem, mirroring laboratoryOrders() above exactly.
     */
    public function radiologyOrders(): HasMany
    {
        return $this->hasMany(RadiologyOrder::class)->orderBy('ordered_at');
    }

    /**
     * This encounter's prescription orders (Phase 6, Step 46), oldest first.
     * Prescription is its own sibling branch off the encounter, mirroring
     * radiologyOrders()/laboratoryOrders() above exactly — never nested
     * under a CarePlanItem. Distinct from the pre-existing, unrelated
     * `Prescription` model (nested 1:1 on a medication-type CarePlanItem),
     * which is left untouched for the care plan's own simple medication
     * notes.
     */
    public function prescriptionOrders(): HasMany
    {
        return $this->hasMany(PrescriptionOrder::class)->orderBy('prescribed_at');
    }

    /**
     * Clinical procedure orders prescribed during this encounter.
     */
    public function procedureOrders(): HasMany
    {
        return $this->hasMany(ProcedureOrder::class, 'clinical_encounter_id')->orderBy('created_at');
    }

    /**
     * NHIF pre-approval sessions linked to this encounter (Phase 8).
     */
    public function nhifPreApprovalSessions(): HasMany
    {
        return $this->hasMany(NhifPreApprovalSession::class);
    }

    /**
     * Inpatient admission created directly from this encounter, if any.
     */
    public function linkedAdmission(): HasOne
    {
        return $this->hasOne(Admission::class, 'clinical_encounter_id');
    }

    /**
     * Resolve the associated admission whether linked directly via admission_id or inverse clinical_encounter_id.
     */
    public function getResolvedAdmissionAttribute(): ?Admission
    {
        return $this->admission ?? $this->linkedAdmission;
    }
}
