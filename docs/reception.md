# Reception Management & Front Desk Workflow (Phase 2, Step 19)

## The ReceptionVisit entity

A reception visit represents a patient's presence at the front desk, headed
toward a department/service. It is deliberately separate from clinical
treatment — no diagnosis, treatment, prescriptions, or clinical notes live
here or anywhere in this module.

```
ReceptionVisit
  |
  +-- patient       (App\Models\Patient)
  +-- appointment   (App\Models\Appointment, nullable — null for walk-ins)
  +-- department    (App\Models\Department)
  +-- visit_type    (appointment | walk_in | follow_up | other)
  +-- status        (controlled lifecycle, see below)
  +-- completed_at / cancelled_at / cancellation_reason
```

No actor columns (`registered_by`/`checked_in_by`/etc.) exist on the table —
consistent with every other table in this app, "who did it" lives
exclusively in `audit_logs`.

## Status lifecycle

There is no separate `arrived` state. The spec's own workflow (confirming a
patient's arrival *is* the check-in action; walk-in registration *is* the
check-in moment) never produces a visit that has arrived but not yet been
checked in, so that state was not modeled. `ReceptionVisit::TRANSITIONS` is
the single source of truth, enforced in `ReceptionVisitService`:

```
checked_in        → waiting | cancelled
waiting           → ready_for_service | cancelled
ready_for_service → sent_to_service | cancelled
sent_to_service   → completed | cancelled
completed, cancelled → (terminal)
```

## Two ways a visit is created

**Scheduled check-in** — `ReceptionVisitService::checkInAppointment()` calls
the **existing** `AppointmentService::checkIn()` so Step 18's own transition
rules (rejects cancelled/no-show/completed/already-checked-in appointments)
are reused, never re-implemented. Only after that succeeds is the
`ReceptionVisit` created, at `checked_in`.

**Walk-in** — `ReceptionVisitService::createWalkIn()` creates the visit
directly, no appointment involved. The patient must already exist (Step
14's registration is the only path to create one — see below).

## Duplicate active visit prevention & concurrency

A patient may only have **one active visit at a time, hospital-wide**
(`ReceptionVisit::ACTIVE_STATUSES` — everything except `completed`/
`cancelled`). Both creation paths lock the `Patient` row
(`lockForUpdate()`) inside a `DB::transaction()` before checking for an
existing active visit — the same pattern `AppointmentService::create()`
uses for practitioner double-booking. `checkInAppointment()` additionally
locks the `Appointment` row itself, which is what makes the reception-side
action concurrency-safe even though `AppointmentService::checkIn()` doesn't
lock on its own.

*Test-suite caveat* (same as `docs/appointments.md`): SQLite-in-memory has
no `FOR UPDATE` clause and can't host two simultaneous transactions, so
`ReceptionConcurrencyTest` verifies the invariant (never two active visits
for the same patient) rather than true lock blocking, which only actually
engages against the production MySQL database.

## Patient registration handoff (Step 14 integration)

The walk-in form never re-implements patient registration. When a patient
search finds nothing, "Register New Patient" opens the existing
`/patients/create` page in a **new browser tab** — `PatientController`'s
redirect behavior is not modified. The receptionist keeps their in-progress
walk-in in the original tab and searches again once the new patient exists.

## Insurance visibility (Step 17 integration)

`ReceptionVisitService::insuranceIndicator()` derives a concise indicator
(`active | expired | pending_verification | not_verified | none`) from the
patient's existing primary `PatientInsurance` record — reading its
`status`/`verification_status`/`effective_to` fields, never duplicating or
re-implementing insurance logic.

## Hospital scoping

Same pattern as `AppointmentPolicy`: `ReceptionVisitPolicy` restricts a
`hospital-admin` to `$user->staff?->hospital_id ===
$visit->department->hospital_id`; `super-admin` bypasses via the existing
`Gate::before`.

## Authorization

Permissions (`database/seeders/PermissionSeeder.php`): `reception.view`,
`.dashboard`, `.checkin`, `.create_walkin`, `.update` (covers the waiting/
ready/send-to-service progression), `.cancel`, `.complete`. Granted to
`receptionist` and `hospital-admin`/`super-admin` only.

## Routes (Inertia only — no `api/v1` surface for this step)

```
GET  /reception                                     dashboard
GET  /reception/visits                              filterable visit list
GET  /reception/visits/{visit}                      visit detail
GET  /reception/walk-in/create                      walk-in form
POST /reception/walk-in                             create walk-in
POST /reception/appointments/{appointment}/check-in check in an appointment
POST /reception/visits/{visit}/waiting
POST /reception/visits/{visit}/ready
POST /reception/visits/{visit}/send-to-service
POST /reception/visits/{visit}/complete
POST /reception/visits/{visit}/cancel
```

## Dashboard performance

`ReceptionVisitController::dashboard()` computes today's summary via two
`groupBy('status')` aggregate queries (one for `Appointment`, one for
`ReceptionVisit`) rather than N separate `count()` calls, and eager-loads
`patient`, `patient.insurances`, `department`, `practitioner.staff`, and
`receptionVisit` on the appointments list — verified by
`ReceptionDashboardTest`'s explicit query-count assertion (stays constant
regardless of how many rows exist "today").

## Testing

`tests/Feature/Reception/`: check-in validity (including every Step 18
rejection case), walk-in creation/validation, duplicate-active-visit
prevention (both paths), the concurrency invariant, hospital-scoped
authorization/IDOR, every status transition (valid and invalid), and
dashboard correctness/scoping/N+1.

## Future integration boundary (§39)

A future clinical module can read a patient's current
`ReceptionVisit` (`sent_to_service` status) to identify the patient,
department, arrival time, and originating appointment without duplicating
the reception record — `ReceptionVisit::appointment()` and `::patient()`
already expose everything needed. This step does not implement, and does
not need to know about, whatever consumes that state next.
