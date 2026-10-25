# Word Report Generator — PDF input source

Each report tab (Offhire, Onhire, B+C, 3 Party) now has **📊 Excel | 📑 PDF**. Excel is the
default and works exactly as before.

**PDF**: choose one or more PDFs (ship particulars, BDN, sounding sheets, survey reports —
text or scanned, 14 MB each) → **Read PDFs**. Each file shows upload %, then its type, pages,
"text PDF" / "scanned (read by OCR)", and the number of values found; a failed read has
**Retry**. No Excel file is needed.

Values go into the same form the Excel fills (the page's `REPORT_FIELDS`; no new
placeholders). Under each field:

- ✓ **From PDF · file p.N** — also "(+N more agree)" when several PDFs say the same.
- ⚠ **Check** — AI not sure; not found in the PDF's own text (likely misread / invented);
  read from a scanned page; more decimals than the report shows (never rounded); not a
  valid date / number / option.
- ⚠ **The documents differ — choose** — the field is left empty until one value is picked.
- 🧮 **Calculated** — a fuel total worked out from the tank readings with the approved
  Excel format's TABLES 54B formulas (only when no total is printed). When a printed total
  and the calculation disagree, both are offered as a conflict.
- **Not found in the PDFs** — fill by hand.

A value typed by the user, chosen from a conflict, or loaded from Excel is never replaced:
a later PDF read asks first ("Keep mine" shows the PDF value with a **Use** button).

## Privacy

The PDF is read from PHP's temporary upload file and discarded at the end of the request;
nothing is stored and document text is never logged. Admin / Super Admin / Surveyor only.

## Setup

`smalot/pdfparser` (composer.json — installed by the Docker build) reads the text layer.
Gemini (`YSMS_GEMINI_API_KEY`, optional `YSMS_GEMINI_MODEL`) reads the PDFs, text and
scanned. Without the key the PDF option reports that AI reading is not set up; Excel and
manual entry keep working.
