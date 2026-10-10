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

---

## Confirmed issues

### RCA-00 — Cloud SQL cost spiked 2504% month-over-month and now accounts for 93% of the entire bill
**Severity:** Critical
**Evidence:** `BILLING` (October 2026 Cloud Billing Reports export)

**Observed symptoms:** Total monthly GCP spend of ₹21,865.22 (~$254–263), of which Cloud SQL alone is ₹20,329.50 (~$236–245) — a 2504% increase over the previous billing period. No other product comes close: the next-largest line items (Compute Engine ₹818.59, Cloud Load Balancing ₹507.68) are each under 4% of the total.

**Root cause — hypothesis, needs live confirmation:** A jump of this magnitude (≈25×) in one billing period is far larger than normal traffic growth could explain for a ≤50-user internal tool, and is consistent with one of:
1. The instance tier/edition was changed to something much larger or to **Enterprise Plus edition** (which this session's pricing research found priced noticeably higher per vCPU/GB than standard Enterprise edition — see `cloud-cost-projection.md` sourcing notes).
2. **High Availability (regional instance)** was enabled, which roughly doubles compute+storage cost on its own — doesn't fully explain 25×, but could be a compounding factor alongside #1.
3. **Storage auto-increase** fired repeatedly (Cloud SQL's automatic storage growth) and the disk was never right-sized back down — storage costs do not shrink automatically once grown.
4. A **read replica** or an extra instance (e.g. a short-lived test/clone instance left running) was added and not cleaned up.
5. A one-off heavy operation (bulk import, long-running migration/test query, an accidental full-table scan loop) drove I/O-based billing for part of the month.

Repository inspection (`cloudbuild.yaml`, both branches) only shows `--add-cloudsql-instances ymr-sms:asia-south1:ymrmarine` with no tier, edition, HA, or storage-size flags set from the deploy pipeline — meaning the instance's actual sizing was configured **out-of-band** (Cloud Console or a manual `gcloud sql instances patch`/`create`), so this repository cannot by itself say which of the five hypotheses above is correct. This is the single most important fact to pin down before any further infrastructure decision, including the Postgres-migration question this whole evaluation was commissioned to answer.

**Business impact:** At the current run rate, this is ~$2,900–3,150/year on a database tier for a 50-user application — almost certainly far more than the workload requires, and the gap between "what's being paid" and "what a rightsized instance should cost" (see `cloud-cost-projection.md` Scenario B) is likely **the majority of the entire savings opportunity in this whole engagement**, larger than anything a database-engine migration could realistically achieve.

**Estimated monthly financial impact:** Likely $150–220/month in avoidable spend once rightsized (see Scenario B modeling); exact figure requires live confirmation of current tier/edition/HA/storage settings.

**Recommended corrective action (requires explicit approval before any change, per this engagement's safety rules):**
1. `gcloud sql instances describe ymr-sms:asia-south1:ymrmarine` immediately to read the actual tier, edition, HA setting, storage size/type, and storage auto-increase status. This is read-only and safe to run as soon as GCP credentials are available.
2. Compare that configuration against the workload a 50-user, low-concurrency PHP app actually needs (a small shared-core or single small dedicated-core tier, non-HA for a budget-constrained internal tool, SSD storage sized to actual data volume, not an inflated auto-grown figure).
3. Only after the user reviews and approves a specific target configuration, schedule a maintenance-window resize (note: shrinking storage is **not** possible in Cloud SQL — if storage auto-grew unnecessarily, a size reduction requires exporting data into a freshly-sized instance, which is itself a small-scale version of the same migration machinery described in `managed-postgres-migration-plan.md`, just MySQL→MySQL).
4. Set a **budget alert** (Cloud Billing budgets, e.g. at $100 and $150 thresholds) so a future spike like this is caught within days, not discovered a month later in a billing PDF.

**Verification procedure:** `gcloud sql instances describe` (read-only) — `NEEDS-LIVE`, highest priority of every verification item in this document.

**Confidence:** High that the cost spike is real and Cloud-SQL-concentrated (directly from billing data). Low-to-Medium confidence on *which* of the five hypotheses explains it — genuinely unknown without the instance description.

---

### RCA-00b — Compute Engine, Persistent Disk, and Cloud Load Balancing costs with no corresponding resource found in the repository
**Severity:** High
**Evidence:** `BILLING` + `REPO` (absence of evidence)

**Observed symptoms:** October 2026 billing shows Compute Engine (₹818.59, +3369%), Persistent Disk (₹49.30, +1566%, normally a VM boot-disk charge), and Cloud Load Balancing (₹507.68, +3380%) — combined ≈ ₹1,375.57/month (~$16–17/month, small in absolute terms but 100% unaccounted-for by this repository).

**Root cause — hypothesis:** This architecture, as described in `cloudbuild.yaml` and the Dockerfile, is Cloud Run + Cloud SQL + Cloud Storage only — **no Compute Engine VM is referenced anywhere** in the deploy pipeline or application code. Two plausible explanations:
1. **Legitimate and expected:** Cloud Run domain mapping is not available in every region; a common, correct pattern is to front Cloud Run with an external HTTPS Load Balancer + Serverless NEG to serve the custom domains the user mentioned (`ymrmarine.in`, `staging.ymrmarine.in`). If so, the Load Balancing charge is expected and the Compute Engine/Persistent Disk charges might be a **separate, unrelated VM** (e.g., a jump box, a one-off test instance, or infrastructure predating this repository) that happens to bill in the same period.
2. **Orphaned resource:** a VM created for a one-off task (testing, a manual migration attempt, a forgotten dev instance) and never torn down. Persistent Disk charges in particular almost always indicate a VM's boot/data disk still exists and is being billed whether or not the VM is running.

**Business impact:** Low in absolute dollars (~$16-17/month) but worth resolving on principle — an unidentified, undocumented resource is itself a minor security and cost-governance gap (nobody can currently say from this repo what it is or whether it's still needed).

**Recommended corrective action:** `gcloud compute instances list --project ymr-sms` and `gcloud compute forwarding-rules list --project ymr-sms` (both read-only) to identify exactly what these resources are; keep the Load Balancer if it's genuinely serving the custom domains, decommission the Compute Engine instance + its Persistent Disk if it turns out to be orphaned (only after user approval, per this engagement's safety rules).

**Verification procedure:** `NEEDS-LIVE`.

**Confidence:** Medium — the billing line items are certain; their explanation is not.

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

### RCA-08 — Backup, PITR, and HA configuration unknown
**Severity:** Critical if backups/PITR are not enabled (unconfirmed either way)
**Evidence:** `NEEDS-LIVE`

**Hypothesis:** None of `cloudbuild.yaml`, the Dockerfile, or the SQL files reference backup configuration — Cloud SQL backup/PITR/HA settings are configured out-of-band (Console or `gcloud sql instances patch`, never committed to this repo), so this repository gives **zero** evidence either way about whether backups are enabled, how long they're retained, or whether point-in-time recovery is on.

**Business impact if backups are NOT enabled:** Critical — a single Cloud SQL incident (operator error, bad migration, storage corruption) could permanently lose survey records, client data, uploaded report metadata, and user accounts for a live commercial operation.

**Recommended corrective action:** This is the single highest-priority item to verify before anything else in this document. `gcloud sql instances describe ymr-sms:asia-south1:ymrmarine --format="value(settings.backupConfiguration)"` the moment GCP access is available.

**Verification procedure:** `NEEDS-LIVE`, Critical priority.

**Confidence:** N/A — explicitly unknown, flagged as the top data gap in this RCA.

---

## Issues investigated but NOT found / not evidenced

- **Slow queries / missing indexes:** `database_cloudsql.sql` defines only a small number of explicit indexes beyond primary/foreign keys; several frequently-filtered columns (e.g. `surveys.status`, `surveys.survey_type_id`, date columns used in report filters) do not appear to have dedicated indexes in the inspected schema file. This is a plausible slow-query risk at larger data volumes, but with ≤50 users and a modest survey volume, MySQL's query planner can likely table-scan these without visible impact today. **Not elevated to a confirmed root cause** — no slow-query log or `EXPLAIN` output was available to confirm actual query cost. Re-evaluate if/when row counts grow materially (thousands→tens of thousands of surveys).
- **Excessive logging / network egress:** No Cloud Logging sink configuration, log-based metrics, or retention settings are present in the repo (standard Cloud Run default logging is used implicitly). No evidence of excess; also no evidence of insufficiency. `NEEDS-LIVE`.
- **Staging/production database inconsistency beyond RCA-01:** Only the one concrete, personally-confirmed case (RCA-01) is evidenced. Broader drift is plausible given the "safety net ALTER TABLE" pattern but unconfirmed beyond that single column.

---

## Severity summary

| ID | Title | Severity | Status |
|---|---|---|---|
| **RCA-00** | **Cloud SQL cost spike: +2504% MoM, 93% of ₹21,865 total bill** | **Critical** | **Confirmed (real billing data), root cause unverified, top priority** |
| RCA-00b | Unexplained Compute Engine / Persistent Disk / Load Balancing (~$16-17/mo) | High | Confirmed cost, source unverified |
| RCA-01 | Schema drift via inline ALTER TABLE instead of real migrations | High | Confirmed, open |
| RCA-02 | Duplicate notification emails | Medium | Confirmed, **fixed this session** |
| RCA-03 | Slow upload submit from sequential per-recipient email sends | Medium | Confirmed, **fixed this session** |
| RCA-04 | Shared Cloud SQL instance for staging + production | High | Hypothesis, needs live confirmation |
| RCA-05 | No explicit Cloud Run scaling/resource limits | Medium | Hypothesis, needs live confirmation |
| RCA-06 | `docker build --no-cache` on every deploy | Low–Medium | Confirmed config, cost impact unverified |
| RCA-07 | Gemini AI key wired on staging only | Low | Confirmed config, impact unclear |
| RCA-08 | Backup/PITR/HA status unknown | **Critical if unconfirmed** | **Top verification priority** |

**Bottom line:** the real October 2026 billing data changes the priority order completely. **RCA-00 (the Cloud SQL cost spike) is now the single most important finding in this entire engagement** — it is worth an order of magnitude more, in dollars, than every other finding combined, and very likely explains why the account is 2.6× over its $100/month target. The two issues the user reported directly this session (duplicate emails, slow uploads) are root-caused and already fixed. RCA-08 (confirm backups/PITR are actually enabled) remains the top *data-safety* priority, separate from RCA-00's top *cost* priority — both should be checked in the same `gcloud sql instances describe` call, since both answers come from the same command.
