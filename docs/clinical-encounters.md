# OPD / Clinical Encounter Foundation (Phase 3, Step 21)

## The ClinicalEncounter entity

A clinical encounter represents a patient's actual clinical episode of care
with the health service — distinct from `ReceptionVisit`, which represents
only the administrative front-desk arrival and preparation. A reception
visit may lead to a clinical encounter; the two are never merged just
because both carry a patient and a date. No clinical content (triage, vital
signs, consultation, diagnosis, treatment) is modeled here — this step only
establishes the parent/context those future modules will attach to.

```
ClinicalEncounter
  |
  +-- patient          (App\Models\Patient)
  +-- receptionVisit   (App\Models\ReceptionVisit, nullable)
  +-- appointment      (App\Models\Appointment, nullable)
  +-- department       (App\Models\Department)
  +-- practitioner     (App\Models\Practitioner, nullable — assigned later)
  +-- encounter_type   (opd | walk_in | scheduled | follow_up)
  +-- status           (controlled lifecycle, see below)
  +-- started_at / ended_at
  +-- created_by / closed_by  (App\Models\User)
  +-- cancellation_reason
```

No hard-coded "who did what" beyond `created_by`/`closed_by` (needed to
answer "who opened/closed this encounter" directly on the record); the full
change history lives in `audit_logs`, same as every other module.

## Status lifecycle

`ClinicalEncounter::TRANSITIONS` is the single source of truth, enforced in
`ClinicalEncounterService`:

```
open        → in_progress | cancelled
in_progress → completed | cancelled
completed, cancelled → (terminal)
```

Reopening a completed or cancelled encounter is deliberately not modeled —
a future controlled correction workflow would need to introduce it
explicitly, per §38 (no hard deletion) and §39 (no arbitrary edits to a
closed encounter's lifecycle fields; only explicit actions).

## How an encounter is created

`ClinicalEncounterService::create()` accepts `patient_id`, `department_id`,
`encounter_type`, and optionally `reception_visit_id`, `appointment_id`,
`practitioner_id`. Both `StoreClinicalEncounterRequest` variants (API and
web) validate that:

- the patient is `active` and the department is `active`;
- a referenced `reception_visit_id` belongs to the same patient and is
  `ready_for_service` or `sent_to_service` — i.e. the patient is actually
  ready for clinical service, not merely checked in or waiting;
- a referenced `appointment_id` belongs to the same patient;
- a hospital-admin may only open an encounter under their own hospital's
  department.

A reception visit is **not required** at the database or validation level —
`reception_visit_id` is nullable, so a clinical workflow that legitimately
bypasses reception is not blocked — but the normal UI path (the "Open
Clinical Encounter" button on a ready-for-service reception visit) always
supplies one.

`practitioner_id` is optional at creation and may remain null; a future
consultation workflow is responsible for assigning a clinician when the
hospital's workflow doesn't require one up front.

## Duplicate active encounter prevention & concurrency

A patient may only have **one active encounter at a time**
(`ClinicalEncounter::ACTIVE_STATUSES` — `open` and `in_progress`).
`ClinicalEncounterService::create()` locks the `Patient` row
(`lockForUpdate()`) inside a `DB::transaction()` before checking for an
existing active encounter — the same pattern `AppointmentService::create()`
and `ReceptionVisitService` use for their own duplicate/conflict checks.

*Test-suite caveat* (same as `docs/appointments.md` and `docs/reception.md`):
SQLite-in-memory has no `FOR UPDATE` clause and can't host two simultaneous
transactions, so `ClinicalEncounterConcurrencyTest` verifies the invariant
(never two active encounters for the same patient) rather than true lock
blocking, which only actually engages against the production MySQL
database.

## Hospital scoping

Same pattern as `AppointmentPolicy`/`ReceptionVisitPolicy`:
`ClinicalEncounterPolicy` restricts a `hospital-admin` to
`$user->staff?->hospital_id === $encounter->department->hospital_id`;
`super-admin` bypasses via the existing `Gate::before`.

## Authorization & clinical privacy (§27)

Permissions (`database/seeders/PermissionSeeder.php`): `clinical-encounters.
view`, `.create`, `.start`, `.complete`, `.cancel`. These are a **separate**
permission domain from `reception.*` — a receptionist who checks in a
patient or progresses their reception visit does not automatically gain
access to that patient's clinical encounter. Granted to `hospital-admin`
(full), `nurse` (view/create/start/complete/cancel — nurses are typically
first clinical contact), and `doctor` (view/start/complete/cancel, not
create). `receptionist` deliberately holds none of these permissions.

## IDOR protection

Every route (`/clinical-encounters/{clinicalEncounter}` and its actions)
authorizes through `ClinicalEncounterPolicy` before touching the model — a
user cannot view or act on another hospital's encounter by
guessing/incrementing the id in the URL. Verified by
`ClinicalEncounterAuthorizationTest`.

## Web routes (Inertia)

No `update`/`destroy` route: an encounter is never hard-deleted or
arbitrarily edited, only progressed through the explicit lifecycle actions
below (§39). Listing supports `search`, `patient_id`, `department_id`,
`practitioner_id`, `status`, `encounter_type`, `date_from`/`date_to`,
`sort`, and is always paginated — no unbounded query is possible.

*A parallel `/api/v1/clinical-encounters` versioned API existed alongside
these routes and was removed in the HMS Route Architecture Migration (no
consumer, no external-integration need — see `docs/api-standards.md`); this
web surface already covered every action it offered.*

```
GET  /clinical-encounters                          filterable list
GET  /clinical-encounters/create                    creation form
POST /clinical-encounters                            create
GET  /clinical-encounters/{clinicalEncounter}         detail
POST /clinical-encounters/{clinicalEncounter}/start
POST /clinical-encounters/{clinicalEncounter}/complete
POST /clinical-encounters/{clinicalEncounter}/cancel
```

The encounter detail page renders `Triage`, `Consultation` (Step 23),
`Diagnosis` (Step 24), and `Treatment Plan` (Step 25) as fully functioning
inline panels, in that order — see `docs/triage-vital-signs.md`,
`docs/diagnosis.md`, and `docs/care-plan.md` for each module's own
documentation. No placeholder cards remain on this page.

## Reception → Clinical Encounter handoff

`reception/visits/show.tsx` shows an "Open Clinical Encounter" button once
a visit reaches `ready_for_service` or `sent_to_service`, gated on
`clinical-encounters.create`. It links to `/clinical-encounters/create`
with `patient_id`/`reception_visit_id` query params, which pre-fill and
lock the patient on the creation form — the encounter cannot be pointed at
a different patient than the reception visit it came from.

## Testing

`tests/Feature/ClinicalEncounters/`: lifecycle (creation, number generation,
every valid/invalid transition, cancellation from every valid state),
duplicate-active-encounter prevention and its concurrency invariant,
hospital-scoped authorization/IDOR (including the receptionist privacy
boundary), and two full end-to-end workflows — appointment-based
(`Appointment → check-in → ready-for-service → encounter open → in_progress
→ completed`) and walk-in (`walk-in visit → ready-for-service → encounter
open`, with no appointment involved) — verifying every entity stays
correctly linked throughout. `tests/Feature/Api/V1/ClinicalEncounterApiTest.
php` covers the same lifecycle and authorization surface through the JSON
API and standard envelope.

## Future integration boundary (§49)

A future module (triage, consultation, diagnosis, treatment) attaches to a
`ClinicalEncounter` by its id — `ClinicalEncounter::patient()`,
`::receptionVisit()`, `::appointment()`, `::department()`, and
`::practitioner()` already expose everything needed to identify the
patient, origin, and destination. This step does not implement, and does
not need to know about, whatever consumes that state next.
