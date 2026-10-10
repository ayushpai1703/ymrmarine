# Repository & Live Infrastructure Inspection Checklist

Run through this at the start of every evaluation. Not every item will apply to every question asked — skip what's irrelevant to the specific scope, but check for its presence before assuming it doesn't exist.

## Repository (always do this first — works with zero GCP access)

- [ ] `gcloud auth list` / `gcloud config list` — establish whether live access exists at all, before anything else.
- [ ] `cloudbuild.yaml` (or equivalent CI/CD config) on **every** deploy branch — don't assume staging and production configs match; diff them.
- [ ] `Dockerfile` — base image, installed extensions/packages, build flags (watch for things like `--no-cache` that have a recurring cost), ports, entrypoint.
- [ ] Database connection bootstrap code — DSN construction, driver used, connection pooling (or its absence), persistent-connection flags, how many times the connection function is called per typical request.
- [ ] Schema/migration files — is there a single source of truth, or scattered incremental files? Is there a migration-tracking table, or is schema state inferred informally? Look specifically for runtime "safety net" patterns (code that checks for and adds a missing column/table on page load) — these are both a schema-drift risk indicator and a sign the SQL-file inventory alone can't be trusted as "what's really deployed."
- [ ] `composer.json`/`package.json`/equivalent — identify every external paid API (AI models, email relays, SMS/WhatsApp, payment processors, etc.) and where its credentials/keys are referenced.
- [ ] Git log — recent infrastructure-relevant commits, especially anything touching deploy config, database config, or a recent incident fix (recent session history, if available, is `SESSION`-tier evidence and often the richest source of "what's actually broken" available without live access).
- [ ] README and any existing docs — often stale, but check for documented assumptions worth verifying against reality.

## Live GCP (only if authenticated)

- [ ] `gcloud sql instances describe <instance>` — tier, edition, HA setting, storage size/type, storage auto-increase setting, backup configuration (enabled? retention? PITR?), maintenance window.
- [ ] `gcloud run services describe <service> --region <region>` — min/max instances, CPU, memory, concurrency, timeout, env vars (names only — never log secret *values*), mounted volumes.
- [ ] `gcloud compute instances list` / `gcloud compute disks list` / `gcloud compute forwarding-rules list` — catch any resource (VM, disk, load balancer) that isn't accounted for by the application's documented architecture. An unexplained Compute Engine + Persistent Disk + Load Balancing cost was exactly this kind of finding in the October 2026 evaluation.
- [ ] Cloud Billing Reports export or Billing API query — always prefer this over estimation when available; see `evidence-and-estimation-standards.md`.
- [ ] Cloud Monitoring dashboards — CPU/memory/connection metrics on the database, request latency/error rate on the compute layer, if a performance (not just cost) question is in scope.
- [ ] Cloud Logging — recent error-rate trends, auth failures, slow-query logs if enabled.
- [ ] IAM / Secret Manager — confirm secrets are stored there (not as plaintext env vars) as a basic security check; never read or print the secret *values*.

## When evaluating a proposed change (migration, resize, new integration)

- [ ] Inventory every place in the code that would need to change (don't estimate this — grep for it; the October 2026 MySQL→PostgreSQL evaluation found 15+ distinct files with MySQL-specific `SHOW COLUMNS` safety-net code that a surface-level schema comparison would have missed entirely).
- [ ] Check for triggers/views/stored procedures/scheduled events — these are often the hardest part of a cross-engine migration and are easy to miss if only `.sql` schema files (not the live database) are inspected.
- [ ] Identify the *actual* current pain point before assuming the proposed change fixes it — the October 2026 evaluation's biggest finding was that the real cost problem (an oversized Cloud SQL instance) had nothing to do with the database engine, and a migration would not have fixed it. Always ask "does this change address the root cause, or just move the problem?"
