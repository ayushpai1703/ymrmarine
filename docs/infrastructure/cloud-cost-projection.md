# Cloud Cost Projection — Current, Optimized, and Alternative Architectures

**Date:** 2026-10-10
**Primary evidence:** User-provided Cloud Billing "Reports" PDF, October 2026 statement (2026-10-01 → 2026-10-31) — real account data, not an estimate. All other figures in this document are repository-inspection-derived estimates or public pricing research, each explicitly labeled.
**Exchange rate assumption:** ₹83.0–86.0 per USD (October 2026 range observed across sources during this session's research; **not independently verified against a live FX feed** — treat any USD figure converted from the real INR billing data as a range, not a point estimate).
**Pricing research caveat:** Web research during this session returned **conflicting figures from third-party trackers** for several GCP SKUs (Cloud SQL per-vCPU/GB rates, Cloud Run per-vCPU-second rates). Where this happened, it's noted explicitly and a range is given instead of a single number. The official GCP Pricing Calculator (not accessible from this session) is the authoritative source and should be used to firm up any figure here before it drives a purchasing decision.

---

## Scenario A — Current architecture (ACTUAL billing, October 2026)

This is not a projection — it is the real bill.

| Product | Oct 2026 (₹) | Oct 2026 (USD, @₹83–86) | % of total | MoM change |
|---|---:|---:|---:|---:|
| Cloud SQL | 20,329.50 | $236.39 – $244.93 | 93.0% | **+2504%** |
| Compute Engine | 818.59 | $9.52 – $9.86 | 3.7% | +3369% |
| Cloud Load Balancing | 507.68 | $5.90 – $6.12 | 2.3% | +3380% |
| Gemini API | 131.47 | $1.53 – $1.58 | 0.6% | New |
| Persistent Disk | 49.30 | $0.57 – $0.59 | 0.2% | +1566% |
| Artifact Registry | 22.48 | $0.26 – $0.27 | 0.1% | +3022% |
| Cloud DNS | 5.85 | $0.07 | <0.1% | +3341% |
| Cloud Storage | 0.32 | $0.004 | <0.1% | +68% |
| Cloud Run | 0.02 | ~$0 | <0.1% | −100% |
| **Total** | **21,865.22** | **$254.25 – $263.43** | 100% | — |

**Annualized at this run rate:** ₹262,382.64 ≈ **$3,051–$3,161/year** — not a figure anyone should actually pay for a 50-user internal tool; this run rate is the direct subject of RCA-00.

**Confidence:** Highest possible (real billing data) for the October figure itself. Low confidence that this run rate is *representative* of a typical month — a 2504% spike in one product strongly suggests a specific, fixable configuration event rather than steady-state cost (see `root-cause-analysis.md` RCA-00).

**Main cost driver:** Cloud SQL, overwhelmingly. Every other product combined is 7% of the bill.

**What this scenario tells us that estimation alone could not:** Cloud Run — the compute layer actually serving all user traffic — cost **two cents** for the entire month. For a ≤50-user app, Cloud Run compute is essentially free at this traffic level. The entire cost problem lives in the database tier (and two smaller unexplained items), not in application compute. This should redirect all future cost-optimization attention away from Cloud Run tuning and squarely onto Cloud SQL sizing.

---

## Scenario B — Optimized current architecture (Cloud SQL rightsized, same engine)

**Status: estimate, pending live confirmation of current instance tier (RCA-00).** This models what a rightsized MySQL Cloud SQL instance should cost for this workload, using public pricing research gathered this session (see sourcing notes at the end of this document).

Target configuration (recommendation, requires user approval before changing anything):
- Single-zone (non-HA) Cloud SQL for MySQL, Enterprise edition (not Enterprise Plus, unless a specific need for Enterprise Plus's near-zero-downtime maintenance is identified).
- A small dedicated-core tier (e.g. `db-custom-1-3840` / 1 vCPU, 3.75 GB) or, if actual CPU/memory usage proves to be very light once measured, a shared-core `db-g1-small`.
- SSD storage sized to **actual current data volume** (not whatever an unmanaged auto-increase grew it to) plus reasonable headroom — this repo's data model (surveys, uploads metadata, users, clients, ports — no large binary data in the DB itself, files live in Cloud Storage) suggests a 10–20 GB allocation is very likely more than sufficient for years of growth at this scale.
- Automated backups enabled, 7-day retention, point-in-time recovery **on** (this is inexpensive relative to compute and should never be cut to save money — explicit instruction from this engagement's brief).

| Product | Low estimate (USD/mo) | Base estimate (USD/mo) | High estimate (USD/mo) | Basis |
|---|---:|---:|---:|---:|
| Cloud SQL compute (1 vCPU small tier, non-HA, Enterprise) | $25 | $45 | $70 | Third-party trackers this session showed ~$26/mo for `db-g1-small`-class shared-core and conflicting ~$0.04–0.05/vCPU-hr dedicated-core figures; range reflects that spread |
| Cloud SQL storage (15 GB SSD) | $2.55 | $3.30 | $4.50 | $0.17–$0.30/GB-month range found across sources (region/recency conflicting) |
| Cloud SQL automated backups (15 GB) | $1.20 | $1.50 | $2.25 | ~$0.08–$0.15/GB-month range found |
| Compute Engine + Persistent Disk + Load Balancing (RCA-00b, pending audit) | $0 (if orphaned, removed) | $16 (if legitimate, kept) | $17 | Based on Oct 2026 actuals; range depends on whether RCA-00b resolves to "keep" or "decommission" |
| Cloud Run | $0 | $0.02 | $1 | Oct 2026 actual (~$0); small allowance for traffic growth |
| Cloud Storage (uploads) | $0.30 | $1 | $3 | Oct 2026 actual (~$0.004) + growth allowance as survey photo volume accumulates |
| Gemini API | $1.50 | $1.50 | $5 | Oct 2026 actual + headroom if AI photo-description usage grows |
| Artifact Registry + Cloud DNS + misc | $0.30 | $0.35 | $0.50 | Oct 2026 actuals |
| **Total (USD/month)** | **$30.85** | **$68.67** | **$103.25** | |
| **Total (INR/month, @₹83–86)** | **₹2,561–₹2,653** | **₹5,700–₹5,906** | **₹8,570–₹8,880** | |

**Annualized recurring cost:** $370–$1,239/year depending on where in the low/base/high range the real figure lands — still roughly **60–90% less than the current ₹21,865/month run rate.**

**Difference vs. current architecture:** Savings of approximately **$150–$230/month** (≈₹13,000–₹19,500/month), the large majority of it from RCA-00's Cloud SQL rightsizing alone.

**Confidence:** Low-Medium — this is the single most important number in this whole report to firm up, and it genuinely cannot be firmed up further without `gcloud sql instances describe` to see the actual current tier (which tells us both "what to downsize from" and "roughly what it should cost once downsized, by GCP's own calculator"). Treat the $68.67 base estimate as directionally right, not precise.

**Can this stay under $100/month?** Likely yes, based on this modeling — the base and even most of the high-end range land under $100/month once Cloud SQL is rightsized. The main risk to staying under $100 is if RCA-00b's Compute Engine/Load Balancing costs turn out to be legitimate *and* data/traffic grows faster than modeled.

---

## Scenario C — Managed PostgreSQL migration

Per `database-platform-comparison.md`, this scenario is **not recommended**, but is modeled here for completeness as requested.

**Sub-scenario C1 — Cloud SQL for PostgreSQL (stays on GCP):** Cost structure is essentially identical to Scenario B (same vCPU/memory/storage pricing model on Cloud SQL), **plus** a one-time migration cost (see below). No recurring savings over Option B; adds one-time cost and migration risk for no recurring benefit.

**Sub-scenario C2 — Third-party managed Postgres (Neon or Aiven):**

| Provider | Low (USD/mo) | Base (USD/mo) | High (USD/mo) | Basis / caveat |
|---|---:|---:|---:|---:|
| Neon (usage-based, autosuspend) | $10 | $35 | $80 | Compute $0.106–$0.222/CU-hour × estimated low/variable usage; storage $0.09–$0.35/GB-month; genuinely depends on how much the app's connection pattern triggers compute wake-ups — **cannot be modeled accurately without real request-pattern data** |
| Aiven for PostgreSQL | $75 | $75 | $200 | Third-party trackers showed flat per-tier pricing (Startup ≈ $75/mo most consistently cited); no tier below this was found to plausibly fit a $100 total budget alongside other costs |

Plus for either: Cloud Run egress to an external provider outside GCP's network (not present in Scenario A/B, where Cloud SQL traffic never leaves Google's network via the Unix-socket Auth Proxy connection) — **not separately quantified here**, flagged as an additional unknown cost and latency source.

**One-time migration cost (both C1 and C2):** Engineering effort to rewrite ~15+ safety-net blocks, schema conversion, dual-write/sync tooling setup, staging rehearsal, cutover, and a monitored stabilization period. Not a cloud-billing line item, but a real cost — see `managed-postgres-migration-plan.md` for the effort estimate.

**Difference vs. Scenario B:** No recurring savings identified; Neon *could* be cheaper than Scenario B's Cloud SQL estimate at the low end if actual usage is very bursty/light, but this is speculative without real traffic data, and the recurring-cost upside (if any) does not outweigh the one-time migration risk and effort for an app with no PostgreSQL-specific requirement.

**Confidence:** Low (both the provider pricing itself and the usage-pattern assumptions needed to apply it).

---

## Scenario D — Alternative low-cost architecture

No alternative architecture was identified that improves on Scenario B while keeping acceptable security/reliability. Specifically considered and rejected:
- **Self-managed MySQL/Postgres on a bare Compute Engine VM:** could be cheaper in raw compute, but reintroduces the exact operational burden (patching, backup testing, HA) that a 50-user commercial app's small team should not be taking on to save roughly $20–40/month — not a sound trade given the stated business requirement to "retain production reliability."
- **Serverless/edge SQL platforms (e.g., PlanetScale-style, Turso-style):** not evaluated in depth — no MySQL-dialect-compatible option was identified that would avoid the same migration-effort problem as Scenario C while offering a clear cost advantage over a rightsized Cloud SQL instance.

**Recommendation: Scenario D is not pursued.** Scenario B (optimize the existing architecture) is the lowest-realistic-cost option that meets the stated reliability and migration-safety requirements.

---

## Usage-level modeling: 10 / 25 / 50 users

The October 2026 actuals already reflect the account's real current usage level (unspecified exact user count in the billing data itself, but consistent with "up to 50 users" per the brief). Cloud Run's near-zero actual cost (₹0.02) at this real usage level is strong evidence that **Cloud Run compute cost does not meaningfully change between 10, 25, and 50 users** for this app — request volumes this low stay deep inside Cloud Run's free tier (first 180,000 vCPU-seconds, 360,000 GiB-seconds, 2 million requests/month, per the official pricing page) regardless of whether 10 or 50 people are using it.

| Usage level | Cloud Run | Cloud SQL (rightsized, Scenario B) | Cloud Storage | Total (base estimate) |
|---|---:|---:|---:|---:|
| Low (10 users) | ~$0 | $45 (same small tier is sufficient) | $0.50 | **~$46–52/month** |
| Expected (25 users) | ~$0–1 | $45–55 (may need a one-tier bump if concurrent report-generation load rises) | $1 | **~$47–65/month** |
| High (50 users) | ~$1–2 | $55–70 | $2–3 | **~$68–103/month** (matches Scenario B base/high) |

**Confidence:** Medium — the Cloud Run near-zero-cost conclusion is well-evidenced (real data at the actual current usage level); the Cloud SQL figures at each tier are estimates pending the same live-tier confirmation needed everywhere else in this document.

---

## Credits, discounts, and promotional pricing

The October 2026 billing data shows **$0 in Negotiated Savings and $0 in Other Savings** on every line item except small negative "Other savings" adjustments on Compute Engine (−₹60.76) and Persistent Disk (−₹1.35) — meaning **this account is paying full list price with no active committed-use discounts, sustained-use discounts, or promotional credits currently applied.** There is no credit-driven cost cliff to plan around; the figures in this document are real recurring costs, not temporarily-subsidized ones. If a committed-use discount (1-year or 3-year) is considered later as a further optimization once the instance is rightsized, model it as a *separate* decision after Scenario B's base configuration is confirmed stable — locking in a 1-3 year discount on a not-yet-rightsized, possibly-misconfigured instance would lock in the waste, not the savings.

---

## Pricing sourcing notes (for future verification)

- Cloud SQL per-vCPU/GB-hour rates: `cloud.google.com/sql/pricing` — this session's search returned Enterprise N4-series Mumbai rates ($0.0413/vCPU-hr, $0.007/GiB-hr) and conflicting Enterprise Plus N2-series figures; **did not find a confirmed asia-south1 rate specifically for a small shared-core or `db-custom-1-3840`-class instance** — re-verify via the official Pricing Calculator with region=asia-south1 explicitly selected.
- Cloud SQL storage/backup: `$0.17–$0.222/GB-month` (storage) and `$0.08–$0.105/GB-month` (backups) depending on source/recency; a ~30% regional premium for Asia vs. us-central1 was mentioned by one source (nOps) but not independently confirmed.
- Cloud Run: official page confirms the free-tier figures (180,000 vCPU-sec / 360,000 GiB-sec / 2M requests per month); per-second paid rates were inconsistent across sources ($0.000018–$0.000024/vCPU-sec) — irrelevant at this app's actual near-zero usage level either way.
- Cloud Storage: `$0.023/GB-month` (Standard, asia-south1) per Google's own pricing tables, with one conflicting `$0.0253` figure on a separate India-specific table on the same page — re-verify directly.
- Neon: `neon.com/pricing` — Launch $0.106/CU-hr, Scale $0.222/CU-hr, storage $0.35/GB-month per one comparison source; official page snippet only confirmed snapshot pricing ($0.09/GB-month from May 2026), not full compute tiers.
- Aiven: `aiven.io` official page not directly retrieved this session; third-party trackers (Capterra, others) disagreed on Business-tier pricing ($180 vs $200/month) — Startup tier (~$75/month) was the most consistently cited figure.
- **All of the above should be re-verified against the official GCP Pricing Calculator and each named vendor's live pricing page before being used to justify a purchasing decision** — this document's figures are directional, sourced from a mix of official pages and third-party trackers that disagreed with each other in several places, exactly as this engagement's brief anticipated ("avoid false precision").
