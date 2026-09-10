# Triage & Vital Signs (Phase 3, Step 22)

## The TriageAssessment and VitalSign entities

A triage assessment is the initial clinical assessment used to determine a
patient's condition and priority before further care — distinct from
`ClinicalEncounter` (the overall episode of care) and from `VitalSign` (the
objective measurements captured as part of the assessment).

```
ClinicalEncounter
  |
  +-- triageAssessment   (App\Models\TriageAssessment, at most one — see below)
         |
         +-- vitalSigns  (App\Models\VitalSign, hasMany, oldest first)
```

No `patient_id` column exists on either table — both derive the patient
through `clinicalEncounter->patient_id` rather than duplicating ownership
(spec §4). `vital_signs.clinical_encounter_id` is the one deliberate
exception: it's denormalized from `triage_assessment_id` purely so
encounter-scoped queries/indexes don't need a join through
`triage_assessments`.

## One triage assessment per encounter

`triage_assessments.clinical_encounter_id` is a database-level `unique`
constraint — a scope decision (spec §17), not a technical limitation. A
future reassessment/re-triage workflow would model additional rounds as its
own concept rather than a second row here.

## Status lifecycle

`TriageAssessment::TRANSITIONS` is the single source of truth, enforced in
`TriageService`:

```
pending     → in_progress | cancelled
in_progress → completed | cancelled
completed, cancelled → (terminal)
```

Completing an assessment requires both a `triage_category` and at least one
recorded vital-sign observation (spec §35) — enforced in
`TriageService::complete()`, not just in the UI.

## Triage category

Four standard acuity levels, most urgent first (spec §6):
`emergency`, `very_urgent`, `urgent`, `non_urgent` —
`TriageAssessment::CATEGORIES`. No existing classification was found
elsewhere in the codebase to reuse (confirmed by inspection before
implementing).

## Vital signs — supported measurements and canonical units

| Measurement | Column | Canonical unit |
|---|---|---|
| Temperature | `temperature_c` | °C |
| Blood pressure | `systolic_mmhg`, `diastolic_mmhg` (separate columns, never a `"120/80"` string) | mmHg |
| Pulse | `pulse_bpm` | bpm |
| Respiratory rate | `respiratory_rate_bpm` | breaths/min |
| Oxygen saturation | `oxygen_saturation_pct` | % |
| Weight | `weight_kg` | kg |
| Height | `height_cm` | cm |
| BMI | `bmi` | kg/m² — **always server-calculated** from weight/height (`VitalSignsService::calculateBmi()`), never accepted as direct input |

**Deliberately not implemented** (spec §8: "do not implement every possible
measurement automatically"): blood glucose, consciousness level, pulse
rhythm, oxygen delivery device, pregnancy-specific and age-specific
measurements. Every column is a plain nullable field, so adding one later is
a single migration, not a redesign. Pain score lives on
`triage_assessments.pain_score` (0–10), not duplicated onto `vital_signs`.

**No alternative-unit input**: the API/forms only ever accept the canonical
unit directly — there is no Fahrenheit/lb/inch input path to convert from.
"Never mix units silently" (spec §9) is satisfied trivially: only one unit
per measurement is ever accepted.

## Validation — technical bounds vs. clinical abnormality

`config/vitals.php` is the single source of truth for both:

- **Technical bounds** (`min`/`max`) — a value outside these is rejected as
  a data-entry error (e.g. SpO2 = 250%). Enforced by
  `ValidatesVitalSignMeasurements` (shared by the store/correct requests,
  API and web) via Laravel's `between:` rule, reading the same config the
  reference ranges below use.
- **Reference ranges** (`normal_low`/`normal_high`,
  `critical_low`/`critical_high`) — general **adult** ranges, not age/sex
  adjusted, used only by `VitalSignsService::evaluate()` to flag a
  technically valid value as `low`/`high`/`critical`/`normal` for display.
  **Never used to reject a value, never presented as a diagnosis.** SpO2 =
  82% is clinically abnormal but a valid percentage — it's accepted and
  flagged `critical`, not rejected (spec §10).

A systolic/diastolic pair, when both given, must satisfy
`diastolic < systolic` (also enforced in the shared validation trait). At
least one measurement must be present in every store/correct request.

## Vital-sign history and immutability

Every observation is a permanent, append-only row (spec §18/§19) —
`VitalSignController`/`TriageController` (web and API) expose no
update/destroy route for a `VitalSign`. Multiple observations during one
encounter simply accumulate:

```
08:30 → BP 120/80
10:15 → BP 135/85
```

**Correction, not editing**: `VitalSignsService::correct()` never modifies
the original row. It creates a new row with `corrects_vital_sign_id`
pointing back at the one it supersedes, carrying its own `recorded_by`,
`recorded_at`, and required `correction_reason`. An observation can be
corrected at most once — a further correction targets the correction
itself, keeping "what's current" unambiguous. The full audit trail
(original values, who corrected, when, why) is preserved in both the row
itself and `audit_logs`.

## Duplicate triage prevention & concurrency

`TriageService::create()` locks the `ClinicalEncounter` row
(`lockForUpdate()`) inside a `DB::transaction()` before checking for an
existing triage assessment — the same pattern `ClinicalEncounterService`
and `AppointmentService` use for their own duplicate/conflict checks. The
database-level `unique` constraint on `clinical_encounter_id` is the final
backstop.

*Test-suite caveat* (same as `docs/appointments.md`, `docs/reception.md`,
`docs/clinical-encounters.md`): SQLite-in-memory has no `FOR UPDATE` clause
and can't host two simultaneous transactions, so
`TriageConcurrencyTest` verifies the invariant (never two triage assessments
for the same encounter) rather than true lock blocking, which only actually
engages against the production MySQL database.

## Authorization & role separation (spec §26)

Permissions (`database/seeders/PermissionSeeder.php`): `triage.view`,
`.create`, `.update`, `.complete`, `.cancel`; `vital-signs.view`, `.create`,
`.correct`. Starting an assessment (`pending → in_progress`) reuses
`triage.create` rather than a separate permission — the same provider-side
split `AppointmentPolicy::start()` uses.

- `hospital-admin`: full access to both, scoped to their own hospital.
- `nurse`: full access to both — the typical first clinical contact.
- `doctor`: view-only on both — can see triage/vitals but does not record
  them in this HMS's workflow.
- `receptionist`: **none of either permission**, despite already holding
  `reception.*` and `clinical-encounters.view` is also not granted — a
  receptionist who checks a patient in does not thereby gain access to
  their clinical triage/vitals. Verified explicitly by
  `TriageAuthorizationTest`.

## IDOR protection

Every triage/vital-sign route authorizes through
`TriageAssessmentPolicy`/`VitalSignPolicy` before touching the model, with
hospital-admin scoping checked against the owning encounter's department. A
vital sign fetched or corrected through `/clinical-encounters/{a}/...` is
additionally verified to actually belong to encounter `{a}` — substituting
another encounter's id returns 404, not another patient's data.

No `{triage}` route parameter: at most one triage assessment exists per
encounter, so it's always resolved from the encounter itself
(`TriageController::findOrFail()`).

*A parallel `/api/v1/clinical-encounters/{clinicalEncounter}/triage` and
`/vital-signs` versioned API existed alongside these routes and was removed
in the HMS Route Architecture Migration (no consumer, no external-integration
need — see `docs/api-standards.md`); this web surface already covered every
action it offered.*

## Web routes (Inertia)

Triage and vital signs are **not** standalone pages (spec §38) — they're
rendered inline on the existing `clinical-encounters/show` screen via
`TriagePanel`. The web routes exist only as POST/PUT action targets that
redirect back to that same page:

```
POST {clinicalEncounter}/triage            POST {clinicalEncounter}/triage/complete
PUT  {clinicalEncounter}/triage            POST {clinicalEncounter}/triage/cancel
POST {clinicalEncounter}/triage/start
POST {clinicalEncounter}/vital-signs
POST {clinicalEncounter}/vital-signs/{vitalSign}/correct
```

No new sidebar entry was added, per the same spec guidance.

## Reception → Clinical Encounter → Triage integration

`ClinicalEncounterController::show()` eager-loads the encounter's triage
assessment (with its vitals) and passes it to the same Inertia page as the
`encounter` prop. `TriagePanel` renders one of three states: no assessment
yet (a "Start Triage" button), an open assessment (editable fields, the
vitals form, full history), or a closed one (read-only).

## Patient History integration (Step 16)

`PatientHistoryService::timeline()` was extended to include
`ClinicalEncounter`, `TriageAssessment`, and `VitalSign` audit-log entries,
scoped through the patient's own encounter ids — the same
audit-log-only pattern the service already used for appointments/reception
visits, never a duplicate events table. (`ClinicalEncounter` itself was not
previously wired into this timeline in Step 21; adding it was a small,
natural extension of the same query while wiring up Step 22, not a
retroactive Step 21 rewrite.)

## Testing

`tests/Feature/Triage/`: full status-transition lifecycle (including the
category/vitals-required completion gate), duplicate-triage prevention and
its concurrency invariant, vital-sign recording (BMI calculation, abnormal
flagging without rejection, immutability), the correction workflow
(original preserved, single-correction rule, audit trail), role-separation
and hospital-scoped IDOR authorization, and two full end-to-end workflows
(appointment-based and walk-in) verifying every entity stays linked from
appointment through a completed triage, including the technical-vs-clinical
validation boundary (SpO2 = 250% rejected; SpO2 = 82% accepted and flagged).
(The former `tests/Feature/Api/V1/TriageApiTest.php`/`VitalSignApiTest.php`
covered the same surface through the now-removed JSON API — see
`docs/api-standards.md`.)

## Future integration boundary (spec §51)

Doctor consultation, diagnosis, ICD coding, treatment, prescription, care
plan, lab, pharmacy, billing, and admission/discharge are explicitly out of
scope. A future consultation module attaches to a `ClinicalEncounter` (and
can read its completed `TriageAssessment`/`VitalSign` history) by id — no
new integration surface is required beyond what already exists.
