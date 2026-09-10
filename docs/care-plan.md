# Treatment & Care Plan (Phase 3, Step 25)

## The CarePlan, CarePlanItem, Prescription, and Medication entities

`CarePlan` is what should be done for the patient — distinct from
`EncounterDiagnosis` (what the clinician believes/identifies) and from
pharmacy dispensing (what medication was actually handed over, out of scope
for this step). `CarePlanItem` is a single treatment action within the plan
(medication, procedure, clinical intervention, referral, monitoring, or
follow-up — spec §7). `Prescription` is the structured medication order for
a `medication`-type item, referencing the `Medication` catalog rather than
duplicating a drug name inline.

```
ClinicalEncounter
  |
  +-- carePlan          (App\Models\CarePlan, at most one — see below)
         |
         +-- items       (App\Models\CarePlanItem, hasMany)
                |
                +-- prescription  (App\Models\Prescription, hasOne, only when type = medication)
```

## No separate referrals table

Comparing spec §19's referral field list (department, practitioner, reason,
priority, instructions, date, status) against `CarePlanItem`'s already-generic
columns, only `referred_to_department_id`/`referred_to_practitioner_id` are
genuinely referral-specific — everything else reuses the item's own
`description`/`priority`/`instructions`/`start_date`/`status`. Two nullable
FK columns on `care_plan_items`, populated only when `type = referral`, do
the job a whole extra table would otherwise just re-host.

## No auto-scheduled follow-up appointment

Spec §18 asks that a follow-up integrate with the existing Appointment
module rather than a second scheduling system, but building a "book a
follow-up appointment from here" flow (conflict checking, department/
practitioner picking, its own authorization) is a real scope expansion
beyond a care-plan item. This step records the follow-up *instruction* only
(via the item's generic fields, `type = follow_up`) — a
`followUpAppointment()` link to `App\Models\Appointment` is a natural,
clearly-flagged future enhancement, not built here.

## Medication catalog

`Medication` (name + category + status) mirrors `DiagnosisCode`'s exact
shape — a small reusable catalog, not a full pharmacy formulary.
`MedicationSeeder` loads ~40 representative medications across common
categories (analgesics, antibiotics, antimalarials, antihypertensives,
antidiabetics, etc.) — a starter set, same honest scoping as Step 24's
ICD-10 seed. `GET /medications/search-json` (2-character minimum, capped at
10 results) goes through `MedicationSearchService`, mirroring
`DiagnosisCodeSearchService`'s shape exactly — no versioned API (see
`docs/api-standards.md`). `MedicationSearch` (a combobox copying
`DiagnosisCodeSearch`'s structure) queries it with a 300 ms debounce and a
request-id guard against stale responses.

## One care plan per encounter, many items

`care_plans.clinical_encounter_id` is a database-level `unique` constraint,
matching `Consultation`. Multiple `CarePlanItem`s exist per plan with no such
constraint — a plan commonly holds a mix of medication, monitoring, and
follow-up items addressing one or more diagnoses.

## Dependency chain

`CarePlanService::create()` requires the encounter to be active and to have
**at least one non-cancelled `EncounterDiagnosis`** (spec §14) — this
transitively guarantees the consultation was engaged too, since a diagnosis
can't exist without one, so no redundant direct consultation check is
needed.

## Status lifecycle

`CarePlan::TRANSITIONS` and `CarePlanItem::TRANSITIONS` are the single
sources of truth, enforced in `CarePlanService`:

```
CarePlan:      draft    → active | cancelled
               active   → completed | cancelled
               completed, cancelled → (terminal)

CarePlanItem:  planned  → active | cancelled
               active   → completed | cancelled
               completed, cancelled → (terminal)
```

Cancellation is excluded from both `TRANSITIONS`-driven `changeStatus()`
paths — it's its own action (`CarePlanService::cancel()`/`cancelItem()`)
requiring a mandatory reason, the same split every other clinical module in
this app uses. `activate()` additionally requires at least one non-cancelled
item to exist — an "active" plan must have actual treatment content.

## Mutability & the correction workflow

Plan fields are editable only while `draft`/`active`; item fields only while
`planned` (an `active` item represents treatment already underway). Once
`completed`, only `correct()` can touch either (audited old/new diff, same
as `Consultation`/`EncounterDiagnosis`). **Prescription structured fields are
never touched by `correctItem()`** — a wrong dose on a completed item is
cancelled and re-added as a new item, not silently edited; this is a
deliberate safety simplification beyond what spec §31 strictly requires,
applied specifically to medication data.

## Prescription is its own service

`PrescriptionService` (spec §22) owns the `prescription_*` audit vocabulary
distinctly from the item's own `treatment_item_*` events — called *from*
`CarePlanService` whenever an item's type is `medication`, never a parallel
routing/authorization surface. The item's lifecycle (planned/active/
completed/cancelled) is always `CarePlanService`'s job; cancelling a
medication item also triggers `PrescriptionService::cancel()` purely to
emit its own audit event (there is no independent prescription status — see
spec §9's clinical/pharmacy separation, satisfied here by keeping
Prescription a pure detail extension of its parent item).

## Authorization & ownership

Permissions (`database/seeders/PermissionSeeder.php`): `treatment.view`,
`.create`, `.update`, `.activate`, `.complete`, `.cancel`, `.correct`;
`prescription.view`, `.create`, `.update`, `.cancel`; `medications.view` for
the catalog — matching spec §32's exact names (note: the model/table layer
uses `CarePlan`/`CarePlanItem`, while the permission group is `treatment`,
the same deliberate divergence `EncounterDiagnosis`/`diagnosis` already
established). `hospital-admin` and `doctor` hold the full set; `nurse`/
`receptionist`/`pharmacist` hold none — treatment authoring is doctor-scope,
the same split `consultation`/`diagnosis` use. A future pharmacy-dispensing
module is the natural place to grant `pharmacist` read access, not this
step.

`CarePlanPolicy::ownsOrSupervises()` anchors ownership to the encounter's
consultation practitioner, exactly like `EncounterDiagnosisPolicy` —
`CarePlanItemPolicy` delegates to it (via constructor injection) so the two
never drift. **`prescription.*` is a genuinely separate permission gate**,
checked directly in the controller (not folded into the item Policy) only
when an item's `type` is `medication`, via a minimal `PrescriptionPolicy`
with permission-only checks (no model instance needed — mirrors
`DiagnosisCodePolicy`'s shape). This means a user can hold full `treatment.*`
and still be unable to prescribe medication without `prescription.*`
separately.

## IDOR protection

Care plan items need a real `{item}` route segment (unlike the plan itself,
which is resolved from the encounter with no route parameter, same as
`Consultation`). Every item action verifies
`$item->carePlan->clinical_encounter_id === $clinicalEncounter->id` via an
`assertBelongsToEncounter()` guard (copied from `EncounterDiagnosisController`/
`VitalSignController`) before authorizing or touching the model.

## Web routes (Inertia only — no API surface)

Treatment is **not** a standalone page — rendered inline on the existing
`clinical-encounters/show` screen via `CarePlanSection`, the same pattern as
`TriagePanel`/`ConsultationPanel`/`DiagnosisSection`. The web routes exist
only as POST/PUT action targets that redirect back to that same page:

```
POST clinical-encounters/{clinicalEncounter}/care-plan
PUT  clinical-encounters/{clinicalEncounter}/care-plan
POST clinical-encounters/{clinicalEncounter}/care-plan/activate
POST clinical-encounters/{clinicalEncounter}/care-plan/complete
POST clinical-encounters/{clinicalEncounter}/care-plan/cancel
POST clinical-encounters/{clinicalEncounter}/care-plan/correct

POST clinical-encounters/{clinicalEncounter}/care-plan/items
PUT  clinical-encounters/{clinicalEncounter}/care-plan/items/{item}
POST clinical-encounters/{clinicalEncounter}/care-plan/items/{item}/status
POST clinical-encounters/{clinicalEncounter}/care-plan/items/{item}/cancel
POST clinical-encounters/{clinicalEncounter}/care-plan/items/{item}/correct

GET  medications/search-json
```

No new sidebar entry was added, per the same convention every prior clinical
module used. No route was added to `routes/api.php` — this step lands right
after the HMS Route Architecture Migration, which established that internal
HMS functionality never gets a versioned API surface (see
`docs/api-standards.md`).

## Diagnosis → Treatment integration

`ClinicalEncounterController::show()` eager-loads the encounter's care plan
(with its items, each item's diagnosis reference, referral department/
practitioner, and prescription/medication) and passes it as a `carePlan`
prop, a fifth sibling alongside `triage`/`consultation`/`diagnoses`. The show
action also passes `departments`/`referralPractitioners` lists — needed only
by the care plan's referral item form, the same query shape already used by
`clinical-encounters/create`.

## Patient History integration

`PatientHistoryService::timeline()` was extended to include `CarePlan`
audit-log entries (not individual items/prescriptions — the plan-level
lifecycle is the meaningful timeline granularity), the same audit-log-only,
content-free pattern already used for triage/vitals/consultation/diagnosis.

## Testing

`tests/Feature/CarePlan/`: `CarePlanTest` (full draft → active → completed
lifecycle, activation's non-cancelled-item requirement, the dedicated cancel
path, the correction workflow), `CarePlanItemTest` (item creation across all
types, diagnosis-reference cross-encounter rejection, referral fields,
medication+prescription creation, the planned → active → completed
lifecycle, correction excluding prescription fields), `PrescriptionTest`
(medication-only attachment, update-while-planned), `CarePlanAuthorizationTest`
(role-separation, ownership, hospital-scoped IDOR, mass-assignment
protection, and the standalone `prescription.create` gate proven with a user
who holds full `treatment.*` but not `prescription.*`), `CarePlanConcurrencyTest`
(simultaneous plan-creation invariant), and `CarePlanEndToEndTest` (the full
HTTP chain: triage → consultation → diagnosis → treatment plan → medication
item with prescription → follow-up → referral → activate → complete, every
audit event, and patient-timeline surfacing).

## Future integration boundary

Pharmacy dispensing, laboratory orders/results, billing, NHIF claims,
admission/discharge, and surgery are explicitly out of scope. NHIF remains a
server-side integration service (not built yet) — if treatment/prescription
data must later reach NHIF, that flows through a dedicated
`App\Services\NHIF\*` integration service reading `CarePlan`/`CarePlanItem`/
`Prescription` by id, never a browser-to-NHIF call and never new internal API
routes (see `docs/api-standards.md`).
