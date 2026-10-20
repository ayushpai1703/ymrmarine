# YSMS daily emails — 17:00 IST

Two reports, each sent once a day at **17:00 Asia/Kolkata**:

| Job key (`?job=`) | Email | From → To | Subject |
|---|---|---|---|
| `surveyor_pending` | **Daily Assigned Vessels Report** — one separate email per active surveyor, only the vessels currently assigned to that surveyor (status *Pending Vessel*, as primary surveyor or co-surveyor in `survey_surveyors`) | `ops@ymrmarine.com` → the surveyor's profile email (`users.email`) | `Daily Assigned Vessels Report – 21-10-2026` |
| `appointments` | **Daily Vessel Appointments Report** — one consolidated email: every vessel whose Appointment Date is today **or** that was received (assigned, `assign_date`) today, any status; a clear "no appointments" notice when there are none | `ops@ymrmarine.com` → `survey@ymrmarine.com` | `Daily Vessel Appointments Report – 21-10-2026` |

Both mails use the YMR Marine Solutions HTML template with a table of the vessels plus the same Excel layout as *Export Excel* attached. Sender, recipients, CC, time and the "empty" behaviour can be changed in **Admin Controls → Daily Emails**. Surveyors with no assigned vessels get no mail (option to change that on the same page). The application stores no appointment *time*, only the Appointment Date, so none is shown.

## Code

| File | What |
|---|---|
| `YSMS/includes/daily_emails.php` | Jobs, data queries, HTML template, Excel, run/delivery log, idempotency, retry |
| `YSMS/ajax/daily_emails_run.php` | Endpoint the scheduler calls (`X-Cron-Token`) |
| `YSMS/admin_daily_emails.php` | Admin page: settings, today's deliveries, Retry failed, Test to me, Send now, run log |
| `YSMS/admin_controls.php` | "Daily Emails" link |
| `YSMS/includes/footer.php` | Safety-net check from logged-in pages (at most every 10 min per browser) — never needed when Cloud Scheduler runs, can never cause a duplicate |

Mail goes through the existing `sendHtmlEmailMulti()` (PHPMailer): Gmail API domain-wide delegation as `ops@ymrmarine.com`, falling back to the Workspace SMTP relay (`YSMS_SMTP_RELAY_*`). No surveyor passwords are used. A delivery is recorded as `sent` only when PHPMailer / the Gmail API accepted the message.

## Database (created automatically on first use, no manual migration)

* `daily_email_settings` — name/value settings (+ `last_scheduler_call_<job>`, `schedule_1700_applied`).
* `daily_email_runs` — one row per run. Scheduled runs carry `auto_key = "<job>|<YYYY-MM-DD>"` (UNIQUE): the first call of the day claims it; parallel or repeated calls do nothing. `status` running / sent / failed / skipped, `attempts`, `via` (scheduler / page / admin-retry).
* `daily_email_deliveries` — one row per recipient per run (UNIQUE `run_id, recipient`): status, error, attempts, sent_at.

On first use the 17:00 schedule is switched on for both jobs once (`schedule_1700_applied`); if that happens after 17:00, that day is marked *skipped* so the first email is the next 17:00.

## Idempotency, retries, failures

* Scheduler retries / repeated calls: a run already `sent`/`skipped` is never repeated that day.
* A run that ends `failed` (any recipient failed, or an error) answers **HTTP 500**, so Cloud Scheduler retries it. A retry takes the run over atomically (compare-and-set) and sends **only to recipients whose delivery is not `sent`**. Up to 5 attempts per day; a run stuck in `running` for 15 minutes can be taken over.
* The two jobs are separate scheduler jobs and separate runs — one failing never blocks the other.
* Admin → Daily Emails → **Retry failed** does the same retry by hand. Failures are also written to the Cloud Run log as JSON (`daily_email_run`, `daily_email_delivery_failed`).
* **Test to me** sends one sample to the admin's own profile email only and never touches the scheduled run. **Send now** sends an extra copy to the real recipients.

## Google Cloud setup (required — not done from the repository)

Project `ymr-sms`, region `asia-south1`, Cloud Run services `ymrmarine` (production) and `ymrmarine-staging`.

```bash
PROJECT=ymr-sms
REGION=asia-south1

# 1. Shared secret for the scheduler (never put it in the repository)
openssl rand -hex 32 | tr -d '\n' | gcloud secrets create YSMS_CRON_TOKEN --project=$PROJECT --replication-policy=automatic --data-file=-

# 2. Let the Cloud Run runtime service account read it
RUN_SA=$(gcloud run services describe ymrmarine --project=$PROJECT --region=$REGION --format='value(spec.template.spec.serviceAccountName)')
gcloud secrets add-iam-policy-binding YSMS_CRON_TOKEN --project=$PROJECT \
  --member="serviceAccount:${RUN_SA}" --role=roles/secretmanager.secretAccessor

# 3. Expose it to the app (adds it, keeps the existing secrets)
gcloud run services update ymrmarine --project=$PROJECT --region=$REGION --update-secrets=YSMS_CRON_TOKEN=YSMS_CRON_TOKEN:latest
#    staging: the same for ymrmarine-staging, AND add ",YSMS_CRON_TOKEN=YSMS_CRON_TOKEN:latest" to the
#    --set-secrets line in cloudbuild.yaml — that line replaces the whole secret list on every deploy.

# 4. Cloud Scheduler — one job per email, 17:00 IST, retries on HTTP 500
URL=$(gcloud run services describe ymrmarine --project=$PROJECT --region=$REGION --format='value(status.url)')
TOKEN=$(gcloud secrets versions access latest --secret=YSMS_CRON_TOKEN --project=$PROJECT)
for JOB in surveyor_pending appointments; do
  gcloud scheduler jobs create http "ysms-daily-${JOB//_/-}" --project=$PROJECT --location=$REGION \
    --schedule="0 17 * * *" --time-zone="Asia/Kolkata" \
    --uri="${URL}/YSMS/ajax/daily_emails_run.php?job=${JOB}" --http-method=POST \
    --headers="X-Cron-Token=${TOKEN}" \
    --attempt-deadline=300s --max-retry-attempts=4 --min-backoff=120s --max-backoff=900s
done
```

If the Cloud Run URL is not reachable directly (ingress restricted to the load balancer), use `https://<your YSMS domain>/YSMS/ajax/daily_emails_run.php?job=...` instead. Cloud Run's request timeout (default 300 s) covers the 280 s the job allows itself.

Also required in production (already used by the existing assign/upload emails): `YSMS_SMTP_RELAY_USERNAME`, `YSMS_SMTP_RELAY_PASSWORD` (Workspace SMTP relay) and/or Gmail API domain-wide delegation for `ops@ymrmarine.com` (`YSMS_GMAIL_SERVICE_ACCOUNT_EMAIL`), and `YSMS_EMAIL_NOTIFICATIONS_ENABLED` not `false`.

## Verify after deploy

```bash
# Run the scheduler jobs once now (outside 17:00 they answer "not due": {"ran":{}})
gcloud scheduler jobs run ysms-daily-surveyor-pending --project=$PROJECT --location=$REGION
gcloud scheduler jobs run ysms-daily-appointments --project=$PROJECT --location=$REGION
gcloud logging read 'resource.type="cloud_run_revision" AND textPayload:"daily_email"' --project=$PROJECT --limit=20
```

1. Admin → Daily Emails shows *Scheduler connected* and a recent *Last scheduler call* for both jobs.
2. *Test to me* for both reports → check layout, sender `ops@ymrmarine.com`, Excel.
3. After the first 17:00: *Today's scheduled emails* shows both runs `sent` (`via scheduler`) and one delivery row per surveyor.
