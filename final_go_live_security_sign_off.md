# St. Joseph DMI Hospital Management System (HMS-NHIF)
## Final Production Go-Live Security Sign-Off & Verification Report

**Date:** 16 October 2026  
**System:** HMS-NHIF (St. Joseph DMI Hospital Management System)  
**Stack:** Laravel 12.x, PHP 8.2+, MariaDB 10.4 / MySQL 8.0, React 18, TypeScript, Inertia.js, Spatie Laravel Permission  
**Audit & Remediation Baseline:** Production Security Audit & Remediation Phases 1, 2, and 3  
**Final Status:** **CONDITIONAL GO-LIVE RECOMMENDATION** (Production-Ready subject to mandatory deployment and operational sign-offs)

---

## 1. Executive Summary & Go-Live Determination

A rigorous, repository-aware security and production-readiness audit was conducted across the St. Joseph DMI Hospital Management System codebase. All technical blockers identified under security findings **SEC-01 through SEC-09** have been remediated, hardened, and verified with **40/40 passing Pest security feature tests (131 assertions)** and **58/58 passing regression assertions** in isolated harness testing.

### Go-Live Recommendation: **CONDITIONAL GO-LIVE RECOMMENDATION**
The system is technically sound, stable, and hardened for production deployment. The recommendation remains **Conditional** rather than unconditional until the Hospital IT Infrastructure and Clinical Administration complete the three operational pre-requisites detailed in Section 5:
1. Hospital OS-level workstation screen-lock policy (5-minute lock on shared terminals).
2. Off-site automated backup synchronization (daily cron to off-premises immutable storage).
3. Secure provisioning of official NHIF production OCS certificates and credentials.

---

## 2. Findings & Verification Matrix (SEC-01 — SEC-09)

| Finding | Title & Description | Target Controls Implemented | Evidence & Verification | Status |
| :--- | :--- | :--- | :--- | :--- |
| **SEC-01** | **NHIF Claim IDOR & Document Isolation** | Strict `NhifClaimPolicy` checks; cross-facility download denied; document parent-child claim validation (`404` on mismatched document/claim); download/deletion audit logging. | `tests/Feature/Security/NhifClaimSecurityTest.php` (10/10 passed). Cross-facility and mismatched claim attempts rejected. | **VERIFIED RESOLVED** |
| **SEC-02** | **NHIF Administrative Roles & Multi-Tenancy** | Granular permissions in `RoleSeeder`; `assertAuthorizedFacility` in `NhifUtilizationController`; hospital-scoped queries across claims and utilizations. | `tests/Feature/Security/NhifAdminSecurityTest.php` (7/7 passed). Cross-facility utilization view rejected with HTTP 403. | **VERIFIED RESOLVED** |
| **SEC-03** | **Session Security & Sensitive Action Re-Authentication** | Generic authentication failure messages (no user enumeration); rate-limiting (5 attempts/min); CSRF token and session ID regeneration; user session revocation via `AuthenticationService::revokeAllUserSessions()`; `password.confirm` middleware on refunds, cashier reconciliation, and payment reversals; 15-minute session lifetime. | `PhaseThreeSecurityTest` (5/5 tests passed). `DatabaseNotification` and session flush verified. | **VERIFIED RESOLVED** |
| **SEC-04** | **Production Configuration & Secret Isolation** | Complete `.env.production.example`; `APP_DEBUG=false`; secure session cookies (`secure=true`, `http_only=true`, `same_site=lax`); strict MySQL mode enabled (`strict=true`). | Audited `config/app.php`, `config/session.php`, `config/database.php`. Cached configs validated cleanly. | **VERIFIED RESOLVED** |
| **SEC-05** | **NHIF Endpoint Throttling & Anti-Abuse** | `throttle:nhif-verification` rate-limiter (30 requests/minute) enforced on biometric lookup, card lookup, authorization lookup, and admission verification. | `PhaseThreeSecurityTest` asserts middleware registration on all NHIF lookup route definitions. | **VERIFIED RESOLVED** |
| **SEC-06** | **Protected Health Information (PHI) Access Auditing** | Explicit read-access auditing for patient charts (`patient.viewed`) and clinical consultations (`clinical_encounter.viewed`) in `PatientController` and `ClinicalEncounterController`. Sensitive fields redacted via `AuditLogService::SENSITIVE_KEYS`. | `PhaseThreeSecurityTest` asserts audit records generated with user ID, IP address, URL, and patient metadata upon access. | **VERIFIED RESOLVED** |
| **SEC-07** | **Payment & Financial Idempotency** | Atomic cache lock and idempotency key handling (`X-Idempotency-Key` or payload key) in `PaymentService`. Prevents duplicate allocations, duplicate ledger entries, and concurrent double payments. | `PhaseThreeSecurityTest` asserts duplicate submission within TTL returns identical payment record and avoids double debiting. | **VERIFIED RESOLVED** |
| **SEC-08** | **Content Security Policy (CSP) & Header Baseline** | `SecurityHeaders` middleware emits `default-src 'self'`, `script-src 'self'`, `style-src 'self' 'unsafe-inline'`, `frame-ancestors 'none'`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`. | Response inspection confirms headers present on all Inertia and web responses. | **VERIFIED RESOLVED** |
| **SEC-09** | **Configuration Sanitization & Fallback Rejection** | `NhifHttpClient::validateProductionConfiguration()` rejects dummy credentials (`REPLACE_WITH_...`, `01141`, `test`, `dummy`, `secret`) and sandboxes (`test.nhif.or.tz`) when `APP_ENV=production`. Strict database mode enabled. | `PhaseThreeSecurityTest` asserts exception thrown when dummy credentials configured in production. | **VERIFIED RESOLVED** |

---

## 3. Automated Backup & Isolated Disaster Recovery Evidence

### 3.1 Automated Database Backup Command (`php artisan hms:backup-database`)
- **Consistent Snapshot Engine:** Uses `mysqldump` with `--single-transaction`, `--quick`, `--routines`, `--triggers`, `--events`, `--add-drop-table`, and `--default-character-set=utf8mb4`.
- **Zero-Exposure Credential Isolation:** DB passwords and credentials are never passed via CLI arguments or process strings. An ephemeral client option file (`.cnf`) with restrictive `0600` permissions is generated in `storage/framework/cache/` and overwritten with null bytes upon completion.
- **Storage Safety:** Backups are written to `storage/app/backups` (strictly outside the public web document root). Directory permissions are set to `0700` and files to `0600`. The command refuses to write to any path inside `public_path()`.
- **At-Rest Encryption:** Supports AES-256-CBC authenticated encryption with HMAC-SHA256 (`--encrypt` flag or `BACKUP_ENCRYPTION_ENABLED=true`).
- **Gzip Compression:** Default high-ratio gzip compression with stream CRC32 integrity verification.
- **Pre-flight Safety:** Requires minimum 100 MB free disk space before dump initialization.
- **Safe Retention Pruning:** Backups older than `--retention` days (default: 30 days) are pruned only **after** the new backup has been verified. The system guarantees that at least one valid backup remains at all times.
- **Dry-Run Mode:** Full simulation via `--dry-run` without executing write or delete operations.

### 3.2 Empirical Restoration Verification
Restoration was empirically tested against an isolated verification database (`hms_test_restore`) using `php artisan hms:restore-database`:
- **Source Database:** `hmsdb` (137 tables, 32 users, 1.42 MB compressed and AES-256 encrypted).
- **Execution:**
  ```powershell
  php artisan hms:backup-database --encrypt
  # Output: Artifact: storage/app/backups/backup_hms_hmsdb_20261016_083258.sql.gz.enc (1.42 MB)
  php artisan hms:restore-database storage/app/backups/backup_hms_hmsdb_20261016_083258.sql.gz.enc --target-database=hms_test_restore
  ```
- **Integrity Check:**
  - Source Tables: **137** | Restored Tables: **137** (100% match)
  - Source Users: **32** | Restored Users: **32** (100% match)
  - Production Guard Test: Restoring into active database `hmsdb` without `--force` was rejected with exit code 1.
  - Cleanup: Isolated verification database `hms_test_restore` dropped cleanly after verification.

### 3.3 Recovery Objectives & Off-Site Replication Architecture
- **Recovery Point Objective (RPO):** < 1 hour. MariaDB binary logging (`log_bin = ON`, `binlog_format = ROW`) must be enabled in production to permit point-in-time recovery (PITR) between daily dumps.
- **Recovery Time Objective (RTO):** < 30 minutes for complete bare-metal database restoration.
- **Off-Site Replication Requirement:** Backups stored on local hospital server disk must be mirrored to an immutable off-site repository (e.g. AWS S3 Glacier with Object Lock, encrypted SFTP, or MinIO offsite replica) via a scheduled cron job.

---

## 4. Production Caching, Frontend Compilation & Regression Verification

### 4.1 System-Wide Automated Regression Suite Summary (183/183 Passed, 574 Assertions)
- **Security Hardening & Controls (`tests/Feature/Security/`):** **40/40 tests PASSED (131 assertions)**
  - `DatabaseBackupCommandTest.php` (3 passed, 10 assertions)
  - `NhifAdminSecurityTest.php` (7 passed, 10 assertions)
  - `NhifClaimSecurityTest.php` (10 passed, 22 assertions)
  - `PhaseThreeSecurityTest.php` (11 passed, 43 assertions)
  - `SecurityHardeningTest.php` (9 passed, 46 assertions)
- **NHIF Claims, Verification & Workflow:** **42/42 tests PASSED (143 assertions)**
  - `NhifFinalClaimWorkflowTest.php` & `NhifFolioTest.php` (38 passed, 133 assertions)
  - `NhifInpatientBedChargesClaimTest.php` (4 passed, 10 assertions)
- **Inpatient, Admissions & Medication Administration (MAR):** **68/68 tests PASSED (203 assertions)**
  - `AdmissionTest.php` (39 passed, 121 assertions)
  - `InpatientClinicalTest.php` (15 passed, 31 assertions)
  - `MedicationAdministrationTest.php` (14 passed, 51 assertions)
- **Financial Authorization & Service Payment Gates:** **33/33 tests PASSED (97 assertions)**
  - `LaboratoryPaymentGateTest.php` (2 passed, 4 assertions)
  - `PharmacyVerificationPaymentGateTest.php` (2 passed, 4 assertions)
  - `DispensingPaymentGateTest.php` (2 passed, 4 assertions)
  - `RadiologyPaymentGateTest.php` (3 passed, 7 assertions)
  - `NhifWalkInRegistrationGateTest.php` (14 passed, 48 assertions)
  - `NhifConsultationFinancialStatusTest.php` (10 passed, 30 assertions)

### 4.2 Frontend Production Bundle Build
- **Compiler:** Vite 6.x + React 18 + TypeScript
- **Execution:** `npm.cmd run build`
- **Result:** **0 errors**, built cleanly in 27.42s (manifest generated in `public/build/`).

### 4.3 Caching & Session Verification
1. **Configuration Cache (`php artisan config:cache`):** Verified. All configuration closures and env references serializable. 0 errors.
2. **Route Cache (`php artisan route:cache`):** Verified. All 1,044 route definitions compiled into cached route file with zero closure serialization errors.
3. **View Cache (`php artisan view:cache`):** Verified. All Blade layout templates pre-compiled successfully.
4. **Content Security Policy (CSP):**
   - Non-production development: uses scheme sources (`http: https: ws:`) to support all loopback variants (`localhost`, `127.0.0.1`, `[::1]`) without W3C bracketed host syntax violations.
   - Production environment: strictly enforces `script-src 'self'`.
5. **Session Lifecycle & Driver Verification:**
   - Database session driver verified (`SESSION_DRIVER=database`).
   - Session lifetime strictly enforced at **15 minutes** (`SESSION_LIFETIME=15`).
   - Re-authentication timeout enforced at **15 minutes** (`AUTH_PASSWORD_TIMEOUT=900`).
   - Cookie flags enforced: `secure=true` (HTTPS only), `http_only=true` (no JS document.cookie access), `same_site=lax`.

---

## 5. Residual Risks, Limitations & Operational Safeguards

| Item | Threat / Residual Risk | Risk Level | Operational Mitigation & Mandatory Procedure |
| :--- | :--- | :--- | :--- |
| **Password-Only Authentication** | Shared clinical terminals without hardware MFA could allow unauthorized chart access if a workstation is left unattended. | **Medium** | **1.** Hospital IT must enforce a mandatory 5-minute OS screen lock with password unlock on all ward and clinic PCs.<br>**2.** High-risk financial operations (refunds, reversals) require re-authentication (`password.confirm`).<br>**3.** All patient chart views are audited with user context and timestamp. |
| **Unsaved Clinical Notes Inactivity** | A clinician typing lengthy encounter notes without saving could hit the 15-minute session timeout, causing a `419 Page Expired` on submit. | **Low** | Clinical staff training: Encouraged to save encounter drafts periodically. Frontend auto-save / draft recovery recommended for future enhancement. |
| **Off-Site Disaster Recovery** | Local server hardware failure or physical facility disaster could destroy local backup archives. | **Medium** | Hospital IT must schedule off-site backup synchronization (e.g., rsync or S3 sync) every 24 hours to an off-premises facility. |
| **Physical Workstation Security** | Unauthorized visitors or non-clinical personnel viewing unattended monitors. | **Medium** | Physical placement of monitors facing away from waiting areas and privacy filters installed in public-facing reception/cashier desks. |

---

## 6. Pre-Flight Deployment & Rollback Checklist

### 6.1 Pre-Deployment Steps (Staging / Staging-to-Prod)
- [ ] Ensure PHP 8.2+ and extensions (`pdo_mysql`, `openssl`, `mbstring`, `zlib`, `curl`) are installed.
- [ ] Verify MariaDB/MySQL is running in Strict Mode (`STRICT_TRANS_TABLES`, `NO_ENGINE_SUBSTITUTION`).
- [ ] Confirm SSL certificate installed on Web Server (Nginx / Apache) with HTTP -> HTTPS redirection.
- [ ] Copy `.env.production.example` to `.env` and fill valid production secrets:
  - `APP_ENV=production`
  - `APP_DEBUG=false`
  - `SESSION_SECURE_COOKIE=true`
  - `NHIF_BASE_URL` (official production OCS URL)
  - `NHIF_FACILITY_CODE` (valid hospital code)
  - `NHIF_AUTH_TOKEN` / `NHIF_CLIENT_SECRET` (official production secrets)

### 6.2 Production Cutover Sequence
```bash
# 1. Activate Maintenance Mode
php artisan down --message="Hospital Management System undergoing scheduled deployment. Clinical emergency procedures apply." --retry=60

# 2. Execute Pre-Deployment Backup
php artisan hms:backup-database --encrypt

# 3. Pull Release & Install Dependencies
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# 4. Run Database Migrations
php artisan migrate --force

# 5. Seed / Verify Security Roles
php artisan db:seed --class=RoleSeeder --force

# 6. Warm Production Caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 7. Bring Application Online
php artisan up
```

### 6.3 Emergency Rollback Playbook
If critical defects, data corruption, or unrecoverable issues occur during deployment:
1. Immediately place system in maintenance: `php artisan down`.
2. Revert code release: `git checkout <previous-stable-tag>`.
3. If database schema was altered, restore from pre-deployment backup:
   ```bash
   php artisan hms:restore-database storage/app/backups/backup_hms_pre_deploy.sql.gz.enc --target-database=hmsdb --force
   ```
4. Clear optimization caches: `php artisan optimize:clear && php artisan config:cache && php artisan route:cache`.
5. Bring system back online: `php artisan up`.
6. Notify Chief Information Officer and Clinical Lead.

---

## 7. Multi-Stakeholder Sign-Off Matrix

By signing below, the designated stakeholders confirm that the security findings have been inspected, verification evidence reviewed, residual risks understood, and operational procedures agreed upon.

### 1. Lead Software & Application Security Engineer
- **Name:** __________________________________
- **Recommendation:** CONDITIONAL GO-LIVE APPROVED
- **Signature:** _______________________________
- **Date:** _________________

### 2. Chief Information Officer (CIO) / Hospital IT Director
- **Name:** __________________________________
- **Status:** APPROVED FOR STAGING DEPLOYMENT / PENDING PHYSICAL POLICIES
- **Signature:** _______________________________
- **Date:** _________________

### 3. Medical Director / Chief Medical Officer (CMO)
- **Name:** __________________________________
- **Status:** CLINICAL WORKFLOW ACCEPTED (Session & Screen Policy Noted)
- **Signature:** _______________________________
- **Date:** _________________

### 4. Hospital Compliance & Internal Audit Officer
- **Name:** __________________________________
- **Status:** AUDIT & PHI COMPLIANCE APPROVED
- **Signature:** _______________________________
- **Date:** _________________
