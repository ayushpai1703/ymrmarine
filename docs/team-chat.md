# Team Chat — implementation notes

Private group chat ("YMR Team Group") inside YSMS. Built on the existing PHP + MySQL + Cloud Storage stack; no new services, buckets, IAM roles, secrets or Cloud Run settings.

## 1. Summary

| Area | What it does |
|---|---|
| Page | `team_chat.php` — **Team** › **Team Chat** in the sidebar / mobile drawer |
| Who can use it | Active **Admin**, **Super Admin**, **Surveyor** accounts that are members of the group. Clients never see it (page and API both refuse them). |
| Who manages members | **Admin** and **Super Admin** (Members button → add/remove). They can manage without being members, but cannot read messages unless they add themselves. |
| Messaging | Text (up to 4,000 chars, emoji supported), Enter to send / Shift+Enter for a new line, AJAX polling every 4 s (15 s when the tab is in the background), only messages newer than the last seen id are fetched, newest 30 on open + "load older" on scroll-up |
| Unread | Per-member `last_read_message_id`; red badge on the sidebar link, red dot on the mobile hamburger, "New messages" divider on open, "N new messages ↓" pill while scrolled up, `(N)` in the tab title |
| Files | PDF, DOC, DOCX, XLS, XLSX, JPG, JPEG, PNG, TXT, CSV; optional caption; upload progress bar; image previews; name / type / size / uploader / date shown; secure download |
| Duplicate sends | Button locked while sending + a per-message `client_token` (unique per sender) so a double-click, a retried request or a network replay returns the original message instead of creating another |
| Failures | "Connection problem — retrying…" banner with back-off, automatic recovery, session-expired and access-removed states, friendly error text for every rejected upload |

### Multiple groups

"YMR Team Group" is created automatically; **Admin / Super Admin** can create more groups with **New Group** (side list on desktop, **Groups** button on mobile). The creator is always a member; other staff can be ticked at creation time or added later from **Members**. Group names are 2–60 characters and unique (case-insensitive). Surveyors only see the groups they belong to; Admin / Super Admin see every group (ones they aren't in show as "Not a member" and stay unreadable until they add themselves). Each group shows its own unread count; the sidebar badge is the total. API: `GET action=groups`, `GET action=staff` (managers), `POST action=create_group` (managers, `name`, `member_ids[]`). No schema change — uses the existing `chat_groups` / `chat_group_members` tables.

## 2. Files

Created:
- `YSMS/team_chat.php` — page (markup, styles, vanilla JS)
- `YSMS/ajax/team_chat.php` — the only chat API endpoint
- `YSMS/includes/chat_helpers.php` — tables, permission checks, storage, upload validation
- `YSMS/database/migration_team_chat.sql` — optional manual migration
- `docs/team-chat.md` — this file

Modified:
- `YSMS/includes/nav.php` — adds the Team Chat link + unread badge (one indexed query, wrapped in try/catch so it can never break a page)

Nothing else is touched: no existing table, report, assignment, email, client workflow or deploy file changes.

## 3. Database

Four new tables (`chat_groups`, `chat_group_members`, `chat_messages`, `chat_attachments`), all utf8mb4. They are created automatically the first time anyone opens Team Chat (same "safety net" pattern the app already uses), or manually with `YSMS/database/migration_team_chat.sql`. Additive only.

- Foreign keys between the chat tables (`ON DELETE CASCADE`).
- User columns are indexed but **not** foreign keys to `users`, matching the app's other lazily-created tables (the `users.id` definition differs between the schema dumps in the repo, and a mismatched FK would stop the table being created).
- Indexes: `(group_id, id)` for polling/paging, `(sender_id, created_at)` and `(uploaded_by, created_at)` for rate limits, unique `(group_id, user_id)` for membership, unique `(sender_id, client_token)` for duplicate prevention.
- Files are **not** stored in MySQL — only metadata + the private object key.

## 4. Configuration and IAM

No IAM changes and no new secrets. Optional environment variables:

| Variable | Default | Purpose |
|---|---|---|
| `CHAT_MAX_UPLOAD_MB` | `20` | Per-file limit (capped at 90; PHP's own limit is 100 MB in `docker/uploads.ini`) |
| `CHAT_STORAGE_DIR` | `YSMS/uploads/chat_private` | Only needed if files should live somewhere else |

### Where files go
Files go to the existing private bucket `ymrmarine-ysms-uploads` (already mounted at `YSMS/uploads`), under `chat_private/<database name>/<yyyy>/<mm>/<random 32 hex>.bin`. Because **staging and production share this bucket**, the database name (`ysms_db` vs `ysms_db_staging`) keeps their files apart.

Files are never reachable by URL:
1. Every download goes through `ajax/team_chat.php?action=download`, which re-checks login, active status and *current* group membership on every request. There are no signed or public URLs, so a removed member's old links stop working immediately.
2. The object key is never sent to the browser.
3. Object names are random 128-bit values with a `.bin` extension, so they are unguessable and never executed or rendered.
4. A deny-all `.htaccess` (`Require all denied`) is written into `chat_private/` at runtime. It can't be shipped in the image because the bucket mount hides anything baked into `YSMS/uploads`.

**Staging check to do once:** confirm the bucket has **no** `allUsers` / `allAuthenticatedUsers` IAM binding and that *Public access prevention* is enforced:
```
gcloud storage buckets describe gs://ymrmarine-ysms-uploads --format="value(public_access_prevention)"
gcloud storage buckets get-iam-policy gs://ymrmarine-ysms-uploads
```
(I could not run this — the access token from earlier had expired.)

**Side finding (pre-existing, not changed):** the `.htaccess` files committed under `YSMS/uploads/bulk_tmp/` and `uploads/completed_remarks/` are hidden at runtime by the same bucket mount, so they probably are not protecting those folders in Cloud Run. Worth a separate look.

## 5. Staging setup and test

1. Deploy `staging` as usual (Cloud Build). Nothing else to configure.
2. Log in to staging as an **Admin** → sidebar **Team › Team Chat**. The group is created on this first visit.
3. Click **Members** → add the four people (include yourself if you want to read the chat).
4. As two different members (two browsers/phones): send text, Shift+Enter, emoji, a PDF, a photo, an Excel file; check the other screen updates within ~4 s, previews show, downloads open.
5. Remove one member → their open chat shows "You no longer have access" within a few seconds and their file links stop working.
6. Log in as a Client and a non-member Surveyor → no Team Chat link; opening `team_chat.php` directly redirects / shows the locked state.
7. If the page says "Chat is temporarily unavailable", check Cloud Run logs for `ensureTeamChatTables` — the most likely cause would be the DB user lacking `CREATE`/`REFERENCES` privileges (other safety-net tables are created the same way, so this is not expected).

## 6. Security review and test results

All tests below were **actually executed** in an isolated sandbox: local MariaDB 10.11 loaded with the repo's `database_cloudsql.sql` + migrations, PHP 8.3 built-in server with the production upload limits, real Chromium via Playwright. They were **not** run against Cloud SQL / Cloud Run (no access from the sandbox) — please do the staging pass in §5.

**API suite — 161/161 passed**, covering:
- Auth: anonymous → 401; Client → 403; inactive account can't log in; deactivating a member mid-session cuts access immediately.
- Authorization: non-members can't read / poll / send / list members / download; Surveyors can't list candidates or add/remove; Admin not in the group can manage but not read.
- CSRF: missing or wrong token rejected on every state-changing action; GET-only actions refuse POST.
- Tampering: a member of group 1 can't read, poll, post or mark-read group 2 by changing `group_id`; group 1 polls never include group 2 messages; unknown and unauthorized attachment ids both return the same 404.
- Membership: exactly four members configured; duplicate add → 409; can't add Clients, inactive or nonexistent users.
- Messaging: emoji round-trip; HTML stored as plain text; empty, over-long and malformed-token requests rejected; duplicate `client_token` returns the original (one DB row); rate limit (20 msgs/min) returns 429; poll throttle returns 429.
- Files: all 10 types accepted with correct metadata and byte-identical downloads. Rejected (each tested): executable renamed `.pdf`, `.php`, `.exe`, HTML renamed `.png`, PNG renamed `.pdf`, binary `.txt`, shell script `.csv`, oversized file, fake `.docx`, macro-enabled `.xlsx`, non-Office zip `.xlsx`, empty file, path-traversal filename. Rejected uploads leave no rows and no stored objects.
- Downloads: `attachment` disposition (images may be `inline` only when requested), server-chosen Content-Type, `nosniff`, `CSP: sandbox`, `no-store`. Storage keys never appear in any API response.
- Removal: removed member immediately loses poll, history, send, old attachment links and the nav link.
- Unread: badge count correct; own messages never unread; mark-read clamps to the latest id and never moves backwards; non-members and Clients get no link.
- History: paging returns every message exactly once; history survives logout/login.
- Concurrency: 3 users × 8 simultaneous sends → all 24 stored, returned once each, in order.
- Storage failure → friendly "file could not be saved", no orphan rows. Upload over PHP `post_max_size` → clean 413 message.
- Existing pages (`index`, `vessels`, `reports`, `completed`, `cancelled`, `profile`) load for Admin, Super Admin, Surveyor and Client.

**Browser suite — 26/26 passed** (desktop 1366×850 + mobile 390×844): history loads; Enter / Shift+Enter; message reaches the other user in ~3.8 s; `<img onerror>` payload shown as text and not executed; triple-submit creates one message; image preview + file chip; image loads for the other user through the authenticated endpoint; file card metadata; download keeps the original filename; client- and server-side upload errors shown; scroll-up loads older; new-message pill + tab-title count + clearing; non-member locked state; admin add/remove from the dialog; live cut-off of a removed member; DB outage shows the retry banner and recovers automatically; Notes widget reachable from the chat header; no JavaScript errors.

Bugs the browser tests caught and that are fixed: the floating Notes button covered the Send button, and the "Remove member?" confirmation opened behind the Members dialog.

Known limitations:
- Legacy `.doc`/`.xls` are checked by their OLE signature only (the same container format as other Office/OLE files); they are always served as downloads, never inline.
- If the database is completely down, `getDB()` (existing code) returns plain text; the chat treats that as a connection problem and retries.
- On phones the link lives in the hamburger drawer (the bottom bar is full); the hamburger gets a red dot when there are unread messages.

## 7. Expected incremental Google Cloud cost

Main driver: polling while the chat tab is open. Estimate for 4 people with the chat open ~8 h/day: ≈ 0.9 M small requests/month (≈ 30–60 ms each).

| Service | Effect | Estimate |
|---|---|---|
| Cloud Run | Request-based billing (the default; `cloudbuild.yaml` sets no `--no-cpu-throttling` / `--min-instances`), so only the milliseconds per poll are billed. The free tier (2 M requests, 180k vCPU-s) likely absorbs it. | **$0 – 3 / month** |
| Cloud SQL | ~5 indexed lookups per poll; no tier change needed on `db-custom-1-3840` | **$0** (no resize) |
| Cloud Storage | Shared files, e.g. 1–5 GB/month | **< $0.20 / month** |
| Network egress | Downloads to users, e.g. 5 GB/month | **≈ $0.60 / month** |

**Total ≈ $0–5 / month.** The thing to watch: if Cloud Run is ever switched to *CPU always allocated* or given `min-instances ≥ 1`, polling would keep an instance billed continuously — revisit polling (or longer intervals) before making that change.

## 8. Deploy and rollback

Deploy: push to `staging` → Cloud Build deploys staging → run §5 → port to `main` only after your approval.

Rollback options (least to most invasive):
1. **Hide it:** revert the `includes/nav.php` change. The link disappears; the data stays.
2. **Remove the code:** `git revert <commit>` and redeploy. The tables and files remain, unused, and do no harm.
3. **Delete the data (destructive, deliberate only):** `DROP TABLE chat_attachments, chat_messages, chat_group_members, chat_groups;` and delete `gs://ymrmarine-ysms-uploads/chat_private/<db name>/`.
