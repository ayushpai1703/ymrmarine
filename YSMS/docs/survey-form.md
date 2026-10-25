# Bunker survey form from photos

Opened from **Vessel details** (Pending Vessel) and **Pending Report details** →
"Bunker survey form from photos". Admin / Super Admin, and the surveyor assigned to the vessel.

1. **Photos** — take photos or pick images / PDF scans (15 MB each). The originals are kept
   unchanged in `uploads/survey_forms/<form id>/`.
2. **Read with AI** — Gemini reads only what is legible. It fills **empty** fields only;
   a different reading of a value the user already has is shown as a suggestion
   ("Use" / "Keep mine"). Values below 80 % confidence are marked **Check**.
3. **Details / Tanks** — every field can be edited; tank rows can be added or removed.
4. **Review** → **Make Excel** — fills the approved Excel format in `formats/`
   (Offhire, Onhire, 3 Party Offhire, ROB / 221B).

## Calculations

V.C.F., G.S.V., metric tons, totals, results, difference and trim always come from the
format's own TABLES 54B formulas (copied from its first tank row onto every tank row and
evaluated on the server). The AI is never asked for them. Extra tank rows are inserted
inside their section; totals and the printable certificate follow automatically.
Inputs are limited to the decimals the format displays, because the formats use Excel's
"precision as displayed".

## Safety

- Making the form or the Excel never changes the survey's status — completion stays the
  normal upload on the vessel page.
- Drafts are saved with a version number: a save from an out-of-date screen is refused,
  nothing is overwritten.
- Every "Make Excel" creates a new file; earlier files are kept.

## Setup

The server needs the Gemini key (same secret as the photo report):
`YSMS_GEMINI_API_KEY` (Secret Manager → Cloud Run `--set-secrets`). Optional:
`YSMS_GEMINI_MODEL`. Without a key the form still works — filled by hand.
