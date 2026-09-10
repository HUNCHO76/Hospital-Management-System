# HMS Security Review — Phase 1, Step 13

**Date:** 2026-09-03 · **Reviewer:** Engineering · **Scope:** Phase 1 HMS foundation

## Executive summary

The HMS foundation is in **good** security condition. The authentication,
authorization, API, audit, and data-exposure controls built in Steps 5–12 hold
up under review: no raw SQL, consistent policy-based authorization, multi-hospital
isolation, mass-assignment protection, sanitized audit logs, and safe error
responses. One real hardening gap (missing HTTP security headers) was **fixed**
in this step. The only open item is build-tooling dependency advisories that do
not affect the production runtime.

**Security review: PASS WITH WARNINGS**

| Severity | Count |
|---|---|
| Critical | 0 |
| High | 0 |
| Medium | 1 (open — dev/build dependencies) |
| Low | 1 (fixed) |
| Informational | 3 |

## Scope

Reviewed: authentication, authorization, session/token security, API security,
input validation, mass assignment, SQL injection, XSS, CSRF, IDOR/object-level
authz, file uploads, password security, secrets/config, database config, error
handling, audit logging, rate limiting, CORS, security headers, frontend
security, and PHP/NPM dependencies. Route list and middleware stack inspected.

## Findings

### SEC-01 — Missing HTTP security headers — LOW — FIXED
- **Component:** HTTP layer (all responses).
- **Description:** Responses did not send `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, or HSTS.
- **Risk:** Marginally increased exposure to MIME sniffing and clickjacking; no direct data exposure.
- **Evidence:** Only `ApiRequestContext` and `HandleInertiaRequests` middleware existed; no header hardening.
- **Fix:** Added `app/Http/Middleware/SecurityHeaders.php` (registered globally in `bootstrap/app.php`) setting the headers above; HSTS emitted only over HTTPS so local dev is unaffected. CSP deliberately deferred (see SEC-04).
- **Status:** Fixed + regression test.

### SEC-02 — Vulnerable frontend build dependencies — MEDIUM — OPEN
- **Component:** Dev toolchain (`npm`): `rollup` (via `vite`), `shell-quote` (via `concurrently`), and related.
- **Description:** `npm audit` reports 23 advisories (2 critical, 12 high). All are in build/dev-time packages.
- **Risk:** Build/CI-time only; these packages are **not** shipped in the browser bundle and do not run in the Laravel/production runtime, so production attack surface is unaffected. Relevant mainly to CI/developer machines.
- **Evidence:** `npm ls` shows the vulnerable packages under `vite`/`concurrently` (devDependencies); `composer audit` is clean.
- **Recommended fix:** Run `npm audit fix` (semver-compatible) and re-verify `npm run build`; upgrade Vite/concurrently on a maintenance cadence. Not applied here — changing dependencies requires maintainer approval.
- **Status:** Open (recommended).

### SEC-03 — Production configuration must be hardened at deploy — INFORMATIONAL
- **Component:** Environment configuration.
- **Description:** Local `.env` has `APP_DEBUG=true`, `APP_URL=http://…`, and `SESSION_SECURE_COOKIE` unset — correct for local development, unsafe for production.
- **Risk:** Only if these local values reach production. `.env` is git-ignored, so they cannot be committed.
- **Recommended fix:** Enforce the production checklist in `docs/security.md` (`APP_DEBUG=false`, HTTPS `APP_URL`, `SESSION_SECURE_COOKIE=true`, configured `API_ALLOWED_ORIGINS`).
- **Status:** Documented (deploy-time responsibility).

### SEC-04 — No Content-Security-Policy — INFORMATIONAL
- **Component:** HTTP headers / frontend.
- **Description:** No CSP is set. A well-formed, nonce-based CSP for the Vite/Inertia app would add defense-in-depth against XSS.
- **Risk:** Low — React auto-escaping already mitigates XSS; no unescaped sinks were found.
- **Recommended fix:** Introduce a tested nonce-based CSP in a dedicated hardening pass (shipping a broken CSP would break the app).
- **Status:** Accepted risk / future work.

### SEC-05 — `dangerouslySetInnerHTML` in pagination component — INFORMATIONAL
- **Component:** `resources/js/components/pagination.tsx`.
- **Description:** Renders `link.label` as HTML.
- **Risk:** None in practice — the labels are trusted, server-generated Laravel paginator strings (`« Previous`, page numbers), never user input.
- **Status:** Verified safe; documented.

## Controls reviewed and confirmed sound (no finding)

- **Password security:** bcrypt hashing, `hashed` cast, `password`/`remember_token` hidden; never logged or returned.
- **Authentication:** session + Sanctum; login/logout/password-reset flows; failed-login auditing without secrets.
- **Authorization & privilege escalation:** policies + permission middleware; `assignableRoles()` excludes super-admin; `staff.create` is super-admin-only.
- **IDOR:** hospital-scoped `StaffPolicy`; `ensureBelongsToPatient` (404); API policy checks. Verified via tests including HTTP-method tampering.
- **Mass assignment:** `$fillable` on all models; `validated()` used; forcing `id`/`created_at` has no effect.
- **SQL injection:** no raw SQL; whitelisted sorting.
- **XSS / CSRF:** React escaping; web CSRF via default middleware; stateless API tokens.
- **API security & data exposure:** auth + throttle; Resources whitelist fields; safe error envelope.
- **CORS:** scoped to `api/*`, env-driven origins, credentials off.
- **Session/token security:** http_only + lax cookies; token revocation on logout; tokens never logged.
- **Audit logging:** append-only, immutable via UI, secrets sanitized.
- **Error handling:** no stack traces/SQL/paths leaked to clients.
- **File uploads / path traversal:** no upload or user-controlled file operations exist yet (N/A).

## Fixed issues
- SEC-01 — HTTP security headers added (middleware + tests).

## Accepted risks
- SEC-04 — CSP deferred to a dedicated, tested hardening pass.
- SEC-05 — trusted-source `dangerouslySetInnerHTML` retained.

## Remaining risks
- SEC-02 — build-tooling dependency advisories; resolve with an approved `npm audit fix` / toolchain upgrade. No production runtime impact.
- SEC-03 — production environment must apply the deployment checklist.

## Commands executed
- `composer audit` → **No security vulnerability advisories found.**
- `npm audit` → 23 advisories, all in dev/build tooling (see SEC-02).
- `php artisan test` → **360 passed** (incl. 9 new security regression tests).
- `php artisan route:list` → all sensitive routes are behind `auth`/`auth:sanctum` + policies + throttling.
