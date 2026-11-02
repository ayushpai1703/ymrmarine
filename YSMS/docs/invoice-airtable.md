# Invoice Airtable (invoices_airtable.php)

An Airtable-style grid for tracking invoice payments. Only Admins can open it. The sidebar link is
**Invoice Airtable**, placed right after Invoices. `invoice_table.php` redirects here.

## What it is built on (inspection)

- **Invoices.** The `invoices` table of the Invoices module already holds one row per vessel, with
  these fields:
  - `invoice_no`, `invoice_date`, `invoice_amount` (DECIMAL 14,2)
  - the stored PDF (`file_path`). The PDF is either uploaded through Invoices → Upload, or made by
    Generate Invoice → Download.
  - the recovery invoice PDF (`rec_file_path`).
- **Files.** PDFs live in `uploads/invoices/`, which is the Cloud Storage bucket mount. The folder
  has a deny-all `.htaccess`, so files are served only through `ajax/invoices.php?a=file` (Admin).
- **No payment data existed before**, so payments, due dates and the activity log have new tables.
- **Grid rows come straight from `invoices`.** Each row is one invoice document: the vessel invoice,
  or its recovery invoice (number + `B`). A document shows up as soon as its PDF is stored. Nothing
  is entered twice, and no invoice record is copied.

## Database (backward compatible: `database/migration_invoice_ledger.sql`, also created at runtime)

| Table / column | Purpose |
|---|---|
| `invoices.rec_invoice_date`, `invoices.rec_invoice_amount` (DECIMAL) | The recovery invoice's own date and total. Set when the recovery invoice is downloaded; filled from the saved form for older ones. |
| `invoice_accounts` (`invoice_id`, `kind`) | Due date, remarks and the cancelled flag for each document. |
| `invoice_payments` | Every payment transaction (DECIMAL amount). Never overwritten; a wrong entry is voided with a reason. `request_id` is unique (stops double saves). `(invoice_id, kind, transaction_id)` is unique (stops duplicate transactions). |
| `invoice_activity` | Log of edits, payments, voids, cancels and restores. |
| `invoice_grid_prefs` | Each user's column order, hidden columns, widths, sort and page size. |

## Calculations (all done in SQL, on the server)

- **Received** = sum of payments whose status is `success`.
- **Outstanding** = invoice amount − received.
- **Status**, checked in this priority order:
  1. Cancelled
  2. Paid (amount > 0 and outstanding ≤ 0)
  3. Overdue (due date is in the past and a balance is left)
  4. Due Soon (due date within the next 7 days and a balance is left)
  5. Partially Paid
  6. Unpaid
- **Summary cards** cover the current search and filters. Cancelled invoices are left out of every
  money total.
- **Overdue Amount** = sum of the outstanding balance on Overdue invoices.
- **Due Soon** = number of Due Soon invoices.
- **Payments:** a payment over the outstanding balance is refused unless the Admin ticks the
  overpayment box. Each payment locks the invoice's account row (`SELECT … FOR UPDATE`) and checks
  the balance again before saving.

## Files

| File | Role |
|---|---|
| `invoices_airtable.php` | The page |
| `assets/css/invoice-airtable.css`, `assets/js/invoice-airtable.js` | Styling and grid behaviour |
| `ajax/invoice_ledger.php` | Actions: list, detail, receipt, update, add_payment, void_payment, cancel, prefs |
| `export_invoice_ledger.php` | Excel exports: all, filtered, selected, payment history |
| `includes/invoice_ledger.php` | Helpers: tables, the grid query, filters, sort, summary |

Payments never touch survey or vessel status, or the Invoices-module status.

## Test report (local test database, Playwright; 48/48 passed)

1. Generated, recovery and uploaded invoices appear automatically (an upload through Invoices →
   Upload shows up with no second entry). No duplicate records are made.
2. The summary matches the database sums. The summary follows the filters. Cancelled invoices are
   excluded.
3. A payment added from the side panel updates received, outstanding, status, last payment date,
   the grid row and the summary, without a page reload.
4. Multiple payments are kept as separate history rows.
5. Outstanding = amount − received. The cell is green when 0, orange when partly paid, red when
   overdue.
6. An invoice becomes Paid once nothing is outstanding.
7. Partially Paid status is correct.
8. Overdue status and the Overdue Amount are correct. The due-date cell shows "Nd overdue".
9. Due Soon (within 7 days) status and count are correct.
10. Filters, search and sort work:
    - quick filters
    - Outstanding ≥ filter
    - search by payment reference
    - sort by amount, ascending and descending
    - multi-level sort with Shift+click
11. The invoice PDF opens through the secure handler.
12. Receipt upload works. Files are checked on the server (PDF/JPG/PNG), and a fake file is refused.
13. Void, overpayment refusal, server validation (future date, 3-decimal amounts), duplicate
    request and duplicate transaction ID, and concurrent payments (one saved, one refused) all
    behave as expected.
14. Surveyor, Super Admin and Client get 403 on the page, the data, edits and exports, and do not
    see the menu item. A write without the CSRF token is refused.
15. Survey rows and Invoices-module statuses are unchanged by payments.

Also checked:
- Hiding, reordering (drag) and resizing columns is saved for each user.
- All four exports contain the database values.
- On a phone, the page does not scroll sideways.
- No JavaScript errors.
- The existing Invoices tests still pass (78/78).
