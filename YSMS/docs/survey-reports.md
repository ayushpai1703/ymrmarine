# Survey Reports and Report Templates

Surveyors enter the bunker survey on the website. The report is made in the company's own Excel
format (the uploaded template, e.g. *MV. PROTECTOR - OFFHIRE - FIELD REPORT.xlsx*) and printed to
PDF from that workbook.

- **Survey Reports** (`survey_reports.php`, sidebar) — Admin, Super Admin (view only), Surveyor
  (only the vessels assigned to them). Making a report never changes the vessel's status or files.
  The report is one page, in the SHIP DETAILS order:
  - **IMO + Vessel lookup** on top (see below);
  - Vessel Name, Flag, Port of Registry, GRT, NRT, Voyage No., Owners, Charterers Name;
  - **FWE** (date, time, ROB VLSFO / HSFO / LSMGO);
  - **Survey Started** (date, time, ROB);
  - **Survey Completed** (date, time);
  - Place of Survey, Drafts, Master, Chief Engineer, Surveyor, List, Holds, Builder, Keel laid,
    Manager/Operator, Callsign, LOA, Breadth, Depth, Summer Draft, Summer Deadweight, Class,
    Alongside;
  - then the **Tanks**, and at the bottom the totals and **Make Excel + PDF**.

  Nothing else is asked:
  - the Offhire redelivery texts and the remarks keep the template's own wording;
  - the RESULTS block and the REPORT sheet's typed values are left empty.

  Enter goes to the next box. Everything saves by itself.

  **Vessel lookup.** Type the IMO number and press **Vessel lookup**. The server
  (`srVesselLookup`, `ajax/survey_reports.php?a=lookup`) asks Gemini with Google Search
  grounding for the particulars of that IMO: name, flag, port of registry, GRT, NRT, owners,
  manager/operator, builder, keel laid month/year, call sign, LOA, breadth, depth, summer draft,
  summer deadweight, class, holds.
  - The prompt allows only values a web source states for that IMO, otherwise null (no guessing).
    The reply must name the same IMO, and every value is checked against the field's type
    (numbers, month names) before it is used.
  - The filled boxes are highlighted, the web sources are listed, and **Undo** puts the earlier
    values back. Values are saved like typed ones; check them against the ship's documents.
  - The API key stays on the server (`YSMS_GEMINI_API_KEY`, sent as a header). One lookup per
    5 seconds per user. Needs internet (the button is off when offline).

- **Report Templates** (`admin_report_templates.php`, Admin) — upload, versions, checks, cell
  mapping, PDF sheets and test generation.

## The template (inspection of the MV. PROTECTOR workbook)

| Sheet | What it holds | How the website fills it |
|---|---|---|
| SHIP DETAILS | Vessel / voyage / parties / FWE and survey times / BROB / drafts / crew / particulars (labels in column B, values in C–D, BROB in G–I); Offhire redelivery place, date, time in F4:G6 | Every value cell, found by its label. The two arrow shapes are kept. |
| TABLES 54B | The report: header from SHIP DETAILS, three tank sections (VLSFO rows 23–34, HSFO 41–44, LSMGO 50–54), totals, remarks, signatures; RESULTS block (M17:Q21, outside the print area); 54B constants in V21:V22 | Tank rows: name, ref. height, sounding, volume, density, temperature. More rows are inserted inside the section when needed. Remarks paragraph and the typed RESULTS values are form fields. |
| OFFHIRE | Certificate of Off-hire Bunker Quantity (logo, wording, signatures), all by formulas from SHIP DETAILS / TABLES 54B | Not typed into; its formulas follow the inserted rows. The REMARK text is a form field. |
| STAMP | Empty (one merged range, no image) | Kept as it is; not printed. |
| INTERP | Trim correction, sounding interpolation, bunker mixture density, density transfer 20→15 °C (worked examples with typed numbers) | Kept unchanged (a calculator sheet; not linked to the report). |
| REPORT | KEY / value list (B column) read by other documents; values are formulas into SHIP DETAILS / TABLES 54B, a few are typed | Typed keys (REPORT_DATE, REDELIVERY_*, CONS_*, HOLDS …) are form fields (collapsed section). |

**Tank calculation (TABLES 54B, per row).** The formulas were reproduced independently. The
results match Excel's own values for all 15 tanks (45 values) and the totals (VLSFO 1029.798 MT,
LSMGO 130.042 MT).

- ρ = ROUND(F/2·1000, 0)·2 (U)
- α = ROUND(K0/ρ² + K1/ρ, 8) (V), with K0 = 186.9696 and K1 = 0.4862 (V21, V22)
- V.C.F. = ROUND(EXP(−α·Δt·(1+0.8·α·Δt)), 4) (H), with Δt = T − 15
- G.S.V. = ROUND(Volume·V.C.F., 3) (I)
- WCF density = F − 0.0011 (X)
- Metric tons = ROUND(G.S.V.·(F − 0.0011), 3) (Y → J)

The generated workbook keeps these formulas. `assets/js/report-calc.js` repeats them for the
on-screen figures and the offline file. It does so only when the uploaded template has exactly
this pattern; constants and decimals are read from the template.

**Checks shown for review** (Report Templates → Details; nothing is "fixed" silently):

| Level | Where | Finding | What the system does |
|---|---|---|---|
| Error | OFFHIRE!K44 | `=CONCATENATE("Mr. ",'SHIP DETAILS'!#REF!,"")` — broken reference (in the hidden columns K:L) | Kept; Excel shows #REF! there. Fix it in the template. |
| Review | TABLES 54B!I45, J46 | HSFO section has no total formulas, so the certificate's HSFO quantity was empty | **Approved fix**: every section's totals are written as `=SUM(...)` over exactly its tank rows (VLSFO/LSMGO get the same, equivalent formula). |
| Review | TABLES 54B!N18, N19 | RESULTS: VLSFO "Actual" / "Log book" are typed numbers (802.764 / 738.799 — not this survey's 1029.798) | Kept as form fields (outside the print area). |
| Review | TABLES 54B!O18 | RESULTS: HSFO "Actual" = SHIP DETAILS!H11 (BROB at FWE), not the HSFO total | Kept (outside the print area). |
| Review | TABLES 54B!P21 | Total difference `=SUM(N20+P20)` leaves out HSFO | Kept (outside the print area). |
| Review | REPORT!B10 | HOLDS = 5 while SHIP DETAILS "No. Of Holds" = 7 | Kept; HOLDS is a form field. |
| Info | V21:V22 | One pair of 54B constants (fuel-oil band) for all sections. Gas oil below 838.5 kg/m³ is in the ASTM jet/kerosene band (K0 594.5418, K1 0). For MDO STORAGE 1 (824.8 kg/m³, 30 °C) that would be 0.018 MT. | Methodology kept as in the template. |
| Info | Names SSLink_0..2 | Defined names pointing at #REF! (old links, unused) | Kept. |
| Info | OFFHIRE, STAMP | No paper size on OFFHIRE; STAMP sheet empty | PDF prints on A4; STAMP not printed. |

## Excel generation (server, PhpSpreadsheet) — `includes/report_templates.php`

The workbook is a copy of the uploaded template version; nothing else is rebuilt.

- Values are written into their cells. Dates are written in the template's own style
  ("1st OCTOBER 2026") and times as Excel times.
- Tank rows go into their section. When a section needs more rows than the template has, rows
  are inserted inside it and copy the row formatting.
- The template's formulas are written on every tank row, and the totals are `SUM` over the
  section.
- Every reference to TABLES 54B moves with the inserted rows (certificate, REPORT, print area).
- Calculated values are stored in the file, and Excel recalculates when it opens.
- Calculation settings, page setup, and "no paper size" where the template has none are kept as
  in the template.

Each "Make Excel + PDF" creates new files; earlier ones are kept.

## PDF — LibreOffice (`rtPdf`)

The filled workbook is printed exactly as laid out:

- Only the report sheets are printed: TABLES 54B and OFFHIRE by default; the Admin can change
  this.
- The template's print areas, scaling, margins, logo and fonts are used. Liberation fonts stand
  in for Arial / Times.
- Pages are A4. Hidden columns are not printed, as in Excel.
- The result is 2 pages: Bunker Survey Report, then the Certificate of Off-hire Bunker Quantity.

The Dockerfile installs `libreoffice-calc-nogui`. Without LibreOffice the Excel is still made and
the page says that the PDF is not available.

## Template management

- **Upload.** Every upload is a new version. The file is stored byte for byte as
  `uploads/report_templates/<kind>-v<n>-<sha>.xlsx` and made read-only. It is never overwritten,
  and the same file cannot be uploaded twice.
- **Versions.** The first version is active. Later versions are activated by the Admin. A report
  keeps the version it was made with; "use latest" is available through the API.
- **Mapping.** The detected cell of every field is shown. To override it, type `SHEET!C12`;
  `-` leaves the field out. A cell that holds a formula is refused.
- **Test generation.** The version is filled with the data already in the workbook. The test
  makes the Excel and the PDF and compares every tank result and total with the values Excel had
  calculated. A preview of the result is shown.

## Offline

Open the report once while online. The page, its scripts (ExcelJS is vendored in
`assets/vendor/exceljs`, MIT), its styles and the template workbook are then kept by
`survey_report_sw.js`. The service worker's scope is the Survey Reports pages only.

- **Offline entry and calculation.** Edits are stored on the device (localStorage) and sent when
  the connection is back. If someone saved the report meanwhile, you are asked which version to
  keep.
- **Excel on this device** (`assets/js/report-offline-xlsx.js`). It is built from the same
  template with the same layout data and gives the same values and formulas as the server file.
- **What ExcelJS does not keep in the offline file:**
  - the arrow shapes on SHIP DETAILS;
  - printer-driver settings;
  - the three #REF! names;
  - half-point font sizes (9.5 becomes 9 in three header cells).

  Make the final report on the server when online. PDF needs the server.

## Installation

1. Deploy (the Docker image now includes LibreOffice). Tables are created on first use; the SQL
   is in `database/migration_survey_reports.sql`, reverse in `_down.sql`.
2. As Admin, open **Report Templates** and upload the Offhire workbook. Read the checks, run
   **Generate test report**, and keep the version active.
3. Surveyors / Admin: **Survey Reports → New report**, choose the vessel, then fill in the form.
   Tanks can be pasted from Excel. Click **Make Excel + PDF**.

## Acceptance test (local test stack, MV. PROTECTOR workbook)

`sr_test.js`: 41/41 checks pass (including the one-page layout and Vessel lookup with a mock
Gemini: unknown IMO fills nothing, known IMO fills all 18 particulars = the workbook's values,
sources shown, Undo, highlight cleared on edit, button off offline).

1. All six sheets exist, in the same order.
2. Vessel information is populated exactly. The only differences are two trailing spaces in
   template cells.
3. All 15 tanks are populated exactly (entered by paste).
4. Every numeric cell matches an independent recalculation (LibreOffice). The tank results match
   Excel's values in the uploaded file.
5. Fuel totals are correct: VLSFO 1029.798, HSFO 0, LSMGO 130.042 MT.
6. The Offhire certificate is populated (quantities, vessel / flag / registry text, signatories).
7. Formatting and print layout are identical: styles, merges, print area, images, page setup,
   column widths.
8. The PDF is A4, 2 pages, with the real layout.
9. Offline: the report opens, the edit is kept on the device, and the offline Excel equals the
   server Excel except for the edit. Back online, the edit is saved.
10. The original workbook is unchanged: the source file and the stored version are byte for
    byte identical.

Also checked:
- the duplicate upload is refused;
- a mapping onto a formula cell is refused;
- access: unassigned surveyor 404, Super Admin view only, Client no access, non-Admin no
  templates page;
- the vessel status is unchanged;
- there are no JavaScript errors.

The same engine was also run with extra rows (VLSFO 15 tanks, HSFO 2 tanks):
- rows are inserted;
- the certificate / REPORT / print area references move (J36→J39, J46→J49, J56→J59, print area
  to row 159);
- the HSFO total is 144.605 MT, and every cell matches the LibreOffice recalculation.

## Files

New:
- `admin_report_templates.php`
- `survey_reports.php`
- `survey_report.php`
- `survey_report_sw.js`
- `ajax/report_templates.php`
- `ajax/survey_reports.php`
- `includes/report_templates.php`
- `includes/survey_reports.php`
- `assets/js/report-calc.js`
- `assets/js/survey-report.js`
- `assets/js/report-offline-xlsx.js`
- `assets/css/survey-report.css`
- `assets/vendor/exceljs/` (ExcelJS 4.4.0 + LICENSE)
- `database/migration_survey_reports.sql`
- `database/migration_survey_reports_down.sql`
- this file

Changed:
- `includes/nav.php` — Survey Reports, Report Templates links.
- `includes/gemini_json.php` — optional Google Search grounding (`['search' => true]`) for the
  Vessel lookup; existing callers unchanged.
- `Dockerfile` — LibreOffice.

The existing Survey Form (`survey_form.php`) and its formats are not changed.
