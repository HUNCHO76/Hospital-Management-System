# HMS Routing Architecture & API Standards

## The rule

```
Internal HMS functionality   → routes/web.php  → Controllers → Services/Actions → Inertia → React
External integrations        → routes/api.php  → Integration Services → External API
```

**Internal HMS functionality is never built as an API consumed by React.** Every
page, form, search, and workflow action in this application is a Laravel web
route that returns an Inertia response (or redirects back to one). React talks
to the backend through Inertia's `router.get/post/put/patch/delete`, never
through `axios`/`fetch` against an internal endpoint.

`routes/api.php` exists **only** for genuine external-integration endpoints —
a real client outside this application (a future NHIF integration, a mobile
app, a third-party system) that cannot use a session-authenticated Inertia
page. A new internal module needing data in React is never a reason to add a
route here. If you're about to add a controller under `app/Http/Controllers/`
that returns `response()->json(...)` for something a browser page could
instead receive as an Inertia prop, it belongs in `web.php` instead.

## History

This project originally built a full versioned external API (`/api/v1/...`)
in parallel with the web application, covering every internal module
(patients, staff, appointments, clinical encounters, triage, vital signs,
consultation, diagnosis) — one Api\V1 controller/Resource/Request per web
controller, sharing the same Service layer. It was a deliberate, documented
design (this file, prior revisions), not accidental drift.

It was removed in the **HMS Route Architecture Migration**: an audit found
zero consumers anywhere in the repository (no `axios`, no `fetch` to
`/api/v1/`, no mobile app, no external client, no NHIF integration — NHIF
does not exist in this codebase; see `docs/insurance.md`) despite being fully
tested. Every one of its endpoints already had a complete, working web+Inertia
equivalent. Keeping a fully-featured, unused external API surface around
"for later" is exactly the trap section above warns against — it invites new
internal modules to keep adding to it out of habit. All of it was deleted;
`routes/api.php` now contains only a public liveness check
(`GET /api/health`), and this document now states the policy rather than a
now-removed contract.

## What still lives here and why

| Route | Purpose |
|---|---|
| `GET /api/health` | Public liveness/uptime-monitoring endpoint. Not application functionality — the one deliberate exception, since an ops probe isn't "internal HMS functionality" any more than it's an external integration. |

Laravel's own built-in `/up` health check (`bootstrap/app.php`'s
`health: '/up'`) also exists independently for process-level monitoring;
`/api/health` is the JSON-envelope one for anything that wants a structured
body.

## Infrastructure kept in place for future use

Nothing above requires deleting the supporting infrastructure a real
integration will need later — removing it now would just mean rebuilding it:

- **`App\Support\Api\ApiResponse`** — the standard JSON envelope (`success`,
  `message`, `data`, `meta` for pagination, `request_id`). Still used by the
  health check; reuse it for any future integration endpoint rather than
  inventing a new response shape.
- **Rate limiters** (`AppServiceProvider::configureRateLimiters()`) — named
  `api` (standard authenticated traffic) and `auth` (strict, credential
  endpoints) limiters, backed by `config/api.php`'s `rate_limits`. Currently
  unused by any route; apply them to a new integration's routes rather than
  writing new ones.
- **Laravel Sanctum** — installed, `User` still has `HasApiTokens`. No route
  currently issues or checks a token, but this is what a future bearer-token
  external client (NHIF or otherwise) would authenticate through.
- **`config/api.php`** — pagination bounds, rate limits, `allowed_origins`
  for CORS. Still the place to configure a future integration.

## When you actually need a new `routes/api.php` endpoint

Only when there's a genuine external caller that cannot be a browser session
— a documented integration contract (e.g. NHIF's REST API expects a webhook
callback into this app), a mobile app, or an approved third-party consumer.
When that day comes:

```
routes/api.php  →  Controller  →  Form Request  →  Service  →  Model  →  Resource  →  JSON
```

- API controllers return JSON only; Inertia controllers return Inertia only. Never mix them.
- Business logic lives in `App\Services\*` and is **shared** with the web app — never duplicated into a parallel API-only implementation.
- Build the response via `App\Support\Api\ApiResponse` (envelope shapes below), authenticate via Sanctum, and apply the `api`/`auth` rate limiters.
- Never expose external-integration credentials (client secrets, tokens) to React — all HTTP communication with the external system happens server-side inside a dedicated `App\Services\{Integration}\*` class, called from a normal web controller if the result needs to reach a page (see `docs/insurance.md`'s NHIF section for the intended shape once that integration exists).

### Standard response envelope (for when one is needed again)

Success (single):
```json
{ "success": true, "message": "...", "data": { }, "request_id": "uuid" }
```
Success (collection):
```json
{ "success": true, "message": "...", "data": [], "meta": { "current_page": 1, "per_page": 20, "total": 100, "last_page": 5 }, "request_id": "uuid" }
```
Error:
```json
{ "success": false, "message": "...", "errors": { "field": ["..."] }, "request_id": "uuid" }
```

All responses carry a `request_id` and an `X-Request-ID` header for tracing.
Production never exposes stack traces, SQL, credentials, tokens, or file
paths — enforced globally in `bootstrap/app.php`'s exception handling for any
request under `api/*` or expecting JSON.
