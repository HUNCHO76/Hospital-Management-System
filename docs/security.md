# HMS Security Configuration & Architecture

How security is implemented across the HMS, and what must be set for a secure
production deployment. No real secrets appear in this document.

## Authentication

- **Session auth** for the entire application (Inertia) — internal HMS functionality has no separate API surface (see `docs/api-standards.md`). Sanctum remains installed for a future external-integration bearer-token consumer, but no route currently issues or checks a token.
- Passwords are hashed with Laravel's `Hash` (bcrypt, `BCRYPT_ROUNDS=12` in prod) and the `User.password` cast is `hashed` — plaintext storage is impossible.
- `password` and `remember_token` are in the model's `$hidden` array and never serialized.
- Login is rate-limited (5 attempts per email+IP). Password-reset routes use `throttle:6,1`. The `auth`/`api` rate limiters remain configured in `AppServiceProvider` for reuse by a future external-integration endpoint.
- Logout invalidates the session (web) and deletes the current token (API).
- Failed logins are audited (`login_failed`) **without** the submitted password.

## Authorization

- Spatie roles/permissions; `Gate::before` grants super-admin everything.
- Every privileged action is guarded by a **Policy** (`authorizeResource`/`$this->authorize`) or the permission middleware — enforced on the backend, not the UI.
- **Privilege escalation** is blocked: staff onboarding's `assignableRoles()` excludes `super-admin`, and `staff.create` is granted only to super-admin.
- **Multi-hospital isolation**: hospital-scoped resources (staff) derive the hospital from the authenticated user's own record — never a client-supplied `hospital_id` — via `StaffPolicy`. Patients are a global identity, permission-scoped.
- **IDOR**: nested resources verify ownership (`PatientInsuranceController::ensureBelongsToPatient` → 404); API record access runs policies.

## Input validation & data integrity

- All writes go through Form Requests (required/enum/unique/foreign-key/date rules); frontend validation is never trusted.
- **Mass assignment**: every model declares `$fillable`; controllers/services pass `$request->validated()`. Primary keys and timestamps cannot be smuggled in.
- **SQL injection**: no raw SQL (`whereRaw`/`DB::raw`/`orderByRaw`) anywhere; Eloquent/query-builder bindings only. API sorting is whitelisted.
- **XSS**: React escapes by default; no Blade `{!! !!}`; the single `dangerouslySetInnerHTML` (pagination) renders trusted Laravel paginator labels only.

## API security

- `auth:sanctum` + `throttle:api` on protected routes; standard JSON envelope; API Resources whitelist fields (no internal columns leak).
- Errors return safe messages only — no stack traces, SQL, or file paths (see `bootstrap/app.php` render handler). `X-Request-ID` aids tracing.

## Sessions & cookies

`config/session.php`: `http_only=true`, `same_site=lax`, `secure` via `SESSION_SECURE_COOKIE`. Session regenerates on login and is invalidated on logout.

## CORS

`config/cors.php` applies only to `api/*`. Allowed origins come from `API_ALLOWED_ORIGINS` (empty by default — no cross-origin access until explicitly configured); `supports_credentials=false` (bearer tokens, not cookies). Never `*` for a credentialed API.

## HTTP security headers

`app/Http/Middleware/SecurityHeaders.php` (global): `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` (camera/mic/geolocation/payment disabled), `X-XSS-Protection: 0`, and `Strict-Transport-Security` **only over HTTPS**.

> **Content-Security-Policy** is intentionally not yet set — it requires a tested, nonce-based policy for the Vite/Inertia front end. Tracked as future hardening; a broken CSP is worse than none.

## File uploads

No upload endpoints exist in Phase 1. When added, enforce: server-side MIME/extension allow-lists, size limits, generated (non-user) filenames, private disk storage, and policy-gated access for medical documents.

## Secrets management

- `.env`, `.env.backup`, `.env.production` are git-ignored; `.env.example` holds placeholders only (no real credentials).
- No secrets are hardcoded in source. `VITE_`-prefixed vars are the only ones exposed to the browser bundle — keep backend secrets un-prefixed.

## Audit logging

Append-only (`audit_logs`, no `updated_at`); no edit/delete routes and the policy denies them. Records actor, action, module, record id, IP, user agent, URL, timestamp. `AuditLogService` strips `password`/`token`/`secret`/`api_key`/etc. from stored values.

## Logging

`ApiRequestContext` logs only method, path, status, duration, user id, hospital id, IP — never tokens, auth headers, or request bodies.

## Production deployment checklist

Set in the production environment (not committed):

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
API_ALLOWED_ORIGINS=https://your-frontend-origin
BCRYPT_ROUNDS=12
```

- Serve exclusively over HTTPS (HSTS then activates automatically).
- Use a least-privilege MySQL user (only the app schema; no admin grants); never expose the DB port publicly.
- Run `php artisan config:cache route:cache` on deploy; keep `APP_KEY` set and secret.
- Rotate any credential that was ever committed.

## Dependency auditing

- `composer audit` — run per release (currently clean).
- `npm audit` — build-tooling dev dependencies (rollup/shell-quote) carry advisories; they run at build time only and don't ship to production. Address with `npm audit fix` and re-verify the build.

## Security testing

Regression tests live in `tests/Feature/Security/` and across the suite (auth, authorization, IDOR/hospital isolation, audit redaction). Run `php artisan test` before every release.
