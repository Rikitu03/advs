# E2E Document Submission Wiring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the already-built vendor→officer submission pipeline actually run locally, and prove it end-to-end with the real stuck submission (SUB-1, 3 documents).

**Architecture:** No application-code changes. Two configuration fixes (Python interpreter for Stage T forensics; queue names for the dev worker), then drain the 3 stuck `ProcessDocumentJob`s with a one-shot worker and verify each stage's DB side-effects. Spec: `docs/superpowers/specs/2026-07-15-e2e-submission-wiring-design.md`.

**Tech Stack:** Laravel 12 (database queue), Livewire/Volt, Python 3.12 venv at `python/env/Scripts/python.exe`, MySQL 8 (XAMPP MariaDB).

## Global Constraints

- Never commit `.env` (gitignored). Commit `.env.example` and `composer.json` only.
- Run `php artisan config:clear` before any `php artisan test` run — a cached `bootstrap/cache/config.php` otherwise points tests at MySQL instead of sqlite `:memory:`.
- The Python interpreter MUST be `C:\xampp\htdocs\projects\advs\python\env\Scripts\python.exe` (python.org 3.12.10 with the ML stack). Never bare `python` (MSYS2, no wheels) or `python3` (also MSYS2 here).
- If Stage T fails on a real file, root-cause it with superpowers:systematic-debugging — do not bypass or fake the verdict.
- MySQL may be started by hand rather than XAMPP Control Panel (see project memory `mysql-innodb-recovery-2026-07-02`); if DB connections fail, check that `mysqld` is running before anything else.

---

### Task 1: Point Stage T at the working Python interpreter

**Files:**
- Modify: `.env` (add one line; NOT committed)
- Modify: `.env.example` (add documented key; committed)

**Interfaces:**
- Consumes: `config('advs.forensics.python_bin')` default `env('ADVS_PYTHON_BIN', 'python3')` (`config/advs.php:79`).
- Produces: `ADVS_PYTHON_BIN` env var that `TamperDetectionService::analyze()` uses to spawn `scripts/tamper_analyze.py`. Task 3 relies on this being correct.

- [ ] **Step 1: Verify the venv interpreter can import what the tamper script needs**

Read the script's imports first:

Run: `head -30 python/scripts/tamper_analyze.py`

Then verify each third-party import it declares resolves in the venv (adjust the module list to match what you saw; `cv2`/`numpy`/`PIL` are the expected core):

Run: `python/env/Scripts/python.exe -c "import cv2, numpy, PIL; print('imports OK')"`
Expected: `imports OK` (exit 0). If an import fails, STOP — the venv is broken; investigate before continuing (do not pip-install blindly; check project memory `msys-python-no-wheels`).

- [ ] **Step 2: Add the key to `.env`**

Append to `.env` (backslashes, no quotes — the path has no spaces):

```dotenv
ADVS_PYTHON_BIN=C:\xampp\htdocs\projects\advs\python\env\Scripts\python.exe
```

- [ ] **Step 3: Add the documented key to `.env.example`**

Append to `.env.example`:

```dotenv
# Absolute path to the Python interpreter for the ML/forensics pipeline
# (Stage T tamper analysis). Must be the project venv, not a system Python.
# Windows example: C:\xampp\htdocs\projects\advs\python\env\Scripts\python.exe
ADVS_PYTHON_BIN=python3
```

- [ ] **Step 4: Verify Laravel sees the value**

Run: `php artisan config:clear && php artisan tinker --execute 'echo config("advs.forensics.python_bin"), PHP_EOL;'`
Expected output: `C:\xampp\htdocs\projects\advs\python\env\Scripts\python.exe`

- [ ] **Step 5: Smoke-run Stage T through the real service (no hand-built payload)**

`TamperDetectionService::analyze()` builds its own payload from the document and spawns the script exactly as the job would, so call it directly against SUB-1's document #1 (`Recent Card.jpg`, a raster image — the simplest case). Write to the scratchpad as `smoke_tamper.php`:

```php
<?php

use App\Models\Document;
use App\Services\Document\TamperDetectionService;

$doc = Document::findOrFail(1);
$verdict = app(TamperDetectionService::class)->analyze($doc);

echo 'tamper_score=', var_export($verdict['tamper_score'] ?? null, true), PHP_EOL;
echo 'tamper_authenticity=', var_export($verdict['tamper_authenticity'] ?? null, true), PHP_EOL;
echo 'flags=', json_encode($verdict['flags'] ?? []), PHP_EOL;
```

Run: `php artisan tinker "<scratchpad>/smoke_tamper.php"`
Expected: prints a numeric `tamper_score` and `tamper_authenticity` (no exception). This does NOT persist anything (`persist()` is not called), so it cannot corrupt Task 3's clean run. If it throws `Document tampering analysis script failed`, read the stderr in `storage/logs/laravel.log` and root-cause per Global Constraints before Task 3.

- [ ] **Step 6: Commit `.env.example` only**

```bash
git add .env.example
git commit -m "Document ADVS_PYTHON_BIN in .env.example for the Stage T pipeline"
```

---

### Task 2: Make the dev queue worker listen to the pipeline queue

**Files:**
- Modify: `composer.json:61` (the `dev` script's `queue:listen` segment)

**Interfaces:**
- Consumes: `ProcessDocumentJob::__construct` dispatches to queue `document-processing` (`app/Jobs/ProcessDocumentJob.php:31`); `NotificationService` mail jobs may use `mail`.
- Produces: a `composer run dev` that actually processes submissions. Nothing else depends on it programmatically.

- [ ] **Step 1: Edit the dev script**

In `composer.json` line 61, change:

```
"npx concurrently -c \"#93c5fd,#c4b5fd,#fdba74\" \"php artisan serve\" \"php artisan queue:listen --tries=1\" \"npm run dev\" --names='server,queue,vite'"
```

to:

```
"npx concurrently -c \"#93c5fd,#c4b5fd,#fdba74\" \"php artisan serve\" \"php artisan queue:listen --tries=1 --queue=document-processing,mail,default\" \"npm run dev\" --names='server,queue,vite'"
```

- [ ] **Step 2: Validate the JSON is still well-formed**

Run: `composer validate --no-check-all --no-check-publish`
Expected: `./composer.json is valid`

- [ ] **Step 3: Commit**

```bash
git add composer.json
git commit -m "Process document-processing and mail queues in composer run dev

ProcessDocumentJob is dispatched to the document-processing queue, so the
dev worker's bare queue:listen (default queue only) never picked it up and
submissions sat in processing forever."
```

---

### Task 3: Drain the 3 stuck jobs and verify every pipeline side-effect

**Files:**
- Create: `C:\Users\Profile\AppData\Local\Temp\claude\...\scratchpad\verify_pipeline.php` (tinker verification script; scratchpad, never committed)
- No repo files modified.

**Interfaces:**
- Consumes: `ADVS_PYTHON_BIN` from Task 1. Jobs already in the `jobs` table (3 × `ProcessDocumentJob`, queue `document-processing`).
- Produces: SUB-1 in `pending_review` with composite risk — the state Task 4's browser walkthrough needs.

- [ ] **Step 1: Confirm preconditions**

Run: `php artisan tinker --execute 'echo "jobs=", DB::table("jobs")->count(), " failed=", DB::table("failed_jobs")->count(), PHP_EOL;'`
Expected: `jobs=3 failed=0`. (If jobs=0 and failed>0, retry them first: `php artisan queue:retry all`.)

- [ ] **Step 2: Run the one-shot worker**

Run (timeout 600000 ms — Stage T can be slow on first model load):

```bash
php artisan queue:work --queue=document-processing --stop-when-empty
```

Expected: three `App\Jobs\ProcessDocumentJob ... DONE` lines, worker exits 0. Watch the live log tail for `Stage T tamper_analyze.py failed` errors.

- [ ] **Step 3: Write the verification script to the scratchpad**

`verify_pipeline.php`:

```php
<?php

use App\Models\Notification;
use App\Models\Submission;

$s = Submission::with(['documents.validationResult', 'documents.tamperAnalysis'])->find(1);

echo 'submission_status=', $s->status, PHP_EOL;
echo 'composite_risk=', var_export($s->composite_risk_score, true), ' level=', var_export($s->risk_level, true), PHP_EOL;

foreach ($s->documents as $d) {
    echo 'doc#', $d->id, ' ', $d->processing_status,
        ' tamper=', $d->tamperAnalysis?->tamper_score ?? 'NULL',
        ' risk=', $d->validationResult?->document_risk_score ?? 'NULL',
        ' flags=', json_encode($d->validationResult?->flags ?? []), PHP_EOL;
}

echo 'recent_notifications:', PHP_EOL;
foreach (Notification::latest()->take(6)->get(['user_id', 'type', 'title']) as $n) {
    echo '  user#', $n->user_id, ' ', $n->type, ' — ', $n->title, PHP_EOL;
}
```

- [ ] **Step 4: Run it and check every expectation**

Run: `php artisan tinker "<scratchpad>/verify_pipeline.php"`

Expected, per the spec:
- `submission_status=pending_review`
- `composite_risk=` a float (max of the 3 document scores), `level=` one of `low|medium|high`
- each `doc#`: `completed`, non-NULL tamper score, non-NULL risk, flags including the four `"... unavailable"` standby markers
- notifications include a processing-complete entry for the vendor user AND entries for officer/admin users

- [ ] **Step 5: If any expectation fails — systematic debugging, then re-verify**

Invoke superpowers:systematic-debugging. Likely suspects, in order: Python import/runtime error in `tamper_analyze.py` (check `storage/logs/laravel.log` for stderr), PDF handling for `BSCS-Curriculum-Checklist.pdf`, path quoting. Fix root cause, `php artisan queue:retry all`, re-run Steps 2–4. A document legitimately marked `failed` with the submission still transitioning to `pending_review` + a `Processing failed for N document(s)` flag is spec-compliant behavior for a genuinely broken input — but an environment/config failure is not, and must be fixed.

- [ ] **Step 6: Nothing to commit** (DB-state task). Delete the smoke/verify scratch files.

---

### Task 4: Regression suite + browser walkthrough handoff

**Files:**
- No files modified.

**Interfaces:**
- Consumes: SUB-1 in `pending_review` (Task 3).
- Produces: green suite + user-facing walkthrough instructions.

- [ ] **Step 1: Run the full test suite**

Run: `php artisan config:clear && php artisan test --compact`
Expected: all tests pass (suite was green at 34+ tests before; VendorSubmissionModuleTest alone has 11). Any failure = investigate before handoff; config changes should not affect tests (tests use sqlite/sync queue).

- [ ] **Step 2: Hand the browser walkthrough to the user**

Tell the user (they drive; agent watches the log tail):
1. Run `composer run dev` (now processes the right queues) — or keep existing `artisan serve` + a separate `php artisan queue:work --queue=document-processing,mail,default`.
2. Log in as officer/admin → dashboard should show 1 pending; open **Pending Submissions** → SUB-1 listed with risk badge.
3. Open SUB-1 → verify risk breakdown, 3 files, tamper verdict visible → **Approve** (or Reject) with a comment.
4. Log in as the vendor → **My Submissions** shows the decision at 100% progress; **Notifications** shows the processing + decision alerts.

- [ ] **Step 3: Confirm decision persisted after the user reports back**

Run: `php artisan tinker --execute '$s = App\Models\Submission::find(1); echo $s->status, " by=", $s->reviewed_by, " at=", $s->reviewed_at, PHP_EOL;'`
Expected: `approved` (or `rejected`), non-null reviewer id and timestamp.
