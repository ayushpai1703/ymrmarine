# Vessels Data (vessels_data.php)

One Airtable-style, editable grid with every vessel record from Pending Vessels, Pending Reports,
Completed and Cancelled. It is an **additional** module: the existing pages, their AJAX and their
exports are not changed, and they read the same `surveys` rows. A change made in Vessels Data shows
on those pages straight away, and the other way round.

The sidebar link is **Vessels Data**, placed right after Pending Reports, with a TESTING badge. It
shows on desktop and in the phone drawer; the phone bottom bar keeps its five slots.

## What it is built on (analysis)

- **Records.** `surveys` holds one row per vessel, and that row is also its survey/report.
  - `status` is one of `Pending Vessel`, `Pending Report`, `Completed`, `Cancelled`.
  - The row links to `clients`, `ports`, `survey_types` (several types in `survey_type_ids`) and
    `users` (the primary surveyor in `surveyor_id`; co-surveyors in `survey_surveyors`; user 1 means
    Outsourcing / Unassigned).
  - No vessel table is copied and no new record is created.
- **Access.** This follows the rules the existing lists and the Admin inline edit already use.
  - **Admin** sees and edits everything, changes status, runs bulk changes, imports and exports.
  - **Super Admin** sees everything and can export. Editing on the detail pages is Admin-only today,
    so Super Admin has no edits here either.
  - **Surveyor** sees only the vessels they are assigned to (primary or co-surveyor), read-only, and
    can export them.
  - **Client** has no access.
  - The rules are checked on the server for every request. CSRF tokens are checked as everywhere in
    YSMS (`csrf_require` in config.php).
- **Field rules.** These are the same rules the pencil edits on the detail pages use
  (`includes/admin_inline_edit.php`):
  - Report Number must be unique.
  - IMO Number must be 7 digits.
  - Vessel Name and Agent are required.
  - A Pending Report needs a Survey Completed Date.
  - A Completed record needs a Report Uploaded Date that is not before the Survey Completed Date.
  - Picking a date keeps the stored time of day.
  - If the survey type is changed to a "recovery not applicable" type, the recovery figures are
    cleared.
- **Recovery** is never typed here. It comes from the existing recovery engine (Calculation Sheet /
  detail page) and is shown with the Recovery page's rules:
  - Totals use `recoveryCountedSql`: Pending Report + Completed, NOT APPLICABLE types count as 0,
    and values are always positive.
  - Onhire values are shown with a minus sign.
  - The Total Recovery card equals the Recovery page total for the same rows.
- **Due / Overdue days** use the same start dates and limits as the overdue alert on the detail
  pages (`includes/overdue_alert.php`):
  - A Pending Vessel is overdue 7 days after its appointment date.
  - A Pending Report is overdue 4 days after its survey completed date.
  - Due Days shows the days left. Overdue Days shows the days since the start date once the limit is
    reached.
  - The old stored `due_days` / `overdue_days` columns are not maintained by the app, so they are
    not used.

## Features

- **Summary cards.** Total, Pending Vessels, Pending Reports, Completed and Cancelled are counted
  over the current filters, leaving out the status filter, so they show the breakdown. Clicking a
  card filters by that status. Total Recovery (with VLSFO / LSMGO) is counted over all the filters.
- **Grid.**
  - Search runs on the server and is debounced. It covers vessel, IMO, report number, client,
    agent, survey type, port, surveyor (incl. co-surveyors), remarks and notes.
  - Quick filters: Status, Client, Surveyor, and an Assigned date range.
  - The Filter panel adds vessel, report number, IMO, agent, survey type, port, the Survey Completed
    date range and a Total Recovery range. All filters apply together, and Clear filters resets them.
  - Sort on any column, up to 3 levels (Shift+click a header). The default is latest Assigned Date
    first.
  - Pages of 25 / 50 / 100 / 250 / All (All shows up to 5,000 rows).
  - Columns can be shown or hidden, reordered by dragging, and resized. The layout is saved per user
    in the existing `list_grid_prefs` table.
  - Sticky header, sticky number and Vessel Name columns, and horizontal scrolling.
  - Row selection with Select all.
- **Optional columns** (hidden by default; turn them on under Columns): Appointment Date,
  HSFO Recovery, Live Status, Status Updated (when / by whom) and Agent Email Sent.
- **Editing (Admin).**
  - Click a cell to edit it. Enter or Tab saves, Esc cancels, and leaving the cell saves.
  - Cell types:
    - text: Vessel Name, IMO, Report Number, Agent
    - dropdown: Client, Port
    - multi-select: Survey Type, Surveyor (incl. Outsourcing / Unassigned)
    - date: Survey Completed, Report Uploaded, Appointment
    - date and time: Assigned
    - long text (Ctrl+Enter saves): Remarks, Notes
  - While saving, the cell shows a spinner and the save cannot be sent twice. A saved cell flashes
    green, a refused one red, and a toast shows the message.
  - Each save sends the value the grid showed. If someone changed the record in the meantime, the
    save is refused and the latest value is shown, so nobody's change is overwritten.
  - The browser warns before leaving the page while a save is still running.
- **Status (Admin).** Change the status from the dropdown in a cell, or for many vessels at once
  with the bulk bar.
  - A confirmation dialog explains what the change does.
  - Moving a vessel to Pending Report or Completed asks for any date the record is missing (Survey
    Completed / Report Uploaded), as the upload workflow would have set them.
  - The change records who made it and when (`status_updated_by`, `status_updated_at`) and notifies
    the assigned surveyors, as Admin Controls → Cancel does.
  - Bulk changes are all-or-nothing in one transaction, for up to 500 vessels.
  - A status change does not upload report files; use the detail page for those.
- **Record panel.** Open it from the expand button in the Vessel cell or from History. It shows all
  fields, recovery, remarks and notes, and the change history.
  - Links to the existing pages: View details, Edit record & files (the same detail page, which has
    the pencil edits and the file actions), Report generator (Pending Report) and View recovery.
- **Export to Excel.** Export the list (search + filters), the selected vessels, all vessels, or an
  import template. Every field is included, Record ID first.
- **Import (Admin).**
  - Upload an exported (or template) sheet. Rows are matched by Record ID.
  - Import updates existing records only. It never creates a vessel (new ones still come from
    Assign Vessel / Bulk Assign), so it cannot make duplicates.
  - Column names are checked, and every cell is validated with the same rules as the grid.
  - Blank cells mean "no change". Status, recovery and the other read-only columns are ignored.
  - A preview lists every change (old → new) and every failed row with the reason. Nothing is saved
    until the Admin confirms.
  - The confirmed import runs in one transaction. If any record changed after the preview, nothing
    is imported.
- **Change history.** Every edit, status change and import is recorded in `vessel_change_history`:
  survey, user, field, old and new value (as readable names), source (grid / bulk / import) and
  time. Rows are only ever added.

## Database

| Change | Notes |
|---|---|
| `vessel_change_history` (new table) | `database/migration_vessel_change_history.sql`. It is also created at runtime (`vdHistoryTable()`), before any transaction. Reverse with `migration_vessel_change_history_down.sql`. |
| `list_grid_prefs` (existing) | Layout rows with `page = 'vessels_data'`. |

No existing table, column, status value or index is changed. No index was added. The grid filters
and sorts with the existing keys (client, surveyor, type, port, IMO, report number) and pages on the
server. If the table grows far beyond ~10,000 rows and the status filter gets slow, an index on
`surveys(status)` would be the first one to consider.

## Installation

1. Deploy the files below (staging first). There is no build step; PhpSpreadsheet is already in
   `composer.json`.
2. Optional: run `database/migration_vessel_change_history.sql`. The table is also created the first
   time someone saves in Vessels Data.
3. Open **Vessels Data** in the sidebar as an Admin.

To remove the module: delete the new files and the nav link, then run the `_down.sql`.

## Files

New:
- `vessels_data.php` — the page
- `ajax/vessels_data.php` — list, row, history, update, status, bulk_status, prefs, import_preview,
  import_apply
- `export_vessels_data.php` — Excel export and import template
- `includes/vessels_data.php` — access, filters, sorts, rows, validation, saves, status, history
- `includes/vessels_data_import.php`
- `assets/js/vessels-data.js`
- `assets/css/vessels-data.css`
- `database/migration_vessel_change_history.sql`, `database/migration_vessel_change_history_down.sql`
- `docs/vessels-data.md`

Changed:
- `includes/nav.php` — the Vessels Data link only.

The grid reuses `assets/css/invoice-airtable.css` unchanged.

## Testing report (local test stack: PHP 8.3, MariaDB copy of the schema)

The E2E suite (Playwright) has 65 checks, and every one is checked against the database. All 65
pass on the test server that has the complete PhpSpreadsheet. On the test server that uses the
repo's partial local `vendor/`, 62 pass: the three Excel checks fail there because of a version
mismatch that only exists in that local `vendor/`. The other exports (Cancelled, Invoices) fail on
that server for the same reason. Production installs PhpSpreadsheet with composer.

| # | Requirement | Result |
|---|---|---|
| 1–4 | All Pending Vessel / Pending Report / Completed / Cancelled records appear (counts = `surveys`) | pass |
| 5 | No duplicates | pass |
| 6 | Counts match existing modules (Cancelled list); Total Recovery = Recovery page | pass |
| 7 | Inline editing: text, IMO validation, duplicate report number refused, dropdown, multi-select, long text, date (time kept), date-time, Esc cancels, required date kept, concurrent change not overwritten | pass |
| 8 | Status change (asks for the missing date) → shows in Pending Reports, gone from Pending Vessels; bulk → shows in Cancelled; bulk refused as a whole when dates are missing | pass |
| 9 | Row recovery = stored recovery | pass |
| 10 | Excel export: all, filtered, selected | pass on full PhpSpreadsheet |
| 11 | Filters together (status + client + completed date), recovery range, quick status filter + cards | pass |
| 12 | Server-side pagination (25 / page 2), sorting | pass |
| 13 | Admin permissions | pass |
| 14 | Surveyor: only assigned, read-only, 403 on save / status / bulk, no access to other vessels or their history; Super Admin view-only; Client no access | pass |
| 15 | Change history recorded (field, old, new, user, source) and shown in the panel | pass |
| — | Import: preview lists changes and failed rows (unknown ID, empty ID, duplicate ID, bad date, ignored columns), nothing saved before confirmation, applied after; never creates records | pass |
| — | CSRF (POST without token → 403), unknown field refused, text escaped (no XSS) | pass |
| — | Phone layout fits the width; no JavaScript errors | pass |
| 16–20 | Existing pages load without errors: Pending Vessels, Pending Reports, Completed, Cancelled, Recovery, Invoices, Invoice Airtable, Dashboard, report / completed detail | pass |

Regression suites for existing modules:

| Suite | Result |
|---|---|
| Cancelled grid | 20/20 |
| Mobile cards | 29/29 |
| Dashboard | 17/17 |
| Completed cards | 7/7 |
| Invoice generator | 16/16 |
| Report table | 74/74 |
| Back-fill | 8/8 |
| Invoices | 67 checks passed, then stopped at its Excel step (local `vendor/` issue) |
| Invoice Airtable | the suite stopped at a recovery-PDF fixture that was removed from the local `uploads/` during clean-up; the checks before it passed |
