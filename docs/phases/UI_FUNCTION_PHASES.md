# ADVS — Functions of UIs Phases

> **One of three concern-split phase plans.** This file owns the **UI / UX track**: what every screen
> does, per role, and the phased build that wires the currently **demo-backed** Livewire/Volt + Flux UI
> to **real persistence** while adding the **Negofood compliance-lifecycle** surfaces.
>
> Companions:
> - Pipeline integration → [PIPELINE_INTEGRATION_PHASES.md](./PIPELINE_INTEGRATION_PHASES.md)
> - Model training → [MODEL_TRAINING_PHASES.md](./MODEL_TRAINING_PHASES.md)
>
> Sources this plan integrates:
> - **Dashboard structure & behavior** — [ADVS reference](../ADVS_REFERENCE.md) (§4 navigation, §6 risk-score drill-down, §7 notifications).
> - **How we build the frontend** — [README.md](../../README.md) and [AGENTS.md](../../AGENTS.md).
> - **New client direction** — [CLIENT_INTERVIEW_GAP_PLAN.md](../CLIENT_INTERVIEW_GAP_PLAN.md) §4 (checklist + extracted-fields panels, lifecycle KPI cards, resubmission, requirement-profile CRUD).
> - **Theme tokens** — `resources/css/app.css` and the frontend rules in [AGENTS.md](../../AGENTS.md).
>
> **Build skills:** `fluxui-development`, `volt-development`, `tailwindcss-development`, `frontend-design`.

---

## Frontend conventions (do not deviate)

- **Flux-first.** Build from `<flux:*>` components (`input`, `button`, `heading`, `badge`, `table`,
  `modal`, …); drop to raw HTML only when no Flux component fits. No jQuery.
- **Livewire/Volt** for interactive/stateful UI — prefer **Volt single-file components** in
  `resources/views/livewire/…`, registered full-page via `Volt::route(...)` (mirror the settings pages).
  Full-page components render inside `components.layouts.app` (see [livewire-v4-layout-override] note).
- **Tailwind v4 utilities inline** in Blade — no `tailwind.config.js`, no inline `style=""`, no
  `@tailwindcss/forms` (Flux styles controls). New utility classes require `npm run dev`/`npm run build`.
- **Alpine ships bundled** with Livewire/Flux — small `x-data`/`x-on` only; never `import Alpine`.
- **Role-scoped nav.** Gate every nav item + action with the `role:` middleware and `@can`/`hasRole(...)`
  — vendors never see officer/admin items; officers never see admin-only panels (reference §3/§4).
- **Risk badges** are conditional: `<flux:badge :color="…">` driven by the risk band
  (Low 0–30 green / Medium 31–60 yellow / High 61–100 red).
- **Desktop-primary but mobile-safe** (`sm:` breakpoints). No mobile push (reference §7).

> **Migration discipline:** much of today's UI is driven by session-scoped demo data
> (`App\Support\VendorDemoData` / DemoStore). Each phase below **replaces the demo store with real models
> incrementally** — no big-bang rewrite. A screen isn't "done" until it reads/writes real persistence.

---

## Negofood additions to the UI (what's new vs the original design)

The original dashboards (reference §4/§6) covered submissions, risk scores, and decisions. The interview
adds **lifecycle** surfaces:

- **Lifecycle KPI cards:** Incomplete, Expiring soon, Expired, Due-for-renewal — beside pending/flagged/approval-rate.
- **Checklist panel:** present / missing / expired per required document type, from the vendor's requirement profile.
- **Extracted-fields panel:** business name, document number, issue/expiry dates — with a **red badge when expired**.
- **Request Resubmission** action: reopen upload for **only** the flagged items.
- **Admin CRUD** for requirement profiles + food-domain document types.

These render the data produced by [PIPELINE_INTEGRATION_PHASES.md](./PIPELINE_INTEGRATION_PHASES.md)
(field extraction P3, compliance engine P4, renewal scheduler P5).

---

## Current state

| Surface | Status | Notes |
|---|---|---|
| Flux app shell (sidebar/header) | ✅ Exists | Reusable `components/layouts/app`; nav not yet fully role-aware for new items. |
| Officer/admin screens | ⚠️ Demo-backed | Dashboard, pending queue, submission drill-down, archived, vendor profiles, risk logs, notifications, user mgmt, settings, retention, audit, ML model mgmt — all wired but driven by **demo data**, not real persistence. |
| Vendor portal | ⚠️ Demo-backed | submit / submissions / notifications / profile exist as demo flows. Vendor-only by design (no personnel portal — G5 descoped). |
| Compliance panels (checklist / extracted fields / lifecycle KPIs / resubmission) | ❌ Missing | Not built. |
| Risk-score drill-down (incl. Stage-T expand) | 🟡 Partial | Component breakdown design exists (reference §6); verify Stage-T five-technique expansion is surfaced. |

---

## Phase U0 — Role-aware shell, theme tokens & demo-store seam *(prerequisite)*

**Goal:** One consistent, role-scoped app shell and theme, plus a clean seam so screens can flip from
demo data to real models one at a time.

**Tasks:**
- Make the sidebar **role-aware** for all current + planned items (vendor / officer / admin),
  gated by `hasRole(...)` / `role:` middleware.
- Maintain the theme tokens in `resources/css/app.css`
  (`@theme`) so brand color/surface/gradient are reusable utilities (no per-component hex).
- Introduce a thin read-model boundary (e.g. a query/repository the views call) so swapping
  `VendorDemoData`/DemoStore for Eloquent is a localized change per screen, not a rewrite.

**Definition of Done:** every role sees only its own nav; theme tokens compile (`npm run build`) with no
console errors; a single screen is proven flippable demo→real without touching siblings;
`php artisan view:cache` succeeds.

---

## Phase U1 — Vendor portal

**Goal:** Vendors can submit, track lifecycle status, and respond to resubmission requests — on real
persistence. (Vendor-only; personnel/employee onboarding is out of scope — see gap plan G5.)

**Screens & functions** (reference §4 Vendor sidebar, extended):
- **Dashboard (Home):** summary cards (total / pending / approved / rejected) **+ checklist progress**
  ("4 of 6 required documents") and **expiry badges**; recent activity feed.
- **Submit Documents:** Flux file field / drag-and-drop with MIME preview + **10 MB client guard**
  (server-enforced in Pipeline P2). Format guidance; submission confirmation. The form is scoped to
  the vendor's requirement profile.
- **My Submissions:** table (date, document, status incl. `incomplete` / `resubmission_requested`,
  detail link); row → per-document statuses + **validity badges** (`valid`/`expiring_soon`/`expired`).
- **Resubmission affordance:** for `resubmit_requested` items, a focused re-upload of **only** the
  flagged documents (drives Pipeline P4's loop).
- **Notifications:** received / processed / flagged / decision / **renewal-reminder** alerts (reference §7).
- **Profile:** account settings, password, contact info. (Signature enrollment lives in registration, not here.)

**Definition of Done:** a vendor can submit real files (rows persist, job dispatched), see checklist
progress + expiry badges from real data, and complete a targeted re-upload. Demo store removed from
these screens. Volt/feature tests cover submit + resubmission.

---

## Phase U2 — Compliance Officer dashboard & review

**Goal:** The reviewer's cockpit — triage by risk **and** compliance, then drill down and decide
(human-in-the-loop, reference §5 Stage 6 / §6).

**Screens & functions** (reference §4 Officer sidebar + §6 drill-down, extended):
- **Dashboard (Home):** KPI cards — pending review, flagged today, approval rate **+ NEW** Incomplete,
  Expiring soon, Expired, and a **Due-for-renewal** list. Quick-access to the most urgent flagged submissions.
- **Pending Submissions:** queue sorted by risk (highest first); each row shows vendor,
  date, **color-coded composite risk score**, flag count, **and compliance flags** (incomplete/expired).
- **Validation Results (drill-down):** per-document breakdown — OCR text, classification + confidence,
  signature similarity, logo similarity (vs the **issuer** reference), Stage-T forensic authenticity, and
  the composite risk score. The risk row expands to the component table; the **Forensic Tampering** row
  expands into its five techniques (metadata/ELA/copy-move/font/cross-ref) with per-technique score and,
  for ELA/copy-move, highlighted suspect regions (reference §6).
  - **NEW — Checklist panel:** present / missing / expired per required type for this subject's profile.
  - **NEW — Extracted-fields panel:** business name, document #, issue/expiry dates — **red badge when expired**.
- **Actions:** **Approve** / **Reject** (with comment) **+ NEW Request Resubmission** (per document or
  submission). Approving a document that carries an **unreferenced issuer logo** triggers `EnrollReferenceJob`
  (reference §4b) — surface that this approval seeds the reference.
- **Archived Reports / Risk Logs / Notifications** per reference §4.

**Definition of Done:** an officer can triage by risk + compliance, open a report, see OCR/ML breakdown,
Stage-T expansion, checklist + extracted-fields panels, and Approve / Reject / Request Resubmission —
all writing real decisions + `audit_logs`. Decisions never auto-fire (human-in-the-loop preserved).

---

## Phase U3 — System Administrator surfaces

**Goal:** Admin manages people, the compliance taxonomy, thresholds, models, audit, and retention
(reference §4 Admin sidebar, extended for Negofood).

**Screens & functions:**
- **User Management:** CRUD accounts; assign/change roles; activate/deactivate; reset passwords.
- **NEW — Requirement Profiles:** CRUD named checklists per vendor category ("Food Supplier",
  "Beverage Distributor") and their items (`document_type`, `is_required`, `requires_expiry`, `renewal_window_days`).
- **NEW — Document Types (food domain):** manage the Negofood taxonomy + each type's `issuer_scope` and
  `ocr_template_rules` (keep in lockstep with the classifier classes in [MODEL_TRAINING_PHASES.md](./MODEL_TRAINING_PHASES.md)).
- **System Settings:** the tunable parameters from [ADVS reference §9](../ADVS_REFERENCE.md)
  (file limits, OCR floor, classification/signature/stamp thresholds, risk weights, tamper thresholds,
  **renewal-window default**).
- **ML Model Management:** per-model status, last-trained date, validation accuracy, file paths; trigger
  retrain / swap versions (metrics come from [MODEL_TRAINING_PHASES.md](./MODEL_TRAINING_PHASES.md)).
- **Audit Trail:** immutable, attributed, timestamped event log + CSV export (already strong). Ensure
  decisions **and resubmission requests** are logged (G9).
- **Data Retention:** retention/archival/purge policy config; audit logs never purged (reference §8).

**Definition of Done:** admin can CRUD requirement profiles + food document types and edit thresholds on
real persistence; changes are audit-logged; ML Model Management reflects real recorded metrics;
retention/audit/settings operate on real models (demo store removed from admin screens).

---

## Phase U4 — Notifications, polish & UI verification

**Goal:** Consistent notifications across roles and a verified, cache-safe UI.

**Tasks:**
- In-dashboard notifications for every reference §7 event **+** lifecycle events (expiring/expired,
  renewal reminder, resubmission requested/received); optional email toggles in System Settings.
- Accessibility + responsive pass (`sm:` breakpoints; focus states; Flux semantics).
- Empty/loading/error states for every list and panel; risk + validity badges consistent everywhere.

**Definition of Done:** the end-to-end click-through renders correctly on Chrome/Edge/Firefox with no
JS/CSS console errors; `npm run build` clean; `php artisan view:cache` passes; the scenario from
[PIPELINE_INTEGRATION_PHASES.md](./PIPELINE_INTEGRATION_PHASES.md) Phase P6 is visible end-to-end in the UI
(upload expired permit → officer sees expired + incomplete → request resubmission → re-upload → approval →
renewal reminder).

---

## Cross-cutting UI rules (do not regress)

- **Human-in-the-loop is visible:** the UI presents flags + risk; the officer always clicks the decision.
  No screen auto-approves/rejects.
- **Role scoping is absolute:** nav and actions gated; deep-linking to an out-of-role page returns 403.
- **Issuer-keyed logos, per-vendor signatures** are reflected accurately: Vendor Profiles show the
  per-vendor **signature** reference (enrolled at registration) but **not** a per-vendor stamp — logos
  live in the per-issuer reference library (reference §4 Vendor Profiles, §8).
- **Real data only at "done":** no screen ships still reading the demo store.

[livewire-v4-layout-override]: ../../AGENTS.md
