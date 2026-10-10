# Managed PostgreSQL Migration Plan (Contingency)

**Status: NOT CURRENTLY RECOMMENDED.** Per `database-platform-comparison.md`, retaining and rightsizing the existing Cloud SQL for MySQL instance scores highest on the weighted decision matrix (8.6 vs. 6.8 for Cloud SQL PostgreSQL and 5.4 for third-party managed Postgres), and `root-cause-analysis.md` RCA-00 shows the account's real cost problem is a misconfigured/oversized Cloud SQL instance, not the MySQL engine itself — rightsizing fixes the actual problem directly, without migration risk.

This document exists as a **complete, ready-to-execute contingency plan**, per this engagement's Phase 4 requirement, for the scenario where a future concrete PostgreSQL-specific requirement emerges (e.g., a feature needing native JSONB+GIN indexing, a compliance requirement, or a tooling dependency). **Do not execute any step in this plan without separately re-confirming it is still the right call at that time**, and without explicit user approval at each gated checkpoint below.

---

## 1. Source and target identification

- **Source:** Google Cloud SQL for MySQL 8.x (version not independently confirmed from this repo — `NEEDS-LIVE`: `gcloud sql instances describe` reports exact version), instance `ymr-sms:asia-south1:ymrmarine`, databases `ysms_db` (production) and `ysms_db_staging` (staging), both on the same instance.
- **Target (if this plan is ever executed):** Google Cloud SQL for PostgreSQL, same region (`asia-south1`), to preserve the current near-zero-latency Unix-socket connection pattern and avoid the network-latency/cross-cloud-egress downsides identified for third-party providers in `database-platform-comparison.md`. A third-party provider (Neon/Aiven) is not recommended as a target even in this contingency plan, for the same latency/operational reasons documented there.

## 2. Schema and application compatibility assessment

Already completed in `database-platform-comparison.md`. Summary of what must change:

| MySQL construct | Found where | PostgreSQL equivalent | Risk |
|---|---|---|---|
| `AUTO_INCREMENT` | Every table's `id` column | `GENERATED ALWAYS AS IDENTITY` | Low — mechanical, well-tooled |
| `ENUM(...)` | `surveys.status`, similar columns | `CHECK` constraint or native `ENUM` type | Medium — native PG enums are hard to modify later; prefer `CHECK` |
| `ON UPDATE CURRENT_TIMESTAMP` | `updated_at` columns | Requires a trigger (new server-side logic) | Medium — this schema currently has *zero* triggers; introducing the first one changes the "logic lives in PHP only" property that makes this schema easy to reason about today |
| `SHOW COLUMNS FROM ...` | 15+ "safety net" blocks across the PHP codebase | `information_schema.columns` query | **High — every one of these blocks must be found and rewritten individually**, not just the schema |
| `GROUP_CONCAT(...SEPARATOR...)` | Multi-surveyor name combining queries | `STRING_AGG(..., separator)` | Low-Medium — mechanical rename, different NULL-handling edge cases to test |
| `ALTER TABLE ... MODIFY COLUMN` | Safety-net blocks, e.g. `assign_date DATETIME` conversions | `ALTER TABLE ... ALTER COLUMN ... TYPE ... [USING ...]` | Medium |
| Unix-socket DSN `mysql:unix_socket=...` | `config/config.php:getDB()` | `pgsql:host=...` (different Cloud SQL Postgres Auth Proxy socket path) | Low — one function, but must be tested against every code path that calls `getDB()` (78 call sites) |
| `SET time_zone = '+05:30'` per connection | `getDB()` | `SET timezone = 'Asia/Kolkata'` | Low |

## 3. Inventory of database objects

From the SQL files in `YSMS/database/`:
- **Tables:** 8+ in the real schema (`database_cloudsql.sql`: roles, users, clients, survey_types, ports, surveyors, surveys, reports, documents, uploads, activity_logs — exact final count needs a live `SHOW TABLES` since 24+ migration files have been layered on since this base file).
- **Indexes:** A small number of explicit indexes beyond primary/foreign keys (see RCA investigation notes in `root-cause-analysis.md`) — full inventory requires `SHOW CREATE TABLE` on every live table, `NEEDS-LIVE`.
- **Foreign keys:** At least 3 confirmed in the early schema draft; the real count in the live schema needs live confirmation.
- **Triggers / Views / Routines / Events:** **None found anywhere in any `.sql` file.** This is the one genuinely good piece of news for migration feasibility — there is no procedural SQL to port.
- **Transactions:** Not grepped exhaustively in this pass; PHP's PDO usage pattern throughout the codebase is simple prepare/execute without explicit `beginTransaction()` in most of the files touched this session — multi-statement atomicity is **not** a dependency this migration needs to preserve carefully (low risk either way), but should be re-confirmed with a full-codebase grep for `beginTransaction` before execution.

## 4. Data type and SQL dialect conversion

Standard `pgloader`-class conversions apply (`INT`→`integer`, `VARCHAR(n)`→`varchar(n)`, `TEXT`→`text`, `DATETIME`→`timestamp`, `TINYINT(1)`→`boolean` with explicit 0/1→false/true mapping, `DECIMAL(p,s)`→`numeric(p,s)`). The recovery-amount columns (`recovery_amount`, `vlsfo_recovery`, `lsmgo_recovery`, `hsfo_recovery`, all `DECIMAL(12,3)`) convert cleanly. Collation: MySQL `utf8mb4` → PostgreSQL's native UTF-8 (PostgreSQL doesn't have a separate "mb4" distinction; this is typically a non-issue but worth a smoke test on any stored emoji/Telugu-script text, since this codebase's comments and some UI text are bilingual English/Telugu).

## 5. Migration tooling selection

Recommended (if executed): **Google Database Migration Service (DMS)** for the initial schema+data conversion pass (handles AUTO_INCREMENT→IDENTITY and most type mapping automatically, GCP-native so stays within the existing IAM/VPC boundary), followed by **manual review and rewrite** of every `ENUM`, every `ON UPDATE CURRENT_TIMESTAMP` column, and — critically — manual rewrite of all PHP-side `SHOW COLUMNS`/`GROUP_CONCAT`/DSN code, since DMS only migrates the database, not the 15+ files of application code that assume MySQL syntax.

## 6–7. Data migration approach: initial full load + optional incremental sync

1. **Full load (staging first, always):** DMS or `mysqldump` → conversion script → `pg_restore`/direct load into a **new, separate** Cloud SQL PostgreSQL instance — never into an existing instance, never overwriting anything.
2. **Incremental sync (if a near-zero-downtime cutover is desired):** DMS supports continuous replication from the MySQL source to keep the PostgreSQL target current during the testing window, right up to the actual cutover moment. Given this app's low write volume (≤50 users, not a high-throughput system), a **short maintenance window** (see downtime estimate below) is a simpler, lower-risk, equally-practical alternative to continuous-replication cutover — recommended over the zero-downtime path for this specific app's scale.

## 8–11. Application changes, environment/secrets, pooling, TLS

- Code changes: per the compatibility table in §2 — rewrite `config/config.php:getDB()`'s DSN branch, every safety-net `SHOW COLUMNS` block, every `GROUP_CONCAT` call, and re-test every ENUM-dependent status-transition code path (`surveys.status` drives a large fraction of this app's business logic, per this session's own extensive work on Pending Vessel → Pending Report → Completed transitions).
- New environment variables: `YSMS_DB_HOST` would point at the new Postgres instance's Cloud SQL connection name; a **parallel** `YSMS_DB_DRIVER=pgsql` flag would need to be introduced so `getDB()` can branch cleanly, ideally defaulting to `mysql` so nothing breaks if the var is unset.
- Secrets: same GCP Secret Manager pattern already in use (`YSMS_DB_PASS`) — no new secret-management approach needed, just a new secret value for the new instance's credentials.
- Connection pooling: currently **none** on either engine (RCA-05) — if ever implemented, `pgbouncer`-style pooling is more commonly needed for Postgres than MySQL due to Postgres's heavier per-connection memory footprint; worth adding at the same time as a Postgres migration if one ever happens, not before.
- TLS: Cloud SQL Postgres, like Cloud SQL MySQL, supports the Cloud SQL Auth Proxy's automatic encrypted Unix-socket connection — no change in the TLS posture versus today.

## 12. Data validation and reconciliation

Row-count comparison per table (source vs. target) plus a checksum/spot-check pass on a sample of `surveys` rows (the highest-value table — vessel names, client references, survey types, recovery figures) before any cutover is considered complete.

## 13. Financial and performance testing

Run the full `cloud-cost-projection.md` Scenario C modeling against the *actual* quoted Cloud SQL Postgres price for the chosen tier once this plan is ever activated; run a basic load test (simulate the report-generation and Excel-export code paths, which are this app's heaviest DB-query paths) against the staging Postgres instance before considering production cutover.

## 14. Staging migration and UAT

Always first. The existing `ysms_db_staging` database/environment is the natural rehearsal ground — migrate it to a staging Postgres instance, run the full YSMS application against it (every role: Admin, Surveyor, Client, Super Admin), and have real users exercise the core workflows (assign vessel, upload documents, generate photo reports, export Excel) before touching anything production-related.

## 15. Production cutover procedure (if ever executed)

1. Final incremental sync catch-up (or final `mysqldump` if using the maintenance-window approach).
2. Freeze writes to the MySQL source (brief maintenance window, communicated to all ~50 users in advance).
3. Final data validation pass (§12).
4. Flip `YSMS_DB_HOST`/`YSMS_DB_DRIVER` env vars in `cloudbuild.yaml` and redeploy.
5. Smoke-test every major workflow against production traffic for a defined monitoring window before declaring success.

## 16. Rollback procedure

Because the MySQL source is **never deleted** during cutover (see safety rules below), rollback is: revert the env var change, redeploy the previous Cloud Run revision pointing back at the original MySQL instance. Any writes that happened against PostgreSQL during the failed cutover window would need manual reconciliation back into MySQL — this is exactly why a short, communicated maintenance window (§6-7) is preferred over a live cutover for this app's scale: it minimizes the "what happened to writes during the gap" problem.

## 17. Post-migration monitoring

Minimum 2-week elevated-attention window: Cloud SQL Postgres CPU/memory/connection dashboards, application error logs, and a daily manual spot-check of a few real records against known-good values from before migration.

## 18. Old database retention and decommissioning

**Never decommission the MySQL source immediately.** Retain it, fully backed up and in a stopped-but-not-deleted state, for a minimum of 30 days after a successful cutover and stabilization period, per this engagement's mandatory safety rules. Only decommission after explicit user approval and confirmation that no rollback will be needed.

---

## Architecture diagrams

**Current:**
```
[Cloud Run: ymrmarine / ymrmarine-staging]
         │  (Cloud SQL Auth Proxy, Unix socket)
         ▼
[Cloud SQL instance: ymr-sms:asia-south1:ymrmarine]
   ├── ysms_db            (production YSMS)
   ├── ysms_db_staging     (staging YSMS)
   ├── ymrmarine           (production marketing site)
   └── ymrmarine_staging   (staging marketing site)
```

**Proposed (contingency, if ever executed):**
```
[Cloud Run: ymrmarine / ymrmarine-staging]
         │  (Cloud SQL Auth Proxy, Unix socket, pgsql driver)
         ▼
[Cloud SQL instance: ymr-sms:asia-south1:ymrmarine-pg]  (NEW, separate instance)
   ├── ysms_db            (production YSMS, PostgreSQL)
   └── ysms_db_staging     (staging YSMS, PostgreSQL)

[Cloud SQL instance: ymr-sms:asia-south1:ymrmarine]      (RETAINED, MySQL)
   ├── ymrmarine / ymrmarine_staging   (marketing site, unaffected — no reason
   │                                    to migrate this alongside YSMS)
   └── ysms_db / ysms_db_staging        (kept as rollback source, 30+ days)
```

Note: the marketing site (`ymrmarine`/`ymrmarine_staging` databases) is a **separate application** from YSMS and is out of scope for this migration plan entirely — nothing in this document should be read as proposing to touch it.

## Pre-migration checklist (if ever activated)

- [ ] RCA-00 resolved (Cloud SQL rightsized) — do this *regardless* of the migration decision, first.
- [ ] RCA-08 resolved (backups/PITR confirmed enabled on the source).
- [ ] Full inventory of all `SHOW COLUMNS`/safety-net blocks completed (grep `SHOW COLUMNS FROM` across the codebase, confirmed >15 files currently).
- [ ] Staging Postgres instance created and migrated first; UAT signed off by real users in every role.
- [ ] Rollback procedure tested at least once on staging (not just documented).
- [ ] Maintenance window scheduled and communicated.
- [ ] Explicit user approval obtained for the cutover date/time.

## Migration effort estimate

Realistically **2–4 weeks of focused engineering work** for one developer familiar with this codebase, dominated by rewriting and testing the 15+ safety-net blocks and every `ENUM`-dependent status-transition code path across a codebase with dozens of PHP files touching `surveys.status` — not a "change the connection string" afternoon task.

## Go/No-Go acceptance criteria (for if this plan is ever reactivated)

**Go** requires all of: a specific, named PostgreSQL feature requirement driving the decision; staging UAT fully passed; rollback tested; explicit user sign-off on the maintenance window.
**No-Go** if any of: cost projection (re-run at that time) doesn't show a clear benefit over an optimized MySQL Cloud SQL instance; UAT surfaces unresolved data-integrity issues; backups/PITR not independently verified on the new Postgres instance before cutover.

**Current recommendation: No-Go, revisit only if a concrete PostgreSQL-specific requirement emerges.**
