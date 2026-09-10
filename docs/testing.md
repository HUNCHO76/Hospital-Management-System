# Testing Guide

The HMS backend is covered by an automated test suite built on **Pest** (on top
of PHPUnit) using Laravel's HTTP and database testing utilities. Tests are the
safety net for every Phase 1 module and must stay green before any new work
merges.

## Running the tests

```bash
php artisan test                      # whole suite (compact: add --compact)
php artisan test --testsuite=Unit     # unit tests only
php artisan test --testsuite=Feature  # feature tests only
php artisan test --filter=PatientApi  # a single test/file by name
vendor/bin/pest                       # run Pest directly (same arguments)
```

Coverage (requires Xdebug or PCOV):

```bash
php artisan test --coverage
```

## Test environment (isolation)

Configured in `phpunit.xml` — no developer-specific setup is required, so the
suite runs unchanged in CI:

| Concern | Setting | Effect |
|---|---|---|
| Environment | `APP_ENV=testing` | Never touches dev/prod config |
| Database | `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` | Fresh in-memory DB; **cannot** reach the MySQL dev/prod database |
| Cache / Session | `array` | No shared state between tests |
| Queue | `sync` | Jobs run inline; no worker needed |
| Mail | `array` | No real email is sent |
| Hashing | `BCRYPT_ROUNDS=4` | Fast password hashing in tests |

Every DB-backed test uses `RefreshDatabase`, so migrations run against the
in-memory database and each test starts from a clean, isolated state — tests do
not depend on execution order.

## Structure

```
tests/
├── Unit/
│   └── Models/        # model behaviour: relationships, casts, business numbers, soft deletes
├── Feature/
│   ├── Api/V1/        # versioned API: health, auth, patients, staff isolation, errors, rate limits
│   ├── Audit/         # audit generation, viewing, search, sensitive-data redaction
│   ├── Auth/          # login, logout, registration, password reset
│   ├── Authorization/ # policies, permission middleware, role/permission matrix
│   ├── Database/      # migration integrity, constraints, seeders
│   ├── Departments/ Hospitals/ Patients/ Practitioners/ Staff/ Insurance/ Settings/
│   └── ...
├── Pest.php           # binds TestCase + RefreshDatabase; global helpers
└── TestCase.php
```

`tests/Pest.php` binds `TestCase` + `RefreshDatabase` to `Feature`, `Unit/Models`
and `Unit/Concerns`, and exposes two helpers used across the suite:

- `seedRolesAndPermissions()` — runs `PermissionSeeder` + `RoleSeeder`.
- `userWithRole(string $role)` — creates a user assigned that role (permissions seeded).

## Factories

Every model has a factory under `database/factories`. Factories generate only
**fake** data (via Faker) — never real patient names, national IDs, phone
numbers, or credentials. Notable states/traits:

- `AuditLogFactory::withoutUser()` — a system event with no acting user.
- FK-independent factories (e.g. `PatientInsuranceFactory`) default the
  dependent foreign key to `null` so unrelated records are never linked by
  accident; pass the parent id explicitly when a relationship matters.

## Mocking external effects

The app currently performs no custom notifications, queued jobs, or outbound
HTTP calls, so those need no per-test fakes — the environment config above
already neutralises mail (array) and queues (sync). When such features are
added, use Laravel's fakes in the relevant tests:

```php
Notification::fake();  Mail::fake();  Queue::fake();  Http::fake();
```

Never call real external services or send real mail/SMS from a test.

## What is covered

- **Authentication** — session login/logout, registration, password reset; API token issue/revoke.
- **Authorization** — every policy, permission middleware, guest/unauthorized/authorized paths, and multi-hospital isolation (staff IDOR).
- **API (v1)** — standard success/error envelope, status codes, validation (422), pagination `meta`, rate limiting (429), health.
- **Validation** — Form Request rules for patients, staff, insurance, etc. (required/enum/unique/foreign-key/date).
- **Business rules** — business-number generation, primary-insurance switching, duplicate detection, provider→scheme integrity.
- **Audit logging** — events are recorded for create/update/deactivate/login/logout; sensitive fields (password, tokens, secrets) are never stored; the log is immutable via the web UI.
- **Security** — protected routes, unauthorized access, password hashing, hidden `password`/`remember_token`, IDOR.
- **Database** — all core tables/constraints exist, soft-delete columns present, seeders populate roles/permissions.

## Conventions

- **Naming**: test names describe the behaviour — `it('rejects a scheme belonging to a different provider')` — never `test1`/`works`.
- **Arrange → Act → Assert**: one behaviour per test.
- **Isolation**: no test depends on another; all use fresh DB state.
- **Failures**: never edit a test to match broken behaviour — fix the root cause, or the test's own wrong assumption, and say which.

## CI/CD

The suite is CI-ready: it needs no local data, no manual steps, and no external
services. A CI job only needs PHP 8.2+, the Composer dependencies, and:

```bash
php artisan test
```
