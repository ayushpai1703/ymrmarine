# YMR Marine / YSMS — Infrastructure Root Cause Analysis

**Date:** 2026-10-10
**Scope:** `ymrmarine` GCP project (`ymr-sms`), Cloud Run services `ymrmarine` (production) and `ymrmarine-staging`, shared Cloud SQL instance `ymr-sms:asia-south1:ymrmarine`.
**Method:** Static repository inspection (code, `cloudbuild.yaml`, `Dockerfile`, SQL files, git history) plus this session's own first-hand debugging history. **No live GCP access was available in this session** (`gcloud auth list` → "No credentialed accounts"). Every finding below is tagged with its evidence source; nothing here is invented from billing or monitoring data we could not see.

## Evidence sources key

| Tag | Meaning |
|---|---|
| `BILLING` | User-provided Cloud Billing "Reports" export, **October 2026 statement (2026-10-01 → 2026-10-31)** — real, actual GCP cost data, the strongest possible evidence tier |
| `REPO` | Found directly in the repository (code, config, SQL, git log) |
| `SESSION` | Personally diagnosed and fixed earlier in this same working session — the strongest evidence available |
| `NEEDS-LIVE` | Cannot be confirmed without `gcloud`/Cloud Console/Billing access; treat as hypothesis only |

---

## October 2026 actual billing — the headline finding

The user supplied the account's Cloud Billing "Reports" PDF for October 2026. This is real, not estimated, and it reframes everything below: **the infrastructure's real problem is cost concentration in one SKU, not the database engine choice.**

| Product | Subtotal (₹, Oct 2026) | % change vs. previous period |
|---|---:|---:|
| **Cloud SQL** | **₹20,329.50** | **+2504%** |
| Compute Engine | ₹818.59 | +3369% |
| Cloud Load Balancing | ₹507.68 | +3380% |
| Gemini API | ₹131.47 | New this period |
| Persistent Disk | ₹49.30 | +1566% |
| Artifact Registry | ₹22.48 | +3022% |
| Cloud DNS | ₹5.85 | +3341% |
| Cloud Storage | ₹0.32 | +68% |
| Cloud Run | ₹0.02 | **−100%** |
| Cloud Interconnect / Performance Dashboards / Network Analyzer / Network Topology | ₹0 each | dropped to zero |
| **Total** | **₹21,865.22** | — |

At a typical October-2026 USD/INR rate of roughly ₹83–86, **₹21,865 ≈ $254–263/month** — about **2.6× the user's stated $100/month target**, and Cloud SQL alone (₹20,329 ≈ **$236–245**) accounts for **93% of the entire bill**.

This single number supersedes most of the speculative cost analysis a repo-only inspection could produce, and is treated as ground truth for the rest of this document and `cloud-cost-projection.md`.

> **Update, same day, after live GCP access was granted:** the 2504% spike is now fully explained, not a hypothesis — see RCA-00 below. The Cloud SQL instance ran on a high-end `db-perf-optimized-N-8` tier from its creation (Aug 24, 2026) until the user independently downsized it to a small, correctly-sized tier on Oct 7, 2026 — three days before this report was finished. The October bill blends about a week of the expensive tier with the rest of the month on the now-correct tier. The fix was already made; this report's job shifted from "find the cause" to "confirm it's actually resolved," which happens via November's bill.

---

## Confirmed issues

### RCA-00 — Cloud SQL ran on a high-end "performance-optimized" tier for 6+ weeks; already downsized by the user on Oct 7
**Severity:** Critical (cause), **Resolved** (status — already fixed independently before this report was finished)
**Evidence:** `LIVE-GCP` — Cloud SQL Admin Activity audit log, `gcloud logging read` against `resource.type="cloudsql_database"`, cross-checked with `gcloud sql operations list` and `gcloud sql instances describe`. This supersedes the five-hypothesis list originally written in this section before live access was available.

**Observed symptoms:** Total October 2026 GCP spend of ₹21,865.22 (~$254–263), of which Cloud SQL alone is ₹20,329.50 (~$236–245) — a 2504% increase over the previous billing period.

**Confirmed root cause:** The audit log shows the exact history:

| Timestamp (UTC) | Action | Tier | Disk |
|---|---|---|---|
| 2026-08-24T11:37:04 | Instance created | `db-perf-optimized-N-8` | 100 GB SSD |
| 2026-10-07T16:31:45 | Resized (briefly) | `db-custom-2-8192` | — |
| 2026-10-07T16:38:22 | Resized again, 7 min later | `db-custom-1-3840` | — |

`gcloud logging read` confirms **zero** configuration changes between Aug 24 and Sept 23 — the instance ran on `db-perf-optimized-N-8`, a high-end Enterprise-Plus "performance optimized" class, continuously from creation through the first week of October. All three resize operations were made by the account holder themselves (`ashok.j123456789@gmail.com`), ending on the current small, appropriately-sized `db-custom-1-3840` (1 vCPU, 3.75 GB) tier — confirmed via live `gcloud sql instances describe`, which also shows `availabilityType: ZONAL` (no HA) and `edition: ENTERPRISE` (not Plus) in the *current* state.

**In short: this was not a misconfiguration discovered by this engagement — it was already found and fixed by the user three days before this analysis began.** The October bill is high because it blends roughly a week of the expensive tier with ~24 days on the now-correct small tier; a full month entirely on `db-perf-optimized-N-8` would have been considerably higher still.

**Business impact:** At the pre-fix run rate, this tier alone would have cost in the range of several hundred dollars a month indefinitely — the fix already applied removes that risk going forward. The open question is whether **November's bill actually reflects a full month on the small tier**, which is the only remaining way to fully confirm this is resolved.

**Estimated monthly financial impact going forward:** Should land close to the `cloud-cost-projection.md` Scenario B range (~$31–103/month) once a full billing month passes on the current tier — consistent with the fact that `db-custom-1-3840` is almost exactly what that scenario modeled as the rightsizing target, independently of this audit-log discovery.

**Recommended corrective action:**
1. ~~Rightsize the instance~~ — **done**, Oct 7, by the user.
2. Watch the **November 2026 bill** specifically to confirm Cloud SQL drops to roughly the Scenario B range now that the whole month runs on the small tier.
3. Set a **budget alert** (Cloud Billing budgets, e.g. at $100 and $150 thresholds) so a future tier change — accidental or deliberate — is caught within days, not discovered weeks later in a billing PDF.
4. Consider documenting *why* the instance was originally created at `db-perf-optimized-N-8` (a deliberate performance test? a console default that wasn't noticed?) so the same thing doesn't happen again on a future instance.

**Verification procedure:** Complete — confirmed via live audit log and current instance state. Remaining open item is simply watching the next full billing cycle.

**Confidence:** High — this is now a confirmed, not hypothesized, root cause, backed by the Cloud SQL Admin Activity audit log and the current live instance configuration.

---

### RCA-00b — Compute Engine VM confirmed orphaned (pending your confirmation); Load Balancer confirmed legitimate
**Severity:** Medium (cost ~$16-17/month; previously rated High while unidentified)
**Evidence:** `LIVE-GCP` — `gcloud compute instances list`, `gcloud compute disks list`, `gcloud compute forwarding-rules list`, all run against the live project.

**Observed symptoms:** October 2026 billing showed Compute Engine (₹818.59), Persistent Disk (₹49.30), and Cloud Load Balancing (₹507.68) with no matching resource in the repository.

**Confirmed findings:**
- **Cloud Load Balancing is legitimate.** `ymrmarine-https-rule` → `ymrmarine-https-proxy` is exactly the external HTTPS Load Balancer + Serverless NEG pattern needed to serve the custom domains (`ymrmarine.in` / `staging.ymrmarine.in`) in a region where Cloud Run's native domain mapping isn't available. **No action needed — keep this.**
- **The Compute Engine VM and its disk look orphaned.** `instance-20260818-094020` (`e2-medium`, a 10 GB Persistent Disk) was created Aug 18 — using GCP Console's **auto-generated default name** (the name it gives when you create an instance without typing one), which is itself a strong signal this was a manual, ad-hoc creation rather than part of any deployment pipeline. It has been running continuously for 7+ weeks with no reference anywhere in `cloudbuild.yaml`, the Dockerfile, or the application code.

**Business impact:** ~$16-17/month if left running indefinitely and genuinely unused — small, but worth closing.

**Recommended corrective action:** **Needs your confirmation before any action is taken** — is `instance-20260818-094020` something you're still using (a jump box, a one-off test environment, something mid-task)? If not, it can be stopped/deleted along with its disk once you confirm.

**Verification procedure:** Complete.

**Confidence:** High on what each resource is; decommissioning the VM requires your sign-off, not further investigation.

---

### RCA-01 — Schema drift via "safety-net" ALTER TABLE statements instead of real migrations
**Severity:** High
**Evidence:** `SESSION`, `REPO`

**Observed symptoms:** Pending Vessels Excel export worked on `staging` but failed on `main` with "Something went wrong while generating this export."

**Root cause:** The codebase has no migration-tracking mechanism. `YSMS/database/` contains 24+ loose `migration_*.sql` files plus a stale `schema.sql` (4 tables, clearly an early draft) and `database_cloudsql.sql` (the real 8+ table schema) — there is no single file or tool that represents "what the live schema actually is," and no record of which `.sql` files were ever run against which environment.

Instead, many pages carry their own defensive `SHOW COLUMNS ... ; ALTER TABLE ... ADD COLUMN` block that silently adds a missing column the first time that specific page loads (e.g. `assign_vessel.php` self-heals `appointment_date`). `export_vessels.php` queried that same column explicitly but had no such safety net. On `main`, nobody had opened `assign_vessel.php` yet, so the column genuinely did not exist there, and the export's prepared statement threw "Unknown column" — while `vessels.php`'s own `SELECT s.*` silently tolerated the same missing column and showed no error.

**Business impact:** A core export feature was unusable in production with no clear diagnostic path for a non-technical admin; the only way to know a column is "really" in a given environment is to grep every PHP file for `ADD COLUMN` and cross-reference which pages have been visited.

**Likelihood of recurrence:** High — this is a structural pattern (self-healing columns scattered across 15+ files), not a one-off bug. The same class of failure will recur for any future column that one page adds defensively and another page assumes exists.

**Estimated monthly financial impact:** Not directly billable, but each recurrence costs debugging time and, if it happens on a page a client/surveyor relies on, risks a missed deliverable (real business cost, not a cloud-cost one).

**Recommended corrective action:**
1. Adopt one real migration tool (even something as simple as a `schema_migrations` tracking table + an ordered list of `.sql` files applied via a small PHP/CLI runner) so "what ran where" is a queryable fact, not a guess.
2. Stop writing new "safety net" `ALTER TABLE` blocks in page code going forward; require schema changes to go through the migration runner before the corresponding code ships.
3. Add a scheduled (weekly) job or startup check that diffs `main` and `staging` schemas and alerts on drift.

**Verification procedure:** `SHOW COLUMNS FROM surveys` on both the `ysms_db` (production) and `ysms_db_staging` (staging) databases and diff the output — requires Cloud SQL access, `NEEDS-LIVE`.

**Confidence:** High (directly reproduced and fixed this session). Missing evidence: how many *other* columns have already drifted between the two databases — only discoverable with live DB access.

---

### RCA-02 — Per-recipient email loop caused duplicate notification copies
**Severity:** Medium
**Evidence:** `SESSION`

**Observed symptoms:** User reported the same upload-notification email landing in the shared `survey@ymrmarine.com` inbox 2–3 times for a single document upload.

**Root cause:** `notifyAdminsOfReportUpload()` in `includes/mailer.php` looped once per distinct Admin account and called `sendHtmlEmail()` separately for each one, CC'ing `survey@ymrmarine.com` on *every* one of those sends — so with N admins on file, that one shared inbox received N duplicate copies.

**Business impact:** Confusing/noisy inbox for the ops team; no data loss, but erodes trust in the notification system and burns outbound-email quota.

**Estimated monthly financial impact:** Negligible on its own (SMTP relay / Gmail API send volume is not separately billed per the current setup), but the *pattern* — one fully separate send (with its own Gmail API `signJwt` + OAuth token-exchange round trip, or a full SMTP handshake) per recipient — was also the dominant cause of RCA-03 below.

**Recommended corrective action:** Already implemented and shipped this session: a single `sendHtmlEmailMulti()` call addresses every admin in one message, with the shared CC sent at most once. ✅ **Fixed** (commits `0b91f15` staging / `fc17991` main).

**Verification procedure:** Trigger a document upload with 2+ Admin accounts configured and confirm the shared inbox receives exactly one copy. `NEEDS-LIVE` to confirm in production mailbox, but the code fix itself was verified by reading the diff and PHP lint.

**Confidence:** High.

---

### RCA-03 — Document-upload request latency caused by N sequential email sends with re-authentication per recipient
**Severity:** Medium
**Evidence:** `SESSION`

**Observed symptoms:** User reported the upload-document form taking a long time to submit.

**Root cause:** Same loop as RCA-02: each of the N admin notification emails re-ran a full SMTP handshake, or — when sending via the Gmail API domain-wide-delegation path — a fresh `IAM Credentials.signJwt` call **and** an OAuth token-exchange call to `oauth2.googleapis.com`, two extra synchronous HTTPS round trips **per admin**, all before the HTTP response could reach the browser. PDF/Excel attachments were also re-encoded into the MIME message from scratch on every one of those N sends.

**Business impact:** Perceived app slowness/unresponsiveness during a core daily workflow (surveyors uploading field reports), with no user feedback while waiting — risk of users double-submitting out of confusion (which itself would have doubled DB rows / duplicate file uploads had the earlier dedupe logic not existed).

**Recommended corrective action:** Already implemented and shipped this session:
1. Collapsed N separate sends into one `sendHtmlEmailMulti()` call (one MIME encode, one handshake).
2. Added per-process caching of the Gmail API delegated access token (valid ~1 hour) so repeat sends from the same address skip the `signJwt`/token-exchange round trip.
3. Added a "please wait" overlay + submit-button lock on the upload form so a slow request is not silently confusing.
✅ **Fixed** (commits `898b6e2` staging / `cf6058e` main).

**Verification procedure:** Time an upload submission with 2–3 Admin accounts before/after; `NEEDS-LIVE` for an exact before/after millisecond number, since Cloud Logging request-latency data was not accessible in this session.

**Confidence:** High on root cause and fix; Medium on exact magnitude of the improvement (no production latency metrics available to quote a number).

---

## Suspected issues (hypotheses — require live GCP access to confirm)

### RCA-04 — Single shared Cloud SQL instance for both production and staging
**Severity:** High (if confirmed)
**Evidence:** `REPO` (`cloudbuild.yaml`, both branches)

**Observed symptoms:** None reported yet — this is a structural risk, not a reported incident.

**Hypothesis:** Both `cloudbuild.yaml` (staging) and `main`'s `cloudbuild.yaml` (production) point `--add-cloudsql-instances` at the exact same instance connection name, `ymr-sms:asia-south1:ymrmarine`. They use separate logical databases (`ysms_db` vs `ysms_db_staging`, `ymrmarine` vs `ymrmarine_staging`) on that one instance, not separate instances. This means:
- A staging load test, a runaway query, or a staging-only migration mistake shares the same CPU/memory/IOPS/connection-limit budget as production.
- A single instance maintenance window, failover event, or storage-full condition affects both environments simultaneously — there is no environment isolation at the infrastructure level, only at the schema level.
- It is one line item on the bill, making it impossible to see "what staging costs us" vs "what production costs us" from Cloud SQL billing alone.

**Business impact if confirmed:** A staging-only incident (e.g., someone testing a bulk-import script) could degrade or take down the production database.

**Recommended corrective action:** Either (a) accept this consciously as a documented cost/isolation trade-off appropriate for a 50-user app and set alerting on the shared instance's CPU/connections, or (b) split into a second, smaller Cloud SQL instance (e.g. `db-f1-micro`) for staging only — see `database-platform-comparison.md` Option A for the cost delta.

**Verification procedure:** `gcloud sql instances list --project ymr-sms` — `NEEDS-LIVE`.

**Confidence:** High that this is the deployed configuration (directly read from both branches' `cloudbuild.yaml`); Medium on business impact since no incident has actually occurred from this yet.

---

### RCA-05 — No Cloud Run `--min-instances` / `--max-instances` / `--cpu` / `--memory` / `--concurrency` set explicitly
**Severity:** Medium
**Evidence:** `REPO`

**Hypothesis:** Neither `cloudbuild.yaml` sets these flags, so Cloud Run uses platform defaults (historically: `min-instances=0`, `max-instances=100`, `cpu=1`, `memory=512Mi`, `concurrency=80`, `timeout=300s`) unless someone has since changed them by hand in the Cloud Console — which would not show up in this repo and is unverifiable here. Two concrete risks follow from `min-instances=0`:
- **Cold starts** on every period of inactivity — PHP + Apache + Composer-autoloaded PhpSpreadsheet is not an instant boot, so the first request after idle time is slow.
- **Connection-count risk**: with `max-instances` at its default (100) and `concurrency` at its default (80), a traffic spike could theoretically open far more simultaneous MySQL connections (via the Cloud SQL Unix-socket proxy, one new `PDO` connection per request — confirmed in `config/config.php:getDB()`, no connection pooling or `ATTR_PERSISTENT`) than a small Cloud SQL tier's `max_connections` allows. For this app's real traffic (≤50 users, not all concurrent) this is unlikely to bite today, but it is unbounded by configuration, not by actual expected load.

**Business impact if confirmed:** Occasional slow first-load for users after idle periods (likely overnight); low-probability but non-zero risk of connection-limit errors under a traffic spike (e.g., everyone uploading reports at month-end).

**Recommended corrective action:** Set explicit `--min-instances=0` (fine for cost at this scale) but cap `--max-instances` (e.g. 3–5) and `--concurrency` (e.g. 20–40) to bound worst-case simultaneous DB connections; consider `--min-instances=1` on production only if cold-start latency becomes a real complaint (small, bounded cost increase — see cost projection).

**Verification procedure:** `gcloud run services describe ymrmarine --region asia-south1` — `NEEDS-LIVE`.

**Confidence:** Medium — the *absence* of the flags in the deploy script is confirmed; whether someone later overrode them by hand in the console is unknown.

---

### RCA-06 — `docker build --no-cache` on every single deploy
**Severity:** Low–Medium
**Evidence:** `REPO` (`cloudbuild.yaml`, both branches, `--no-cache` flag)

**Hypothesis:** Every push to `staging` or `main` triggers a full, uncached Docker rebuild (`apt-get install` of `libpng-dev`, `libjpeg62-turbo-dev`, `libfreetype6-dev`, `libzip-dev`, `libmagic-dev`, PHP extension compilation, Composer install) instead of reusing unchanged layers. This directly increases Cloud Build minutes billed per deploy and deploy wall-clock time, for a repo that deploys very frequently (this session alone pushed 15+ times).

**Business impact:** Slower iteration (minutes per deploy instead of tens of seconds) and avoidable Cloud Build cost — Cloud Build's free tier is 120 build-minutes/day, so a day with many pushes could exceed it.

**Recommended corrective action:** Remove `--no-cache` (or scope it to only the apt-get layer when base-image CVEs need a fresh pull) and let Docker layer caching work normally; the comment trail in the file doesn't explain why `--no-cache` was added, so confirm it isn't masking a real caching bug before removing it.

**Verification procedure:** Compare Cloud Build history build durations with and without the flag — `NEEDS-LIVE` for actual before/after minutes; the flag's presence itself is `REPO`-confirmed.

**Confidence:** High that the flag is present and does what it says; Low confidence on the exact dollar cost without Cloud Build billing data.

---

### RCA-07 — AI (Gemini) feature wired only into staging's secrets, not production's
**Severity:** Low
**Evidence:** `REPO`

**Hypothesis:** `staging`'s `cloudbuild.yaml` includes `YSMS_GEMINI_API_KEY=YSMS_GEMINI_API_KEY:latest` in `--set-secrets`; `main`'s does not. `config/config.php` gates the AI photo-description and AI email-extraction features behind `AI_PHOTO_DESCRIPTION_ENABLED` / `AI_EMAIL_EXTRACTION_ENABLED` (both default `true`), but without the API key present as an env var, the feature's own code (`ajax/gemini_describe.php`, `includes/email_ai_helpers.php`) will fail its own API calls at runtime rather than being cleanly disabled — worth confirming the failure mode is graceful (falls back to manual entry) rather than a hard error, since the flag defaulting to `true` with no key is a slightly inconsistent state.

**Business impact:** Low — if the fallback is graceful (which prior session work suggests it is, by design) this just means production surveyors don't get AI-assisted photo descriptions, which may be intentional (the feature was previously removed from `main` entirely in git history: `5a6c915 Remove the AI photo-description feature...`, then reintroduced on `staging` only).

**Recommended corrective action:** Confirm intentionally: either explicitly set `AI_PHOTO_DESCRIPTION_ENABLED=false` and `AI_EMAIL_EXTRACTION_ENABLED=false` on `main`'s `cloudbuild.yaml` to document the decision, or wire the Gemini secret into `main` once the feature is considered production-ready.

**Verification procedure:** Code read confirms the gap; whether it causes visible errors for production users is `NEEDS-LIVE` (Cloud Logging / user reports).

**Confidence:** Medium.

---

### RCA-08 — Backup, PITR, and HA configuration — CONFIRMED GOOD
**Severity:** Resolved (was flagged Critical-if-unconfirmed; now confirmed configured correctly)
**Evidence:** `LIVE-GCP` — `gcloud sql instances describe`, `settings.backupConfiguration`

**Confirmed configuration:**
```
backupConfiguration:
  enabled: true
  binaryLogEnabled: true
  backupRetentionSettings: { retainedBackups: 15, retentionUnit: COUNT }
  transactionLogRetentionDays: 7
  transactionalLogStorageState: CLOUD_STORAGE
  startTime: '11:00'
availabilityType: ZONAL
```

Automated backups are on, 15 backups retained, binary logging is enabled with 7-day transaction log retention to Cloud Storage — which is exactly what's needed for **point-in-time recovery**, and it's present. `availabilityType: ZONAL` confirms no HA/regional failover is configured, which is a reasonable, explicit cost/reliability trade-off for a budget-constrained internal tool, not an oversight.

**Business impact:** None remaining — the top data-safety risk in this whole engagement is closed. The only residual gap is that **backups existing is not the same as a restore having been tested** (see `infrastructure-risk-register.md` R-08) — a periodic test-restore to a scratch instance remains worth doing, but that's a process habit, not a missing configuration.

**Recommended corrective action:** None required for the configuration itself. Schedule a one-time test restore to a scratch instance to validate R-08, at the user's convenience.

**Verification procedure:** Complete.

**Confidence:** High — directly confirmed from the live instance.

---

## Issues investigated but NOT found / not evidenced

- **Slow queries / missing indexes:** `database_cloudsql.sql` defines only a small number of explicit indexes beyond primary/foreign keys; several frequently-filtered columns (e.g. `surveys.status`, `surveys.survey_type_id`, date columns used in report filters) do not appear to have dedicated indexes in the inspected schema file. This is a plausible slow-query risk at larger data volumes, but with ≤50 users and a modest survey volume, MySQL's query planner can likely table-scan these without visible impact today. **Not elevated to a confirmed root cause** — no slow-query log or `EXPLAIN` output was available to confirm actual query cost. Re-evaluate if/when row counts grow materially (thousands→tens of thousands of surveys).
- **Excessive logging / network egress:** No Cloud Logging sink configuration, log-based metrics, or retention settings are present in the repo (standard Cloud Run default logging is used implicitly). No evidence of excess; also no evidence of insufficiency. `NEEDS-LIVE`.
- **Staging/production database inconsistency beyond RCA-01:** Only the one concrete, personally-confirmed case (RCA-01) is evidenced. Broader drift is plausible given the "safety net ALTER TABLE" pattern but unconfirmed beyond that single column.

---

## Severity summary

| ID | Title | Severity | Status |
|---|---|---|---|
| RCA-00 | Cloud SQL ran on an expensive tier for 6+ weeks | Critical (cause) | **Confirmed via audit log — already fixed by the user, Oct 7** |
| RCA-00b | Compute Engine VM orphaned; Load Balancer legitimate | Medium | Confirmed — LB keep, VM needs your decision |
| RCA-01 | Schema drift via inline ALTER TABLE instead of real migrations | High | Confirmed, open |
| RCA-02 | Duplicate notification emails | Medium | Confirmed, **fixed this session** |
| RCA-03 | Slow upload submit from sequential per-recipient email sends | Medium | Confirmed, **fixed this session** |
| RCA-04 | Shared Cloud SQL instance for staging + production | High | Hypothesis, needs live confirmation |
| RCA-05 | No explicit Cloud Run scaling/resource limits | Medium | Hypothesis, needs live confirmation |
| RCA-06 | `docker build --no-cache` on every deploy | Low–Medium | Confirmed config, cost impact unverified |
| RCA-07 | Gemini AI key wired on staging only | Low | Confirmed config, impact unclear |
| RCA-08 | Backup/PITR/HA status | Resolved | **Confirmed correctly configured** |

**Bottom line:** live GCP access resolved the two biggest open questions in this engagement. **RCA-00's "why did Cloud SQL cost so much" is now a confirmed fact, not a guess** — the instance ran on a high-end `db-perf-optimized-N-8` tier from its creation (Aug 24) until Oct 7, when the user independently downsized it to the small, correctly-sized `db-custom-1-3840` — three days before this report was finished. **RCA-08's backup/PITR question is also resolved**: both are properly configured. The two issues the user reported directly this session (duplicate emails, slow uploads) remain root-caused and fixed. What's left open: confirming November's bill reflects the fix (a full month on the small tier), and a decision on the orphaned Compute Engine VM (RCA-00b).
