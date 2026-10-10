# Database Platform Comparison — Cloud SQL (current) vs. Alternatives

**Date:** 2026-10-10
**Current confirmed state (REPO evidence):** Google Cloud SQL for **MySQL** (InnoDB, `utf8mb4`), single instance `ymr-sms:asia-south1:ymrmarine` (Mumbai region), connected from Cloud Run over the Cloud SQL Auth Proxy Unix socket, accessed via plain PDO/`pdo_mysql` with no ORM and no connection pooling. No triggers, views, stored procedures, functions, or scheduled events exist anywhere in the SQL files — the application does **all** logic in PHP, not in the database.

This matters for the comparison: a schema with zero server-side logic is the *easiest possible case* for a cross-engine migration, because there is no procedural SQL to rewrite. The hard part of any MySQL→PostgreSQL move here is **SQL dialect differences in the PHP query strings themselves**, not database-side objects.

> **Real billing data changes the framing of this whole comparison.** The user's October 2026 GCP billing export shows Cloud SQL alone costing ₹20,329.50 (~$236–245/month), a 2504% month-over-month spike, versus a near-zero Cloud Run bill (₹0.02) and a trivial Cloud Storage bill (₹0.32) — see `root-cause-analysis.md` RCA-00. **This means the current Cloud SQL instance is almost certainly misconfigured/oversized for a 50-user app, not that MySQL-on-Cloud-SQL is inherently expensive.** Every cost figure below for Option A assumes a *rightsized* instance (per RCA-00's corrective action), not the currently-billed configuration — the currently-billed configuration is **not** a fair baseline for "what Cloud SQL costs for this workload," it's a symptom of an unidentified configuration problem that needs fixing regardless of which database engine is ultimately chosen.

## MySQL-specific dependencies found in the codebase (evidence for Phase 3 migration-necessity question)

Grepping the live query code for MySQL-only syntax found (non-exhaustive, representative):

- `AUTO_INCREMENT` column definitions throughout `database_cloudsql.sql` (PostgreSQL equivalent: `GENERATED ALWAYS AS IDENTITY` or `SERIAL`, different syntax, different reset/ALTER behavior).
- `ENUM(...)` columns (e.g. `surveys.status`, `users.role`/`role_id` pattern) — MySQL `ENUM` has no direct PostgreSQL equivalent; must become a `CHECK` constraint or a native PostgreSQL `ENUM` type (different ALTER semantics — PostgreSQL can't easily reorder/remove enum values).
- `ON UPDATE CURRENT_TIMESTAMP` column attribute (used for `updated_at` columns) — has no PostgreSQL equivalent; requires a trigger (reintroducing exactly the "server-side logic" this schema currently avoids).
- `GROUP_CONCAT(... SEPARATOR ' + ')` — used repeatedly for combining multi-surveyor names (`getCombinedSurveyorNames`-style queries). PostgreSQL equivalent is `STRING_AGG(..., ' + ')` — different function name, broadly compatible syntax otherwise.
- `SHOW COLUMNS FROM ...` / `SHOW TABLES` — used pervasively by the "safety net" pattern (see RCA-01) to check column existence at runtime. PostgreSQL has no `SHOW COLUMNS`; the equivalent is a query against `information_schema.columns`. **Every single one of the 15+ safety-net blocks across the codebase would need rewriting**, not just the schema.
- `ALTER TABLE ... MODIFY COLUMN` — MySQL syntax; PostgreSQL uses `ALTER TABLE ... ALTER COLUMN ... TYPE ...` with different rules (e.g., explicit `USING` clause often required for type changes).
- Unix-socket DSN format `mysql:unix_socket=...` in `config/config.php` — PDO's PostgreSQL driver uses a different DSN shape entirely (`pgsql:host=...`), and Cloud SQL's Postgres Auth Proxy socket path differs from MySQL's.
- Date/time handling: the app sets `SET time_zone = '+05:30'` per-connection (MySQL session variable) and uses MySQL's permissive implicit date/string casting in several places. PostgreSQL is stricter about type casting and uses `SET timezone = 'Asia/Kolkata'` with different session-variable semantics.
- `PDO::ERRMODE_EXCEPTION` and prepared-statement usage are driver-agnostic — **this part requires no change** either way (confirmed: `getDB()` returns a plain `PDO` object, not a MySQL-specific wrapper).

**Conclusion of this inventory:** a MySQL→PostgreSQL move is **not** a drop-in "change the DSN" exercise for this codebase. It is a genuine application-code migration touching the schema, every safety-net block, every `GROUP_CONCAT`, every `ENUM`/`ON UPDATE CURRENT_TIMESTAMP` column, and the connection bootstrap — realistically dozens of files. This is why Phase 3 explicitly asks "is a direct PostgreSQL migration necessary" rather than assuming yes.

---

## Options evaluated

### A. Google Cloud SQL for MySQL (current engine), optimized
Keep MySQL, keep Cloud SQL, but address RCA-04/05/06/08 (separate staging instance or documented acceptance, explicit Cloud Run scaling limits, drop `--no-cache`, verify/enable backups+PITR).

- **Monthly cost at 50 users:** Lowest of all options — see `cloud-cost-projection.md` Scenario B. No new engine licensing, no new vendor relationship, no data-type conversion risk.
- **Migration complexity:** None (zero code changes required for the database layer itself; only deploy-config and schema-governance changes).
- **PHP compatibility:** Already proven working in production.
- **HA/backups/PITR:** Cloud SQL supports all three natively; currently unverified whether enabled (RCA-08) — **this gap exists regardless of which database option is chosen**, since it's a configuration toggle, not an engine property.
- **Vendor lock-in:** Already locked into GCP via Cloud Run + Cloud Storage regardless of database choice; Cloud SQL adds no *additional* lock-in beyond what already exists.
- **Suitability for 50-user app:** High — this is a small, low-concurrency workload well within a `db-g1-small`/small `db-custom` instance's capacity.

### B. Google Cloud SQL for PostgreSQL
Same GCP-managed operational model (automated backups, PITR, HA, Cloud SQL Auth Proxy, IAM integration) but a different engine.

- **Monthly cost:** Comparable to Option A at equivalent instance sizing — PostgreSQL and MySQL Cloud SQL instances are priced on the same vCPU/memory/storage model; the engine choice itself is close to cost-neutral on Cloud SQL. (Confirmed via GCP pricing page structure; exact Mumbai per-vCPU/GiB rates showed some conflicting figures across tiers in this session's research — see `cloud-cost-projection.md` sourcing notes.)
- **Migration complexity:** High — full schema/query rewrite as inventoried above, **but** migration tooling stays entirely within GCP (Database Migration Service supports MySQL→PostgreSQL migrations with caveats, or a manual `mysqldump`→conversion→`pg_restore` path).
- **Why consider it at all:** Only if there's a specific PostgreSQL feature this app needs that MySQL lacks (e.g., native JSON querying with indexes, stricter data integrity via `CHECK` constraints replacing the removed `ENUM`s, window functions for reporting). **No such requirement was identified in this codebase during inspection** — the app does not use advanced SQL features on either side.
- **Verdict driver:** This option exists primarily to de-risk a *future* PostgreSQL-only requirement while staying on GCP's managed tooling; it is not justified by any current pain point.

### C. Third-party managed PostgreSQL (Neon, Aiven, or similar)
Database leaves GCP entirely; Cloud Run connects over the public internet (TLS) to an external provider.

- **Monthly cost:** Neon's usage-based compute (~$0.106–$0.222 per compute-unit-hour on its paid tiers, plus ~$0.09–$0.35/GB-month storage depending on source) can be **cheaper than Cloud SQL at very low, bursty usage** (Neon's autosuspend-to-zero model), but Aiven's fixed-tier pricing ($75/mo Startup tier and up per third-party trackers — confirm on aiven.io, sources conflicted) is **likely more expensive** than an optimized small Cloud SQL instance for this workload. See `cloud-cost-projection.md` Scenario C for the modeled range.
- **Network latency:** Cloud Run (`asia-south1`) to a Neon/Aiven region outside India adds real round-trip latency per query that the current Unix-socket-local Cloud SQL Auth Proxy connection does not have. Neither Neon nor Aiven publishes a Mumbai-region offering as of this research — the nearest regions are typically Singapore or Frankfurt, adding tens of milliseconds per query versus the current near-zero-latency local-socket connection. For a request-per-page-load PHP app issuing many small sequential queries (not a single batched call), this latency compounds per request.
- **HA/backups/PITR:** Varies by provider and tier; generally available on paid tiers, often less mature/battle-tested than Cloud SQL's at Google's scale.
- **Operational complexity:** New vendor relationship, new secret-management integration (API keys/connection strings outside GCP Secret Manager's existing pattern, though still storable there), new support channel, new billing relationship to monitor.
- **Vendor lock-in:** Lower lock-in to GCP specifically (database is portable to any cloud), but adds a *second* vendor to depend on, which is a different kind of operational risk, not less risk overall.
- **Migration complexity:** Same application-code rewrite burden as Option B, **plus** cross-cloud network/security setup (TLS cert handling, IP allowlisting, egress cost from GCP to the external provider) that Option B (Cloud SQL Postgres) avoids entirely.
- **Verdict driver:** Only clearly wins if the compute-autosuspend cost model (Neon) genuinely beats Cloud SQL for this app's actual (likely low, bursty, mostly-business-hours) traffic pattern — **cannot be confirmed without real usage data** (`NEEDS-LIVE`).

### D. PostgreSQL-compatible managed service on another cloud provider
(e.g., AWS RDS/Aurora PostgreSQL, Azure Database for PostgreSQL)

- **Not justified here.** This would mean operating Cloud Run (GCP) with the database on a different cloud entirely — maximum network latency, maximum operational complexity (two cloud consoles, two billing relationships, two IAM systems, cross-cloud private networking cost), with no offsetting benefit identified. **Eliminated from serious consideration** — included only for completeness per the evaluation brief.

### E. Self-managed PostgreSQL on a VM or container
- **Monthly cost:** Potentially lowest raw compute cost (a small Compute Engine VM can be cheaper than a managed instance), but this ignores the real cost: **someone has to patch the OS, patch PostgreSQL, configure and test backups, configure and test PITR, configure replication/HA, and be on call for all of it.** For a small team running a 50-user commercial survey-management app, this operational burden is a real cost even though it doesn't appear as a GCP line item.
- **Business impact of getting it wrong:** Highest of all options — a misconfigured or untested self-managed backup is the single most common cause of real data-loss incidents industry-wide.
- **Verdict:** **Not recommended** for this team/workload. Explicitly ruled out: the operational overhead and DR risk outweigh any compute savings at this scale.

---

## Weighted decision matrix

Weights reflect this specific app: a commercial, revenue-generating, ≤50-user internal tool where **data durability and migration safety matter far more than shaving a few dollars off a sub-$100/month bill.**

| Criterion | Weight | A. Cloud SQL MySQL (optimized) | B. Cloud SQL PostgreSQL | C. Neon/Aiven (3rd-party PG) | D. Other-cloud PG | E. Self-managed PG |
|---|---|---|---|---|---|---|
| Monthly cost at 50 users | 20% | 9 | 8 | 6 | 3 | 7 (compute only; excludes labor) |
| HA / backup / PITR maturity | 20% | 9 (pending RCA-08 verification) | 9 (same) | 6 (varies by tier) | 7 | 2 |
| Migration complexity / safety | 20% | 10 (none needed) | 3 | 3 | 2 | 2 |
| Network latency to Cloud Run | 10% | 9 (local socket) | 9 (local socket) | 4 (cross-region/cloud) | 2 | 9 (if same region) |
| Operational complexity | 10% | 9 | 8 | 6 | 3 | 2 |
| Vendor lock-in / portability | 5% | 5 | 5 | 7 | 6 | 9 |
| PHP/driver compatibility | 5% | 10 (proven) | 7 (pdo_pgsql works, untested here) | 7 | 7 | 7 |
| Scaling flexibility | 5% | 7 | 7 | 8 | 7 | 4 |
| Support/incident response | 5% | 8 (Google SLA) | 8 | 6 | 6 | 1 (self) |
| **Weighted total** | | **8.6** | **6.8** | **5.4** | **3.7** | **4.3** |

## Recommendation

**Retain and optimize Google Cloud SQL for MySQL (Option A).** No current functional, performance, or cost requirement justifies the migration risk and effort of moving to PostgreSQL — managed or self-hosted, on GCP or elsewhere. The schema's complete absence of triggers/views/procedures means a future migration (if a real PostgreSQL-specific need ever emerges) remains comparatively tractable; there is no reason to pay that migration cost *today* speculatively.

The one item that should happen regardless of the database-engine decision is closing RCA-08 (confirm backups/PITR/HA are actually enabled and tested) and RCA-04/05 (staging isolation and Cloud Run scaling limits) — these are configuration fixes on the *existing* platform, not reasons to change platforms.

**If this recommendation changes in the future**, the most likely trigger would be a concrete PostgreSQL-only feature requirement (e.g., native JSONB querying with GIN indexes for a future analytics feature, or a specific compliance/tooling requirement that mandates PostgreSQL) — not cost or reliability, both of which favor staying on the current engine today.
