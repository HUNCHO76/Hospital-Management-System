# Patient History (Phase 2, Step 16)

## Why this exists

Step 16 was originally marked complete but had never actually been built —
no model, controller, page, or route existed anywhere in the codebase. This
was discovered and fixed during Step 20's integration review, since Step 20
explicitly requires verifying that other modules' events appear on a
patient timeline. Built deliberately minimal, per that review's scope: a
read-only aggregation, not a new clinical-events system.

## Design: a filtered view of the existing audit log, not a new table

`App\Services\PatientHistoryService::timeline()` queries `audit_logs`
directly — filtered to rows whose `auditable_type`/`auditable_id` match the
patient themselves, or any of their `PatientInsurance`, `Appointment`, or
`ReceptionVisit` records. Every action it surfaces (registration,
demographic updates, insurance changes, appointment lifecycle, reception
visits) is already recorded by `AuditLogService` when it happens in its own
module — nothing new is written anywhere. This means:

- No new events table, no risk of the timeline drifting out of sync with
  the authoritative records it summarizes.
- Zero clinical data can leak through it, because none of the audited
  actions in this phase touch clinical data in the first place.
- Every other module's audit action (see `docs/appointments.md`,
  `docs/reception.md`, `docs/insurance.md`, and this file's own patient
  actions) automatically appears here with no additional wiring — a future
  module only needs to keep auditing its own actions as normal.

## What it never exposes

The timeline entry shape is deliberately identical to
`DashboardController`'s existing "recent activity" widget —
`{id, action, module, user, created_at}` — and never includes the audit
log's `old_values`/`new_values`. A receptionist can see *that* an
appointment was rescheduled, not the specific field values changed; that
level of detail (if ever needed) belongs to the existing, more tightly
permissioned `audit-logs.view` system-wide audit viewer, not the patient
profile.

## Access

`GET /patients/{patient}/history`, gated by the same `patients.view`
permission (and, for `hospital-admin`, the same hospital-scoping) as
viewing the patient at all — there is no separate history permission, since
everything shown here is already visible piecemeal through the individual
insurance/appointment/reception modules a viewer already has access to;
this is purely a convenience aggregation.

## Testing

`tests/Feature/Patients/PatientHistoryTest.php`: events from all four
source modules appear, another patient's events never leak in, guest/
unauthorized access is rejected, and no raw audit value ever appears in the
response.
