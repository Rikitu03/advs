# ADVS — Main Pipeline Integration Phases

> **One of three concern-split phase plans.** This file owns the **backend / Laravel ↔ Python
> integration track**: turning the demo-backed scaffold into a live, persisted document-validation
> pipeline and layering the **Negofood compliance-lifecycle** features on top of it.
>
> Companions:
> - Model training → [MODEL_TRAINING_PHASES.md](./MODEL_TRAINING_PHASES.md)
> - UI functions → [UI_FUNCTION_PHASES.md](./UI_FUNCTION_PHASES.md)
>
> Sources this plan integrates:
> - **What the system does** — [ADVS_System_Reference.md](../../ADVS_System_Reference.md) (Stages 0–T, risk score, §9 params).
> - **How we build** — [CLAUDE.md](../../CLAUDE.md) (stack, conventions, original 10-sprint phases §10).
> - **New client direction** — [CLIENT_INTERVIEW_GAP_PLAN.md](../CLIENT_INTERVIEW_GAP_PLAN.md) (Negofood Solution interview, 2026-06-29).
>
> **Branch/integration:** work is staging-first (lead/integration branch `staging`); land each phase as a
> reviewable slice. Precedence rule from `CLAUDE.md`: stack/version facts follow `CLAUDE.md`; product
> behavior follows `ADVS_System_Reference.md`; flag conflicts explicitly (see the calendar-driven note below).

---

## Why this track exists

The client interview reframed the priority. ADVS was built as an **ML fraud-detection pipeline**
(ResNet-50 → YOLOv8 → Siamese → EfficientNet → Stage-T forensics → composite risk score). Negofood
Solution was explicit that **"expired or incomplete documents are more common than fake documents"**
and that **"automation should support the reviewer … final approval should still be done by an
authorized person."**

So this track keeps the ML pipeline **as-is** and adds a **compliance-lifecycle layer**: real upload →
persistence, structured field extraction (esp. **expiry dates**), a **completeness checklist** per
vendor type, **expiration + renewal** monitoring, and a **resubmission** loop. The new compliance signals
become **additional flags surfaced beside** the existing composite risk score — they do not replace it.
(Subjects are **vendors only** — personnel/employee onboarding is out of scope; see gap plan G5.)

> **Approved conceptual departure (flag, per `CLAUDE.md` precedence):**
> [ADVS_System_Reference.md §1](../../ADVS_System_Reference.md) states the system is *"on-demand, not
> calendar-driven."* The **renewal scheduler (Phase P5)** introduces a deliberate calendar dimension.
> This is an approved change; the reference doc carries a pointer to it. Everything else stays on-demand.

---

## Current state on `staging` (integration view)

| Area | Status | Notes |
|---|---|---|
| Auth + roles (Fortify, session) | ✅ Done | `vendor`, `compliance_officer`, `admin`; `role:` middleware; signature enroll at registration. |
| Data model (vendor-centric) | ✅ Done | `vendors`, `document_types`, `submissions`, `documents`, `validation_results`, `vendor_embeddings`, `notifications`, `audit_logs`, `system_settings`, `tamper_analyses`, `logo_references`, `ml_models`, `retention_policies`. |
| Pipeline orchestration (PHP) | 🟡 Partial | `ProcessDocumentAction`, `ProcessDocumentJob`, `RiskScoreService`, `TamperDetectionService`, `SignatureAuthenticityService` exist; **not driven by a live upload.** |
| Real upload → pipeline wiring | ❌ Missing | No `DocumentSubmissionController`; uploads don't persist `Document` rows or dispatch the job from the UI. |
| Python inference contracts | 🟡 Partial | Present: `tamper_analyze.py`, `ocr_dryrun.py`, dataset generators. **Missing named contracts:** `preprocess.py`, `ocr_runner.py`, `classify_document.py`, `signature_verify.py`, `stamp_verify.py`, `enroll_reference.py` (see [MODEL_TRAINING_PHASES.md](./MODEL_TRAINING_PHASES.md) for the weights they load). |
| Compliance lifecycle (expiry/checklist/renewal/resubmission) | ❌ Missing | None of the client-prioritized features exist yet. |
| Admin tooling | ✅ Strong | System settings, audit + CSV export, retention, ML model catalogue built. |

**Bottom line:** the fraud-detection scaffold is well advanced; the **live end-to-end pipeline** and
**every feature the client actually prioritized** do not exist yet. These phases close that gap.

---

## The integrated pipeline (target)

```
Vendor submits
        │
        ▼
Stage 0  Intake → persist Submission + Documents → dispatch ProcessDocumentJob
        │
        ▼
Stage 1  Preprocess (OpenCV: grayscale → binarize 150 → morph-open 2×2 → invert)
        │
        ▼
Stage 2  OCR (PyTesseract + NLP cleanup) → detected city for §4b lookup
        │
        ▼
Stage 2b FIELD EXTRACTION (NEW) → {expiry_date, document_number, business_name, …}
        │
        ▼
Stage 3  Classify (ResNet-50, 512×512) → document_type → issuer_scope
        │
        ▼
Stage 4  Detect (YOLOv8) → 4a Signature (Siamese vs registration ref) · 4b Logo (EfficientNet vs issuer ref)
        │
        ▼
Stage T  Forensic tampering (metadata · ELA · copy-move · font · OCR cross-ref) on the ORIGINAL file
        │
        ▼
Stage 5  Composite risk (5 weighted terms + missing penalty + tamper hard-override)
        │
        ▼
Compliance evaluation (NEW): completeness vs checklist + expiry/validity status
        │
        ▼
Stage 6  Officer review queue — color-coded by risk score AND compliance flags
        │
   ┌────┼───────────────────┐
   ▼    ▼                   ▼
Approve Reject     Request Resubmission (NEW) → vendor re-uploads flagged items only
        │
        ▼
Decision logged (audit) ; if approved + has expiry → schedule renewal reminder (NEW, Phase P5)
                          ; if approved doc carries an unreferenced issuer logo → EnrollReferenceJob
```

The **NEW** stages are what these phases add; Stages 1–T and the risk score already exist and stay put.

---

## Phase P0 — Pipeline contract audit & seam hardening *(prerequisite)*

**Goal:** Confirm the existing orchestrator's seams before wiring anything live, so later phases extend a
known-good contract rather than guessing.

**Tasks:**
- Map `ProcessDocumentAction` against the [ADVS_System_Reference.md §5](../../ADVS_System_Reference.md) stage list; record which stages are implemented vs stubbed.
- Inventory the `Process`-facade Service wrappers and the exact Python CLI contract each expects (`--input`/`--output` JSON), per [CLAUDE.md §6](../../CLAUDE.md). Note any contract that points at a **missing** script (those scripts are delivered in [MODEL_TRAINING_PHASES.md](./MODEL_TRAINING_PHASES.md)).
- Verify temp-payload cleanup happens in `finally` blocks (no leaked `storage/app/python_payloads/*.json`).
- Confirm `documents` retains **both** `file_path` (original) and `converted_image_path` (preprocessed) so Stage T reads the original (reference §Stage T).

**Definition of Done:** a short seam map exists; no orphaned temp files after a manual `ProcessDocumentAction` run on a fixture; the Python contract table is accurate and the "missing scripts" list is handed to the model-training track.

---

## Phase P1 — Data & domain model (compliance foundation)

**Goal:** Add the schema the compliance layer needs — expiration fields, requirement profiles, and
lifecycle statuses — without editing shipped migrations. *(See [schema-first-modeling] discipline:
read the live schema before adding columns.)*

> Coordinate column names/types/casts with [MODEL_TRAINING_PHASES.md](./MODEL_TRAINING_PHASES.md) where
> field extraction writes into them, and with [UI_FUNCTION_PHASES.md](./UI_FUNCTION_PHASES.md) where they render.

**Tasks:**
- **Subject stays the vendor (G5 descoped).** Submissions remain keyed to `vendors` only — **no**
  `personnel`/`accreditation_subjects` table and **no** `subject_type`/`subject_id` polymorphism.
  Personnel/employee onboarding is out of scope; do not reintroduce it.
- **Expiration fields (G1/G4).** Add to `documents` (or `validation_results`): `issue_date` (nullable
  date), `expiry_date` (nullable date), `document_number` (nullable string), `extracted_fields` (json),
  and a derived `validity_status` enum (`valid` / `expiring_soon` / `expired` / `unknown`). Cast in `casts()`.
- **Requirement profiles (G3).** New tables: `requirement_profiles` (named checklist, scoped by subject
  kind) and `requirement_profile_items` (profile → `document_types` with `is_required`, `requires_expiry`,
  `renewal_window_days`).
- **Lifecycle status expansion (G6/G8).** Extend `submissions.status` to include `incomplete` and
  `resubmission_requested`; add `documents` review states (`accepted`, `rejected`, `resubmit_requested`)
  and a `review_notes` field.
- **Renewal tracking (G2).** A lightweight `document_reminders` table (or a scheduled scan over
  `notifications`) keyed on `expiry_date − renewal_window_days`.
- **Food-domain document types (G7).** Re-seed `DocumentTypeSeeder` for the Negofood domain, each tagged
  with `requires_expiry` + `issuer_scope` (drives Stage 4b). The **type taxonomy is shared** with the
  classifier — keep codes in lockstep with [MODEL_TRAINING_PHASES.md](./MODEL_TRAINING_PHASES.md).
- Factories + seeders for every new model.

**Definition of Done:** `php artisan migrate:fresh --seed` runs clean; new factories produce valid
records; `php artisan test --compact` green.

---

## Phase P2 — Live upload pipeline (close the demo gap)

**Goal:** A real upload persists rows and dispatches the queued job — replacing the demo store for the
submission path.

**Tasks:**
- **`DocumentSubmissionController`** (thin) + a **Form Request**: accept `image/jpeg`, `image/png`,
  `application/pdf`; ≤ 10 MB per file (reference §9 `MAX_FILE_SIZE_MB`); **server-side MIME sniffing**
  (`getMimeType()`), not just extension.
- Store to `storage/app/documents/{subject}/{submission}/` (private — never `public/`).
- Persist a `Submission` (status `processing`) + one `Document` per file; for PDFs, mark first-two-page
  handling per reference §2 / §Stage 0.
- Dispatch `ProcessDocumentJob` (already `$tries=3`, `$backoff`, `$timeout=300`); reuse the existing
  `ProcessDocumentAction` orchestrator.
- Wire the existing Volt upload UI to this controller (the actual panels are built in
  [UI_FUNCTION_PHASES.md](./UI_FUNCTION_PHASES.md); this phase guarantees the controller + persistence exist).

**Definition of Done:** a real upload creates `Submission` + `Document` rows and
`Queue::assertPushed(ProcessDocumentJob::class)` passes; a disguised `.php`-as-`.jpg` is rejected by MIME
sniffing; a 10.1 MB file is rejected, exactly 10 MB accepted. `php artisan test --filter=DocumentSubmission` green.

---

## Phase P3 — Field-extraction stage (OCR → structured fields)

**Goal:** Turn raw OCR text into the structured fields the client needs — above all **expiry dates** —
and persist them, degrading gracefully on poor scans.

**Tasks:**
- Add a **field-extraction step** to `ProcessDocumentAction` immediately after OCR (Stage 2 → new Stage 2b).
- Parse `expiry_date`, `issue_date`, `document_number`, `business_name` from `ocr_extracted_text` using
  per-type regex rules modeled in `document_types.ocr_template_rules` (json). The Python side
  (`ocr_runner.py` / a new `extract_fields.py`) returns `{expiry_date, document_number, business_name, …}` —
  delivered in [MODEL_TRAINING_PHASES.md](./MODEL_TRAINING_PHASES.md) (it is **OCR + rules, no new ML model**).
- Compute `validity_status` from `expiry_date` vs today and the type's `renewal_window_days`
  (`valid` / `expiring_soon` / `expired`); **`unknown`** when extraction is low-confidence or no date is found.
- Store into the Phase P1 columns (`extracted_fields`, `expiry_date`, `issue_date`, `document_number`).

**Definition of Done:** a real upload of a permit with a printed expiry produces a `Document` /
`ValidationResult` row with `expiry_date` populated and a correct `validity_status`; a blurry scan yields
`validity_status = unknown` (never a false `valid`). Unit tests cover the regex parses and the date math
(incl. boundary: expires exactly today, within window, past).

---

## Phase P4 — Compliance engine (completeness, validity, resubmission)

**Goal:** Evaluate each submission against its subject's checklist and validity, and give officers a
**Request Resubmission** action — the human-in-the-loop loop the client asked for.

**Tasks:**
- **`Services/Compliance/ChecklistService`** — given a submission + its subject's requirement profile,
  compute **present / missing / expired** items; set `submissions.status = incomplete` when required items
  are missing or expired.
- **Validity integration** — surface `expiring_soon` / `expired` documents as **compliance flags**
  alongside (not inside) the composite risk score from [ADVS_System_Reference.md §5](../../ADVS_System_Reference.md).
- **Resubmission action** — officer "Request Resubmission" on a document/submission sets
  `resubmit_requested`, notifies the subject, and reopens upload for **only the flagged items**; vendor
  re-upload re-enters the pipeline. Every transition writes to `audit_logs` (G9).
- Keep ML **as-is**: signature/stamp/tamper/risk stay; compliance signals are additive.

**Definition of Done:** a submission missing a required type surfaces as `incomplete`; an expired permit
shows an `expired` flag; "Request Resubmission" transitions state, notifies, logs to audit, and a
targeted re-upload clears the flag. `php artisan test --filter=Compliance` (checklist completeness,
status transitions, resubmission flow) green.

---

## Phase P5 — Renewal scheduler (the calendar dimension)

**Goal:** Proactively flag expiring/expired credentials and send renewal reminders — the client's #1
and #2 pains. **This is the approved on-demand → calendar-driven departure.**

**Tasks:**
- A **scheduled command** (registered in [routes/console.php](../../routes/console.php) /
  `bootstrap/app.php`) runs **daily**: recompute `validity_status` across active documents; raise
  `expiring_soon` / `expired` notifications and `document_reminders` keyed on
  `expiry_date − renewal_window_days`.
- Reminder notifications reuse the existing in-dashboard + email notification layer
  ([ADVS_System_Reference.md §7](../../ADVS_System_Reference.md)); window is configurable per
  requirement-profile item (`renewal_window_days`) with a system-settings default.
- Idempotent: re-running the daily scan must not duplicate reminders for the same document/window.

**Definition of Done:** a document expiring within its window produces exactly one `expiring_soon`
notification + reminder; an expired document flips to `expired`; a second same-day run produces no
duplicates. Feature test drives the command with frozen time across the window boundary.

---

## Phase P6 — Hardening & integration verification

**Goal:** Prove the full path end-to-end and harden the seams (mirrors [CLAUDE.md §9c](../../CLAUDE.md)).

**Tasks:**
- Run the full pipeline (upload → queue → Python → DB) on representative real document types; confirm a
  `ValidationResult` with non-null fields each time.
- Confirm concurrent uploads don't collide on `python_payloads/*.json` (use `$documentId`/`$jobId` in temp names).
- Confirm `storage/app/documents/` is not HTTP-reachable; downloads stream through an ownership/role-checked controller.
- Failure handling: fail `ProcessDocumentJob` 3× → `Document.status = failed` + admin notification fires.
- Profile end-to-end time on a single-page PDF (reference §Performance target: median < 60 s).

**Definition of Done:** the manual click-through passes — vendor uploads an **expired** permit → officer
sees **expired + incomplete** flags → requests resubmission → vendor re-uploads → approval logged →
renewal reminder scheduled. `php artisan config:cache && route:cache && view:cache` succeed; suite green.

---

## Cross-cutting integration rules (do not regress)

- **Fail-forward.** Stages record flags; they do not abort on poor input. No early `throw`/abort branches
  the spec doesn't call for.
- **Signatures are verified, never enrolled in the pipeline.** The per-vendor 128-D reference is captured
  at registration; `ProcessDocumentAction` only computes Euclidean distance vs it.
- **Logo/stamp references are issuer-keyed, seeded on approval.** `document_types.issuer_scope` decides
  the key (`national` → by type; `lgu` → by type+city; `null` → skip). Unreferenced issuer → flag +
  `EnrollReferenceJob` only on officer approval.
- **Human-in-the-loop is mandatory.** The pipeline produces flags + a risk score; an officer makes the
  final call. Never auto-approve/reject — including on extracted fields.
- **Roles enforced in middleware**; document downloads ownership-checked.
- **Python is called only via the `Process` facade inside queued Jobs** — never from a controller.

---

## Verification (whole track)

- `php artisan migrate:fresh --seed` clean; factories valid.
- `php artisan test --compact` green — including new tests: expiration status transitions, checklist
  completeness, resubmission flow, real upload happy-path +
  `Queue::assertPushed(ProcessDocumentJob)`, renewal scheduler across the window boundary.
- Manual end-to-end click-through (the Phase P6 DoD scenario) passes.
- Python inference contracts exist and pass their tests (owned by [MODEL_TRAINING_PHASES.md](./MODEL_TRAINING_PHASES.md)).

[schema-first-modeling]: ../../CLAUDE.md
