---
name: cloud-cost-risk-evaluation
description: Reusable GCP infrastructure root-cause, cost-projection, and risk-evaluation workflow for the YMR Marine / YSMS project. Use whenever asked to investigate cloud costs, evaluate a database or infrastructure change, assess migration risk, or produce an updated cost/risk report for this project — not limited to the original MySQL-vs-PostgreSQL question.
---

# Cloud Cost & Risk Evaluation

Reusable methodology for evaluating this project's GCP infrastructure: root-cause analysis of cost/reliability problems, cost projection across scenarios and usage levels, and risk assessment for a proposed change — database engine, Cloud Run config, storage growth, a new AI integration, user-count growth, or anything else infrastructure-related.

Originally built for the October 2026 MySQL-vs-managed-PostgreSQL evaluation (see `docs/infrastructure/` for that engagement's full artifact set — read `decision-summary.md` there first for context and to compare against a new evaluation).

## When to use this skill

Invoke it whenever the user asks to:
- Investigate why a GCP bill is high or increased unexpectedly.
- Evaluate whether to change Cloud SQL sizing, Cloud Run config, or storage.
- Assess a proposed database or infrastructure migration.
- Re-run a cost/risk check after infrastructure or usage changes.
- Produce a fresh root-cause-analysis/cost-projection/risk-register set for a *different* question than the original MySQL/Postgres one (same methodology, new scope).

## Hard safety rules (never violate these)

- **Read-only by default.** Inspect, research, and report — never run a command that creates, modifies, resizes, or deletes a GCP resource, changes billing/budget configuration, or migrates data, without the user's **explicit, separate approval** for that specific action.
- Never print passwords, API keys, service-account keys, access tokens, or connection strings containing credentials. Redact.
- Never claim parallel execution happened if it didn't — if subagents/parallel tool calls aren't used, say the analysis was sequential.
- Never invent a cost, usage, or configuration figure. If it can't be verified (no live `gcloud` access, no billing export provided), say so explicitly and label it as an assumption or estimate with a stated range — see `references/evidence-and-estimation-standards.md`.
- Treat a user-provided billing export (PDF/CSV) as the highest-confidence evidence available — always prefer it over estimated/researched figures when present, and reconcile the two explicitly if they disagree.

## Workflow

1. **Check live access first.** Run `gcloud auth list` and `gcloud config list`. If authenticated, use live resource/billing queries wherever the read-only commands in `references/evidence-and-estimation-standards.md` apply. If not authenticated, say so up front and proceed on repo-inspection + research, flagging every finding's evidence tier.
2. **Ask for a billing export if one isn't already provided and live billing access isn't available.** A real Cloud Billing "Reports" PDF/CSV export is dramatically more useful than estimation — see how the October 2026 evaluation's findings changed completely once real billing data arrived (Cloud SQL turned out to be 93% of the bill with a 2504% spike, something no amount of repo-only inspection could have surfaced).
3. **Inspect the repository**: `cloudbuild.yaml` (both branches if the project has staging/production branches), `Dockerfile`, DB connection code, schema/migration files, composer/package manifests for API integrations (AI, email, payments, etc.), git log for recent config changes. Full checklist in `references/inspection-checklist.md`.
4. **Load prior reports if they exist** (`docs/infrastructure/*.md` from a previous run of this skill) and compare: has cost changed materially? Have risk scores changed? Call out deltas explicitly rather than re-deriving everything from scratch silently.
5. **Research current public pricing** for every relevant SKU/provider via web search. Expect and report conflicting figures from different sources — this is normal, not a research failure. Present ranges (low/base/high), never false precision. Cite source URLs and retrieval context.
6. **Write the artifacts** (adapt filenames to the specific question being evaluated; the original engagement's files are a template):
   - `docs/infrastructure/root-cause-analysis.md` — confirmed issues first (tag their evidence source), then hypotheses clearly marked as unconfirmed, each scored for severity.
   - `docs/infrastructure/cloud-cost-projection.md` — actual-billing scenario first if available, then optimized/alternative scenarios, each with low/base/high ranges and explicit sourcing notes.
   - A comparison/decision-matrix document if evaluating alternatives (weighted criteria, scored options, a clear recommendation).
   - A risk register (likelihood × impact scoring, preventive controls, detection method, contingency, owner, residual risk).
   - A `decision-summary.md` that directly answers: what's the problem, what's confirmed vs. hypothesis, what's recommended, what does it cost, what are the top risks, what needs approval.
7. **Reconcile across documents** before finishing: same cost figures everywhere they're referenced, same recommendation everywhere, no contradictions. If parallel subagents were used to draft different documents, this reconciliation pass is mandatory, not optional.
8. **Report a concise terminal summary**: files produced, top findings, recommendation, cost range + confidence, top risks, and what needs the user's approval before anything is implemented.

## Evidence standards

See `references/evidence-and-estimation-standards.md` for the full evidence-tagging convention (`BILLING` > `LIVE-GCP` > `REPO`/`SESSION` > `NEEDS-LIVE` research estimate) and the severity/likelihood/impact scoring rubrics used across every artifact this skill produces. Keep the same tagging convention across runs so reports stay comparable over time.

## Output location

Default to `docs/infrastructure/` for report artifacts unless the user specifies otherwise or the project already has an established docs location for this kind of report — check for one before creating a new path.
