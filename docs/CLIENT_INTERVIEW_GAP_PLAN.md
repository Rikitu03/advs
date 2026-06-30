# ADVS — Client Interview Alignment Plan

> Deliverable: a comprehensive markdown plan committed to the repo (proposed path
> `docs/CLIENT_INTERVIEW_GAP_PLAN.md`) on branch `claude/client-interview-system-plan-hnfgav`.
> Lead/integration branch is **`staging`** — this plan is written against staging's state.

---

## Context — why this plan exists

A client interview with **Negofood Solution** (a growing food-service/operations company)
was conducted on 2026-06-29. The interview reveals that the **real-world problem** the
client needs solved differs in emphasis from what ADVS was originally designed around.

ADVS was built as an **ML fraud-detection pipeline** (ResNet-50 classification, YOLOv8
detection, Siamese signature verification, EfficientNet stamp/logo matching, Stage-T
tamper forensics → composite risk score). The client, however, was explicit:

> "**Expired or incomplete documents are more common than fake documents.**"
> "Automation should **support the reviewer** — it should flag issues, extract important
> information, and organize the workflow, but **final approval should still be done by an
> authorized person.**"

Their actual priorities are **compliance lifecycle management**: completeness checklists,
**expiration monitoring + renewal reminders**, **OCR field extraction** (esp. expiry dates),
centralized status tracking, a clean **resubmission** loop, an **audit trail**, and onboarding
of **both vendors and personnel/employees**. The food-business context also changes the
**document types** (Philippine LGU/food-safety permits) the system must recognize.

**Approved direction (confirmed with the user):**
1. **Keep the existing ML pipeline**, add a compliance lifecycle layer on top of it.
2. **Include personnel/employees now** via a generalized subject model.
3. This request's output is the **plan document only** (no feature code yet).

---

## How far along are we? (current state on `staging`)

| Area | Status | Notes |
|---|---|---|
| Auth + roles (Fortify, session) | ✅ Done | `vendor`, `compliance_officer`, `admin`; role middleware; signature-enroll step at registration. |
| Data model (vendor-centric) | ✅ Done | `vendors`, `document_types`, `submissions`, `documents`, `validation_results`, `vendor_embeddings`, `notifications`, `audit_logs`, `system_settings`, `tamper_analyses`, `logo_references`, `ml_models`, `retention_policies`. |
| Officer/admin UI (Volt + Flux) | ⚠️ Demo-backed | Dashboard, pending queue, submission drill-down, archived, vendor profiles, risk logs, notifications, user mgmt, settings, retention, audit, ML model mgmt — all wired but driven by **session-scoped demo data** (`App\Support\VendorDemoData` / DemoStore), not real persistence. |
| Vendor portal (Volt) | ⚠️ Demo-backed | submit / submissions / notifications / profile pages exist as demo flows. |
| Real upload → pipeline wiring | ❌ Missing | No `DocumentSubmissionController`; uploads don't persist `Document` rows or dispatch the job from the UI. |
| Pipeline orchestration (PHP) | 🟡 Partial | `ProcessDocumentAction`, `ProcessDocumentJob`, `RiskScoreService`, `TamperDetectionService`, `SignatureAuthenticityService` exist; not driven by a live upload. |
| Python inference scripts | 🟡 Partial | Present: training/dataset generators, `tamper_analyze.py`, `ocr_dryrun.py`. **Missing as named contracts:** `preprocess.py`, `ocr_runner.py`, `classify_document.py`, `signature_verify.py`, `stamp_verify.py`, `enroll_reference.py`. Trained model weights are gitignored/not present. |
| Admin tooling | ✅ Strong | System settings, audit trail + CSV export, data retention, ML model catalogue all built. |

**Bottom line:** The *fraud-detection scaffold* is well advanced (schema, services, admin
tooling, demo UI). The *end-to-end live pipeline* and — critically — **every feature the
client actually prioritized** (expiration, checklists, personnel, resubmission, renewals)
**do not exist yet.**

---

## Gap analysis — interview need vs. current build

| # | Client need (from interview) | Today | Gap |
|---|---|---|---|
| G1 | **Expiration monitoring** — flag expired permits; the #1 pain | No expiry/issue-date fields anywhere | New data fields + extraction + status logic |
| G2 | **Renewal reminders** — calendar-driven alerts before expiry | System is explicitly "on-demand, not calendar-driven" | New scheduler + reminder notifications |
| G3 | **Completeness checklist** per vendor/personnel type — "detect missing documents" | `document_types.is_required` boolean only | Requirement profiles + per-submission checklist state |
| G4 | **OCR field extraction** — business name, registration #, expiry, permit validity | OCR returns raw text + confidence; no structured fields | Structured field parsing + storage + display |
| G5 | **Personnel/employee onboarding** — IDs, health/food-handler certs, NBI, SSS/PhilHealth, contracts | Vendor-only | Generalized subject model + personnel doc types |
| G6 | **Resubmission loop** — "request resubmission" of missing/expired/unclear docs | Statuses: processing/pending_review/approved/rejected | New states + request-resubmission action + vendor re-upload |
| G7 | **Food-business document types** — Mayor's/Business Permit, BIR COR, DTI/SEC, Sanitary, FDA, food-handler | Seeded: BIR, GIS, Financial Stmt, Business Permit, Signed Contract | Re-seed/extend document types for the domain |
| G8 | **Centralized status visibility** — pending/incomplete/approved/rejected/due-for-renewal at a glance | Status exists but no "incomplete" / "due for renewal" | Add lifecycle statuses + dashboard surfacing |
| G9 | **Audit trail of who approved/rejected** | `audit_logs` table + viewer exist | Mostly satisfied — ensure decisions/resubmissions are logged |
| G10 | **Concerns: accuracy on blurry scans, false approvals, manual review** | Confidence scores + human decision already designed | Mostly satisfied — surface quality warnings clearly |

---

## What needs to change / be added

### 1. Data model & migrations

> Pattern: add new migrations (never edit shipped ones); add `casts()` + relationships
> on models; extend factories/seeders. Representative files:
> `database/migrations/`, `app/Models/`, `database/seeders/DocumentTypeSeeder.php`.

- **Generalize the subject (G5).** Introduce an `accreditation_subjects` concept so a
  submission belongs to either a **vendor** or a **personnel** record. Two viable shapes —
  recommend **(a)** for least churn:
  - (a) Keep `vendors`; add a `personnel` table; add `subject_type`/`subject_id`
    (polymorphic) to `submissions`. Vendor stays the default path.
  - (b) Rename to a unified `subjects` table with a `kind` enum. (Bigger refactor — defer.)
- **Expiration fields (G1/G4).** Add to `documents` (or `validation_results`):
  `issue_date` (nullable date), `expiry_date` (nullable date),
  `document_number` (nullable string), `extracted_fields` (json),
  and a derived `validity_status` (`valid` / `expiring_soon` / `expired` / `unknown`).
- **Checklist / requirement profiles (G3).** New tables:
  - `requirement_profiles` — a named checklist (e.g., "Food Supplier", "Kitchen Staff"),
    scoped by subject kind.
  - `requirement_profile_items` — links a profile to `document_types` with
    `is_required`, `requires_expiry`, `renewal_window_days`.
  - Per-submission completeness derived by comparing uploaded docs against the profile.
- **Lifecycle status expansion (G6/G8).** Extend `submissions.status` enum to include
  `incomplete` and `resubmission_requested`; add `documents` review states
  (`accepted`, `rejected`, `resubmit_requested`) and a `review_notes` field.
- **Renewal tracking (G2).** A lightweight `document_reminders` table (or reuse
  `notifications` + a scheduled scan) keyed on `expiry_date - renewal_window_days`.

### 2. Architecture & functions

- **Real upload pipeline (closes the demo gap).** Build `DocumentSubmissionController`
  (thin) + Form Request (MIME `jpeg/png/pdf`, ≤10 MB, server-side MIME sniffing) →
  persist `Submission` + `Document` rows → dispatch `ProcessDocumentJob`. Reuse the
  existing `ProcessDocumentAction` orchestrator.
- **Add a field-extraction stage** to `ProcessDocumentAction` (after OCR): parse
  `expiry_date`, `document_number`, `business_name` from `ocr_extracted_text` using
  per-type regex rules already modeled in `document_types.ocr_template_rules` (json).
  Store into the new `extracted_fields` / `expiry_date` columns.
- **Completeness service.** New `Services/Compliance/ChecklistService` — given a submission
  + its subject's requirement profile, computes missing / present / expired items. Feeds
  the `incomplete` status and the officer dashboard.
- **Expiration + renewal scheduler (G2).** A scheduled command (registered in
  `routes/console.php` / `bootstrap/app.php`) runs daily: recomputes `validity_status`,
  raises `expiring_soon` / `expired` notifications and reminder records. This is the one
  place ADVS becomes **calendar-driven** — a deliberate departure from
  `ADVS_System_Reference.md §1`, which must be documented as an approved change.
- **Resubmission action (G6).** Officer "Request Resubmission" on a document/submission →
  sets state, notifies vendor, reopens upload for just the flagged items; logged to
  `audit_logs`.
- **Keep ML as-is.** Signature/stamp/tamper/risk-score stay; the new compliance signals
  (missing, expired, low-OCR-quality) become **additional flags** surfaced alongside the
  existing composite risk score rather than replacing it.

### 3. Workflow changes

```
Vendor/Personnel submits
        │
        ▼
Intake → persist Submission + Documents
        │
        ▼
Pipeline: preprocess → OCR → FIELD EXTRACTION(new) → classify → signature/stamp/tamper
        │
        ▼
Compliance evaluation(new): completeness vs checklist + expiry check
        │
        ▼
Officer review queue  ── color-coded: risk score AND compliance flags
        │
   ┌────┼─────────────┐
   ▼    ▼             ▼
Approve Reject  Request Resubmission(new) ──► vendor re-uploads flagged items only
        │
        ▼
Decision logged (audit trail) ; if approved + expiry → schedule renewal reminder(new)
```

### 4. UI (Volt + Flux)

- **Officer dashboard:** add KPI cards for **Incomplete**, **Expiring soon**, **Expired**,
  and a "Due for renewal" list — alongside existing pending/flagged/approval-rate.
- **Submission drill-down:** add a **checklist panel** (present/missing/expired per required
  type) and an **extracted-fields panel** (business name, doc #, issue/expiry dates with a
  red badge when expired). Add the **Request Resubmission** action.
- **Vendor/personnel portal:** show checklist progress ("4 of 6 required documents"),
  expiry badges, and a re-upload affordance for resubmission-requested items.
- **Admin:** CRUD for **requirement profiles** and their items; food-domain document types.
- Wire the above to **real persistence**, replacing the demo store incrementally.

### 5. Document types (G7) — re-seed for the food domain

Replace/extend `DocumentTypeSeeder` with Negofood-relevant types, each tagged with
`requires_expiry` and `issuer_scope`:

- Mayor's / Business Permit (LGU, expires) · BIR Certificate of Registration (national) ·
  DTI or SEC registration (national) · **Sanitary Permit** (LGU, expires) ·
  **Food Handler Certificate** (expires) · **FDA registration/certificate** (national, expires) ·
  Valid Government ID · Supplier Accreditation Form · Bank details ·
  **Personnel:** NBI/Police Clearance (expires), Health/Medical Certificate (expires),
  SSS/PhilHealth/Pag-IBIG/TIN, Employment Contract.

### 6. Python scripts

- Implement the named inference contracts the orchestrator expects but that are missing:
  `preprocess.py`, `ocr_runner.py`, `classify_document.py`, `signature_verify.py`,
  `stamp_verify.py`, `enroll_reference.py` (per `CLAUDE.md §6` I/O contract).
- Extend `ocr_runner.py` (or a new `extract_fields.py`) to return structured
  `{expiry_date, document_number, business_name, ...}` driven by `ocr_template_rules`.
- No new ML models required for the compliance features — field extraction is OCR + rules.

---

## New requirements summary (for the thesis/SoP)

1. The system **monitors document validity** and **flags expired credentials**.
2. The system **sends renewal reminders** ahead of expiry (configurable window).
3. The system **tracks completeness** against a **per-subject-type checklist** and flags
   missing documents.
4. The system **extracts key fields** (name, registration #, dates) and shows them to the reviewer.
5. The system onboards **both vendors and personnel** with distinct requirement profiles.
6. The system supports a **resubmission loop** (request → re-upload flagged items only).
7. **Human officer makes the final decision**; every decision is **audit-logged**.
8. Document types reflect **Philippine food-business compliance** (LGU + food-safety permits).

---

## Suggested phasing (incremental, staging-first)

> **Now elaborated as three concern-split phase plans** — Phases A–E below are reorganized by concern and
> carried into them: backend/pipeline → [phases/PIPELINE_INTEGRATION_PHASES.md](phases/PIPELINE_INTEGRATION_PHASES.md),
> ML training → [phases/MODEL_TRAINING_PHASES.md](phases/MODEL_TRAINING_PHASES.md),
> UI functions → [phases/UI_FUNCTION_PHASES.md](phases/UI_FUNCTION_PHASES.md).

- **Phase A — Data & domain:** migrations (expiry fields, personnel, requirement profiles,
  status enums), models/relationships/factories, re-seed food document types. *DoD:*
  `migrate:fresh --seed` green; `php artisan test` green.
- **Phase B — Live pipeline:** `DocumentSubmissionController` + Form Request + real
  persistence + dispatch `ProcessDocumentJob`; add field-extraction stage. *DoD:* a real
  upload creates `Document` + `ValidationResult` with `expiry_date` populated.
- **Phase C — Compliance engine:** `ChecklistService`, completeness statuses, expiration
  recompute, resubmission action. *DoD:* incomplete/expired submissions surface correctly.
- **Phase D — Renewal scheduler:** daily command + reminder notifications. *DoD:* a doc
  expiring within the window produces a notification.
- **Phase E — UI wiring:** replace demo store with real data; checklist/extracted-fields
  panels; officer + vendor/personnel views. *DoD:* end-to-end click-through works.

---

## Risks & considerations

- **Conceptual departure:** `ADVS_System_Reference.md §1` states the system is
  "on-demand, not calendar-driven." Renewal reminders introduce a calendar dimension —
  update the reference doc and flag the conflict per `CLAUDE.md`'s precedence rule.
- **OCR accuracy on blurry scans** (a stated client concern): expiry extraction must
  degrade gracefully — `validity_status = unknown` + a quality warning, never a false
  "valid". Never auto-approve on extracted fields; reviewer confirms.
- **Demo-store coupling:** much current UI depends on `VendorDemoData`/DemoStore; plan to
  migrate views to real models incrementally to avoid a big-bang rewrite.
- **Personnel = PII:** health certs, NBI, IDs are sensitive — keep under
  `storage/app/documents/` (private), honor retention policies, role-gate access.
- **Scope discipline:** ML pipeline stays as-is this round; resist re-tuning models.

---

## Verification (for the eventual implementation phases)

- `php artisan migrate:fresh --seed` runs clean; new factories produce valid records.
- `php artisan test --compact` green, including new tests:
  expiration status transitions, checklist completeness, resubmission flow,
  personnel submission, real upload happy-path + `Queue::assertPushed(ProcessDocumentJob)`.
- `pytest python/tests/ -v` green for new inference + field-extraction scripts.
- Manual click-through: vendor upload (expired permit) → officer sees "expired" +
  "incomplete" flags → requests resubmission → vendor re-uploads → approval logged →
  renewal reminder scheduled.

---

## Status of this document

This is a **planning document only** — no feature code accompanies it. It captures the
gap between the current build and the Negofood Solution interview, and lays out Phases A–E
for the actual implementation. Implementation begins once this plan is reviewed/approved.
