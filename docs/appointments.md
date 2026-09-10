# Appointment Management & Scheduling (Phase 2, Step 18)

## The Appointment entity

An appointment is a **planned visit** — distinct from a clinical encounter
(the actual visit itself, which belongs to a future step). It links a
patient to a department and a practitioner for a scheduled time window, and
carries its own independent status lifecycle.

```
Appointment
  |
  +-- patient          (App\Models\Patient)
  +-- department       (App\Models\Department)
  +-- practitioner     (App\Models\Practitioner)
  +-- appointment_type (controlled list, see below)
  +-- scheduled_start / scheduled_end
  +-- status           (controlled lifecycle, see below)
  +-- reason / notes
  +-- cancelled_at / cancellation_reason (set only on cancellation)
```

No `created_by`/`cancelled_by`/`verified_by` actor columns exist on the
table — consistent with every other table in this app, "who did it" lives
exclusively in `audit_logs` (see Audit Logging below), not duplicated onto
the row. `cancelled_at`/`cancellation_reason` are the one exception, kept on
the row (like `PatientInsurance.verified_at`) because the UI needs to show
them without joining audit logs.

## Appointment number

Generated the same way as `patient_number`/`practitioner_number`/
`staff_number` — `App\Models\Concerns\GeneratesBusinessNumber`, a
transaction + `lockForUpdate()` over the year's existing numbers, never a
`count() + 1` or max-ID guess. Format: `APT-2026-000123`.

## Appointment type

`Appointment::TYPES` — a PHP class constant list (matching the
`Practitioner::TYPES`/`Patient::MARITAL_STATUSES` convention already used
throughout this app for controlled vocabularies), not a new database table:
`initial_consultation`, `follow_up`, `review`, `procedure`,
`specialist_consultation`, `other`.

## Status lifecycle

`Appointment::TRANSITIONS` is the single source of truth for which status
changes are allowed, enforced in `AppointmentService` — never trusted from
the frontend:

```
scheduled   → confirmed | checked_in | cancelled | no_show
confirmed   → checked_in | cancelled | no_show
checked_in  → in_progress | cancelled
in_progress → completed | cancelled
completed, cancelled, no_show → (terminal)
```

Any other transition (e.g. `completed → scheduled`) raises a
`ValidationException` (surfaced as a normal 422/inline field error) rather
than being silently allowed or ignored. No appointment is ever automatically
marked `no_show` — that's always an explicit authorized action.

`checked_in → in_progress` ("start") is gated by the same
`appointments.complete` permission as completing — both are provider-side
actions taken once the patient is in the room, and the spec's permission
list has no separate "start" permission.

## Conflict detection & concurrency

A practitioner cannot be double-booked. `AppointmentService::findConflict()`
looks for any other appointment for the same practitioner, in an
`Appointment::ACTIVE_STATUSES` state (`scheduled`, `confirmed`,
`checked_in`, `in_progress` — cancelled/no-show/completed appointments free
their slot), whose window overlaps the requested `[start, end)`.

**Concurrency-safe booking**: `create()` and `reschedule()` both run inside
`DB::transaction()` and first take `Practitioner::whereKey($id)->lockForUpdate()`.
This serializes two concurrent booking attempts for the *same practitioner*
— the second request's transaction blocks on the row lock until the first
commits, then re-runs the conflict check and correctly sees the first
request's now-committed row. This is the same `DB::transaction` +
`lockForUpdate()` shape `GeneratesBusinessNumber` already uses for
sequential numbers — no new concurrency primitive was introduced.

A conflict raises `App\Exceptions\AppointmentConflictException` (extends
Symfony's `ConflictHttpException`): the API surface lets it propagate to the
existing global exception handler, which renders it as a 409 automatically;
the Inertia web controller catches it explicitly and re-throws it as a
`ValidationException` on `scheduled_start`, so the UI shows a plain inline
"This practitioner is already booked during the selected time" message —
never a raw exception page or database error.

*Test-suite caveat*: the test database is SQLite in-memory
(`phpunit.xml`), which has no `FOR UPDATE` clause at all — Laravel's SQLite
grammar makes `lockForUpdate()` a no-op there, and a single in-memory
connection can't host two genuinely simultaneous transactions. The
concurrency tests (`tests/Feature/Appointments/AppointmentConcurrencyTest.php`)
therefore verify the invariant the lock protects (the second of two
back-to-back booking attempts for the same slot is always rejected, never
both accepted) rather than true multi-connection blocking, which only
actually engages against the production MySQL database.

## Hospital scoping

Appointments are not directly `hospital_id`-scoped; the hospital is derived
through `appointment.department.hospital_id` (a department always belongs
to exactly one hospital). This follows the exact `StaffPolicy`/
`DepartmentPolicy`/`PractitionerPolicy` pattern already established:
`hospital-admin` users are restricted to `$user->staff?->hospital_id ===
$appointment->department->hospital_id`; every other role holding the
permission (in practice only `super-admin`, which bypasses all policies via
`Gate::before`) is unrestricted. `StoreAppointmentRequest::authorize()`
enforces the same check against a client-supplied `department_id` before
the record exists, and also validates that the chosen practitioner's
hospital (via `practitioner.staff.hospital_id`) matches the chosen
department's hospital — an appointment can never span two hospitals.

## Search & filtering

`AppointmentSearchService` (shared by the Inertia and API controllers, same
split as `PatientSearchService`) supports: free-text `search` (appointment
number or patient name/number/phone), `patient_id`, `department_id`,
`practitioner_id`, `status`, and a `date_from`/`date_to` range over
`scheduled_start`. Sorting is whitelisted (`scheduled_start`,
`scheduled_end`, `status`, `created_at`) — an unknown `sort` value is
silently ignored, never injected into `ORDER BY`.

## Authorization

Permissions (`database/seeders/PermissionSeeder.php`): `appointments.view`,
`.create`, `.update`, `.confirm`, `.reschedule`, `.cancel`, `.checkin`,
`.complete`, `.no_show`. `RoleSeeder` grants: `hospital-admin` everything;
`receptionist` the front-desk actions (view/create/update/confirm/
reschedule/cancel/check-in/no-show); `doctor`/`nurse` the clinical actions
(view/confirm/check-in/complete/cancel/no-show, doctor also gets `complete`
which implicitly covers "start"). `AppointmentPolicy` enforces permission +
hospital scoping for every ability; IDOR is prevented the same way as every
other resource — Laravel route-model binding plus the policy check, never a
client-trusted ID alone.

## API

See the endpoint table in `docs/api-standards.md`. `Api\V1\AppointmentController`
shares `AppointmentService`/`AppointmentSearchService` with the Inertia
`AppointmentController` — business logic is never duplicated between the
two surfaces, only the transport (JSON envelope vs. Inertia response)
differs, matching the `Patient` module's precedent.

## Frontend

- `pages/appointments/index.tsx` — searchable/filterable/sortable/paginated
  list, following `pages/patients/index.tsx`'s debounced-search +
  `router.get(..., { preserveState: true })` pattern.
- `pages/appointments/calendar.tsx` — day/week calendar (`react-big-calendar`
  + `date-fns` localizer, newly added dependencies — no calendar library
  existed in this app before), color-coded by status, always scoped to the
  requested date range server-side (never the whole table).
- `pages/appointments/create.tsx` / `edit.tsx` / `show.tsx` — booking,
  non-scheduling-field editing, and detail/actions respectively. `show.tsx`
  only renders the action buttons valid for the appointment's current
  status (`lib/appointment-status.ts`'s `allowedAppointmentActions()`) and
  gates each on the matching permission via the existing `Can` component —
  the backend is still the actual authority, this only avoids offering an
  action that would be rejected anyway.
- `components/appointments/patient-selector.tsx` — a new search-and-select
  combobox (no equivalent existed anywhere in this app), backed by a
  lightweight `GET /patients/search-json` web route that reuses
  `PatientSearchService` rather than re-implementing patient search — a plain
  session-authenticated endpoint, not a versioned API (see
  `docs/api-standards.md`).
- `components/appointments/reschedule-appointment-dialog.tsx` /
  `cancel-appointment-dialog.tsx` — small dedicated dialogs; a conflict on
  reschedule surfaces as the same inline field error as on create.
- New dependencies: `react-big-calendar`, `date-fns`, `@radix-ui/react-popover`
  (matching the already-installed Radix component family) — approved before
  installation since this app's convention requires sign-off before adding
  dependencies.

## Testing

`tests/Feature/Appointments/` — creation/validation, status transitions,
conflict detection, the mandatory concurrency invariant, hospital-scoped
authorization/IDOR, and search/filtering. `tests/Feature/Api/V1/` — the same
coverage through the JSON surface, plus envelope/`request_id`/409 assertions.

## What this step deliberately does not include

Per the spec: no claims, billing, payments, admissions, discharge, queue
management, full clinical consultation, or a full SMS/notification
platform. Status-change points (confirm/cancel/reschedule) are clean
integration points for a future notification system, but no notification
code was added here — there is no existing notification architecture in
this app to hook into yet.
