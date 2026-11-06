# Survey Reports and Report Templates

Surveyors enter the bunker survey on the website. The report is made in the company's own Excel
format (the uploaded template, e.g. *MV. PROTECTOR - OFFHIRE - FIELD REPORT.xlsx*) and printed to
PDF from that workbook.

- **Survey Reports** (`survey_reports.php`, sidebar) — Admin, Super Admin (view only), Surveyor
  (only the vessels assigned to them). Making a report never changes the vessel's status or files.
  The report is a step-by-step wizard (`assets/js/survey-report.js`). A progress bar shows the
  current step in blue and completed steps in green with a check. A step is opened from the bar
  when every step before it is complete, or when it was opened before. Back / Next stay at the
  bottom: sticky on desktop, fixed above the app's bottom bar on phones. Everything saves by
  itself, the current step included, so a refresh or reopening the draft comes back to the same
  place.

  1. **Vessel Particulars.**
     - At first only the **IMO Number** and **Lookup** (button or Enter) are shown.
     - After the lookup the 18 particulars appear below: Vessel Name, Flag, Port of Registry,
       GRT, NRT, Owner Name, Number of Holds, Builder, Keel Laid Month / Year,
       Manager / Operator, Callsign, LOA, Breadth, Depth, Summer Draft, Summer Deadweight, Class.
     - Particulars that were not found are red, and a message names them. Every value can be
       typed or corrected.
     - A failed lookup only says so and opens manual entry. "or enter the particulars by hand"
       does the same.
     - Next is enabled only when all 18 particulars and the IMO number are valid.
  2. **Survey Particulars.**
     - **Fuel grades on board**: VLSFO, ULSFO, HSFO, LSMGO, HSMGO.
     - **A. Engine Particulars**: FWE date, FWE time, FWE ROB of each chosen grade.
     - **B. Survey Start**: date, time, Log Book ROB of each chosen grade (the engine-room
       log-book ROB at survey start).
     - **C. Survey Completion**: date, time, Surveyor Name.
     - **D. Ship Staff**: Chief Engineer, Master.
     - **E. Survey Location**: Port Name (from the vessel record, editable), Place of Survey.
     - **F. Draft Particulars**: Forward, Aft, Mean Draft and Trim (worked out; Trim = Aft − Fwd
       as on the template's TABLES 54B), List.
     - **Other details**: Voyage No., Charterers, Alongside (the existing fields).
     - Next checks the required values. Missing ones turn red, the page scrolls to the first,
       and a message lists them. Survey Completed must not be before Survey Started.
  3. **One step per chosen grade** (unchosen grades get no step).
     - Tanks are cards one below the other, with no table and no sideways scrolling.
     - **Add tank** / **Add another tank** adds a card with these fields:
       - Tank Name
       - Reference / Total Height
       - Sounding or Ullage (switch). For ullage, Sounding = Reference height − Ullage, shown
         and used in the report.
       - Volume (m³)
       - Density at 15°C
       - Temperature (°C)
     - V.C.F., W.C.F., G.S.V. (m³ @15°C) and MT in Air are worked out while typing.
     - **Save tank** checks the readings and collapses the card to: Tank Name, Sounding,
       Density, Temperature, MT in Air.
     - **Edit**, **Expand** (ref. height, ullage, volume, V.C.F., W.C.F., G.S.V.) and **Delete**
       (asks first) are on every card.
     - The grade total is shown below the cards. Tanks can still be pasted from Excel (name,
       ref. height, sounding, volume, density, temp).
     - Next / **Next grade** needs at least one tank, with every tank complete.
  4. **ROB Summary & Export.**
     - Only the chosen grades with tanks are shown.
     - Survey Completed = sum of the tanks' MT in Air. Log Book = the survey-start ROB from
       step 2. Difference = Survey Completed − Log Book.
     - **TOTAL DIFFERENCE** is shown with its sign.
     - The figures are calculated again on the server from the template's formulas
       (`a=verify`), and the page says so.
     - Anything still missing is listed per step, with **Go to step**. While anything is
       missing, the two buttons are off: **Download PDF Report** and **Download Excel Report**.

  Not asked (as before):
  - the Offhire redelivery texts and remarks keep the template's own wording;
  - the RESULTS block and the REPORT sheet's typed values stay empty.

  MT in Vacuum is not shown: the template has no such calculation (decided with the user).

  **Lookup (IMO).** Type the IMO number and press **Lookup** (or Enter). The server
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

### The report workbook (`srBuildBook`, `includes/survey_reports.php`)

Both downloads come from one build of the template copy, so the PDF and the Excel show the same
values. The original template is never changed.

- **Chosen grades the template has** (VLSFO, HSFO, LSMGO) are filled in their sections.
- **Grades not on board** are hidden in the generated copy, never deleted, so no formula
  breaks. That covers:
  - their TABLES 54B section and their line in the totals list;
  - their certificate lines (quantity, consumption, onboard at redelivery);
  - their column of the BROB header.
- **ULSFO / HSMGO** (the template has no section for them) go on a copy of TABLES 54B named
  *TABLES 54B (ULSFO-HSMGO)*, placed after it. The copy has the same layout, logo and 54B
  formulas:
  - ULSFO goes in the first fuel-oil section, HSMGO in the gas-oil section;
  - headings and totals labels are renamed;
  - the BROB header shows their own log-book values;
  - the unused section is hidden.
- **ROB SUMMARY** sheet, after the tables, on the company letterhead banner
  (`assets/images/invoice-header.png`):
  - the particulars, with Mean Draft and Trim as formulas;
  - per chosen grade, Survey Completed (a formula to the section total), Log Book (the cell on
    SHIP DETAILS, or the value for ULSFO / HSMGO) and Difference;
  - the TOTAL DIFFERENCE;
  - signature lines for the Master, Chief Engineer and Surveyor;
  - A4 portrait.
- Printed: TABLES 54B, the ULSFO-HSMGO copy (when used), ROB SUMMARY, the Offhire certificate.
- `srValidate` refuses an export while anything is missing (the same rules as the page).
  `srResults` reads the results from the workbook's own formulas.

Each download makes new files; earlier ones are kept ("Earlier files" on the summary).

## PDF — LibreOffice (`rtPdf`)

The filled workbook is printed exactly as laid out:

- Only the report sheets are printed: TABLES 54B and OFFHIRE by default; the Admin can change
  this.
- The template's print areas, scaling, margins, logo and fonts are used. Liberation fonts stand
  in for Arial / Times.
- Pages are A4. Hidden columns are not printed, as in Excel.
- The result is the Bunker Survey Report, the ULSFO / HSMGO tables (when used), the ROB Summary,
  then the Certificate of Off-hire Bunker Quantity: 3 A4 pages for VLSFO + LSMGO, 4 with ULSFO /
  HSMGO.

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
- **Draft Excel on this device** (`assets/js/report-offline-xlsx.js`). It is built from the same
  template, with the template's grades and the same values and formulas as the server file.
  ULSFO / HSMGO and the ROB SUMMARY are only in the server report.
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
3. Surveyors / Admin: **Survey Reports → New report**, choose the vessel, then follow the
   steps. On the summary, click **Download PDF Report** / **Download Excel Report**.

No database change: the wizard's values (grades, step, ROB of ULSFO / HSMGO, port name, tank
mode / ullage) are kept in the report's existing JSON (`survey_reports.data`), linked to the
survey as before.

## Acceptance test (local test stack, MV. PROTECTOR workbook)

Two test files cover the wizard. Gemini is mocked.

`sr_test.js` (54/54) drives the wizard with VLSFO + LSMGO:
- the lookup steps;
- the blocked survey step;
- the tanks pasted;
- the summary;
- both downloads;
- the checks below.

`sr_wizard_test.js` (39/39) covers the brief's scenarios:

| Test | Result |
|---|---|
| 1. VLSFO + LSMGO only | 5 steps (2 grade steps); 2 grades on the summary, Excel and PDF; HSFO rows hidden |
| 2. All five grades | 5 independent grade steps; summary, Excel (copy sheet + ROB SUMMARY) and PDF (4 pages) agree |
| 3. Several tanks in one grade | each card = 54B formulas; grade total = sum; ullage → sounding |
| 4. Incomplete lookup | 3 missing particulars red and named, Next off until typed in |
| 5. Survey step incomplete | Next blocked, fields red, message |
| 6. Edit a saved tank | live results, card, grade total and server-checked summary update |
| 7. Refresh mid-step | reopens the same step with the half-typed tank; saved on the server; no duplicate tanks |
| 8. PDF and Excel | the same values, only the chosen grades |
| 9. Calculation accuracy | every numeric cell = independent LibreOffice recalculation; MV PROTECTOR totals 1029.798 / 130.042 MT |
| 10. Mobile (iPhone 13) | no horizontal scrolling on any step; tank fields one column; Back / Next in view |

Checked against the MV PROTECTOR workbook:

1. All six sheets exist, in the same order (ROB SUMMARY is added after TABLES 54B).
2. Vessel information is populated exactly. The only differences are two trailing spaces in
   template cells.
3. All 15 tanks are populated exactly (entered by paste).
4. Every numeric cell matches an independent recalculation (LibreOffice). The tank results match
   Excel's values in the uploaded file.
5. Fuel totals are correct: VLSFO 1029.798, HSFO 0, LSMGO 130.042 MT.
6. The Offhire certificate is populated (quantities, vessel / flag / registry text, signatories).
7. Formatting and print layout are identical: styles, merges, print area, images, page setup,
   column widths.
8. The PDF is A4, 3 pages (report, ROB summary, certificate), with the real layout.
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

## Report types, no vessel pick, one-page tank report, tank change history

> Superseded in part by "Survey Report Generator Enhancement" below: reports are now started from
> the vessel's page (no "New report" button, no reports without a vessel), and the field-level
> change log was replaced by the tank version history.

**Report types.** "New report" lists the report types: 221B / ROB Survey, Offhire, Onhire,
3 Party Offhire, 3 Party Onhire, 4 Party Offhire (`RT_KINDS`).
- Each type has its own template. The Admin uploads it in Report Templates, which has one tab
  per type.
- A type without an uploaded template is shown but cannot be chosen.

**No vessel is picked.** Choosing a type opens the report right away. The vessel details come
from the IMO Lookup (`survey_reports.survey_id` is now NULL for these reports).
- The surveyor who made the report can open and edit it, and so can the Admin.
- The Super Admin can view it.

**Workbook (`srBuildBook`).**
- The BROB header shows the grades on board side by side, with no empty column between them
  (`srHeaderCompact`).
- Only the tanks entered appear: spare tank rows are hidden (`srHideEmptyTankRows`).
- The tank report sheet(s) print on one A4 page: the print area ends at the last filled row and
  is fitted to 1 × 1 page (`srFitOnePage`).
- The ROB Summary and the certificate stay on their own pages.

**Tank change history (Admin).**
- When a saved tank ("Save tank") is changed and saved again, every changed value is logged:
  tank, field, old → new, who, when. The fields covered are name, ref. height, sounding /
  ullage, volume, density and temperature.
- **Edit** marks the tank as being edited. A tank still being edited when the report is made is
  logged as well, and a deleted saved tank is logged as "Tank deleted".
- Storage: `survey_report_tank_saved` (last saved readings) and `survey_report_tank_log`.
- The Admin sees the history on the report's summary step.
- On the Survey Reports list, the Admin sees for every report:
  - the report type;
  - who made it;
  - the number of tank changes;
  - the latest PDF / Excel to download.
- Every file made is kept in the report's "Earlier files".

Tests: `sr_test.js` 57/57, `sr_wizard_test.js` 43/43, including the change history (a saved and
edited tank, and an edit that was not saved, then the report made) and the list for the Admin.

## Survey Report Generator Enhancement (entry point, live preview, tank version history)

**Entry point — the vessel's page.** The "Survey Reports" menu item is gone. A survey report is
started from the vessel: Pending Vessels (or Pending Reports) → vessel → **Survey Report** card
(`includes/survey_report_card.php`, on `vessel_detail.php`, `report_detail.php`,
`completed_detail.php`).
- No report yet: Admin / the assigned Surveyor choose the report type and press **Start
  Survey** (`a=start`). The type is preselected only when the vessel's survey type is exactly one
  report type (e.g. "Offhire"); otherwise the user chooses.
- Start runs in a transaction that locks the vessel row: double clicks or two users at once open
  the same draft, never two reports. The report keeps the vessel id, its name and IMO.
- A report exists: **Resume Survey** (or **Open report** once the vessel is no longer pending).
- A report can no longer be made without a vessel (`a=create` removed). Old reports made without
  a vessel still open from `survey_reports.php` (the list stays, not in the menu).
- The editor's back link goes to the vessel's page.

**Split layout and live preview.** `survey_report.php` shows the wizard on the left and a live
preview on the right (screens ≥ 1100 px); on a phone a **Preview** button opens it full screen.
- The preview is the real report (same template, letterhead, tables and calculations) made by
  the server from the **saved** values: `a=preview` → a temporary PDF in the server's temp folder,
  reused while nothing changes, removed after an hour. Nothing is stored with the report, no file
  is listed, no number is given, the vessel's status is not touched.
- It refreshes after each save and step change (debounced; an older answer never replaces a newer
  one). Page navigation, zoom, refresh. Viewer: pdf.js 3.11.174 from cdnjs.
- **Save Progress** button; the indicator shows Saved / Saving… / Save failed (and "Changes on
  this device" when offline).

**Tank version history (Admin only).** Table `survey_tank_entry_history`
(`database/migration_survey_tank_history.sql`).
- Tracked: Sounding (typed, or reference height − ullage), Volume, Density, Temperature.
  Name, remarks and every other field never make a version.
- Version 1 when a tank is first saved ("Save tank", or Next on a complete tank). A new version
  only when at least one of the four changes — several at once make one version; going back to an
  earlier value makes a new version. Numbers are compared normalised (250.5 = 250.50, .948 =
  0.9480), so autosave and repeated saves add nothing.
- A tank opened with **Edit** gets its version when it is saved again; when the report is made,
  tanks still being edited are recorded too.
- Written in the same transaction as the report's current values (`SELECT … FOR UPDATE` on the
  report, version check, update, history insert, commit). Any failure rolls everything back;
  a stale or simultaneous save is refused with 409 and overwrites nothing. UNIQUE (report_id,
  tank_id, version_number) makes a duplicate version impossible. Rows are never updated or deleted.
- Admin: the vessel page's Survey Report card → **Tank history**: choose a tank, see every
  version (newest first, current marked, changed values highlighted, who and when) and compare any
  two versions. Also on the summary step of the report.
- `a=tank_history` answers only the Admin (403 for Surveyor, Super Admin and everyone else,
  before anything is read). The client PDF / Excel never contain history.
- The Survey Reports list column "Tank changes" counts versions after the first.

**Files.** `includes/survey_report_card.php` (new), `includes/survey_reports.php`
(`srEnsureHistory`, `srTrackNorm`, `srTracked`, `srTankVersionsApply`, `srTankHistory`, `srStart`,
`srReportForSurvey`, `srPreviewPdf`; the old `srLogTankChanges` / `srTankLog` removed),
`ajax/survey_reports.php` (`start`, `for_survey`, `tank_history`, `preview`; transactional save),
`survey_report.php`, `survey_reports.php`, `assets/js/survey-report.js`,
`assets/css/survey-report.css`, `includes/nav.php`, `vessel_detail.php`, `report_detail.php`,
`completed_detail.php`, `database/migration_survey_tank_history*.sql`.

**Deploy.** No manual step: the table is created at runtime on first use (or run
`database/migration_survey_tank_history.sql` beforehand). Existing reports and data are not
changed. Rollback: deploy the previous revision; the new table can stay (unused).

## Survey Reports v3 (Excel kept in the system, A4 pages, formats, copy tank, live, previous attendance, share, offline PDF)

**Excel kept in the system (no download).** The summary step has **Download PDF Report** and
**Preview Report** only. Each PDF made also stores the Excel (`survey_report_files`).
- The Excel cannot be downloaded by the surveyor: `a=file` gives the Excel to the Admin only.
- The Word report generator (`report_generator.php`, `ajax/report_generator_data.php`) lists
  the latest Survey Report Excel of each vessel first, with a negative id = survey report file.
  It is preselected for the vessel the page was opened for (`?id=`), and **Upload** loads it as
  before (REPORT sheet).
- Access is the same as the report: Admin, or the vessel's surveyors.

**A4 pages (`srFitA4`).** TABLES 54B (and its ULSFO/HSMGO copy) and the Offhire certificate:
- print area = the cells used;
- margins 0.3", centred horizontally, fitted to the page width;
- the height left over is shared over the rows (≤ 1.6×), so the page is filled and still prints
  on one page.

Centred text wider than column A (e.g. "REMARK:") starts at the edge; merged titles stay centred.
The letterhead is unchanged (a new one will be supplied later).

**Header without empty columns.** The BROB block shows exactly the grades on board. The columns
left over lose their borders, and "M.Tons" moves next to the last grade (`srHeaderCompact`).

**Reading formats.** Sounding 0.000, density 0.0000, temperature 00.0 — however they are typed:
- in the form, when leaving the box and on Save tank;
- in the saved-tank summary;
- as number formats of the Excel/PDF cells (`RT_TANK_FORMATS`).

Versions compare normalised values, so a re-format makes no new version.

**Step 2.** VLSFO and LSMGO are chosen by default on a new report, and the "Alongside" box is
removed.

**Preview Report.** No live preview on the page. **Preview Report** (in the header, on the summary,
and the phone button) opens it:
- on a computer: a large window;
- on a phone: full screen.

It refreshes after saves while open, and Esc closes it.

**Copy tank.** **Copy** puts a new tank right after it with the same values, open and marked
"Copy". It is not auto-saved by Next. Its version 1 is written when it is saved (after its values
are changed), or when the report is made.

**Live.** The report page checks every 8 s and shows what someone else saved (e.g. the Admin
watches the surveyor fill the tanks). This happens only while nothing is waiting to be saved on
that device and no box is being typed in, and an "Updated live — name, time" badge shows.
Moving between steps no longer saves by itself; the step is remembered on the device. So two
people looking at one report do not clash.

**Fill from previous attendance (step 1).** Offered when the vessel was surveyed before (`a=previous`,
`srPrevious`). It is matched by IMO, else by vessel name, from:
1. the newest earlier Survey Report on the website;
2. else the Excel uploaded for an earlier survey of the vessel, read with the template analysis.
   Values must pass the field checks and are marked "please check".

It fills:
- step 1: the vessel particulars;
- step 2: the grades;
- step 3: tank names and reference heights only. Tanks already entered are kept, and no readings,
  dates or ROB are copied.

**Share (WhatsApp).** **Share** on the ROB comparison makes a 1080 px picture of the card (vessel,
type, grades, survey vs log book, differences, total).
- On a phone it opens the share sheet (WhatsApp).
- On a computer the picture is copied/downloaded and WhatsApp Web opens.

**Session expired ("Server error (200)").** The cause: a request after the session ended was
redirected to `ajax/login.php`, which answers "Invalid Request" with status 200. Sessions are
files on the Cloud Run instance, so they end when the instance changes or restarts.
- The API now answers JSON 401, and the page shows "session expired — sign in again (new tab)".
- Changes stay on the device, the CSRF token is refreshed (`a=token`), and saving resumes by itself.
- Stray PHP output can no longer break the JSON.

Keeping sessions across instances (DB sessions) would be a separate change.

**Offline.**
- Everything is kept on the device and saved when the connection is back (unchanged).
- With no internet the summary offers a **draft PDF made on the device** (jsPDF 2.5.1 +
  autotable 3.8.2). It has the letterhead banner, particulars, BROB, tank tables, ROB summary and
  signatures, and is marked DRAFT.
- When the connection is back, the offline changes are saved and the final PDF (and the Excel
  kept in the system) is made by itself, with a "final report ready" message.
- The offline Excel (ExcelJS) is no longer used.
