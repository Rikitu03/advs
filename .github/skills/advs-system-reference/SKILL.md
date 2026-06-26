---
name: advs-system-reference
description: "ACTIVATE when implementing or reasoning about ADVS domain logic — the Automated Document Validation System for vendor accreditation. Trigger when working on the document-validation pipeline (preprocessing, OCR, classification, signature/stamp detection and verification, enrollment), risk-score computation or thresholds, ResNet-50 / YOLOv8 / Siamese CNN / EfficientNet inference, OpenCV/PyTesseract/pdf2image scripts in python/, document upload constraints (MIME, size, multi-page PDFs), vendor/compliance-officer/admin roles and permissions, the dashboard sidebars and risk-score drill-down, validation reports, notifications, embedding storage, data retention, or any tunable system parameter (BINARIZATION_THRESHOLD, STAMP_SIMILARITY_THRESHOLD, RISK_WEIGHT_*, etc.). Also activate when the user mentions ADVS, vendor accreditation, validation pipeline, risk score, document classification, signature verification, stamp authentication, or references ADVS_System_Reference.md, ProcessDocumentJob, ProcessDocumentAction, ValidationReport, SignatureEmbedding, or StampFeatureVector. Do NOT activate for generic Laravel/Livewire/Tailwind work with no document-validation domain element."
license: MIT
metadata:
  author: advs-team
---

# ADVS System Reference

The authoritative specification of **what the Automated Document Validation System does** lives in [`ADVS_System_Reference.md`](../../../ADVS_System_Reference.md) at the project root. This skill points you there and tells you which section to read for the task at hand.

> **Division of responsibility**
> - `CLAUDE.md` and `AGENTS.md` define **how to build** — stack, versions, conventions, folder structure, phases.
> - `ADVS_System_Reference.md` defines **what to build** — pipeline behavior, the risk-score formula, role permissions, dashboard layout, notifications, storage, and the complete tunable-parameter table.
>
> Read the relevant reference section **before** writing domain logic, so behavior matches the thesis design. On a stack/version detail, prefer `CLAUDE.md`; on product behavior, prefer `ADVS_System_Reference.md`; if they conflict, flag it.

## Section map — read before you build

| Task | Section in `ADVS_System_Reference.md` |
|---|---|
| Upload validation, accepted MIME types, size caps, multi-page PDF handling (first 2 pages, 300 DPI) | `§2` File Upload Constraints |
| Authentication, the three roles, what each can/cannot access, `role:` middleware | `§3` Access Control and User Roles |
| Sidebar navigation, role-scoped tabs, dashboard pages | `§4` Dashboard Navigation Structure |
| Stage 0 intake & queue dispatch | `§5` Stage 0 |
| OpenCV preprocessing (grayscale → binarize 150 → morph-open 2×2 → invert) | `§5` Stage 1 |
| PyTesseract OCR, NLP cleanup, template/field validation | `§5` Stage 2 |
| ResNet-50 classification (512×512 input, confidence threshold) | `§5` Stage 3 |
| YOLOv8 signature/stamp detection, crop routing, no-detection handling | `§5` Stage 4 |
| Siamese CNN signature verify — reference enrolled at **registration** (128-D embedding, Euclidean distance) | `§5` Stage 4a |
| EfficientNet logo verify vs the **issuer's** reference — `document_types.issuer_scope` (national by doc type; lgu by doc type+city) (feature vector, cosine similarity, 85%) | `§5` Stage 4b |
| Composite risk-score formula, weights, missing-component penalty, report fields, risk levels | `§5` Stage 5 |
| Officer review, approve/reject, human-in-the-loop, audit | `§5` Stage 6 |
| Risk-score drill-down UI (component breakdown, expandable rows) | `§6` Risk Score Drill-Down |
| In-dashboard + email notifications, event→recipient table | `§7` Notification and Alerting |
| Document storage paths, reference embeddings, model files, retention | `§8` Database and Storage |
| **Any tunable parameter and its default** | `§9` Complete Parameter Reference |
| End-to-end actor/system/officer flow | `§10` End-to-End Flow Summary |

## Working rules

- **Never invent thresholds or weights.** Pull defaults from `§9` (e.g. `BINARIZATION_THRESHOLD=150`, `CLASSIFICATION_CONFIDENCE_THRESHOLD=0.70`, `STAMP_SIMILARITY_THRESHOLD=0.85`, `RISK_WEIGHT_*=0.25`, `MISSING_COMPONENT_PENALTY=15`, risk bands Low 0–30 / Medium 31–60 / High 61–100). Treat them as configurable, not hard-coded magic numbers.
- **The pipeline is fail-forward.** Stages do not abort on poor input — they record a *flag* that feeds the composite risk score (blank scan, low OCR, low classification confidence, no signature/stamp detected). Preserve this behavior; don't add early `throw`/abort branches that the spec does not call for.
- **Signatures are verified, never enrolled in the pipeline.** The per-vendor 128-D reference is captured at **registration** (`§5` 4a), so it always exists by submission time — `ProcessDocumentAction` only computes Euclidean distance vs it. Do not add a "first submission auto-enrolls signature" branch.
- **Logo/stamp references are keyed by issuer, seeded on approval.** There is **no per-vendor stamp embedding** (`§5` 4b). Stage 3 gives the document type → `document_types.issuer_scope` decides the key: `national` (BIR/SEC) → by document type; `lgu` → by (document type, OCR city from `§5` Stage 2); `null` → no logo check. The pipeline verifies against the **issuer's** reference logo in `logo_references`; an issuer with no reference yet → `Unknown / unreferenced logo` flag, seeded only when an officer approves the first document carrying that issuer's logo (`EnrollReferenceJob`). The tamper check runs regardless.
- **Human-in-the-loop is mandatory.** The ML pipeline only produces flags and a risk score; a compliance officer makes the final approve/reject call (`§5` Stage 6). Do not auto-approve/reject.
- **Roles are enforced in middleware**, and nav is role-scoped (`§3`, `§4`) — vendors never see officer/admin items.

When a task spans both "how" and "what", cross-check the matching framework skill (`fortify-development`, `fluxui-development`, `volt-development`, `tailwindcss-development`, `laravel-best-practices`) alongside the reference section above.
