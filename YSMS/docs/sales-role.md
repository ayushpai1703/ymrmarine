# Sales role (Phase 1)

A login for the sales person. Decided with the user:
- there is one sales person, who sees every client;
- no invoice data of any kind is shown;
- surveys are only requested (the Admin assigns the surveyor);
- there are monthly targets.

## Admin — Sales Team (`admin_sales.php`, sidebar → Sales Team)
- **Sales persons.** Add a sales person (name, username, email, phone, password). Each one has
  **Password** and **Deactivate** / **Activate** buttons. A deactivated person cannot log in.
- **Monthly targets** per sales person:
  - **Surveys**: the surveys assigned in the month, for all clients, not counting cancelled ones.
  - **New clients**: the clients added in the month.

  Leave a box empty for no target.
- **History** for the last 6 months, per month: surveys achieved / target, new clients
  achieved / target, completed surveys, and the requests the person made.

## Sales login
The Sales sidebar has: Dashboard, Clients, Surveys, Request Survey, My Profile.

- **Dashboard** (`sales_dashboard.php`):
  - three highlighted cards: **Total Clients**, **Total Vessels Completed**,
    **Total Recovery** (MT), each with the chosen month's figure;
  - the month's targets, with progress bars;
  - **Month-wise**: vessels completed and recovery for the 12 months ending with the chosen
    month, with the 12-month total and the all-time total;
  - **Client-wise**: vessels and recovery of every client, for the month and in total;
  - **Country-wise**: vessels, for the month and in total;
  - the person's latest survey requests with their status.

  The figures use the Admin dashboard's rules (`salesCompletionStats`):
  - a vessel counts once it has left Pending Vessels (Pending Report / Completed), in the month
    of its own Survey Completed Date;
  - NOT APPLICABLE survey types count no recovery;
  - the country is the port's country (India when empty).

  Pending Vessels / Pending Reports are not shown.
- **Clients** (`sales_clients.php`):
  - every client, with contact, phone, email, total surveys, surveys this month and the last
    survey date;
  - **Add** and **Edit** are available. There is no delete and no client logins.
  - The same company name or short code cannot be used twice.
- **Surveys** (`sales_surveys.php`, view only):
  - every survey with vessel, report number, client, port, survey type, status, and the
    assigned / appointment / completed dates;
  - status tabs and filters for client, month and search;
  - no recovery, invoice or report files, and no vessel pages.
- **Request Survey** (`assign_vessel.php`):
  - the same form the clients use, with every client in the list;
  - there is no surveyor field. The survey is saved as **Pending Vessel** with "Requested by
    Sales (name) — awaiting surveyor assignment".
  - The admins get an in-app notification and an email to assign the surveyor.

## Access (deny by default)
`config/config.php` checks every request of a Sales user against `salesAllowedPaths()` in
`includes/sales.php`:
- any other page (Pending Vessels, Reports, Completed, Invoices, Invoice Airtable, Recovery,
  vessel pages, admin pages, Survey Reports, Team Chat, exports) sends them to the Sales
  dashboard;
- any other endpoint answers 403.

The Android app's native screens are not for this role; the website works on a phone.

## Database
- A new role row `Sales`.
- New tables `sales_targets` and `sales_requests`.
- Two nullable columns `clients.created_at` / `created_by`. Existing clients keep NULL: their
  date is unknown, so they do not count as new.

All of this is created on first use. The SQL is in `database/migration_sales_role.sql`, and
the reverse in `_down.sql`.

## Test (local stack)
- `sales_test.js` 30/30 (dashboard figures equal the Admin dashboard: month vessels, month recovery, Total Recovery):
  - Admin adds the person and sets targets;
  - Sales lands on its dashboard;
  - every other page and endpoint is refused;
  - client add / edit / duplicate refused;
  - surveys are view only, with counts matching the database;
  - Request Survey saves with no surveyor and is logged;
  - dashboard counts, Admin history, deactivate.
- `roles_regress.js` 5/5: Admin / Client / Surveyor pages are unchanged.
