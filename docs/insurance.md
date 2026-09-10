# Patient Insurance & Coverage Management (Phase 2, Step 17)

*This module previously had no dedicated documentation file — written as
part of Step 20's documentation pass, describing the implementation as it
already existed.*

## Architecture

Three normalized entities, matching the separation the module was designed
around:

```
InsuranceProvider (name, code, type, api_enabled)
   └─ InsuranceScheme (provider_id, name, code)
        └─ PatientInsurance (patient_id, provider_id, scheme_id?, ...)
```

`PatientInsurance` is the coverage record linking a `Patient` to a provider
(and optionally a scheme): membership/policy/card numbers, principal
member/relationship (for dependants), `effective_from`/`effective_to`,
`is_primary`, `status`, and an internal `verification_status`. A patient can
hold multiple coverage records (current and historical) — the module never
assumes one insurance per patient.

## Status vs. verification status

These are deliberately separate: `status` (`active`/`inactive`/`expired`/
`suspended`) is the record's own lifecycle state; `verification_status`
(`pending`/`verified`/`failed`/`expired`) is whether the coverage has been
confirmed with the provider. Having an insurance card does not itself mean
the coverage is currently verified. Verification here is **internal only**
— `PatientInsuranceService::verify()` sets the status and timestamp
directly; there is no external provider API integration (no NHIF or other
integration exists anywhere in this codebase to connect to).

`resources/js/lib/insurance-status.ts`'s `effectiveInsuranceStatus()`
additionally treats an `active` record whose `effective_to` has passed as
effectively `expired` for display, without rewriting the stored status —
the distinction between "administratively deactivated" and "date lapsed" is
preserved in the data.

## Primary coverage

Exactly one `PatientInsurance` per patient may be `is_primary` at a time.
`PatientInsuranceService::create()`/`update()`/`makePrimary()` all clear any
existing primary within the same `DB::transaction()` before setting the new
one — never two primaries, never a race window where neither is primary.

## Duplicate detection

`PatientInsuranceService::findPossibleDuplicates()` matches on
`(insurance_provider_id, membership_number)` and returns matches as an
assistive warning (`possibleDuplicates` flashed to the create form) rather
than a hard block — a receptionist can confirm and proceed
(`confirm_duplicate`), since two different people can coincidentally share
provider+number formatting but the system can't be certain without human
judgement, unlike the hard national-ID/passport uniqueness enforced on
`Patient` itself.

## Authorization

Permissions: `insurance-providers.*`, `insurance-schemes.*`,
`patient-insurance.{view,create,update,delete,verify}`. No hospital scoping
— insurance, like `Patient`, is a global record (mirrors `PatientPolicy`
exactly, per its own inline comment). IDOR on the nested
`/patients/{patient}/insurance/{patientInsurance}` route is guarded
explicitly by `PatientInsuranceController::ensureBelongsToPatient()`
(`abort_unless($patientInsurance->patient_id === $patient->id, 404)`).

## Routes (Inertia only — no `api/v1` surface)

```
/insurance/providers            — full CRUD
/insurance/schemes              — full CRUD
/insurance/patient-insurance    — cross-patient list (accounts/admin view)
/patients/{patient}/insurance   — create/store/show/edit/update/destroy,
                                   plus {id}/primary and {id}/verify actions
```

Deactivation (`destroy`) never hard-deletes — it sets `status = inactive`
and clears `is_primary`, since an inactive record must never remain a
patient's primary coverage while also preserving history for billing/claims
in a future step.

## Audit logging

`patient_insurance_created`, `_updated`, `_verified`, `_deleted` — via the
existing `AuditLogService`, surfaced on the patient's history timeline (see
`docs/patient-history.md`) by `auditable_type = PatientInsurance`.

## Testing

`tests/Feature/Insurance/` and `tests/Unit/Models/InsuranceModelTest.php`:
CRUD, primary-switching, duplicate-warning (both confirm and override
paths), authorization/IDOR, and cross-provider/cross-patient isolation.
