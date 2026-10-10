# Decision Summary — GCP Infrastructure RCA, Database Evaluation, Cost & Risk Assessment

**Date:** 2026-10-10 (updated same day after live GCP access was granted)
**Full artifacts:** `root-cause-analysis.md`, `database-platform-comparison.md`, `managed-postgres-migration-plan.md`, `cloud-cost-projection.md`, `infrastructure-risk-register.md`, and the reusable skill at `.claude/skills/cloud-cost-risk-evaluation/SKILL.md`.
**No production changes were made during this analysis.** Every verification step taken was read-only (`gcloud ... describe`, `... list`, `gcloud logging read`); nothing was resized, deleted, or reconfigured by this engagement. Everything below that touches a live resource is still a recommendation pending the user's explicit approval.

---

### What is the current infrastructure and its confirmed problems?

PHP 8.3 app (pure PHP, no framework) on Apache, deployed to Cloud Run (`ymrmarine` production, `ymrmarine-staging` staging) from a single repository, backed by **one shared Cloud SQL for MySQL instance** (`ymr-sms:asia-south1:ymrmarine`) holding four separate logical databases (production + staging × YSMS app + marketing site), with file uploads on Cloud Storage-backed volume mounts and a custom-domain Load Balancer in front of Cloud Run. No ORM, no connection pooling, no database triggers/views/procedures.

**Confirmed problems**, now fully resolved to actual root causes via live GCP access:
1. **The Cloud SQL instance ran on a high-end `db-perf-optimized-N-8` tier from creation (Aug 24, 2026) until Oct 7, 2026**, when the user independently downsized it to the correctly-sized `db-custom-1-3840` (1 vCPU, 3.75 GB) — confirmed directly from the Cloud SQL Admin Activity audit log. The October bill (₹20,329.50, 93% of a ₹21,865 total) reflects roughly a week on the expensive tier blended with the rest of the month on the now-correct one. **This was already fixed by the user three days before this report was finished.**
2. **A Compute Engine VM (`instance-20260818-094020`, `e2-medium`) has been running since Aug 18 with no reference anywhere in the codebase** — its auto-generated default name suggests a manual, ad-hoc creation, not part of the deployment pipeline. ~$16-17/month combined with its disk. The Load Balancer billed alongside it is legitimate (serves the custom domains) and should stay.
3. **(Personally diagnosed and fixed this session)** Duplicate notification emails and slow document-upload submissions — both root-caused to a per-recipient email-sending loop and already fixed in code, pushed to both branches.
4. **(Confirmed in code)** Schema changes are managed via scattered, ad-hoc "safety net" `ALTER TABLE` blocks across 15+ PHP files instead of a real migration system — this exact pattern caused a production export failure that was debugged and fixed this session, and will recur in some other form until the pattern itself is replaced.

### What are the actual root causes versus unverified hypotheses?

**Everything in this engagement is now confirmed, not hypothesized**, after live GCP access was granted. The Cloud SQL tier history, the current instance configuration (zonal, Enterprise edition, backups/PITR enabled), the Compute Engine VM's identity, and the Load Balancer's legitimacy were all verified directly via `gcloud` and Cloud Logging audit-log queries, not inferred from the repository. The only items still open are process/decision questions, not unknowns: whether to split staging/production onto separate Cloud SQL instances (RCA-04), and your call on decommissioning the orphaned VM (RCA-00b).

### Should we keep Cloud SQL or migrate to managed PostgreSQL?

**Keep Cloud SQL, and the fix is already in place.** The weighted decision matrix in `database-platform-comparison.md` scores retaining/optimizing Cloud SQL MySQL at 8.6/10 versus 6.8 for Cloud SQL PostgreSQL and 5.4 for a third-party managed Postgres provider. No PostgreSQL-specific feature requirement was identified anywhere in this codebase. The real cost problem (RCA-00) turned out to be a tier that was already corrected by the user on Oct 7 — a database-engine migration would never have been the fix for this, and would have added weeks of risk for no benefit.

### Which alternative offers the best cost/reliability balance?

The **already-applied** fix: Cloud SQL for MySQL, `db-custom-1-3840`, non-HA, Enterprise edition, backups + PITR on — this is close to exactly what `cloud-cost-projection.md` Scenario B modeled independently as the rightsizing target (~$31–$103/month), before the live data confirmed it's already the live configuration.

### What is the estimated monthly bill for 10, 25, and 50 users?

| Users | Estimated monthly (USD) |
|---|---|
| 10 (low) | ~$46–52 |
| 25 (expected) | ~$47–65 |
| 50 (high) | ~$68–103 |

Cloud Run cost is effectively flat across this whole range (confirmed by the real October bill: $0.02/month at the account's actual current usage) — the user-count variable barely moves the total; Cloud SQL sizing is what moves it, and that's now fixed.

### Can we realistically stay under $100/month?

**Likely yes, and the hard part is already done.** With the instance now on `db-custom-1-3840`, a full month at this configuration should land close to the Scenario B base case (~$69/month), comfortably under budget. **November's bill is the number that actually confirms this** — October is a blended month and isn't representative. The main remaining threat to staying under $100 is if the orphaned Compute Engine VM (RCA-00b) is kept rather than decommissioned, combined with organic growth.

### What are the major migration and operational risks?

Per `infrastructure-risk-register.md`, the highest-scored *remaining* risks are: recurring billing-increase blindness without budget alerts (R-02, score 12 — exactly what let the Aug–Oct tier run unnoticed for 6 weeks), Cloud SQL cost-spike recurrence without a pinned configuration (R-03, score 12 — nothing currently prevents someone from resizing back up without anyone noticing until the next bill), and schema drift recurring in a new form (R-05, score 12). Data-loss risk (R-01) and backup-restoration risk (R-08) are substantially de-risked now that backups/PITR are confirmed properly configured — the residual piece is simply test-restoring once to prove it works end to end. A PostgreSQL migration itself remains a No-Go per the formal decision gate.

### What should be fixed immediately?

1. ~~Verify backups/PITR status~~ — **done, confirmed correctly configured.**
2. ~~Verify and rightsize the Cloud SQL instance~~ — **done, by the user, Oct 7.**
3. **Decide on the orphaned Compute Engine VM** (`instance-20260818-094020`) — confirm whether it's still needed; decommission if not.
4. **Set Cloud Billing budget alerts** at $100/$150 — the single most valuable remaining action. This is what would have caught the six-week expensive-tier period within days instead of it surfacing in a monthly PDF.
5. **Test-restore a backup once**, to a scratch instance, to confirm the existing backup configuration actually works end to end (R-08's residual risk).

### What should be deferred?

- The PostgreSQL migration — explicitly No-Go, revisit only if a concrete feature requirement emerges.
- Splitting staging/production into separate Cloud SQL instances (RCA-04) — a reasonable future improvement, not urgent, and should be a deliberate cost-vs-isolation decision rather than a default.
- Replacing the ad-hoc migration pattern (RCA-01) with a real migration tool — important for long-term maintainability, not urgent/billing-related, reasonable to schedule as normal engineering work.
- Removing `docker build --no-cache` (RCA-06) — low-cost, low-risk cleanup, fine to batch with other deploy-pipeline work.

### What approvals are required before implementation?

Much less than originally anticipated, since the main cost fix is already applied:
- **Decommissioning the Compute Engine VM + its disk** — requires your confirmation that it's safe to remove.
- **Setting Cloud Billing budget alerts** — essentially no downside, a fast yes.
- **Splitting staging/production Cloud SQL instances, if pursued** — requires approval of the added monthly cost (a second small instance) against the isolation benefit.
- Everything else in this engagement was either already fixed in code (duplicate emails, slow uploads) or is read-only documentation/process guidance (the migration-tracking recommendation, the reusable skill) with nothing further to approve.

### How do I invoke the new reusable skill for future evaluations?

The skill lives at `.claude/skills/cloud-cost-risk-evaluation/SKILL.md`. Invoke it the same way any other project skill is invoked — ask Claude Code to use it, e.g. *"Use the cloud-cost-risk-evaluation skill to check why this month's bill went up"* or *"Run the cloud cost and risk evaluation skill against the current Cloud Run config before we change concurrency settings."* It checks for live GCP access first (including, per this engagement's experience, pulling the Cloud SQL Admin Activity audit log when a cost question needs a configuration-change history, not just a current-state snapshot), asks for a billing export if one isn't already available, inspects the repository, researches current pricing, and produces the same kind of RCA/cost-projection/risk-register artifact set — comparing against this engagement's reports when relevant so cost or risk changes over time are visible rather than re-derived from scratch each time.
