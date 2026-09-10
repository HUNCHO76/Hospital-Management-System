# Patient Management — Registration & Search (Phase 2, Steps 14–15)

## Phase 2 overview (Steps 14–20)

This file documents Steps 14–15 in detail. The rest of the phase is
documented per module, all built on the same `Patient` record and the same
conventions (Inertia + shared `App\Services\*`, Spatie permissions +
policies, `AuditLogService` for every state change, `DB::transaction()` +
`lockForUpdate()` for concurrency-safe writes):

| Step | Module | Doc |
|---|---|---|
| 14–15 | Registration & Search | this file |
| 16 | Patient History (read-only audit timeline) | `docs/patient-history.md` |
| 17 | Insurance & Coverage | `docs/insurance.md` |
| 18 | Appointment Management & Scheduling | `docs/appointments.md` |
| 19 | Reception & Front Desk Workflow | `docs/reception.md` |
| 20 | Integration & end-to-end hardening | this section + `tests/Feature/Integration/` |

**Patient lifecycle** (the workflow Step 20 verifies end-to-end, exercised by
`tests/Feature/Integration/PatientLifecycleIntegrationTest.php`):

```
Registration (14) → Search (15) → Insurance (17, optional)
   → Appointment or Walk-in (18/19) → Reception Check-in (19)
   → Waiting → Ready for Service
```

Every record created along this path (`PatientInsurance`, `Appointment`,
`ReceptionVisit`) carries the same `patient_id` back to the one record
created in Step 14 — no module creates its own patient identity, and
`Patient` never grows a `hospital_id` or clinical columns of its own.

**Role/permission matrix** (the actual permissions seeded in
`database/seeders/{PermissionSeeder,RoleSeeder}.php` — no new roles were
introduced for this phase):

| Function | receptionist | doctor / nurse | hospital-admin |
|---|---|---|---|
| Search / view patient | `patients.view` | `patients.view` | `patients.view` |
| Register patient | `patients.create` | — | `patients.create` |
| Edit demographics | `patients.update` | `patients.update` | `patients.update` |
| View patient history | `patients.view` (same gate — no separate history permission) | same | same |
| Manage insurance | full (`patient-insurance.*` incl. `verify`) | view only | full |
| Manage appointments | booking/reschedule/cancel/confirm/check-in/no-show | confirm/checkin/complete/cancel/no-show (clinical side) | full |
| Reception check-in / walk-in | full (`reception.*`) | none | full |
| Progress/complete/cancel a visit | full | `reception.checkin`/`complete` only (nurse), none (doctor beyond that) | full |

`hospital-admin` and clinical roles are additionally scoped to their own
hospital (via `department.hospital_id`/`staff.hospital_id`) everywhere
except `Patient` itself, which is a global identity with no hospital column
— see each module's own policy for the exact `belongsToUsersHospital()`
check. `super-admin` bypasses every policy via the app-wide `Gate::before`.

## The Patient entity

`App\Models\Patient` is the hospital's **single canonical master record** for a
person. Every future module (search, history, insurance, appointments, reception,
clinical, laboratory, pharmacy, billing) references this record — none create their
own patient identity. The table holds demographics, contact, and identification
only; **no clinical data** (diagnoses, prescriptions, results, admissions, billing)
lives here.

Emergency/next-of-kin contacts live in the related `patient_contacts` table
(`Patient::contacts()`), so a patient can have several.

## Patient number

- Format: `PT-{YEAR}-{SEQUENCE}` (e.g. `PT-2026-000001`), unique.
- Generated server-side by `App\Models\Concerns\GeneratesBusinessNumber` on
  `creating`, inside a transaction using `lockForUpdate()` on the last row for
  the year — **concurrency-safe**. It never uses `count()` or `MAX(id)+1`, so
  simultaneous registrations cannot collide.
- Not user-supplied; `patient_number` is not fillable via the registration forms.

## Fields

- **Personal:** first_name*, middle_name, last_name*, date_of_birth, gender*, marital_status, blood_group, occupation.
- **Contact:** phone, email, address, region, district, ward.
- **Identification:** national_id, passport_number, other_identification.
- **Status:** `active` / `inactive` / `deceased` (default `active`).
- Soft-deletable; deactivation sets `status=inactive` and soft-deletes (records are never hard-deleted).

## Registration workflow

`App\Services\PatientService::create()` runs inside a DB transaction: create the
patient (+ contacts), then write a `patient_created` audit event. A failure rolls
everything back — no partial records.

## Duplicate detection (§18 classification)

Two tiers, both computed from submitted identifiers before creation:

| Tier | Signal | Behaviour |
|---|---|---|
| **Confirmed** | exact `national_id` **or** `passport_number` match | **Blocks** registration; returns the existing patient reference. **Not** overridable. |
| **Possible** | same `phone`, or same `first_name`+`last_name`+`date_of_birth` | **Warns**; staff may proceed with `confirm_duplicate` (web) / `confirm: true` (API). |

- Name alone is never a duplicate signal. Records are **never** auto-merged
  (merging is a dedicated future workflow).
- Web: a confirmed duplicate redirects back with `confirmedDuplicate`; a possible
  one with `possibleDuplicates`. API: both return **409 Conflict** — confirmed
  carries `errors.existing_patient`, possible carries `errors.possible_duplicates`
  and is retryable with `confirm: true`.
- **Concurrency (Step 20 hardening)**: `findConfirmedDuplicate()` is a
  check-then-insert and cannot, by itself, stop two simultaneous
  registrations for the same national ID/passport from both passing the
  check before either commits. `patients.national_id` and
  `patients.passport_number` carry a DB-level `unique` index (migration
  `2026_09_04_130000_add_unique_constraints_to_patients_table`) as the
  actual concurrency-safe guarantee — NULL values never conflict with each
  other. `PatientService::create()` catches the resulting SQLSTATE `23000`
  violation and converts it into the same clean validation error the
  non-raced case already produces, never a raw database exception. See
  `tests/Feature/Patients/PatientRegistrationConcurrencyTest.php`.

## Search & identification (Step 15)

Search is served by the **same** `/patients` endpoint used for listing —
there is no separate search endpoint, and no API surface (see
`docs/api-standards.md`). The in-app patient-picker combobox (used by
appointment booking, reception, etc.) hits the lightweight
`/patients/search-json` web route instead, sharing the same
`App\Services\PatientSearchService` query builder so search behavior never
drifts between the two.

### Supported parameters

| Parameter | Matching | Notes |
|---|---|---|
| `patient_number` | exact | Short-circuits: no other filter is applied once given — an identifier lookup needs nothing else. |
| `national_id` | exact | |
| `passport_number` | exact | |
| `phone` | exact, format-tolerant | Matched against `0…`, `+255…`, `255…`, and bare local-number variants via `App\Support\Patients\PhoneNormalizer`, so reasonable formatting differences don't prevent a match. |
| `date_of_birth` | exact date | Validated as a real date; a malformed value is rejected (422 / redirect with errors), never a DB error. |
| `gender` | exact | `male` / `female` (Step 14's values — no second definition). |
| `status` | exact | `active` / `inactive` / `deceased`. |
| `search` | free text, min 2 chars | Matches patient number, name (first/middle/last), phone, national ID, and passport number; multi-word queries (e.g. "John Michael Smith") match every token against the name columns in any order; a `YYYY-MM-DD`-shaped query also matches date of birth. |
| `sort` | — | Whitelisted: `patient_number`, `first_name`, `last_name`, `date_of_birth`, `created_at`, `updated_at`. Prefix with `-` for descending (e.g. `-created_at`). An unsupported value silently falls back to the default order rather than erroring — consistent with every other HMS API index. |
| `page`, `per_page` | — | Server-side pagination; `per_page` is clamped to `config('api.pagination.max')` (never rejected, never unbounded — `Patient::all()` is never used). |

All parameters can be **combined** (e.g. name + date of birth, phone + name,
national ID + date of birth) — they are applied as `AND` conditions.

### Design decisions

- **Exact vs. fuzzy**: identifier parameters (`patient_number`, `national_id`,
  `passport_number`, `phone`) always match exactly — an identifier is either
  supplied whole or not at all, and exact matching stays index-friendly. Only
  `search` performs a wildcard scan, and only once at least 2 characters are
  given, to prevent an expensive near-unbounded query.
- **No dedicated "search" endpoint**: the existing patient list endpoint already
  supports full filtering, so a second endpoint would duplicate the Step 10 API
  conventions. An empty request still returns a **paginated** browsable list
  (never the whole table) — this is the existing, intentional list-page design,
  not an accidental full-table dump.
- **List results are minimized and phone-masked**: a search result exists to
  *identify* a patient, not to expose their full record. The API's
  `PatientSearchResultResource` and the web index's transformed row both return
  only `id`, `patient_number`, name, gender, date of birth, a **masked** phone
  (`0712****78` — first 4 and last 2 digits visible), status, and registration
  date. National ID, passport, email, and address are **not** included in list
  results — a match is confirmed by opening the (separately authorized) profile.
- **No search-activity audit trail**: consistent with Step 9's philosophy of
  auditing data-changing and security events (not reads), individual patient
  searches are not written to `audit_logs` — that would be high-volume and
  would risk storing complete sensitive identifiers in the log. Standard
  technical request logging (method, path, user, status — never query values)
  already runs via `ApiRequestContext` for every API request.
- **No new indexes needed**: Step 14 already indexed every field Step 15
  searches on (`patient_number` unique, `national_id`, `passport_number`,
  `phone`, `date_of_birth`, `status`, plus a composite `(last_name, first_name)`).
  `gender` is intentionally not indexed (two values — an index wouldn't help).
- **No relationships are eager-loaded** for search — results never include
  contacts, insurance, or any future clinical data, keeping search lightweight.

### Frontend

`pages/patients/index.tsx` is both the patient list and the search UI: a
debounced (350ms) free-text search box, a collapsible **Advanced Filters** panel
(`components/patients/patient-search-filters.tsx` — patient number, national ID,
passport, phone, date of birth, gender, status, region, district), sortable
column headers, a loading indicator during search, and the shared `EmptyState`/
`Pagination` components. Selecting a result navigates to the existing patient
profile (`/patients/{id}`) — no new profile page was built in this step.

## Authorization

Spatie permissions via `PatientPolicy`:

| Action | Permission |
|---|---|
| View list / record | `patients.view` |
| Register | `patients.create` |
| Update | `patients.update` |
| Deactivate | `patients.delete` |

Patients are a **global identity** (no `hospital_id`), so access is permission-based,
not hospital-scoped. Guests get 401; authenticated-but-unauthorized get 403. Backend
authorization is mandatory — frontend checks are UX only. Record access runs the
policy (IDOR-safe); `patient_number`/`id`/timestamps are not fillable (mass-assignment safe).

## Routes (Inertia only — no API surface)

All behind the `auth` middleware + the policy table above.

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/patients` | `patients.view` |
| POST | `/patients` | `patients.create` |
| GET | `/patients/{patient}` | `patients.view` |
| PUT/PATCH | `/patients/{patient}` | `patients.update` |
| DELETE | `/patients/{patient}` | `patients.delete` |

A parallel `/api/v1/patients` versioned API existed alongside these routes
and was removed in the HMS Route Architecture Migration (no consumer, no
external-integration need — see `docs/api-standards.md`); this web surface
already covered every action it offered.

## Frontend

- Registration: `/patients/create` → `patients/create.tsx` + `components/patients/patient-form.tsx`
  (Personal / Contact / Identification / Address / Emergency-contact sections; required
  indicators, field + server validation, processing/disabled state, cancel).
- Duplicate UX: `duplicate-patient-warning.tsx` (possible, with "continue") and a
  destructive alert linking to the existing record (confirmed, blocked).
- Profile: `/patients/{id}` → `patients/show.tsx`. Edit: `/patients/{id}/edit`.

## Testing

```bash
php artisan test --filter=Patient      # patient-focused tests
php artisan test tests/Feature/Patients tests/Feature/Api/V1
php artisan test                        # full suite
```

Covers: authorized/guest/unauthorized registration, validation (required, future
DOB, email/gender/enum), patient-number generation & non-override, confirmed vs
possible duplicate detection (web + API), authorization & IDOR, mass-assignment
protection, sensitive-field exposure, and audit record creation. All data is
synthetic (Faker) — no real patient information.

**Search-specific** (`PatientSearchTest`, `PatientSearchApiTest`,
`PhoneNormalizerTest`, `PhoneMaskerTest`): exact patient-number/national-ID/
passport/phone lookup, phone-format tolerance, date-of-birth filtering,
case-insensitive multi-word name search, combined filters (name+DOB,
national-ID+DOB, phone+name), sort (valid/invalid/asc/desc), pagination
(including the existing `per_page` clamp), validation (malformed date, invalid
gender/status, too-short search), SQL-injection- and XSS-style input handled
safely, phone masking in results, and guest/unauthorized access rejected.
