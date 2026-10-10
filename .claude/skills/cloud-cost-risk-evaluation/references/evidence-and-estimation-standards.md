# Evidence and Estimation Standards

## Evidence tiers (use consistently, tag every finding)

| Tag | Meaning | Confidence |
|---|---|---|
| `BILLING` | A real Cloud Billing export (Reports PDF/CSV, or a live Billing API query) the user provided or the agent pulled with live credentials | Highest |
| `LIVE-GCP` | A live `gcloud`/Console/Monitoring query result | Highest |
| `SESSION` | Something the agent personally diagnosed/fixed/observed in the current or a very recent working session (e.g., a bug just reproduced and fixed) | High |
| `REPO` | Found directly in the repository: code, config files, SQL, Dockerfile, deploy scripts, git history | High for "this is what's configured," but does not confirm live/deployed state matches the repo |
| `RESEARCH` | Public pricing pages, vendor documentation, third-party cost trackers found via web search | Medium — always cross-check multiple sources; report disagreement explicitly rather than picking one silently |
| `NEEDS-LIVE` | Explicitly unconfirmed; requires `gcloud`/Console/Billing access that wasn't available this run | None — treat as a flagged gap, never fill it with a guess presented as fact |

**Rule:** never let a `RESEARCH` or `NEEDS-LIVE` figure read as confidently as a `BILLING`/`LIVE-GCP` one in the final report. Say "estimate" or "pending confirmation" out loud in the prose, not just in a tag.

## When conflicting pricing data is found

This will happen — public pricing trackers disagree with each other and with official pages regularly (confirmed repeatedly during the October 2026 evaluation: Cloud SQL vCPU rates, Cloud Run per-second rates, and Aiven tier pricing all had conflicting figures across sources). When it does:

1. Report the range, not a single cherry-picked number.
2. Name which source said what.
3. State which source is most likely authoritative (official vendor pricing page > official pricing calculator > third-party tracker) and recommend verifying there before a purchasing decision.
4. Never silently average conflicting numbers into a fake-precise single figure.

## Severity / Likelihood / Impact scoring (keep consistent across runs so reports are comparable over time)

**RCA severity** (used in root-cause-analysis documents): Critical / High / Medium / Low, judged by:
- Critical: active data-loss risk, or cost impact >50% of the total infrastructure budget, or complete service unavailability risk.
- High: confirmed meaningful cost waste (double-digit percent of budget) or a real reliability gap with no current mitigation.
- Medium: confirmed but bounded impact, or a plausible-but-unconfirmed hypothesis with real business consequence if true.
- Low: cosmetic, small-dollar, or very-low-likelihood findings.

**Risk register scoring**: Likelihood (1–5) × Impact (1–5) = Score (1–25).
- Likelihood: 1 Rare, 2 Unlikely, 3 Possible, 4 Likely, 5 Almost Certain.
- Impact: 1 Negligible, 2 Minor, 3 Moderate, 4 Major, 5 Severe.
- Bands: 1–4 Low, 5–9 Medium, 10–14 High, 15–25 Critical.

Always record **residual risk after mitigation**, not just the raw score — a register that only shows pre-mitigation scores doesn't help anyone decide what's still worth worrying about.

## Cost projection standards

- Always model at least low/base/high, never a single point estimate, unless the figure is `BILLING`-tier real data (which is inherently a point, not an estimate).
- Separate recurring monthly costs from one-time (migration, setup) costs in every table.
- State the currency-conversion assumption explicitly wherever converting between currencies (e.g., "₹83–86/USD, October 2026 range, not independently verified against a live FX feed").
- Report pre-discount/pre-credit list cost separately from any negotiated savings, committed-use discounts, or promotional credits — never present a credit-subsidized figure as the steady-state recurring cost.
- Model at the usage levels the user actually cares about (the original engagement used 10/25/50 users); if real billing data exists at the current usage level, use it to sanity-check whether the *other* usage levels' modeling assumptions (e.g., "Cloud Run cost barely changes with user count at this app's scale") still hold, rather than re-deriving every level from first principles.

## Inspection checklist

See `inspection-checklist.md` in this same directory for the concrete list of files/commands to check during Phase 1 ("Inspect the repository") of any run of this skill.
