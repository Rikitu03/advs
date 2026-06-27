# AGENTS.md — ADVS (Automated Document Validation System)

> Orientation for AI coding agents working in this repo. It records **what is actually built today**
> and how to work here. Three docs, three jobs:
>
> - **This file** = ground truth on the *current state* of the code. When docs disagree about what
>   exists right now, this file wins.
> - [`CLAUDE.md`](CLAUDE.md) = the *intended* stack, conventions, and 10-phase build plan. Trust it for
>   **conventions and target design**, but note parts are **aspirational** and a few facts are stale
>   (see [Landmines](#9-landmines)). The Laravel Boost guidelines at the bottom of *this* file are the
>   same auto-managed block that appears in `CLAUDE.md`.
> - [`ADVS_System_Reference.md`](ADVS_System_Reference.md) = the product/domain spec: *what the system
>   does* — pipeline stages, composite risk-score formula, role permissions, dashboards, notifications,
>   storage, and the **complete tunable-parameter table (`§9`)**. Read the relevant section before
>   implementing any domain logic (the `advs-system-reference` skill also auto-activates):
>
>   | Working on… | Read |
>   |---|---|
>   | Upload validation, MIME/size, multi-page PDFs | `§2`, `§9` |
>   | Auth, roles, route gating (`role:`) | `§3` |
>   | Sidebars, dashboards, role-scoped nav | `§4` |
>   | Python pipeline stage / Service / Job | `§5` (Stages 0–6) |
>   | Risk-score weights & thresholds | `§5` Stage 5, `§6`, `§9` |
>   | Signature / stamp verification & enrollment | `§5` Stages 4–4b |
>   | Notifications & alerting | `§7` |
>   | Migrations, storage paths, embeddings, retention | `§8` |

ADVS is a desktop-first web app for **vendor accreditation**: vendors upload accreditation documents
(BIR permits, financial statements, business registrations); a planned ML pipeline classifies them, runs
OCR, and verifies signatures/stamps to produce a **risk score**; compliance officers review and
approve/reject. Academic thesis project (Pamantasan ng Lungsod ng Pasig, CCS).

---

## 1. Tech stack (as installed)

| Layer | Tech | Version | Notes |
|---|---|---|---|
| Backend | PHP / Laravel | 8.2 / 12.x | Laravel 12 slim structure — middleware/providers in `bootstrap/app.php`, no `Http/Kernel.php` |
| Auth | Laravel Fortify | ^1.37 | Session / `web` guard. **Not JWT.** |
| Reactivity | Livewire + Volt | 4.x / 1.x | Full-page **Volt single-file components** for most pages |
| UI | Livewire Flux (free) | 2.x | `<flux:*>` components first; Alpine ships bundled — never `import Alpine` |
| CSS | Tailwind CSS | 4.x | Config in `resources/css/app.css` (`@theme`), **no `tailwind.config.js`**, no `@tailwindcss/forms` |
| Build | Vite | 6.x | `npm run dev` / `npm run build` |
| DB (app) | MySQL | 8.0+ | Database `advs`. Tests use sqlite `:memory:` |
| Tests | PHPUnit | 11.x | sqlite `:memory:`, `QUEUE=sync`, `MAIL=array` (in `phpunit.xml`) |
| Format | Laravel Pint | ^1.18 | **Run `vendor/bin/pint --dirty` before finalizing PHP changes** |
| Python (ML) | python.org 3.12 + TF 2.16 | — | venv at `python/env/` (see [§5](#5-python-ml--training-only-today)) |

Environment is **Windows 11 + XAMPP**. Primary shell is PowerShell; a Bash tool is also available.

---

## 2. Current development state (read this first)

The repo is roughly **"auth + full UI prototype, ML pipeline not started."**

### ✅ Built and real (DB-backed, tested)
- **Authentication (Fortify)** — login, registration, password reset, email verification, password
  confirmation. Custom Flux Blade views in `resources/views/auth/*`, wired via `FortifyServiceProvider`.
- **Two-step vendor registration** — account creation → **reference-signature enrollment**
  (`resources/views/livewire/auth/signature-enroll.blade.php`) → email verification. Enforced by the
  `EnsureSignatureEnrolled` middleware (appended to the whole `web` group). The verification email is
  *suppressed* until the signature step completes (`User::sendEmailVerificationNotification()`).
- **Role-based access** — roles `vendor`, `compliance_officer`, `admin`; `role:` middleware alias;
  `/dashboard` dispatcher → `User::dashboardRoute()`.
- **Admin: User Management** — `UserController` + `UserPolicy` + Form Requests, real Eloquent CRUD,
  self-demote/self-delete guards.
- **Admin: System Settings** — `SystemSetting` model + `SystemSettingsService`; all tunable thresholds
  editable in-app, seeded by `SystemSettingSeeder`.
- **Admin: Audit Trail** — append-only `AuditLog` model (no `updated_at`), filter/search/CSV export.
- **Theming** — custom light/dark theme (`cu-*` design tokens, `theme` cookie, `theme-toggle`,
  preference settings) with a **large dedicated test suite** under `tests/Feature/Theme/`.

### 🟡 Built as UI prototype on DEMO DATA (no DB, no ML)
The **compliance-officer/admin review dashboards** and the **vendor portal** are fully designed and
interactive, but backed by static/session fixtures, **not** the database or any model output:
- `App\Support\DemoData` — static officer-side submissions, vendors, KPIs, risk logs, notifications.
- `App\Support\DemoStore` — **session-backed** mutable overlay so approve/reject decisions, notification
  read-state, and vendor accreditation side-effects persist for the demo session only.
- `App\Support\VendorDemoData` — static vendor-portal submissions/notifications.
- The vendor "Submit Documents" page validates client-side (Alpine) and flips a `$submitted` flag —
  **it does not store a file or dispatch a job.**

Wiring the real pipeline means **replacing these `Support\*` classes with Eloquent-backed
services/models** while keeping each view's data shape intact (views read arrays with the same keys).

### ❌ Not built yet (despite being described in CLAUDE.md / the reference)
- **No ML inference Python scripts.** `preprocess.py`, `ocr_runner.py`, `classify_document.py`,
  `signature_verify.py`, `stamp_verify.py`, `enroll_reference.py` **do not exist.** Only **training**
  scripts exist (see §5).
- **No queued Jobs** (`ProcessDocumentJob`, `EnrollReferenceJob`, …), **no `ProcessDocumentAction`**,
  **no document/OCR/classification/verification Services**, **no `Process`-facade calls** to Python.
- **No Eloquent models** for the pipeline tables. Migrations exist for `vendors`, `documents`,
  `submissions`, `validation_results`, `vendor_embeddings`, `document_types`, `notifications`, but the
  only models in `app/Models/` are `User`, `AuditLog`, `SystemSetting`. The pipeline tables have **no
  models, factories, or relationships yet.**
- `App\Services\Signature\SignatureAuthenticityService` is a **mock** (flags "forged" only when the
  uploaded filename matches `/edited|forged|fake|tampered/i`).

Current branch: `staging`. Main: `main`.

---

## 3. Architecture & where things live

```
app/
├── Actions/Fortify/         registration/profile/password logic (Fortify owns auth)
├── Console/Commands/        PruneAbandonedRegistrations (cleans unverified, un-enrolled signups)
├── Http/
│   ├── Controllers/Admin/   UserController, SystemSettingsController, AuditLogController (thin CRUD)
│   ├── Middleware/           EnsureUserHasRole ("role:"), EnsureSignatureEnrolled
│   ├── Requests/Admin/       Form Requests for user + settings mutations
│   └── Responses/            Login/Register response overrides (skip /dashboard hop, route to signature step)
├── Models/                  User, AuditLog, SystemSetting   ← ONLY these (pipeline models TBD)
├── Policies/                UserPolicy, AuditLogPolicy, SystemSettingPolicy
├── Providers/               FortifyServiceProvider (views, rate limiters), AppServiceProvider, VoltServiceProvider
├── Services/                SystemSettingsService, Signature\SignatureAuthenticityService (MOCK)
└── Support/                 DemoData, DemoStore, VendorDemoData   ← prototype data layer (replace later)

resources/views/livewire/    Volt full-page components:
├── admin/                   dashboard, pending, archived, risk-logs, notifications, submissions/show,
│                            vendors/{index,show}, users/*, settings/index, audit/index
├── vendor/                  dashboard, submit, submissions, notifications, profile
├── auth/signature-enroll    step 2 of registration
└── settings/                profile, password, preference, appearance
resources/css/app.css        Tailwind v4 @theme + cu-* tokens + dark overrides + cu-animate-in/skeleton
routes/web.php               all app routes (auth routes come from Fortify)
bootstrap/app.php            middleware aliases + web-group appends + `theme` cookie excluded from encryption
config/livewire.php          component_layout → components.layouts.app (REQUIRED — see Landmines)
config/fortify.php           features + guard/home
python/                      training scripts + venv (see §5)
database/migrations/         users + 9 ADVS tables (2026_06_07_*) + signature columns on users
```

**Routing:** Fortify owns auth routes (don't redefine). App routes use named-route helpers, grouped by
`role:` middleware. Most pages are `Volt::route(...)`; only admin CRUD needing redirect/streaming
responses (user create/update/delete, settings reset, audit export/show) uses thin controllers.

---

## 4. ML pipeline (design intent — to be implemented)

Documented in `ADVS_System_Reference.md` §5 and `CLAUDE.md` §6–§7. Target so a new agent knows the shape
before building any of it:

**Four models, four independent signals:**
1. **ResNet-50** → document **classification** (`bir_permit`, `financial_statement`, `business_registration`, `fake`, …).
2. **YOLOv8** → **detect** signature / stamp regions (bounding boxes).
3. **Siamese CNN** → **signature** verification: 128-D embedding, **Euclidean distance** vs the vendor's reference (enrolled at **registration**, not on first submission → pipeline always *verifies*).
4. **EfficientNet** → **stamp/logo** verification: feature vector, tamper check (always) + **cosine similarity** vs the **issuer's** reference logo — keyed by `document_type` (national: BIR/SEC) or `(document_type, city)` (LGU), per `document_types.issuer_scope`; **not** per vendor; seeded on first officer approval for that issuer.

**Stages:** intake → `preprocess` (grayscale → binarize → morph-open → invert) → `ocr` (pytesseract,
`--psm 6`) + text-field validation → `classify` → `detect` → `verify` signature/stamp → compose risk →
officer decision. PDFs: convert **first 2 pages** at **300 DPI** via pdf2image.

**Risk score** (0–100 composite; weights/thresholds live in `system_settings`, seeded defaults):
- weights: text `0.25`, classification `0.25`, signature `0.25`, stamp/logo `0.25`
- `missing_component_penalty = 15` per absent required component
- bands: **High ≥ 61**, **Medium ≥ 31**, else Low
- gates: `classification_confidence ≥ 0.70`, `yolo_detection_confidence ≥ 0.50`,
  `signature_distance ≤ 1.20`, `stamp_similarity (cosine, vs issuer reference) ≥ 0.85`

**Integration contract (planned):** Laravel calls Python only via the `Process` facade, inside queued
Jobs, behind Services — never from controllers. Scripts use `--input <json>` / `--output <json>` and
exit non-zero on failure (`CLAUDE.md` §6 has the I/O table + `Process::run` wrapper). None of this exists
yet; build it on the migrations already in place (§6).

---

## 5. Python (ML) — training only today

- **Use the right interpreter.** The working venv is `python/env/Scripts/python.exe`
  (**python.org 3.12.10**, full TF/Ultralytics stack installed and verified). Do **not** use a bare
  `python` (resolves to an MSYS2 build with no ML wheels) or `py` (3.14 — too new for TensorFlow).
- **TensorFlow is 2.16.x, not 2.15** (`CLAUDE.md` pins 2.15, but 2.15 has no 3.12 wheels; 2.16 defaults
  to Keras 3). `onnx` is pinned `<1.17` (1.17+ conflicts with TF 2.16's `ml-dtypes`). See
  `python/requirements.txt` header for the full rationale.
- **What exists:** `python/scripts/train_classifier.py` (ResNet-50), `train_detector.py` (YOLOv8),
  `train_signature.py` (Siamese). Each supports `--dry-run` (validate data layout, stdlib only),
  `--smoke` (1-epoch tiny CPU run), and full training. Data layout is `python/data/{training,validation}/...`.
  Training scaffolding/fixtures: `python/.claude/skills/run-advs-training/` (`driver.py`, `make_fixtures.py`).
- **What's missing:** all *inference* scripts and `python/utils/` (`model_loader.py`, `image_utils.py`,
  `json_io.py`). Trained weights (`python/models/*.h5`, `*.pt`) are gitignored and not present.

---

## 6. Database schema & rationale

`php artisan migrate:fresh --seed` builds everything. The ADVS tables (`database/migrations/2026_06_07_*`)
intentionally **differ from `CLAUDE.md` §4's earlier sketch** — they follow a later canonical design
(migration docblocks cite "`ADVS_Final_Schema.sql §N`"). Key decisions:

| Table | Purpose / rationale | vs CLAUDE.md §4 |
|---|---|---|
| `vendors` | One company profile per vendor user (`user_id` unique). `status`: pending/under_review/approved/rejected. | ~same |
| `document_types` | **Lookup table** of supported categories (`code`, `ocr_template_rules` JSON). Makes types data-driven instead of hardcoded. | new |
| `submissions` | **The review unit** — a batch of documents uploaded in one accreditation request. Holds `composite_risk_score`, `risk_level`, `reviewed_by`, decision fields. | review attaches here (batch), not per-document |
| `documents` | **One row per uploaded file** with its own `processing_status` enum (queued→preprocessing→ocr→classifying→detecting→verifying→completed/failed) + `converted_image_path`. | replaces `documents.status`; per-file pipeline state |
| `validation_results` | **1:1 with `documents`** (`document_id` unique). All ML outputs in one wide row, **every stage nullable** (stages can be skipped). | replaces planned `validation_reports`; per-document, flatter |
| `vendor_embeddings` | Per-vendor **signature** reference (128-D JSON), with enrolled-at timestamp + image path. Populated at **registration**. The `stamp_*` columns are **superseded** — logo references are now per-**city**, not per-vendor (see below). | holds signature ref; stamp columns legacy |
| `logo_references` | Per-**issuer** logo/stamp/seal reference vector, **unique(`document_type_id`, `city`)**. `city` is **NOT NULL** — `''` sentinel for national issuers (BIR/SEC), the city name for LGU — so the unique index enforces one logo per national document type (a nullable city would not, MySQL NULLs being distinct). Provenance (`seeded_from_document_id`, `enrolled_by`); seeded on first officer-approved doc for that issuer. Scope from `document_types.issuer_scope` (`lgu`/`national`/null). | built 2026-06-26; replaces per-vendor `stamp_feature_vectors` / `vendor_embeddings.stamp_*` (dropped) |
| `notifications` | Targeted, typed in-app alerts (`type` enum, `related_submission_id`). Custom table (not Laravel's notifications table). | new |
| `audit_logs` | **Append-only**: `created_at` only (no `updated_at`), polymorphic `entity_type`/`entity_id`, JSON `details`, `nullOnDelete` user. | new |
| `system_settings` | **Key/value** store of every tunable threshold; values stored as strings, typed on read via `SystemSetting::int/float/bool`. | new |
| `users` (+ later migration) | Adds `role`, plus `signature_path` + `signature_enrolled_at` for enrollment. | role + signature enrollment |

**Reminder:** only `users`, `audit_logs`, `system_settings` have models. Before creating models for the
other tables, **inspect the live schema first** (read the migration + `php artisan db:table <t>` /
`model:show`) so casts, enums, nullability, and FK `onDelete` behavior match exactly.

---

## 7. Conventions (follow the existing code)

- **Make files with Artisan**, `--no-interaction`: `php artisan make:model Foo -mf`, `make:test --phpunit`,
  `make:class`, etc. Don't hand-roll boilerplate.
- **PHP style:** curly braces always; constructor property promotion; explicit param + return types;
  PHPDoc array shapes over inline comments. **Run `vendor/bin/pint --dirty` before finishing.**
- **Volt components:** single-file, full-page. Existing components use the **class-based** style
  (`new class extends Component { ... }; ?>` then Blade). Match siblings.
- **Flux first** for UI (`<flux:input>`, `<flux:button>`, `<flux:badge :color>`, `<flux:navlist>`); raw
  HTML only when no component fits. **No inline `style=""`.** Apply Tailwind utilities directly.
- **Theme tokens:** use the `cu-*` semantic utilities (`bg-cu-surface`, `text-cu-text`, `text-cu-muted`,
  `border-cu-border`, `cu-gradient`, `cu-animate-in`) so light/dark both work. Guard tests in
  `tests/Feature/Theme/` fail if a view uses raw colors where a token is expected.
- **Routes:** always named-route helpers (`route('admin.pending')`), never hardcoded URLs.
- **Roles:** `vendor`, `compliance_officer`, `admin` only. Gate with `role:` middleware or a Policy, not
  inline `if ($user->role === ...)`. There is **no `risk_manager`** role.
- **Frontend changes** only appear after `npm run dev`/`npm run build` (Tailwind v4 JIT).

---

## 8. Testing

PHPUnit class-based tests in `tests/Feature` and `tests/Unit`, isolated from MySQL via sqlite `:memory:`
+ `QUEUE=sync` + `MAIL=array` (`phpunit.xml`).

```bash
php artisan test --compact                                  # whole suite
php artisan test --compact --filter=DashboardTest           # one class
php artisan test --compact tests/Feature/Admin/UserManagementTest.php
```

Coverage today spans: auth (`Auth/*`), role routing (`DashboardTest`), **signature enrollment**
(`Auth/SignatureEnrollmentTest`), registration email-failure handling, the abandoned-registration prune
command, the vendor portal, **officer review/workflow** (`OfficerReviewTest`, `Admin/OfficerWorkflowTest`),
admin user/settings/audit, account settings, and a broad **theme** suite (`tests/Feature/Theme/*`).

**Every change must be covered** — add/extend a test and run the affected file/filter before finishing.
Don't delete existing tests without approval. The planned pipeline target is
`tests/Feature/Document/DocumentSubmissionTest.php` (upload happy-path, 422 bad MIME/size,
`Queue::assertPushed(ProcessDocumentJob::class)`).

---

## 9. Landmines

1. **`CLAUDE.md` mixes "built" and "planned," and a few facts are stale.** It still references JWT/email-OTP
   auth (reality: Fortify session auth + custom signature step), `validation_reports`/`signature_embeddings`/
   `stamp_feature_vectors` tables (superseded — see §6), and inference Python scripts that **don't exist.**
   Verify against this file and the actual code before relying on it.
2. **Officer & vendor dashboards run on `Support\Demo*`, not the database.** Approve/reject "works" only via
   the **session** (`DemoStore`) and resets when the session clears. Don't assume DB persistence or ML output.
3. **Pipeline tables have migrations but no models.** Creating `Submission`/`Document`/`ValidationResult`/
   `Vendor`/`VendorEmbedding` models is net-new — inspect the schema first.
4. **`SignatureAuthenticityService` is a mock** triggered by filename. Real forensic/Python verification is unbuilt.
5. **Python interpreter trap:** use `python/env/Scripts/python.exe`. Bare `python` (MSYS2) and `py` (3.14)
   both lack the ML stack. TF is **2.16**, not 2.15; `onnx<1.17`.
6. **`config/livewire.php` `component_layout` override is load-bearing.** Without it, full-page Volt
   components throw *"No hint path defined for [layouts]"* under Livewire v4.
7. **The `theme` cookie is excluded from encryption** in `bootstrap/app.php` (the layout reads it
   server-side for first-paint dark mode). Don't re-encrypt it or first-paint theming breaks.
8. **`EnsureSignatureEnrolled` runs on the whole `web` group.** A vendor without `signature_enrolled_at` is
   redirected to `signature.create` everywhere except `signature.*`, `logout`, and Livewire's endpoints
   (`livewire.*`, `default-livewire.*`). Seeded vendors are pre-enrolled with a placeholder path.
9. **Tailwind v4 has no `tailwind.config.js`.** Configure via `@theme`/`@utility` in `resources/css/app.css`.
   New utility classes need a rebuild to appear.
10. **Windows/XAMPP host.** Mind path separators; `php artisan serve` + `npm run dev` + a queue worker are
    separate processes — `composer run dev` runs all three concurrently.

---

## 10. Quick commands

```bash
composer run dev                 # serve + queue:listen + vite (concurrently)
php artisan migrate:fresh --seed # rebuild DB + seed role accounts, doc types, settings, audit
php artisan test --compact       # run tests
vendor/bin/pint --dirty          # format changed PHP (run before finalizing)
npm run build                    # production asset build

# Python (training) — from python/, using the bundled venv:
python/env/Scripts/python.exe scripts/train_classifier.py --dry-run
```

**Seeded accounts** (password `password`, all pre-verified): `vendor@advs.test` (vendor),
`officer@advs.test` / `officer2@advs.test` (compliance_officer), `admin@advs.test` / `admin2@advs.test` (admin).

---

> The Laravel Boost guidelines below are auto-managed by `php artisan boost:update` — keep this
> orientation content **above** the `<laravel-boost-guidelines>` block so updates don't clobber it.

---

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.2
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- livewire/flux (FLUXUI_FREE) - v2
- livewire/livewire (LIVEWIRE) - v4
- livewire/volt (VOLT) - v1
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- phpunit/phpunit (PHPUNIT) - v11
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== volt/core rules ===

# Livewire Volt

- Single-file Livewire components: PHP logic and Blade templates in one file.
- Always check existing Volt components to determine functional vs class-based style.
- IMPORTANT: Always use `search-docs` tool for version-specific Volt documentation and updated code examples.
- IMPORTANT: Activate `volt-development` every time you're working with a Volt or single-file component-related task.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

</laravel-boost-guidelines>
