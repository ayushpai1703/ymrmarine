# Decision Summary — GCP Infrastructure RCA, Database Evaluation, Cost & Risk Assessment

**Date:** 2026-10-10
**Full artifacts:** `root-cause-analysis.md`, `database-platform-comparison.md`, `managed-postgres-migration-plan.md`, `cloud-cost-projection.md`, `infrastructure-risk-register.md`, and the reusable skill at `.claude/skills/cloud-cost-risk-evaluation/SKILL.md`.
**No production changes, deletions, data migrations, or billing-configuration changes were made during this analysis.** Everything below is a recommendation pending the user's explicit approval.

---

### What is the current infrastructure and its confirmed problems?

PHP 8.3 app (pure PHP, no framework) on Apache, deployed to Cloud Run (`ymrmarine` production, `ymrmarine-staging` staging) from a single repository, backed by **one shared Cloud SQL for MySQL instance** (`ymr-sms:asia-south1:ymrmarine`) holding four separate logical databases (production + staging × YSMS app + marketing site), with file uploads on Cloud Storage-backed volume mounts. No ORM, no connection pooling, no database triggers/views/procedures.

**Confirmed problems**, by evidence strength:
1. **(Real billing data) Cloud SQL cost spiked 2504% and is now 93% of a ₹21,865 (~$254–263) monthly bill** — over 2.5× the user's $100/month target. Root cause not yet pinned down (needs a live `gcloud sql instances describe`), but this is almost certainly a configuration/sizing problem, not steady-state usage cost.
2. **(Real billing data) ~$16–17/month in Compute Engine + Persistent Disk + Load Balancing charges with no corresponding resource found anywhere in the repository** — needs a live audit to confirm whether it's a legitimate custom-domain load balancer or an orphaned resource.
3. **(Personally diagnosed and fixed this session)** Duplicate notification emails and slow document-upload submissions — both root-caused to a per-recipient email-sending loop and already fixed in code, pushed to both branches.
4. **(Confirmed in code)** Schema changes are managed via scattered, ad-hoc "safety net" `ALTER TABLE` blocks across 15+ PHP files instead of a real migration system — this exact pattern caused a production export failure that was debugged and fixed this session, and will recur in some other form until the pattern itself is replaced.

### What are the actual root causes versus unverified hypotheses?

Confirmed (don't need further investigation): items 2–4 above (RCA-00 cost figures, RCA-01/02/03 in the full RCA).
Hypotheses needing live GCP access to confirm (see `root-cause-analysis.md` for the full list): the *exact* cause of the Cloud SQL spike (RCA-00's five candidate explanations), whether staging/production Cloud SQL sharing is an accepted trade-off or needs splitting (RCA-04), whether Cloud Run's lack of explicit scaling limits has caused any real incident (RCA-05), and — most importantly — **whether backups and point-in-time recovery are actually enabled** (RCA-08, flagged Critical if unconfirmed).

### Should we keep Cloud SQL or migrate to managed PostgreSQL?

**Keep Cloud SQL, and fix its configuration.** The weighted decision matrix in `database-platform-comparison.md` scores retaining/optimizing Cloud SQL MySQL at 8.6/10 versus 6.8 for Cloud SQL PostgreSQL and 5.4 for a third-party managed Postgres provider. No PostgreSQL-specific feature requirement was identified anywhere in this codebase. Critically, **the real cost problem (RCA-00) is a sizing/configuration issue that a database-engine migration would not fix** — it would need to be fixed on the new engine just the same, after paying the cost and risk of a 2–4 week migration effort first.

### Which alternative offers the best cost/reliability balance?

A **rightsized** Cloud SQL for MySQL instance (non-HA, small dedicated-core or shared-core tier, SSD storage sized to actual data volume, backups + PITR on) — modeled in `cloud-cost-projection.md` Scenario B at roughly $31–$103/month depending on exact sizing, versus the current ~$254–263/month.

### What is the estimated monthly bill for 10, 25, and 50 users?

| Users | Estimated monthly (USD) |
|---|---|
| 10 (low) | ~$46–52 |
| 25 (expected) | ~$47–65 |
| 50 (high) | ~$68–103 |

Cloud Run cost is effectively flat across this whole range (confirmed by the real October bill: $0.02/month at the account's actual current usage) — the user-count variable barely moves the total; Cloud SQL sizing is what moves it.

### Can we realistically stay under $100/month?

**Likely yes**, once RCA-00 is resolved — the base-case Scenario B estimate ($68.67/month) lands comfortably under budget, and even the high end of the range ($103.25/month) is close. The main threat to staying under $100 is if the unexplained Compute Engine/Load Balancing costs (RCA-00b) turn out to be necessary and kept, combined with faster-than-modeled growth.

### What are the major migration and operational risks?

Per `infrastructure-risk-register.md`, the highest-scored risks today are: data loss from unconfirmed backups (R-01, score 10), recurring billing-increase blindness without budget alerts (R-02, score 12), Cloud SQL cost-spike recurrence without a pinned configuration (R-03, score 12), schema drift recurring in a new form (R-05, score 12), and backup-restoration never having been tested (R-08, score 10). A PostgreSQL migration itself is currently a No-Go per the formal decision gate — not pursued, so its specific migration risks (R-09, R-10) are dormant, not active.

### What should be fixed immediately?

1. **Verify backups/PITR status** (RCA-08) — one read-only command, highest-severity unknown in this whole evaluation.
2. **Verify and rightsize the Cloud SQL instance** (RCA-00) — the single biggest cost lever available, worth more than every other finding combined.
3. **Audit the unexplained Compute Engine/Load Balancing/Persistent Disk resources** (RCA-00b) — small dollar amount, but an unidentified resource is itself a governance gap worth closing.
4. **Set Cloud Billing budget alerts** at $100/$150 — so the next cost spike is caught in days, not a month later in a PDF.

### What should be deferred?

- The PostgreSQL migration — explicitly No-Go, revisit only if a concrete feature requirement emerges.
- Splitting staging/production into separate Cloud SQL instances (RCA-04) — a reasonable future improvement, but not urgent relative to RCA-00/RCA-08, and the decision should be made deliberately (cost vs. isolation trade-off) rather than by default.
- Replacing the ad-hoc migration pattern (RCA-01) with a real migration tool — important for long-term maintainability, not urgent/billing-related, reasonable to schedule as normal engineering work rather than an emergency fix.
- Removing `docker build --no-cache` (RCA-06) — low-cost, low-risk cleanup, fine to batch with other deploy-pipeline work.

### What approvals are required before implementation?

Every item above that touches a live GCP resource requires explicit user approval before execution, per this engagement's safety rules:
- Running `gcloud sql instances describe` / `gcloud compute instances list` / `gcloud run services describe` — **read-only, safe to run as soon as GCP credentials are available, no approval needed beyond granting access.**
- **Resizing or reconfiguring the Cloud SQL instance** — requires explicit approval of the specific target configuration, and should happen in a maintenance window given it's the production database.
- **Decommissioning any Compute Engine/disk/load-balancer resource** — requires explicit approval after RCA-00b's audit confirms what it actually is.
- **Enabling backups/PITR if found disabled** — this is a pure safety improvement with no downside; still flagged for approval per the engagement's blanket rule, but should be a fast yes.
- **Splitting staging/production Cloud SQL instances, if pursued** — requires approval of the added monthly cost (a second small instance) against the isolation benefit.

### How do I invoke the new reusable skill for future evaluations?

The skill lives at `.claude/skills/cloud-cost-risk-evaluation/SKILL.md`. Invoke it the same way any other project skill is invoked — ask Claude Code to use it, e.g. *"Use the cloud-cost-risk-evaluation skill to check why this month's bill went up"* or *"Run the cloud cost and risk evaluation skill against the current Cloud Run config before we change concurrency settings."* It will check for live GCP access first, ask for a billing export if one isn't already available (the single highest-value input, as this engagement demonstrated), inspect the repository, research current pricing, and produce the same kind of RCA/cost-projection/risk-register artifact set — comparing against this engagement's reports when relevant so cost or risk changes over time are visible rather than re-derived from scratch each time.
