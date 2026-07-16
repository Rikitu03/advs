# End-to-End Document Submission Wiring — Design

**Date:** 2026-07-15
**Branch:** `feat/document_submission`
**Scope decision:** Wire + verify E2E (no new features, no UX polish beyond breaks found).

## Problem

A vendor's real submission (SUB-1, 3 documents) persists to MySQL but never appears on the
officer/admin dashboards. Investigation showed the entire application chain is already built
and Eloquent-backed end to end:

- Vendor submit → `Submission` + `Document` rows + `ProcessDocumentJob` dispatch ✅
- `ProcessDocumentAction` → Stage T forensics (Python) → `ValidationResult` + risk score ✅
- `SubmissionFinalizer` → `pending_review` + composite risk + §7 notifications ✅
- Officer pending queue / dashboard KPIs / drill-down / approve-reject persistence ✅

The failure is **operational wiring**, not missing code. Three blockers:

1. **No queue worker running** — 3 `ProcessDocumentJob`s sit in the `jobs` table; SUB-1 stays
   `processing`, and the officer queue only lists `pending_review`.
2. **Wrong Python interpreter** — `ADVS_PYTHON_BIN` is unset, so Stage T falls back to
   `python3`, which resolves to the MSYS2 build with no ML packages. Jobs would fail the
   moment a worker ran. The verified interpreter is `python/env/Scripts/python.exe`
   (python.org 3.12.10, full stack installed).
3. **`composer run dev` polls the wrong queue** — its `queue:listen --tries=1` has no
   `--queue=` flag, so it only serves `default`; `ProcessDocumentJob` is dispatched to
   `document-processing` (`ProcessDocumentJob::__construct`). Submissions would never process
   even for developers using the documented dev command.

## Approach (chosen: A — minimal wiring fix + live verification)

Rejected alternatives: (B) moving `ProcessDocumentJob` to the `default` queue — diverges from
CLAUDE.md §7's deliberate queue isolation for production workers; (C) a convenience
`advs:process-pending` command — YAGNI, `queue:work` already does it.

### 1. Configuration changes

- `.env`: `ADVS_PYTHON_BIN` set to the **absolute path** of `python/env/Scripts/python.exe`
  (absolute because `Process::path(base_path('python'))` sets the CWD, but Windows PATH
  resolution for bare relative commands is unreliable across shells).
- `.env.example`: same key with a placeholder + comment so other machines configure it.
- `composer.json` dev script: `queue:listen --tries=1` →
  `queue:listen --tries=1 --queue=document-processing,mail,default`.

No application code changes.

### 2. Drain and verify the pipeline (agent-driven, evidence-based)

Run once: `php artisan queue:work --queue=document-processing --stop-when-empty`.

Expected per document (jpg, pdf, png — SUB-1's real files):

- `tamper_analyses` row persisted (Stage T verdict from `tamper_analyze.py`).
- `ValidationResult` with `document_risk_score`, flags including the four
  "…unavailable" markers for the not-yet-built ML stages (standby pipeline).
- `documents.processing_status` → `completed`.

Then, once all three documents are terminal:

- SUB-1 `status` → `pending_review`, `composite_risk_score` = max document risk,
  `risk_level` banded per `config('advs.risk.*')`.
- Officer + vendor notifications created by `NotificationService::submissionProcessed`.

If Stage T fails on any real file (e.g., the PDF), that is a **finding to fix at root
cause**, not to bypass; a failed document still finalizes the submission with a
"Processing failed for N document(s)" flag (already-built behavior).

### 3. Officer walkthrough (user-driven, in the browser)

1. Admin dashboard KPIs show 1 pending submission.
2. Pending queue lists SUB-1 sorted by composite risk.
3. Drill-down shows the risk breakdown, OCR placeholder, and all 3 files.
4. Approve or Reject with a comment → decision persists (`OfficerDecisionService`),
   audit-logged, vendor notified.
5. Vendor's My Submissions shows the decision; vendor notifications page shows the alert.

### 4. Testing

No new application code ⇒ no new tests. Run the full suite (`php artisan config:clear` first —
cached config otherwise points tests at MySQL) to confirm nothing regressed. Acceptance
evidence = DB rows from step 2 + the user's browser walkthrough in step 3.

### 5. Error handling (already built, verified in passing)

- Job failure path: 3 tries with backoff → `failed()` marks the document `failed` and still
  calls `SubmissionFinalizer`, so one bad file cannot strand a submission in `processing`.
- Finalizer is idempotent and lock-serialized; concurrent document completions are safe.

## Out of scope

- Stages 1–4b ML wrappers (models not trained yet — standby pipeline by design).
- Vendor-side live progress polling, officer email alerts, UX polish.
- Production supervisor/worker configuration (DEPLOY.md, Phase 10).
