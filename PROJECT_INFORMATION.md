# ADVS Project Information

This document aggregates all documentation files across the ADVS project, organized thematically.

## Table of Contents

- **Project Overview and Guidelines**
  - AGENTS.md
  - README.md
  - START_ADVS.md
  - advs_video/README.md
  - python/README.md
  - python/data/README.md
  - python/tests/fixtures/signature_data/README.md
- **Reference and Implementation**
  - docs/ADVS_REFERENCE.md
  - error_pages_implementation.md
- **Project Phases and Planning**
  - docs/CLIENT_INTERVIEW_GAP_PLAN.md
  - docs/phases/MODEL_TRAINING_PHASES.md
  - docs/phases/PIPELINE_INTEGRATION_PHASES.md
  - docs/phases/UI_FUNCTION_PHASES.md
  - python/DEVELOPMENT_PHASES.md
- **Superpowers and Specs**
  - docs/superpowers/plans/2026-06-18-registered-name-positional-fuzzy.md
  - docs/superpowers/plans/2026-06-19-bir-synthetic-dataset-generator.md
  - docs/superpowers/plans/2026-06-22-theme-consistency-and-search-bar-fill.md
  - docs/superpowers/plans/2026-06-28-business-permit-classifier-dataset.md
  - docs/superpowers/plans/2026-07-01-vendor-registration-update.md
  - docs/superpowers/plans/2026-07-15-e2e-submission-wiring.md
  - docs/superpowers/plans/2026-08-12-stage3-4b-pipeline-gaps.md
  - docs/superpowers/specs/2026-07-15-e2e-submission-wiring-design.md
- **Archive**
  - docs/archive/CLAUDE_LEGACY.md
  - docs/archive/COLOR_PALETTE_LEGACY.md
  - docs/archive/HUGGINGFACE_DEPLOYMENT_LEGACY.md
  - docs/archive/SPRINT_GUIDE_LEGACY.md
  - docs/archive/TRAINING_SCRIPT_LEGACY.md
- **Other**
  - advs_video/DEMO_SCRIPT.md
  - advs_video/src/skills/3d.md
  - advs_video/src/skills/charts.md
  - advs_video/src/skills/messaging.md
  - advs_video/src/skills/sequencing.md
  - advs_video/src/skills/social-media.md
  - advs_video/src/skills/spring-physics.md
  - advs_video/src/skills/transitions.md
  - advs_video/src/skills/typography.md
  - python/M4_training_run_record.md
  - python/data/CLASSIFIER_DATASET_SPEC.md
  - python/kaggle_nginx_deployment.md
  - python/siamese.md

---

# Project Overview and Guidelines

## File: AGENTS.md

# AGENTS.md - ADVS Repository Guide

This file records the current implementation and the rules AI coding agents
must follow in this repository.

Use the documentation by purpose:

- [README.md](README.md): current project status, architecture, and conventions.
- [START_ADVS.md](START_ADVS.md): installation, startup, tests, and troubleshooting.
- [docs/ADVS_REFERENCE.md](docs/ADVS_REFERENCE.md): product behavior, pipeline
  stages, risk scoring, permissions, storage, and tunable parameters.
- `docs/archive/`: historical plans only. Do not use archived files as current
  implementation instructions.

When documentation and code disagree about what exists, inspect the code,
migrations, and tests. When product behavior is unclear, use
`docs/ADVS_REFERENCE.md`.

## Current State

ADVS is a Laravel vendor-accreditation application with an integrated FastAPI
document-validation pipeline.

Built and tested:

- Fortify authentication, email verification, password flows, and mandatory
  vendor reference-signature enrollment.
- Roles `vendor`, `compliance_officer`, and `admin`, enforced by middleware and
  policies.
- Vendor profiles, document upload intake, persistent submissions/documents,
  and queued processing.
- FastAPI `/v1/validate` with multi-page OCR, classification, YOLO detection,
  signature verification, issuer-logo verification, stamp texture checking,
  and document-wide forensic analysis.
- Five-component risk scoring: text, classification, signature, stamp/logo,
  and forensic authenticity.
- Current aggregate results plus append-only pipeline attempts and per-page
  provenance.
- Compliance decisions, notifications, audit trail, system settings, model
  management, issuer-logo enrollment, and retention settings.

Verify before extending:

- Some UI components may still use `App\Support\Demo*`; inspect the component's
  data source before assuming it is Eloquent-backed.
- Model files and datasets are local deployment artifacts. A loaded model is
  not evidence of acceptable production accuracy.
- FastAPI HTTP is the canonical inference contract. References to planned
  standalone `Process`-facade inference scripts in archived docs are obsolete.

## Installed Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.2, Laravel 12 |
| Authentication | Laravel Fortify 1 |
| UI | Livewire 4, Volt 1, Flux UI 2 |
| CSS/build | Tailwind CSS 4, Vite 6 |
| Database | MySQL 8; sqlite `:memory:` for tests |
| Queue | Laravel database queue |
| Python | Python 3.12, TensorFlow 2.16, FastAPI |
| Tests | PHPUnit 11, pytest |

Environment: Windows 11, XAMPP, and PowerShell.

## Architecture

- `app/Actions`: orchestration such as `ProcessDocumentAction`.
- `app/Jobs`: queued document processing and reference enrollment.
- `app/Models`: application, validation, and pipeline-provenance models.
- `app/Services/Document`: ML transport, stage mapping, risk scoring, tamper
  persistence, decisions, and submission finalization.
- `resources/views/livewire`: class-based full-page Volt components.
- `resources/css/app.css`: Tailwind v4 theme and semantic `cu-*` utilities.
- `python/api`: FastAPI service and versioned validation contract.
- `python/scripts`: training, data generation, evaluation, and forensic tools.
- `database/migrations`: canonical schema source.

Laravel owns persistent state and sends reference vectors plus an immutable
settings snapshot with every ML request. FastAPI is stateless and returns
aggregate/per-page stages, flags, model provenance, settings hash, and timings.

## Domain Rules

- The pipeline is fail-forward for inference stages. Record a typed skipped or
  failed stage and flags; do not abort for low confidence or missing evidence.
- Reject unsafe uploads and malformed contracts before inference.
- Compliance officers make the final decision. Never auto-approve or
  auto-reject from the risk score.
- Signature references are enrolled during registration and only verified in
  the document pipeline.
- Logo references belong to issuers, not vendors. National issuers are keyed by
  document type; LGU issuers are keyed by document type plus OCR-detected city.
- Stage 4b texture checking runs even when no issuer logo reference exists.
- Risk uses five default weights of `0.20`; missing core components add the
  configured penalty; a high-confidence forensic signal forces High risk.
- Never invent thresholds. Update `docs/ADVS_REFERENCE.md`, the setting schema,
  seed defaults, migrations where needed, and tests together.

## Python Rules

- Always use `python/env/Scripts/python.exe`. Bare `python` resolves to an
  incompatible environment and `py` may select an unsupported version.
- TensorFlow is 2.16.x; `onnx` remains `<1.17`.
- `/health` is liveness. `/ready` verifies required artifacts against
  `python/models/manifest.json` and returns 503 for missing or mismatched files.
- Model weights remain gitignored. Manifest metadata may be committed.
- `/v1/*` requires a bearer token; Laravel `ML_API_TOKEN` must match Python
  `API_TOKEN`.
- Preserve the legacy aggregate `stages`/`flags` response while extending the
  versioned contract.

## Laravel Conventions

- Use Artisan `make:* --no-interaction` for Laravel boilerplate.
- Before any model, migration, factory, seeder, relationship, or
  column-dependent query change, inspect migrations and run the applicable
  `migrate:status`, `db:table`, and `model:show` commands.
- Use explicit parameter and return types, constructor property promotion, and
  curly braces for all control structures.
- Put casts in a `casts()` method and define typed relationships on both sides.
- Keep controllers and Volt components thin. Use actions, jobs, services,
  policies, and Form Requests.
- Use named routes and `role:` middleware/policies; never hardcode role checks
  as the authorization boundary.
- Use Flux components and semantic `cu-*` theme utilities. Tailwind v4 is
  configured in `resources/css/app.css`; there is no `tailwind.config.js`.
- Do not import Alpine; Livewire bundles it.
- The `config/livewire.php` component layout override and unencrypted `theme`
  cookie are load-bearing.

## Queue Rules

- Document jobs run on `document-processing` and are unique by document ID.
- Do not hold a database transaction open during the FastAPI request.
- Persist successful responses atomically.
- Record failed attempts, rethrow transient transport/contract failures for
  queue retry, and mark terminal failures in `failed()`.
- Keep queue `retry_after` greater than job timeout, and job timeout greater
  than the ML HTTP timeout.

## Tests and Formatting

Every behavior change requires a focused test.

```powershell
php artisan test --compact tests\Feature\Document\ProcessDocumentActionTest.php
python\env\Scripts\python.exe -m pytest python\tests\test_api.py -q
vendor\bin\pint --dirty --format agent
npm.cmd run build
```

The PHP suite expects sqlite `:memory:`, `QUEUE_CONNECTION=sync`, and array
cache/mail/session drivers from `phpunit.xml`. If the shell exports conflicting
values, explicitly set the testing variables before running PHPUnit.

Do not remove tests without approval. Do not revert unrelated dirty worktree
changes. Generated pytest and Augraphy artifacts are not source files.

## Quick Start

```powershell
# Terminal 1: Laravel, queue, and Vite
composer run dev

# Terminal 2: FastAPI
Set-Location python
.\env\Scripts\python.exe -m uvicorn api.main:app --host 127.0.0.1 --port 7860
```

See [START_ADVS.md](START_ADVS.md) for complete setup and troubleshooting.

> The Laravel Boost guidelines below are auto-managed by
> `php artisan boost:update`. Keep repository-specific guidance above them.

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


---

## File: README.md

# ADVS

Automated Document Validation System for vendor accreditation. Vendors submit
business documents, the system runs OCR and ML validation, and compliance
officers make the final accreditation decision using a risk-score report.

For installation and daily startup commands, read [START_ADVS.md](START_ADVS.md).
For detailed product rules and thresholds, read
[docs/ADVS_REFERENCE.md](docs/ADVS_REFERENCE.md).

## Current Status

The application has a working Laravel interface and an integrated FastAPI ML
pipeline. The remaining work is production data/model evaluation, operational
deployment, and replacing any residual demo-backed screens with database-backed
read models.

Implemented:

- Fortify session authentication with email OTP required for every password login, email verification, password reset, and
  reference-signature enrollment during registration.
- Role-based access for `vendor`, `compliance_officer`, and `admin`.
- Vendor document intake with MIME, size, and batch validation.
- Queued document processing through `ProcessDocumentJob`.
- FastAPI `/v1/validate` pipeline with multi-page processing, OCR,
  classification, detection, signature verification, issuer-logo verification,
  stamp texture analysis, and document-wide tamper forensics.
- Five-component risk scoring: text, classification, signature, stamp/logo, and
  forensic authenticity.
- Per-attempt pipeline provenance in `pipeline_runs` and per-page output in
  `pipeline_page_results`.
- Compliance review, officer decisions, notifications, audit logs, system
  settings, model management, and retention settings.
- PHPUnit and pytest coverage for the pipeline and application workflows.

Operational gaps:

- Model quality still depends on the locally supplied trained artifacts and
  their datasets. Production acceptance metrics must be recorded before release.
- `python/models/` contains deployment artifacts that are intentionally not
  committed, except for model manifest metadata.
- Some older dashboard views may still use `App\Support\Demo*`; verify the data
  source before extending a screen.

## Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.2, Laravel 12 |
| Authentication | Laravel Fortify |
| UI | Livewire 4, Volt 1, Flux UI 2 |
| Styling | Tailwind CSS 4, Vite 6 |
| Database | MySQL 8; sqlite in tests |
| Queue | Laravel database queue |
| ML API | Python 3.12, FastAPI |
| Models | ResNet-50, YOLOv8, Siamese CNN, EfficientNet, TrOCR |
| OCR / vision | PyTesseract, OpenCV, pdf2image |
| Tests | PHPUnit 11, pytest |

## User Roles

### Vendor

- Completes account and company-profile registration.
- Enrolls a reference signature before email verification completes.
- Uploads accreditation documents and tracks submission status.
- Receives submission and decision notifications.

### Compliance Officer

- Reviews submissions and the risk-score drill-down.
- Inspects OCR fields, model outputs, flags, and page-level evidence.
- Approves, rejects, or requests resubmission.
- Seeds an issuer logo reference from the first approved qualifying document.

### Administrator

- Has compliance-review access.
- Manages users, thresholds, models, retention, and audit records.
- Does not bypass the required human review decision.

## Validation Pipeline

1. **Intake** validates extension, actual MIME, file size, batch size, and page
   limits, stores the original upload, and dispatches a unique document job.
2. **Preprocessing** renders up to two PDF pages at 300 DPI and prepares images
   for OCR and model inference.
3. **OCR** extracts text and document-specific fields. Multi-page conflicts are
   retained as flags instead of aborting processing. Laravel computes the text
   risk component by matching aggregate OCR text against the vendor's non-empty
   registration fields (including the complete, unified business address); the Python template-quality score is not used for risk.
4. **Classification** predicts the document class and exposes authenticity as
   `1 - P(fake)` so a confident fake prediction increases risk.
5. **Detection** locates signature, stamp, and logo regions with YOLOv8.
6. **Signature verification** compares a detected crop with the vendor's
   registration-time 128-D reference embedding.
7. **Stamp/logo verification** classifies the crop as wet-ink-like or having
   scan/copy texture, then compares its feature vector with an issuer reference.
   A scan/copy texture result indicates a digital or scanned reproduction; it
   does not by itself mean the stamp artwork was altered. National references
   use document type; LGU references use document type plus OCR-detected city.
8. **Forensic analysis** blends metadata, ELA, copy-move, font, and OCR
   cross-reference signals across the document.
9. **Risk scoring** combines available authenticity signals, applies missing
   component penalties, and records Low, Medium, or High risk.
10. **Officer review** remains the final decision. The pipeline never
    automatically accredits or rejects a vendor.

The API is fail-forward for inference stages: unavailable or failed components
become typed stage results and flags. Invalid request contracts and unsafe file
uploads are rejected before inference.

## Risk Score

The default weighted risk is:

```text
risk = 0.20 * (1 - text_authenticity)
     + 0.20 * (1 - classification_authenticity)
     + 0.20 * (1 - signature_authenticity)
     + 0.20 * (1 - stamp_authenticity)
     + 0.20 * (1 - forensic_authenticity)
```

The weighted value is normalized across available components, converted to a
0-100 score, and combined with a 15-point penalty for each unavailable core
component. A high-confidence forensic tamper signal forces the risk band to
High. All weights and thresholds are editable through system settings.

Default bands:

- Low: 0-30
- Medium: 31-60
- High: 61-100

## Architecture

```text
Browser
  -> Laravel routes / Volt pages
  -> upload transaction
  -> ProcessDocumentJob (document-processing queue)
  -> MlPipelineService
  -> FastAPI POST /v1/validate
  -> ProcessDocumentAction persistence
  -> RiskScoreService / SubmissionFinalizer
  -> compliance officer review
```

Laravel owns users, files, references, settings, reports, and decisions.
FastAPI is stateless: reference vectors and an immutable settings snapshot are
sent with each validation request.

## Main Directories

| Path | Purpose |
|---|---|
| `app/Actions` | Pipeline and registration orchestration |
| `app/Jobs` | Queued document and reference-enrollment work |
| `app/Models` | Eloquent domain and pipeline provenance models |
| `app/Services/Document` | ML transport, mapping, risk, and finalization |
| `resources/views/livewire` | Full-page Volt application screens |
| `resources/css/app.css` | Tailwind v4 theme and semantic `cu-*` tokens |
| `database/migrations` | Canonical application schema |
| `python/api` | FastAPI service and versioned validation contract |
| `python/scripts` | Training, data generation, and forensic scripts |
| `python/tests` | Python API and pipeline tests |
| `tests/Feature` | Laravel workflow tests |
| `docs/ADVS_REFERENCE.md` | Detailed product and pipeline rules |
| `docs/phases` | Focused implementation roadmaps |
| `docs/archive` | Superseded root documentation retained for history |

## Core Data Model

- `vendors`: one company profile per vendor user.
- `submissions`: the batch-level compliance review unit.
- `documents`: one uploaded file and its processing state.
- `validation_results`: current aggregate ML and OCR result per document.
- `pipeline_runs`: append-only processing attempts with settings/model
  provenance, timings, flags, and errors.
- `pipeline_page_results`: page-level stages, flags, and timings for each run.
- `vendor_embeddings`: registration-time signature reference vectors.
- `logo_references`: issuer logo vectors keyed by document type and city.
- `notifications`: targeted in-app alerts.
- `audit_logs`: append-only user and administrative activity.
- `system_settings`: typed runtime thresholds stored as key/value rows.

## Important Configuration

Laravel environment:

- `DB_*`: MySQL connection for local application data.
- `QUEUE_CONNECTION=database`: required for queued processing.
- `ADVS_PYTHON_BIN`: absolute path to
  `python/env/Scripts/python.exe` on Windows.
- `ML_API_URL`: normally `http://127.0.0.1:7860` locally.
- `ML_API_TOKEN`: must match FastAPI `API_TOKEN`.
- `ML_API_TIMEOUT`: must remain below the queue connection `retry_after`.
- `composer run dev` launches separate `mail` and `document-processing` queue
  workers so long OCR/ML jobs cannot delay authentication email delivery.

Python environment:

- `API_TOKEN`: bearer token for all `/v1/*` endpoints.
- `MODEL_DIR`: model artifact directory.
- `MODEL_MANIFEST_PATH`: checksum and compatibility metadata for `/ready`.
- `TESSERACT_CMD`: explicit Windows Tesseract executable when not on `PATH`.

Do not use bare `python` or `py` for ML work. Use
`python/env/Scripts/python.exe`; this repository is built around Python 3.12
and TensorFlow 2.16.

## Development Rules

- Keep controllers and Volt components thin; use actions, jobs, and services.
- Call the Python API only from queued service boundaries, never directly from
  controllers.
- Inspect migrations and the live schema before changing Eloquent models.
- Use named routes and role middleware or policies.
- Use Flux components and semantic `cu-*` theme utilities in application views.
- Preserve the fail-forward pipeline and mandatory officer decision.
- Never invent risk weights or thresholds; update the domain reference and
  settings schema together.
- Every behavior change needs a focused PHPUnit or pytest test.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.

## Verification

```powershell
php artisan test --compact
python\env\Scripts\python.exe -m pytest python\tests -q
vendor\bin\pint --dirty --format agent
npm run build
```

The Python API contract and deployment details are documented in
[python/README.md](python/README.md). Model-training status and remaining
evaluation work are in
[python/DEVELOPMENT_PHASES.md](python/DEVELOPMENT_PHASES.md).


---

## File: START_ADVS.md

# Start ADVS

This guide covers first-time setup and the normal local startup workflow on
Windows 11 with XAMPP and PowerShell.

## Prerequisites

- XAMPP with PHP 8.2 and MySQL 8-compatible server
- Composer
- Node.js and npm
- Tesseract OCR and Poppler for PDF processing
- The repository Python environment at `python/env/`
- Trained model artifacts in `python/models/`

Check the main tools:

```powershell
php -v
composer --version
node.exe -v
npm.cmd -v
python\env\Scripts\python.exe --version
```

## First-Time Setup

Run these commands from the repository root:

```powershell
composer install
npm.cmd ci
Copy-Item .env.example .env
php artisan key:generate
```

Create a MySQL database named `advs`, then update `.env`:

```dotenv
APP_URL=http://localhost:8000

# WebAuthn requires a hostname. Do not open the app at 127.0.0.1.
PASSKEYS_RELYING_PARTY_ID=localhost
PASSKEYS_ALLOWED_ORIGINS=http://localhost:8000,http://localhost:8100

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=advs
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=database
ADVS_PYTHON_BIN=C:\xampp\htdocs\projects\advs\python\env\Scripts\python.exe

ML_API_URL=http://127.0.0.1:7860
ML_API_TOKEN=devtoken
ML_API_TIMEOUT=180
ML_API_CONNECT_TIMEOUT=10
```

Create the Python API environment file:

```powershell
Copy-Item python\.env.api.example python\.env.api
```

Set at least this value in `python/.env.api`:

```dotenv
API_TOKEN=devtoken
```

If Tesseract is not on `PATH`, also set:

```dotenv
TESSERACT_CMD=C:/Program Files/Tesseract-OCR/tesseract.exe
```

Build the database and frontend assets:

```powershell
php artisan migrate --no-interaction
php artisan storage:link
npm.cmd run build
```

## Start the Application

ADVS needs two terminal sessions. `composer run dev` starts Laravel, the queue
listener, and Vite. FastAPI runs separately.

### Terminal 1: Laravel, Queue, and Vite

From the repository root:

```powershell
composer run dev
```

This starts:

- Laravel at `http://127.0.0.1:8000`
- Vite development assets
- Dedicated mail queue worker for `mail` with a 60-second job timeout
- Dedicated document queue worker for `document-processing` with a 360-second
  job timeout

Startup first checks the configured database connection. If MySQL is stopped
or the `DB_*` settings are invalid, the command fails before the other
processes start with an actionable error. The queue process is also restarted
automatically by the queue-only `scripts/queue-worker.ps1` supervisor after a
transient database disconnect. Each queue worker has its own restart loop, so
slow OCR/ML jobs cannot block authentication email delivery. Laravel normally
exits a database worker with status 0 when it detects a lost connection, so
the queue-only restart loops are required for a local multi-process development
command. Non-zero worker exits are still propagated so application errors
remain visible.

### Terminal 2: Python ML API

```powershell
Set-Location .\python
.\env\Scripts\python.exe -m uvicorn api.main:app --host 127.0.0.1 --port 7860
```

Model loading can take time. Wait until startup completes before submitting a
document.

Check the service from another terminal:

```powershell
curl.exe http://127.0.0.1:7860/health
curl.exe http://127.0.0.1:7860/ready
```

- `/health` confirms the process is alive and reports model load states.
- `/ready` returns 200 only when the required artifacts match
  `python/models/manifest.json`; otherwise it returns 503 with exact reasons.

## Open ADVS

Visit `http://localhost:8000`.

Seeded accounts use password `password`:

| Role | Email |
|---|---|
| Vendor | `vendor@advs.test` |
| Compliance officer | `officer@advs.test` |
| Compliance officer | `officer2@advs.test` |
| Administrator | `admin@advs.test` |
| Administrator | `admin2@advs.test` |

## Normal Daily Startup

When dependencies and the database already exist:

```powershell
# Terminal 1, repository root
composer run dev

# Terminal 2
Set-Location .\python
.\env\Scripts\python.exe -m uvicorn api.main:app --host 127.0.0.1 --port 7860
```

Do not run `migrate:fresh` during normal startup.

## Run Tests

Laravel:

```powershell
php artisan config:clear
php artisan test --compact
```

Python:

```powershell
python\env\Scripts\python.exe -m pytest python\tests -q
```

Frontend build:

```powershell
npm.cmd run build
```

Formatting after PHP changes:

```powershell
vendor\bin\pint --dirty --format agent
```

## Troubleshooting

### Laravel cannot connect to MySQL

Start MySQL in XAMPP and verify `.env` uses database `advs`. Clear cached
configuration after changing environment values:

```powershell
php artisan optimize:clear
```

### Documents stay queued

Confirm Terminal 1 is running and includes the dedicated document queue worker.
The required queue is `document-processing`; the mail worker does not process
document jobs.

### ML API is unreachable

Confirm FastAPI is listening on port 7860 and that `ML_API_TOKEN` matches
Python `API_TOKEN`.

```powershell
curl.exe http://127.0.0.1:7860/health
curl.exe http://127.0.0.1:7860/ready
```

### `/ready` returns 503

Read the `errors` array. Common causes are missing weights, a missing manifest,
or a checksum mismatch after replacing a model file. Update the manifest when
the deployed artifact set changes.

### OCR fails on Windows

Set `TESSERACT_CMD` in `python/.env.api` and ensure Poppler's `bin` directory is
available on `PATH` for PDF conversion.

### Frontend changes do not appear

Keep `composer run dev` running, or rebuild production assets:

```powershell
npm.cmd run build
```

### Port already in use

Check the process using the port:

```powershell
netstat -ano | Select-String ':8000|:7860|:5173'
```

Stop the old process or start the affected service on another port and update
the corresponding environment URL.


---

## File: advs_video/README.md

# Remotion Prompt to Motion Graphics

<p align="center">
  <a href="https://github.com/remotion-dev/logo">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://github.com/remotion-dev/logo/raw/main/animated-logo-banner-dark.apng">
      <img alt="Animated Remotion Logo" src="https://github.com/remotion-dev/logo/raw/main/animated-logo-banner-light.gif">
    </picture>
  </a>
</p>

AI-powered motion graphics generator that transforms natural language prompts into Remotion code.

## Architecture

```
User Prompt → Validation → Skill Detection → Code Generation → Sanitization → Live Preview
```

## How It Works

### 1. Validation

Before expensive model calls, a lightweight classifier determines if the prompt describes valid motion graphics content.

**Accepted**: animated text, data visualizations, UI animations, social media content, abstract motion graphics

**Rejected**: questions, conversational requests, non-visual tasks

### 2. Skill Detection

The system analyzes the prompt to identify which **skills** are relevant. Skills are modular knowledge units that provide domain-specific guidance to the code generation model.

There are two types of skills:

- **Guidance Skills** - Pattern libraries with best practices for specific domains (charts, typography, transitions, etc.)
- **Example Skills** - Complete working code references that demonstrate specific animation patterns

This approach keeps the base prompt lightweight while dynamically injecting only the relevant expertise for each request.

### 3. Code Generation

Uses a one-shot prompt with the base Remotion knowledge plus any detected skills. The generated code follows these principles:

- **Constants-first design** - All text, colors, and timing values are declared as editable constants at the top
- **Aesthetic defaults** - Guidance on visual polish, spacing, and animation feel
- **Crossfade patterns** - Smooth state transitions without layout jumps
- **Spring physics** - Natural, organic motion using Remotion's spring() function

### 4. Sanitization & Compilation

The response is cleaned (removing markdown wrappers and trailing commentary), then compiled in-browser using Babel. The compiled component renders directly in the Remotion Preview with all necessary APIs injected.

## Skills System

Skills enable contextual expertise without bloating every prompt. Located in `src/skills/`:

### Guidance Skills

| Skill              | Purpose                                                                                 |
| ------------------ | --------------------------------------------------------------------------------------- |
| **charts**         | Data visualization patterns - bar charts, pie charts, axis labels, staggered animations |
| **typography**     | Kinetic text - typewriter effects, word carousels, text highlights                      |
| **messaging**      | Chat UI - bubble layouts, WhatsApp/iMessage styling, staggered entrances                |
| **transitions**    | Scene changes - TransitionSeries, fade/slide/wipe effects                               |
| **sequencing**     | Timing control - Sequence, Series, staggered delays                                     |
| **spring-physics** | Organic motion - spring configs, bounce effects, chained animations                     |
| **social-media**   | Platform-specific formats - aspect ratios, safe zones                                   |
| **3d**             | Three.js integration - 3D scenes, camera setup                                          |

### Example Skills (Code Snippets)

Example skills provide complete working references (histogram, chat messages, typewriter effects, etc.) that demonstrate these patterns in action. We think of them like implementation archetypes that can be used and adjusted for the user prompt.

## Usage Tips

**Prompting best practices:**

- Be specific about colors, timing, and layout ("green sent bubbles on the right, gray received on the left")
- Include data directly in the prompt for charts and visualizations
- Describe the animation feel you want ("bouncy spring entrance", "smooth fade", "staggered timing")

**Images:**

- Direct image uploads are not supported
- Reference images via URL - the generated code will use Remotion's `<Img>` component
- Example: _"Create a DVD screensaver animation of this image https://example.com/logo.png"_

**What works well:**

- Kinetic typography and text animations
- Data visualizations with animated entrances
- Chat/messaging UI mockups
- Social media content (Stories, Reels, TikTok)
- Logo animations and brand intros
- Abstract motion graphics

## Commands

**Install Dependencies**

```console
npm i
```

**Start Preview**

```console
npm run dev
```

**Render video**

```console
npx remotion render
```

**Upgrade Remotion**

```console
npx remotion upgrade
```

## Docs

Get started with Remotion by reading the [fundamentals page](https://www.remotion.dev/docs/the-fundamentals).

## Help

We provide help on our [Discord server](https://discord.gg/6VzzNDwUwV).

## Issues

Found an issue with Remotion? [File an issue here](https://github.com/remotion-dev/remotion/issues/new).

## License

Note that for some entities a company license is needed. [Read the terms here](https://github.com/remotion-dev/remotion/blob/main/LICENSE.md).


---

## File: python/README.md

---
title: ADVS ML API
emoji: 📄
colorFrom: blue
colorTo: gray
sdk: docker
app_port: 7860
pinned: false
---

# ADVS ML API

Stateless FastAPI service wrapping the ADVS document-validation pipeline for
**independent deployment** (Hugging Face Docker Space or any container host).

Architecture: **Laravel (ADVS)** → HTTPS/JSON → **this service** →
ResNet-50 · PyTesseract · YOLOv8 · Siamese CNN · EfficientNet · Stage T forensics.

Laravel stays the orchestrator and the single owner of state: per-vendor
signature reference embeddings and issuer logo vectors live in Laravel's DB
and are **passed in each request**; the composite risk score (Stage 5) is
computed by Laravel's `RiskScoreService`, never here.

## Endpoints

| Endpoint | Stage | Status | Notes |
|---|---|---|---|
| `GET /health` | — | live, **no auth** | liveness and model load report; keep-warm ping target |
| `GET /ready` | — | live, **no auth** | deployment readiness; 503 when required artifacts or `manifest.json` are missing/incompatible |
| `GET /v1/config` | — | **live** | current/effective thresholds, overrides, and boot defaults |
| `PATCH /v1/config` | — | **live** | runtime threshold changes from the admin ML Models page (partial body; `null` resets a key) |
| `POST /v1/classify` | 3 | **live** | ResNet-50 → `{label, confidence, probabilities, passed_threshold}` |
| `POST /v1/ocr` | 2 | **live** | multipart `file` (+ `template=bir\|none`) → per-page `{text, words, fields, quality}` |
| `POST /v1/tamper` | T | **live** | original upload (+ optional JSON `context`) → forensic verdict |
| `POST /v1/detect` | 4 | 503 until weights | YOLOv8 boxes → `{detections, flags}` |
| `POST /v1/signature/enroll` | registration | 503 until detector + Siamese weights | three-signature photo → crops, embeddings, consistency, centroid, forensics |
| `POST /v1/signature/embed` | 4a | 503 until weights | crop → 128-D embedding |
| `POST /v1/signature/verify` | 4a | 503 until weights | crop + `reference_embedding` (JSON array form field) |
| `POST /v1/stamp/embed` | 4b | 503 until weights | crop → issuer feature vector (EnrollReferenceJob seeding) |
| `POST /v1/stamp/verify` | 4b | 503 until weights | crop + `reference_vector`; omitted reference → `unreferenced_logo` |
| `POST /v1/validate` | all | **live (fail-forward)** | versioned full pipeline; aggregate `stages`/`flags` plus per-page stages, artifact provenance, settings hash, and timings |

All `/v1/*` routes require `Authorization: Bearer <API_TOKEN>`. Interactive
docs at `/docs` once running.

## Configuration (env)

See `.env.api.example` for the full annotated surface. Highlights:

| Variable | Default | Purpose |
|---|---|---|
| `API_TOKEN` | — (**required**) | bearer token; a HF *Repository secret* on a Space |
| `MODEL_DIR` | `./models` (`/app/models` in Docker) | weight-file root |
| `MODEL_MANIFEST_PATH` | `MODEL_DIR/manifest.json` | artifact versions, SHA-256 hashes, shapes, classes, metrics, training dates, and calibrated thresholds used by `/ready` |
| `CLASSIFIER_MODEL_PATH` | `MODEL_DIR/resnet50_best.keras` | trained ✔ |
| `DETECTOR_MODEL_PATH` | `MODEL_DIR/yolov8_nano_moredata_best.pt` | trained YOLOv8 signature/stamp detector |
| `SIGNATURE_ENROLL_DETECTOR_MODEL_PATH` | unset | optional dedicated detector for blank-paper registration photos; falls back to `DETECTOR_MODEL_PATH` |
| `SIAMESE_MODEL_PATH` | `MODEL_DIR/siamese_encoder.h5` | the encoder train_signature.py saves (the API only embeds) |
| `STAMP_MODEL_PATH` | `MODEL_DIR/efficientnet_feature_extractor.h5` | what train_stamp.py saves |
| `CLASSIFICATION_CONFIDENCE_THRESHOLD` | `0.70` | §9 |
| `YOLO_DETECTION_CONFIDENCE` | `0.50` | §9 |
| `SIGNATURE_ENROLL_DETECTION_CONFIDENCE` | `0.20` | registration-only threshold; does not affect document detection |
| `SIGNATURE_ENROLL_DETECTION_IMGSZ` | `1280` | registration-only inference size for thin handwritten strokes |
| `STAMP_SIMILARITY_THRESHOLD` | `0.85` | §9 (training-produced `stamp_threshold.txt` wins) |
| `SIGNATURE_DISTANCE_THRESHOLD` | unset | empirical/EER; falls back to `signature_threshold.txt` |
| `PDF_DPI` / `MAX_PDF_PAGES` | `300` / `2` | §2 PDF handling |
| `MAX_FILE_SIZE_MB` | `10` | validated before inference; PDF/PNG/JPEG only, with MIME and magic-byte checks |
| `TROCR_MODEL_PATH` | `MODEL_DIR/trocr-base-printed` | default/fast recognizer; local snapshot dir only (never a bare HF Hub id — no network fetch from inside a request-serving container); missing dir = fall back to the accurate one, or skip the ROI pass if neither is loaded |
| `TROCR_ACCURATE_MODEL_PATH` | `MODEL_DIR/trocr-large-printed` | recognizer for `TROCR_ACCURATE_TEMPLATES` |
| `TROCR_ACCURATE_TEMPLATES` | `bir` | comma-separated templates that need the accurate recognizer |
| `ROI_BUDGET_SECONDS` | `210` | per-page cap on the ROI+TrOCR pass (see below) — **not** a §9 parameter |
| `TROCR_MAX_NEW_TOKENS` | `64` | decode cap per field crop; 32 truncated a real address |
| `ROI_TESSERACT_CONFIDENCE_FLOOR` | `90` | skip TrOCR only when Tesseract's read is near-certain |
| `NUM_THREADS` | `min(4, cpu_count)` | 2 on the HF free tier, 4 on Oracle A1, without oversubscribing a dev box |

A model whose weight file is missing is simply reported as not loaded by
`/health`; its endpoints return `503 {"reason": "model_not_loaded", ...}` and
`/v1/validate` marks that stage skipped. **Drop in the weights, set the path,
restart — no code changes.**

### Full validation contract

`POST /v1/validate` accepts an optional multipart `settings_snapshot` JSON
object. Laravel may send its complete settings snapshot; Python applies the
known threshold/page aliases and hashes the complete object as `settings_hash`
for audit provenance. Vector form fields must contain finite JSON arrays and,
when the loaded model or manifest exposes a dimension, must match it exactly.

The response preserves the legacy top-level `stages` and `flags` surface and
adds `schema_version`, `pages`, `models`, `settings_hash`, and `timings`. Each
stage has a typed `status` (`completed`, `skipped`, or `failed`); skipped stages
also retain `skipped: true` and `reason`. The supported classification/OCR
intersection is `bir_certificate`, `business_permit`, and `dti_registration`.
Other declared types use OCR template `none`, return
`unsupported_document_type`, and continue through detection, verification,
and forensics without fabricating a classification contribution.

For PDFs, every rendered page (first two by default) runs independently. The
aggregate picks the lowest classification authenticity (with any `fake` page
dominant), the minimum successful signature/stamp score, the highest tamper
score, and the highest-confidence valid OCR field. Conflicting OCR values add
`ocr_field_conflict`.

### Stage 2 cost model — the ROI+TrOCR field-recognition pass

`scripts/roi_field_ocr.py` recognizes field VALUES from tight crops (RapidOCR
PP-OCRv4 detector → TrOCR), which is what makes Stage 2 accurate — and what
makes it expensive. It is CPU-bound and dominates `/v1/validate`, so it is
deliberately **bounded**, not best-effort:

- **Gated** — a field Tesseract already read at ≥ `ROI_TESSERACT_CONFIDENCE_FLOOR`
  is skipped. Keep that floor high (default 90): on a real BIR page Tesseract
  reported 68–78% on values it read *wrong* (`NORTHZ2N STAR FINANCE`,
  `GEMINI STREZT`, `CONSTRUCTION CF`), so a 60 floor gated out exactly the
  fields TrOCR exists to fix and dropped the page's text-validation score from
  1.0 to 0.778.
- **Budgeted** — `ROI_BUDGET_SECONDS` caps the whole pass; required fields go
  first, and anything unreached keeps its label+regex value (fail-forward, §5).
- **Cached decode** — `generation_kwargs()` passes `use_cache=True` explicitly,
  because `trocr-*-printed`'s own `generation_config.json` ships
  `"use_cache": false`. Measured 2.2× slower (103.9 s → 47.3 s over 3 crops)
  for byte-identical text. Do not drop that kwarg.

Measured on this repo's real samples (8-core dev box, `NUM_THREADS=4`,
budget unbounded, per-page `/v1/ocr` wall time):

| Document | ROI fields | trocr-base | trocr-large | values |
|---|---|---|---|---|
| BIR CoR | 11 crops | 94 s | 203 s | **differ** — base misread the issue year (`FEB 24 2025` on a 2023 certificate), `PHYILIS`/`PHYLLIS`, address, tax types |
| DTI | 7 crops | 15 s | 39 s | identical |
| Business Permit | 5 crops | 23 s | 37 s | identical |

Hence the per-template routing (`resolve_recognizer`): **base by default,
large for BIR only** (`TROCR_ACCURATE_TEMPLATES`). With that split the same
three documents run in **159 s / 17 s / 25 s**, BIR's values matching the
large-model reference exactly. For scale: before this pass was bounded, one
BIR page took **410 s** and blew Laravel's then-180 s `ML_API_TIMEOUT`, which
failed the whole call and blanked Stage 2 *and* Stage 3 in the officer
drill-down. `config/advs.php` now allows 300 s.

> The historical <60 s end-to-end target is **not** reachable for BIR
> with any TrOCR configuration measured here, **including ONNX** — see below.
> The remaining levers are a >=16GB build machine for a properly optimized
> ONNX export, or fewer ROI fields per page. Not a smaller budget.

#### ONNX export — attempted, not adopted (2026-07-25)

`scripts/export_trocr_onnx.py` exports a recognizer to ONNX (optionally INT8)
and `roi_field_ocr.load_trocr()` will load either layout transparently. It is
kept as a **build-machine tool**; it did not pay off on the 7.8 GB dev box.
Measured on the same 11 BIR field crops:

| runtime | per crop | output |
|---|---|---|
| torch `trocr-base` | 4.1 s | reference |
| ONNX fp32 | 16.8 s | faithful, **4× slower** |
| ONNX INT8 dynamic | 2.2 s | 1.9× faster, **7/11 crops corrupted** |

INT8 broke exactly what Stage 2 exists to get right — OCN `3U2907511725` →
`3029007511725`, `FEB 24 2025` → `FE9 24 2025`, trade name → garbage. fp32 was
slow because the export lacks transformer attention fusions (`--optimize O2`),
and on 7.8 GB RAM both `O2` **and** optimum's default decoder-merge die in
`onnx.save` → `SerializeToString` (optimum re-saves each 1.2 GB graph without
external-data streaming, needing ~2× the graph in RAM). `--no-post-process` is
what lets the export finish here, and it is also what leaves it unoptimized.
`trocr-large` — the recognizer BIR actually uses — never exported at all.

Retry on a ≥16 GB machine without `--no-post-process`, then re-run the field
comparison before adopting. `optimum` is an **optional** dependency: it is
imported only when a configured model path holds an ONNX export.

Each OCR page in the response carries a `roi` block —
`{attempted, recognized, skipped_over_budget, elapsed_s}` — so a slow or
truncated page is visible rather than silent. `null` means neither recognizer
is loaded, which is different from "ran and recognized nothing".

### Runtime thresholds (admin dashboard)

The §9 tunables (`classification_confidence_threshold`,
`yolo_detection_confidence`, `stamp_similarity_threshold`,
`signature_distance_threshold`, `pdf_dpi`, `max_pdf_pages`) are also
changeable at runtime — the admin dashboard's ML Models page calls:

```bash
curl -H "Authorization: Bearer <token>" http://.../v1/config
curl -X PATCH -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"stamp_similarity_threshold": 0.90}' http://.../v1/config
```

Precedence per key: **admin PATCH > env var > training-produced threshold
file (`signature_threshold.txt` / `stamp_threshold.txt`) > §9 default**.
PATCHing `null` resets a key. Overrides persist to `THRESHOLD_STORE_PATH`
(survives restarts, **not** a container rebuild) — Laravel's system settings
remain canonical and should re-push after a rebuild.

## Run locally

Always use the repo ML venv (never bare `python`; see the root `README.md`):

```powershell
cd python
env/Scripts/python.exe -m pip install -r requirements-api.txt
copy .env.api.example .env.api   # set API_TOKEN
env/Scripts/python.exe -m uvicorn api.main:app --port 7860
```

Smoke checks:

```bash
curl http://localhost:7860/health
curl http://localhost:7860/ready
curl -H "Authorization: Bearer <token>" -F "file=@sample_bir.jpg" http://localhost:7860/v1/classify
curl -H "Authorization: Bearer <token>" -F "file=@sample_bir.jpg" http://localhost:7860/v1/validate
```

Tests: `env/Scripts/python.exe -m pytest tests/test_api.py -v` (from `python/`).

## Deploy to a Hugging Face Space

1. Create a **Docker** Space (this README's YAML header is the Space config).
2. Push the contents of this `python/` directory to the Space repo, excluding
   everything in `.dockerignore` (`env/`, `data/`, `notebooks/`, `tests/`, …).
   Easiest path with the HF CLI:
   ```bash
   hf auth login
   hf upload <user>/advs-ml-api . . --repo-type=space \
     --exclude "env/*" --exclude "data/*" --exclude "notebooks/*" \
     --exclude "tests/*" --exclude "tmp/*" --exclude "json_data/*" \
     --exclude "backup_models/*" --exclude "models/tuning/*" \
     --exclude "**/__pycache__/*" --exclude "*.log" --exclude ".env.api"
   ```
3. Upload trained weights into the Space's `models/` (they are gitignored in
   the main repo; the Space carries its own copies — use `hf upload` or the
   Space UI; large `.h5`/`.keras`/`.pt` files ride Git LFS automatically).
4. Space **Settings → Repository secrets** → add `API_TOKEN`. Set any
   model-path/threshold overrides as Space *variables*.
5. Watch the build logs; first boot loads every model whose weights exist.

### Laravel integration (follow-up branch)

```
ML_API_URL=https://<user>-advs-ml-api.hf.space
ML_API_TOKEN=<same token>
```

Call from a queued job with a generous timeout (free Spaces sleep; a cold
start costs 30–60 s) and `retry(2, 5000)`. For a defense-day demo, either a
cron ping of `/health` every ~10 min keeps the Space warm, or temporarily
upgrade the Space hardware for the week.

## Deploy to Oracle Cloud (Always Free Ampere A1)

Same image, same `/v1/*` contract, same `API_TOKEN` bearer auth — the only
difference from the Space deploy above is a self-managed VM instead of a
managed platform. Chosen specifically for the ROI+TrOCR field-recognition
pass (`roi_field_ocr.py`): the Always-Free **Ampere A1** shape gives a
persistent 4 OCPU / 24GB ARM (aarch64) VM with **no cold start / no sleep**,
unlike HF Spaces' free CPU tier.

1. **Provision the VM**: Oracle Cloud Console → Compute → Create Instance →
   shape `VM.Standard.A1.Flex` (Always Free eligible up to 4 OCPU/24GB),
   image Ubuntu 22.04 (aarch64/ARM). Note the public IP.
2. **Open the port at the cloud networking layer, not just the OS firewall**
   — Oracle blocks traffic at the instance's **Security List / Network
   Security Group** independently of `ufw`/`iptables` on the VM itself.
   Forgetting this is the most common first-deploy trap: add an ingress rule
   for TCP/7860 (or 443 if fronting with TLS) on the VCN's security list
   *and* `sudo ufw allow 7860/tcp` on the VM.
3. **Install Docker** on the VM (`curl -fsSL https://get.docker.com | sh`).
4. **Build natively on the VM** — this repo's `python/` directory, uploaded
   or `git clone`d onto the instance (real ARM hardware; avoids QEMU cross-
   arch emulation, which would make the TrOCR weight-baking step in the
   Dockerfile painfully slow):
   ```bash
   cd python
   docker build -t advs-ml-api .
   ```
5. **Run it** (a `systemd` unit is more durable across reboots than a bare
   `docker run`, but `--restart unless-stopped` covers container crashes and
   Docker-daemon restarts either way):
   ```bash
   docker run -d --name advs-ml-api -p 7860:7860 \
     --restart unless-stopped \
     -e API_TOKEN=<token> \
     advs-ml-api
   ```
   Trained `.h5`/`.keras`/`.pt` weights not already baked into the image can
   be bind-mounted (`-v /opt/advs-models:/app/models`) instead of rebuilding.
6. **TLS**: if the endpoint is reachable from the public internet, put nginx
   or Caddy in front for TLS termination (`API_TOKEN` bearer-auth stays the
   app-level guard regardless). Skip this for a VCN-internal/VPN-only setup.
7. **Verify**: `curl http://<vm-ip>:7860/health` — confirm `rapid_detector`
   and `trocr` (alongside the trained models) report `"loaded": true`.

### A risk worth knowing about upfront: Always Free reclamation

Oracle can reclaim an Always Free compute instance it considers idle —
documented threshold: average CPU, network, **and** memory utilization all
under ~20% for 7 consecutive days. A validation API that only sees traffic
when a vendor submits a document can plausibly look idle by that definition.
Mitigate with the same low-frequency keep-warm ping used for the HF Spaces
cold-start problem above, or accept the risk and watch for a reclamation
notice — make this a conscious choice, not a surprise.

### Laravel integration

Same as the HF Spaces case — only the URL changes:

```
ML_API_URL=http://<vm-ip>:7860        # or https://your-domain if TLS-fronted
ML_API_TOKEN=<same token>
```

No cold-start retry budget is needed here (the VM doesn't sleep), but keep a
reasonable request timeout regardless — ROI+TrOCR adds real per-field
inference time on top of Tesseract's existing OCR pass (see the module
docstring in `scripts/roi_field_ocr.py`); benchmark on the actual VM against
the historical <60-second end-to-end target before relying on it.


---

## File: python/data/README.md

# ADVS training data layout

Each model reads from its own `*_data` folder under `training/` and `validation/`.
Drop real data into these folders (contents are gitignored; the empty scaffold is
kept via `.gitkeep`). For a tiny synthetic dataset to smoke-test the pipeline, run
the fixture generator:

```bash
python/env/bin/python.exe python/.claude/skills/run-advs-training/make_fixtures.py
```

## Layout

```
data/
├── training/
│   ├── classifier_data/        # ResNet-50 — one subfolder PER CLASS:
│   │   ├── bir_permit/             *.jpg / *.png
│   │   ├── financial_statement/
│   │   ├── business_registration/
│   │   └── fake/
│   ├── detector_data/          # YOLOv8
│   │   ├── images/                 *.jpg / *.png  (full document pages)
│   │   └── labels/                 *.txt  (YOLO format: "<cls> cx cy w h", cls 0=signature 1=stamp)
│   ├── signature_data/         # Siamese — one subfolder PER VENDOR/SIGNER:
│   │   ├── vendor_001/             *.png  (genuine signatures)
│   │   │   └── forged/             *.png  (OPTIONAL real skilled forgeries, e.g. CEDAR;
│   │   │                                   absent -> synthetic forgeries are fabricated)
│   │   └── vendor_002/
│   └── stamp_data/             # EfficientNet
│       ├── genuine/                *.png  (genuine stamp crops)
│       └── forged/                 *.png  (forged stamp crops)
└── validation/                 # SAME four subfolders, same internal shape
    ├── classifier_data/  …
    ├── detector_data/    …
    ├── signature_data/   …
    └── stamp_data/       …
```

Image filenames in `detector_data/images/` and `detector_data/labels/` must match
(e.g. `page_3.png` ↔ `page_3.txt`).


---

## File: python/tests/fixtures/signature_data/README.md

# Signature DoD fixtures — Stage 4a (M4)

Real signature crops that gate the trained Siamese verifier in
[`../../test_signature_verify.py`](../../test_signature_verify.py). Distinct from the random-noise
**smoke** fixtures under `python/data/training/signature_data/` (which are gitignored and only exercise
`--smoke`/`--dry-run`). These are **committed** — small, deterministic, and travel with the repo so the
DoD gate is reproducible in CI, the same way `python/tests/fixtures/bir1_words.json` is committed.

## Layout

```
signature_data/
  <signer>/            genuine signatures for one writer (>= 2 .png)
    0.png
    1.png
    ...
    forged/            OPTIONAL skilled forgeries of the SAME writer
      0.png
      ...
```

The test picks the **first** signer directory with >= 2 genuine `.png`, uses `genuine[0]`/`genuine[1]`
for the genuine pair, and `forged/0.png` for the forged pair. If a signer has no `forged/`, the test
falls back to a synthetic elastic-warp forgery of `genuine[0]`. Include a real `forged/` set — a real
skilled forgery is a stronger, more honest test than the synthetic fallback.

## Provenance

- **CEDAR** signature dataset (Kaggle: `shreelakshmigp/cedardataset`), `full_org/` (genuine) +
  `full_forg/` (forged), grouped by writer id (first number in the filename).
- **These fixtures are held-out (signer-disjoint) writers** — the encoder never trained on them, so the
  gate measures generalisation, not memorisation:
  - `signer_08` = CEDAR writer 8 · `signer_18` = CEDAR writer 18 (4 genuine + 3 forged each).
  - Both are in the notebook-03 seed-42 validation split (held out = `8, 18, 19, 25, 28, 29, 40, 42, 46,
    51, 55`) — see [`../../../M4_training_run_record.md`](../../../M4_training_run_record.md) and
    notebook `03_siamese_signature.ipynb` Step 5.
  - Observed distances (EER threshold `1.243976`): signer_08 genuine 0.898 / forged 1.683 ·
    signer_18 genuine 0.777 / forged 1.772 — bracketing the run's genuine 0.807 / forged 1.697 means.
- Keep it minimal: ~2 signers, a few genuine + a few forged each, is enough for the four DoD checks.
  To refresh, re-fetch any held-out writer via the Kaggle API (`dataset_download_file`) and rename to the
  `<signer>/N.png` + `<signer>/forged/N.png` layout.

## What the gate asserts (recalibrated — NOT the literal 0.85)

Against the empirical EER threshold in `python/models/signature_threshold.txt` (**1.243976**):

1. embedding length is exactly **128**
2. genuine pair distance **<= threshold** → match
3. forged pair distance **> threshold** → no match
4. mean genuine distance below mean forged distance by a clear margin (>= 0.30)

Override this location in CI with the `ADVS_SIG_DATA` environment variable.


---

# Reference and Implementation

## File: docs/ADVS_REFERENCE.md

# Automated Document Validation System (ADVS) for Vendor Accreditation

## Comprehensive System Reference — Core Logic, Operations, and Implementation Details

---

> **Implementation phase plans:** this reference defines *what* the system does; the *how/when* now lives in three concern-split phase plans under [`phases/`](phases/) — [pipeline integration](phases/PIPELINE_INTEGRATION_PHASES.md) · [model training](phases/MODEL_TRAINING_PHASES.md) · [UI functions](phases/UI_FUNCTION_PHASES.md) — each folding in the Negofood client-interview direction ([gap plan](CLIENT_INTERVIEW_GAP_PLAN.md)).

---

## 1. System Purpose and Core Logic

The ADVS is a web-based system built on a **Laravel 11 backend with Python inference scripts** that automates the verification of vendor accreditation documents. Its core problem statement is direct: manual document review is slow, error-prone, and vulnerable to fraud. The system replaces that process with a multi-stage machine learning pipeline that examines every document from four independent angles — text content, document classification, signature authenticity (vs a per-vendor reference enrolled at registration), and stamp/logo authenticity (vs a per-issuer reference library — per-agency for national logos like BIR/SEC, per-city for LGU seals) — then fuses those signals into a single composite risk score for a human officer to act on.

The system is **on-demand, not calendar-driven**. It activates whenever a vendor submits documents — whether that's an initial application, a renewal, or an update triggered by an expiring credential or new regulation. The implementing organization decides the cadence; ADVS simply processes whatever arrives.

> **Approved update (Negofood client interview, 2026-06-29):** a **renewal scheduler** is being added that proactively flags expiring/expired credentials and sends renewal reminders — a deliberate **calendar-driven** dimension layered on top of the on-demand core. This is the one approved departure from the statement above. See the compliance-lifecycle plan in [`phases/PIPELINE_INTEGRATION_PHASES.md`](phases/PIPELINE_INTEGRATION_PHASES.md) (Phase P5) and [`CLIENT_INTERVIEW_GAP_PLAN.md`](CLIENT_INTERVIEW_GAP_PLAN.md).

Three distinct user roles interact with the system: **Vendors** (who submit documents), **Compliance Officers** (who review validation results and render accreditation decisions), and **System Administrators** (who manage users, configure thresholds, and oversee the platform). Each role has a scoped view of the system enforced by role-based access control.

---

## 2. File Upload Constraints

### Accepted Formats

The thesis specifies that vendors upload **images or PDFs** through a submission portal. In the Laravel implementation, this translates to the following accepted MIME types:

| Format | Extension | Notes |
|---|---|---|
| PDF | `.pdf` | Multi-page supported; first two pages are extracted |
| PNG | `.png` | Preferred raster format after conversion |
| JPEG | `.jpg`, `.jpeg` | Common scan output format |

PDF handling is explicit in the manuscript: the system uses the **pdf2image Python library** to convert the **first two pages** of any multi-page PDF into high-resolution PNG images at **300 DPI**. This conversion serves two purposes — it standardizes all input into a consistent image format for the pipeline, and it prevents class imbalance that would arise if some documents contributed many more pages than others.

### Size Limits (Configurable)

The thesis does not prescribe a fixed file size cap. In practice, the implementing organization would configure Laravel's upload validation rules. A reasonable production configuration would be:

- **Per-file limit**: 10–20 MB (configurable via Laravel's `upload_max_filesize` and validation rules)
- **Per-submission batch**: 50–100 MB total across all documents in a single submission
- **Minimum resolution**: The system resizes all images to **512 × 512 pixels** for ResNet-50 and applies preprocessing for OCR, so extremely low-resolution scans (below ~150 DPI) will degrade OCR accuracy and model confidence. The system does not reject low-resolution files outright — it processes them and lets the confidence scores and OCR quality metrics reflect the degradation, which then surfaces in the risk score.

### Multi-Page Document Handling

When a vendor uploads a multi-page PDF, the system extracts only the **first two pages** as PNG images at 300 DPI. Each extracted page is treated as a separate image flowing independently through the full pipeline (preprocessing → OCR → classification → detection → verification). The manuscript is explicit about this design choice: it avoids overwhelming the training set with documents that happen to have many pages, keeping class balance stable.

If the organization requires validation of pages beyond the first two, this is a configurable parameter (`MAX_PDF_PAGES`) that can be increased, though it would proportionally increase processing time.

---

## 3. Access Control and User Roles

### Authentication

The thesis mandates **Secure Login** as a core scope item: "Access to the system will be limited to authorized users only, ensuring data protection and confidentiality through account-based authentication." In the Laravel 11 implementation, this means:

- **Session-based authentication** managed by Laravel's built-in auth scaffolding
- **Password hashing** via bcrypt (Laravel default)
- **CSRF protection** on all forms
- **Email OTP on every password login** â€” valid credentials start a ten-minute, single-use six-digit email challenge stored only as a one-way hash and protected by resend, request, and attempt limits.
- **No trusted-device bypass** â€” password logins do not expose or honor "remember me" / "remember this device". A registered passkey can complete the pending password-login challenge, but cannot start a standalone login.
- **Account-based access** — no anonymous or public access to any system feature

### Role Definitions and Permissions

| Role | Can Access | Cannot Access |
|---|---|---|
| **Vendor** | Submission portal, own submission history, own validation status and notifications | Other vendors' data, officer dashboard, admin settings, risk scores, validation reports |
| **Compliance Officer** | Validation results dashboard, pending submissions queue, risk score drill-downs, approve/reject actions, archived reports | System configuration, user management, ML threshold tuning, other officers' decision logs (unless admin grants it) |
| **System Administrator** | Everything the officer can see, plus: user management, vendor account management, ML model configuration, threshold tuning, audit trail, system settings, data retention policies | N/A — full access |

### Permission Enforcement

Permissions are enforced at the **middleware layer** in Laravel. Each route group is gated by role-checking middleware (`role:vendor`, `role:officer`, `role:admin`). The database stores a `role` column on the users table. Blade templates conditionally render navigation elements based on the authenticated user's role, so vendors never see officer-only menu items and officers never see admin-only configuration panels.

---

## 4. Dashboard Navigation Structure

The system features a **sidebar navigation layout** with role-scoped visibility. The sidebar persists on the left side of every authenticated page, with the main content area occupying the remaining viewport. Here is the full navigation tree, annotated by which roles see each item:

### Vendor Sidebar

| Tab / Section | What It Shows |
|---|---|
| **Dashboard (Home)** | Summary cards: total submissions, pending count, approved count, rejected count. Recent activity feed. |
| **Submit Documents** | Upload form with drag-and-drop zone. Format guidance (accepted types, size limits). Submission confirmation after upload. |
| **My Submissions** | Table of all past submissions with columns: date, document name, status (Processing / Pending Review / Approved / Rejected), and a detail link. Clicking a row shows which documents were included and their individual statuses. |
| **Notifications** | Chronological list of in-app alerts: "Your submission has been received," "Your BIR Permit has been flagged for review," "Your accreditation has been approved." |
| **Profile** | Account settings, password change, contact information. |

### Compliance Officer Sidebar

| Tab / Section | What It Shows |
|---|---|
| **Dashboard (Home)** | KPI cards: submissions pending review, flagged documents today, approval rate this period. Quick-access list of the five most urgent flagged submissions. |
| **Pending Submissions** | Queue of submissions awaiting officer review, sorted by risk score (highest first). Each row shows: vendor name, submission date, composite risk score (color-coded: green/yellow/red), number of flags. Clicking opens the full validation report. |
| **Validation Results** | Detailed per-document breakdown for a selected submission. Shows each stage's output: OCR extracted text, classification result and confidence %, signature similarity score, logo similarity score (vs the detected city's reference), individual pass/fail indicators, and the composite risk score. This is the **drill-down view** (see Section 6). |
| **Archived Reports** | Searchable, filterable archive of all past validation reports. Filters include: date range, vendor name, decision (approved/rejected), risk score range. Each archived report is viewable in full. |
| **Vendor Profiles** | Directory of all registered vendors. Each profile shows: company name, registration date, submission history, accreditation status, stored reference signature embedding info (enrolled at registration). Logo/stamp/seal references are **not** stored per vendor — they live in a per-issuer reference library (per-agency for national logos, per-city for LGU seals; see §4b / §8). |
| **Risk Logs** | Chronological audit log of every flag the system has raised. Filterable by flag type (text mismatch, low classification confidence, signature mismatch, stamp mismatch), severity, date range, and vendor. |
| **Notifications** | Officer-specific alerts: "New submission from [Vendor] requires review," "High-risk submission flagged," "Submission #1042 has been pending for 48+ hours." |

### System Administrator Sidebar

The admin sees **everything the officer sees**, plus additional tabs:

| Tab / Section | What It Shows |
|---|---|
| **User Management** | CRUD interface for all user accounts. Assign/change roles, activate/deactivate accounts, reset passwords. |
| **System Settings** | Configurable parameters panel (see Section 8 for the full list of tunable thresholds). Includes: file size limits, accepted formats, OCR confidence floor, ResNet-50 classification threshold, signature distance threshold, stamp/logo similarity threshold (vs city reference), risk score weights. |
| **ML Model Management** | Status of each ML model (ResNet-50, YOLOv8, Siamese CNN, EfficientNet). Last training date, validation accuracy, model file paths. Interface to trigger retraining or swap model versions. |
| **Audit Trail** | Immutable log of every significant system event: logins, submissions, validation runs, officer decisions, configuration changes, user account modifications. Each entry is timestamped and attributed to a user. |
| **Data Retention** | Configuration for archival and purging policies. Set retention periods for uploaded documents, processed images, validation reports, and embedding vectors. |

| **Document Requirements (Per Vendor Type)** | Editable admin UI to define which documents each vendor type must submit (example vendor types: `franchisee`, `supplier`). Admins can add/remove document types, mark each as **required** or **optional**, attach guidance text and example images, set an effective date and version, and scope rules to vendor types. Changes are stored in the global document catalog and become visible to officers and vendors without code changes. |

---

## 5. Processing Pipeline — Complete Document Flow

This section walks through exactly what happens from the moment a file enters the system to the moment a decision is recorded. Each stage describes the physical data transformations, the success path, and the failure path.

### Stage 0: Document Submission and Intake

**Trigger**: Vendor clicks "Submit" after uploading one or more files.

**What happens**:
1. Laravel validates the upload: checks file type against the whitelist (PDF, PNG, JPG), checks file size against the configured limit, checks for upload errors.
2. Valid files are stored to a secure, non-public directory on the server filesystem (or cloud storage, depending on deployment).
3. A `Submission` record is created in the database with status `PROCESSING`.
4. If the file is a **PDF**, the system invokes a Python script using `pdf2image` to convert the first two pages into PNG images at **300 DPI**. Each page becomes a separate processing unit.
5. If the file is already a **PNG or JPG**, it proceeds directly.
6. A processing job is dispatched (via Laravel's queue system) for each image.

**Failure path**: If the file fails validation (wrong type, too large, corrupt), the upload is rejected immediately with an error message to the vendor. No processing job is created.

---

### Stage 1: Image Preprocessing (OpenCV)

**Input**: Raw PNG/JPG image from the upload or PDF conversion.

**Operations** (in this exact sequence):

1. **Grayscale conversion** — `cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)` using BT.601 coefficients (`Gray = 0.114B + 0.587G + 0.299R`). Reduces the 3-channel color image to a single intensity channel, removing color information that is irrelevant for text and structural analysis.

2. **Binarization with inversion** — `cv2.threshold(gray, 150, 255, cv2.THRESH_BINARY_INV)`. Pixels darker than threshold 150 become white (255); brighter pixels become black (0). This isolates text (originally dark ink) as white foreground on a black background. The threshold of 150 was chosen empirically by the researchers as a balance between capturing text and excluding background noise.

3. **Morphological opening** — `cv2.morphologyEx(thresh, cv2.MORPH_OPEN, kernel)` with a 2×2 kernel. Erosion followed by dilation removes small white noise spots (dust, scan artifacts) while preserving the structural integrity of text strokes.

4. **Bitwise inversion** — `cv2.bitwise_not(cleaned)`. Flips the image back to black text on white background, which is the format PyTesseract expects for optimal recognition.

**Output**: A clean, binarized image optimized for OCR.

**Failure path**: This stage is deterministic — it does not "fail" in the traditional sense. However, if the input image is extremely degraded (blank page, completely black/white, severely corrupted), the output will be a meaningless image. This degradation propagates forward: OCR will extract little or no text, and the classification model will produce a low confidence score. Both of these outcomes are captured in the risk score, effectively flagging the document without needing a separate "preprocessing failed" check.

---

### Stage 2: Text Extraction and Validation (PyTesseract OCR)

**Input**: Preprocessed image from Stage 1.

**What happens**:
1. The preprocessed image is passed to **PyTesseract** (`pytesseract.image_to_string()`), which performs its own internal processing (segmentation into lines/words/characters, feature extraction, deep-learning-based character classification, and post-processing with dictionary correction).
2. The raw extracted text is passed through **NLP post-processing** for further correction and sentence structuring.
3. The cleaned text is compared against **predefined templates** — expected field names, required keywords, formatting patterns specific to each document type (e.g., a BIR certificate must contain certain registration numbers, a DTI Business Name Registration must contain a certificate number and TRN).

**Validation logic**: The system checks for:
- **Required fields present**: Does the extracted text contain the expected sections/keywords for this document type?
- **Format compliance**: Do registration numbers, dates, and amounts match expected patterns (regex-based)?
- **Completeness**: Are all mandatory fields populated, or are critical sections missing?
- **Issuing city / LGU**: The text is scanned for the issuing **city name** (e.g., "Pasig"). This city key is passed to **Stage 4b**, which uses it to look up the correct reference logo(s) for stamp/logo verification. If no city can be identified, Stage 4b cannot scope its lookup and raises a `City not identified` flag.

**Output**: A structured OCR result containing the extracted text blob, list of matched fields, list of missing/inconsistent fields, and the detected issuing city (for the §4b logo lookup). After Laravel receives the response, it computes the risk component's text validation score by comparing the aggregate OCR text against the submission vendor's non-empty registration fields: `company_name`, `trade_name`, `tin`, `dti_registration_number`, `sec_registration_number`, `business_permit_number`, `registration_number`, and a complete five-part `business_address`. Matching is case-insensitive, tolerant of punctuation/spacing separators, and automatically normalizes common address abbreviations (Brgy/St/Ave/etc.). The optional legacy SEC and business-permit values participate only when a historical profile has a populated value. The Python API's template-quality `text_validation_score` is not used for risk scoring.

The officer-review OCR table is separate from the aggregate text-risk calculation. It resolves each field against the available vendor-profile candidates and, for owner or representative fields, the related `VendorRepresentative` name variants (full name, first/last name without optional middle name or suffix, and surname-first order). Proprietor or business-owner fields use representative candidates first and the company name only as a fallback. Candidate comparison remains Unicode case-insensitive and boundary-safe while ignoring punctuation and separator differences; it does not use fuzzy matching. A field with no non-empty corresponding candidate is shown as **Unavailable**, rather than as a mismatch.

**Failure path**: If OCR produces little or no recognizable text (due to a blank page, an image without text, or extremely poor scan quality), Laravel records the text validation component as unavailable rather than falling back to the Python score. The system does **not** abort processing — it records the poor OCR result as a flag ("Insufficient text extracted" or "Required fields missing") and continues to the next stages. The missing component penalty contributes to the composite risk score. There is no automatic retry mechanism; the document is flagged for manual review, and the vendor may be asked to resubmit.

---

### Stage 3: Document Classification (ResNet-50)

**Input**: The original uploaded image, resized to **512 × 512 pixels**, converted to a NumPy array of shape `(1, 512, 512, 3)`, and normalized using ResNet-50's `preprocess_input` function (ImageNet standardization).

**Model architecture**:
- Base: ResNet-50 pre-trained on ImageNet, with the final 30 layers fine-tuned on vendor document datasets
- Custom head: Global Average Pooling → Dropout (0.5) → Dense 512 (ReLU, L2 regularization) → Dropout (0.3) → Softmax output layer
- Training: Two-phase — first phase (20 epochs, frozen base, lr=1e-4), second phase (10 epochs, final 30 layers unfrozen, lr=1e-5)
- Loss: Categorical cross-entropy with class weights (computed via scikit-learn's `compute_class_weight`) to handle imbalanced data
- Optimizer: Adam
- Saved format: HDF5 (`resnet50_authenticity.h5`) with pickled LabelEncoder

**What happens**:
1. The model produces a **probability distribution** over all document classes (e.g., `[0.02, 0.01, 0.96, 0.04]` for classes like BIR Permit, DTI Registration, etc.).
2. The index with the highest probability is decoded back to the class label using the saved LabelEncoder.
3. The **confidence score** is the maximum probability value (e.g., 96%).

**Output**: Predicted document class label + confidence score.

**Configurable parameter**: `CLASSIFICATION_CONFIDENCE_THRESHOLD` (e.g., 70%). If the confidence falls below this threshold, the document is flagged as "Unknown document type" or "Potential fake."

**Failure path**: A low confidence score does **not** halt the pipeline. The classification result and its below-threshold confidence are recorded as a flag. Processing continues to Stages 4–5 because even a poorly classified document may still have detectable signatures and stamps that provide additional forensic information. All flags are aggregated in Stage 6.

---

### Stage 4: Signature and Stamp Detection (YOLOv8)

**Input**: The original document image (or the preprocessed version, depending on configuration).

**What happens**:
1. The image is passed through a **YOLOv8 object detection model** trained to detect two object classes: `signature` and `stamp`.
2. YOLOv8 processes the image in a single forward pass through its Backbone (C2f modules + SPPF), Neck (PAN-FPN multi-scale fusion), and decoupled detection Head.
3. The model outputs **bounding box coordinates** with associated **confidence scores** for each detected region.
4. Detected regions are **cropped** from the original image using the bounding box coordinates.
5. **Signature crops** are forwarded to Stage 4a (Siamese CNN).
6. **Stamp / logo / seal crops** are forwarded to Stage 4b (EfficientNet), together with the issuing city OCR extracted in Stage 2.

**Document misalignment handling**: YOLOv8's multi-scale feature extraction inherently handles variations in position, scale, and rotation. There is no need for predefined ROI zones or homography-based alignment — the model locates signatures and stamps wherever they appear in the document.

**Failure path — no signature detected**: If YOLOv8 finds no signature region (confidence below its detection threshold), the system records a flag: "No signature detected." The signature verification stage is skipped for this document, and the absence is recorded as a risk factor in the composite score.

**Failure path — no stamp/logo detected**: Same logic. "No stamp/logo detected" is recorded as a flag. The stamp/logo authentication stage is skipped.

**Failure path — multiple detections**: If YOLOv8 detects multiple signature or stamp regions, the system uses the detection with the highest confidence score as the primary region. Additional detections may be logged for officer review.

---

### Stage 4a: Signature Verification (Siamese CNN)

**Input**: Cropped signature region from YOLOv8.

> **The signature reference is enrolled at vendor registration — not during the pipeline.** Every vendor captures a reference signature during sign-up (registration **step 2**, before email verification): they photograph three separated signatures on white bond paper, arranged in one row or one column. An authenticity check rejects software-edited/filtered images, and the accepted capture is embedded once into the vendor's **128-dimensional reference embedding** and stored on the vendor record (`users.signature_path` / `vendor_embeddings.signature_embedding`). Because the reference exists **before any document is ever submitted**, the pipeline **always runs in verification mode** — there is no "first submission auto-enrolls" branch here. (This is the opposite of the logo handling in §4b, where references are keyed by city and seeded on first approval.)

Registration detection is calibrated independently from Stage 4 document detection. It prefers an optional dedicated enrollment detector and otherwise falls back to the document detector using `SIGNATURE_ENROLL_DETECTION_CONFIDENCE` and `SIGNATURE_ENROLL_DETECTION_IMGSZ`. These settings must not change the document-wide `YOLO_DETECTION_CONFIDENCE` behavior.

**Verification (every submission)**:
1. The new cropped signature is preprocessed (resized to fixed input size, pixel values normalized).
2. It is passed through the Siamese CNN to produce a **query embedding vector** (128 dimensions).
3. The system retrieves the vendor's stored reference embedding (captured at registration).
4. **Euclidean distance** is computed between the query and reference vectors.
5. The distance is converted to a **similarity score** (smaller distance = higher similarity).

**Configurable parameter**: `SIGNATURE_DISTANCE_THRESHOLD` — determined empirically during model validation to balance false acceptance rate (FAR) and false rejection rate (FRR). If the distance exceeds this threshold, the signature is flagged as a possible forgery.

**Output**: Similarity score + pass/fail indicator.

**Failure paths**:
- **Signature mismatch** (distance above threshold) → "Signature mismatch — possible forgery" flag. The document is not rejected outright; the flag feeds the composite risk score, and the officer sees the specific similarity percentage and makes the judgment call.
- **No signature detected** by YOLOv8 (Stage 4) → this verification stage is skipped and "No signature detected" is recorded as a risk factor.

**Cross-document consistency**: Because the reference embedding is fixed at registration and stored persistently, every later submission is checked against the same baseline. If a vendor's signature drifts dramatically from the enrolled reference, every subsequent document triggers a mismatch flag.

---

### Stage 4b: Stamp / Logo / Seal Authentication (EfficientNet)

**Input**: Cropped stamp / logo / seal region from YOLOv8, **plus the city name extracted by OCR in Stage 2**.

> **Logo references are keyed by the document's issuer, not by the vendor.** Official stamps, logos, and seals belong to whoever **issues** the document. `document_types.issuer_scope` records which kind, and that drives how the reference is keyed:
> - **`national`** (e.g. BIR Permit, SEC GIS) — one logo agency-wide; the reference is keyed by **document type alone** (the BIR logo is identical on every BIR document, in any city).
> - **`lgu`** (e.g. Business Permit) — one seal per city; the reference is keyed by **(document type, city)** (Pasig's business-permit seal differs from Quezon City's).
> - **`null`** (e.g. Signed Contract) — no official issuer logo; the reference lookup is skipped (only the texture check runs).
>
> Curated references live under **`python/logo_and_seals`** and are indexed by its versioned `manifest.json`. The manifest may list multiple ordered images for one issuer (for example, the BIR logo and seal). The **`logo_references`** table remains the enrolled-reference fallback when no usable curated candidate exists. There is **no per-vendor stamp embedding** and no per-vendor enrollment step. (This is the opposite of the signature handling in §4a, which uses one per-vendor reference enrolled at registration.)

**Texture check (always runs, reference or not)**:
1. The cropped region is preprocessed (resized, normalized).
2. It is passed through an **EfficientNet model** (pre-trained, classification head removed, used as a fixed feature extractor) to produce a **compact feature vector** and analyze texture.
3. EfficientNet's MBConv blocks distinguish **wet-ink-like impressions** from **photocopied, scanned, or digital reproductions** regardless of whether an issuer reference exists. A reproduction-like result is presented to officers as `Stamp has scan/copy texture`. It describes the crop's texture and does **not** assert that the stamp artwork was altered. (Halftone dot patterns in photocopies, for example, produce a different texture signature than wet ink.) The legacy API/database field remains `stamp_tampered` for compatibility.

**Issuer lookup & verification**:
1. Classification (Stage 3) gives the **document type**; the pipeline reads its `issuer_scope`.
2. It resolves all matching curated candidates from `python/logo_and_seals/manifest.json` by **document type** (`national`) or by **(document type, canonical detected city)** (`lgu`, where the city comes from OCR in Stage 2; for `national` the detected city is ignored).
3. Each usable curated image is embedded lazily and compared with the query crop. Missing or corrupt individual assets are skipped without aborting the stage.
4. If no curated candidate can be used, the pipeline falls back to the single Laravel-provided vector from `logo_references`.
5. All resolved candidates are compared in manifest order. The **highest similarity** determines the legacy aggregate `match`, `similarity_score`, and stamp/logo risk component; per-candidate similarities remain in page-level pipeline provenance.

**Configurable parameter**: `STAMP_SIMILARITY_THRESHOLD` (default: **85%**). If the similarity to the issuer reference meets or exceeds this threshold, the logo is validated; below it, it is flagged for manual review.

**Fallback reference seeding (per issuer, on first approval)**: An enrolled database reference is **not** created automatically on first sight — trusting an unverified logo would let a forgery become the standard. For issuers that have no usable curated candidate, a detected logo is flagged `Unknown / unreferenced logo` and the feature vector is held pending. The **first time a compliance officer approves a document carrying that issuer's logo**, that logo may be enrolled in `logo_references` against the **document type** (`national`) or **(document type, city)** (`lgu`); the officer's approval decision is the trust gate. Curated candidates continue to take precedence over this fallback.

**Output**: Texture result + (when an issuer reference exists) the best similarity percentage and pass/fail indicator; optional `reference_source`, `best_reference_key`, and ordered `reference_matches` identify the candidate evidence while preserving the legacy aggregate fields. When no reference exists, the result carries an `Unknown / unreferenced logo` flag; when `issuer_scope` is `null`, only the texture result is recorded.

**Failure paths**:
- **No issuer logo expected** (`issuer_scope = null`) → the reference lookup is skipped; only the texture result is recorded.
- **City not identified** (an `lgu` document type where OCR found no recognizable city) → `City not identified` flag; the lookup cannot be scoped, so verification is skipped and the absence feeds the risk score.
- **No reference for that issuer yet** → `Unknown / unreferenced logo` flag; contributes to the composite risk score and becomes the reference only if an officer later approves the document.
- **Scan/copy texture detected** → `Stamp has scan/copy texture` flag (raised even before any reference exists). This means the crop resembles a scanned, photocopied, or digital reproduction; it is not a finding that the stamp artwork was altered.
- **Below-threshold similarity** (issuer reference exists) → `Logo mismatch — suspect reproduction` flag.

Issuer-reference availability and identity similarity feed the stamp/logo risk component. The scan/copy texture label is contextual evidence for officer review and does not by itself add stamp risk points or establish document tampering.

---

### Stage T: Forensic Tampering Analysis (document-wide)

**Input**: The **original uploaded file** (and, for PDFs, full-DPI rendered page images) — **not** the Stage 1 preprocessed image, whose grayscale + binarization + morphology destroy the very signals forensics depends on (JPEG compression history, colour, resolution, and the file container/metadata). The `documents` table retains both `file_path` (original) and `converted_image_path` (preprocessed) precisely so this stage can read the original.

**Why this stage exists**: Stages 2–4b validate *components* (text, type, signature, stamp) but do not look for the traces of *editing* a document. A vendor can change a name, date, or TIN, or paste a genuine stamp/signature lifted from another document, while every component still "matches". Stage T is a **document-wide forensic layer** that hunts for those tampering traces. It is implemented as `python/scripts/tamper_analyze.py` over the `python/forensics` package and is **fail-forward**: a technique that cannot run (e.g. ELA on a non-raster page) is recorded as *skipped* and excluded from the blend rather than aborting the document.

**Five techniques** (each returns an authenticity score in [0,1], 1 = clean):

| # | Technique | Catches | Method |
|---|---|---|---|
| T1 | **Metadata analysis** | Editor software signatures (Photoshop/GIMP), modify-date after creation/issue date, stripped metadata | EXIF (Pillow/piexif) for images, document-info/XMP (pikepdf) for PDFs |
| T2 | **Error Level Analysis (ELA)** | Pasted/edited regions with a different compression history | Recompress at a known JPEG quality, measure per-block error, flag spatially-clustered hotspots |
| T3 | **Copy-move / clone detection** | A region (stamp, signature, field) duplicated within the same image | ORB keypoints self-matched, binned by a consistent translation offset |
| T4 | **Font-consistency analysis** | A field re-typed in a mismatched font/size/spacing | Robust (median/MAD) outlier test over Stage 2 OCR word boxes |
| T5 | **OCR cross-reference** | Malformed/fabricated identifiers (TIN, registration number) | Format/checksum validation in Python; authoritative DB existence check on the Laravel side (`App\Support\TinValidator`, `RegistrationNumberValidator`) |

> A 6th **ML fusion** layer — a model trained on genuine/forged documents that ingests the five signals — is the planned next step; until a labelled tampered dataset exists, the aggregate uses a deterministic weighted blend of the five technique scores.

**Relationship to Stage 4b**: complementary, not redundant. Stage 4b's EfficientNet texture check is *stamp-specific* (wet-ink vs. photocopy on the cropped seal); Stage T is *document-wide* and operates on the whole page and the file container.

**Output**: an aggregate verdict stored one-to-one with the document in `tamper_analyses`:
- `tamper_score` (0 clean .. 1 tampered) and `tamper_authenticity` (its inverse, fed to Stage 5 as the 5th weighted component),
- `tamper_confidence` — the single strongest technique signal,
- `hard_flag` — true when any one technique reports tamper evidence at/above `TAMPER_HARD_THRESHOLD` (e.g. an exact-pixel clone), which alone fails the forensic gate so strong localized fraud is not averaged away,
- the per-technique breakdown and a merged `flags` list.

---

### Stage 5: Risk Score Computation and Report Generation

**Input**: All outputs from Stages 1–4b **and Stage T**.

**What happens**:
1. The system **aggregates** results from all validation components:
   - Text validation score (Laravel regex matching of aggregate OCR text against the vendor's non-empty registration fields)
   - Document classification confidence (from ResNet-50)
   - Signature similarity score (from Siamese CNN) — or "not detected" flag
   - Logo similarity score (from EfficientNet, vs the issuer's reference) — or "not detected / unreferenced / city not identified / no issuer logo" flag
   - Forensic tampering authenticity (from Stage T) — or *skipped*
2. Each component contributes to a **composite risk score** using configurable weights.

**Risk score formula** (conceptual):

```
Composite Risk = w1 × (1 - text_validation_score)
               + w2 × (1 - classification_confidence)
               + w3 × (1 - signature_similarity)
               + w4 × (1 - stamp_similarity)
               + w5 × (1 - tamper_authenticity)
               + penalty_flags

IF tamper_confidence ≥ TAMPER_HARD_THRESHOLD:
        risk_level := High   (hard override — regardless of the weighted blend)
```

Where `w1 + w2 + w3 + w4 + w5 = 1.0` and `penalty_flags` adds additional risk for missing/unverifiable components (no signature detected, no logo detected, unreferenced/unidentified city logo, insufficient OCR text). A missing component is excluded from the (renormalised) weighted blend and instead contributes `MISSING_COMPONENT_PENALTY`; a *skipped* forensic stage carries no penalty (fail-forward). The **hard override** ensures a high-confidence tampering signal (e.g. an exact-pixel clone) forces a High Risk band even when the other component scores would otherwise dilute it.

**Configurable parameters**:
- `RISK_WEIGHT_TEXT` (w1) — e.g., 0.20
- `RISK_WEIGHT_CLASSIFICATION` (w2) — e.g., 0.20
- `RISK_WEIGHT_SIGNATURE` (w3) — e.g., 0.20
- `RISK_WEIGHT_STAMP` (w4) — e.g., 0.20
- `RISK_WEIGHT_TAMPER` (w5) — e.g., 0.20
- `MISSING_COMPONENT_PENALTY` — additional risk points for undetected signatures/stamps
- `TAMPER_HARD_THRESHOLD` — tamper confidence at/above which the band is forced to High

**Output**: A **Validation Report** containing:
- Per-document summary (each page processed)
- Individual component scores with pass/fail indicators
- List of all flags raised, with explanations
- Composite risk score (0–100 scale, higher = more risk)
- Risk level classification: Low (0–30), Medium (31–60), High (61–100)
- All reports are stored in the database and linked to the submission record

The submission status changes from `PROCESSING` to `PENDING_REVIEW`.

---

### Stage 6: Officer Review and Decision

**Input**: The generated Validation Report, displayed on the Compliance Officer's dashboard.

**What happens**:
1. The submission appears in the officer's **Pending Submissions** queue, sorted by risk score (highest risk first).
2. The officer clicks into the submission to view the **full Validation Report**.
3. The officer reviews:
   - The uploaded document images
   - OCR-extracted text and any text mismatch flags
   - Document classification result and confidence
   - Signature comparison visual (reference vs. query) with similarity percentage
   - Stamp comparison visual with similarity percentage
   - The composite risk score breakdown showing which components contributed most
4. The officer renders a decision: **Approve** or **Reject**.
5. The decision is recorded with a timestamp, the officer's user ID, and optional comments.
6. The submission status changes to `APPROVED` or `REJECTED`.
7. The vendor is notified of the decision.

**The system is explicitly human-in-the-loop**: The thesis states that the compliance officer "reviews the system's findings and makes the final accreditation decision (approve/reject)." The ML pipeline produces recommendations and flags, but a human always makes the final call. All findings are auditable.

---

## 6. Risk Score Drill-Down in the Dashboard

The composite risk score is not a black box. When an officer clicks on a submission's risk score, the dashboard presents a **component-level breakdown**:

| Component | Score | Threshold | Status | Detail |
|---|---|---|---|---|
| Text Validation (OCR) | 82% | 70% | ✓ Pass | 2 of 14 expected fields could not be matched |
| Document Classification (ResNet-50) | 96% confidence | 70% | ✓ Pass | Classified as "BIR Permit" |
| Signature Match (Siamese CNN) | 43% similarity | 75% | ✗ Fail | Distance: 1.87 (threshold: 1.20) |
| Issuer References Matching | 91% similarity | 85% | ✓ Pass | Cosine similarity: 0.91 vs Pasig reference logo |
| Forensic Tampering (Stage T) | 88% authenticity | 50% | ✓ Pass | Metadata/ELA/copy-move/font/cross-ref all clean |
| **Composite Risk Score** | **62 / 100** | — | ⚠ Medium | Signature mismatch is primary driver |

Each row is expandable. The **Forensic Tampering** row expands into its five techniques (metadata, ELA, copy-move, font, cross-reference) with per-technique score, flags, and — for ELA/copy-move — the suspect region(s) highlighted on the page. Clicking **Signature Match**, for example, shows:
- The reference signature image (enrolled at vendor registration)
- The query signature image (from this submission)
- The 128-D embedding distance value
- A visual overlay or side-by-side comparison

This drill-down allows the officer to see exactly **which component** caused a high risk rating and make an informed judgment. A high risk score driven by a stamp mismatch might lead to a different decision than one driven by a text extraction failure on a poorly scanned document.

---

## 7. Notification and Alerting

### In-Dashboard Notifications

Every significant event generates an in-app notification visible in the user's **Notifications** tab:

| Event | Recipient | Notification |
|---|---|---|
| Document uploaded | Vendor | "Your submission has been received and is being processed." |
| Processing complete | Vendor | "Your submission has been processed and is awaiting review." |
| Document flagged | Officer | "Submission from [Vendor] has been flagged: [flag type]." |
| High-risk submission | Officer | "High-risk submission detected (score: 78/100) from [Vendor]." |
| Decision made (approved) | Vendor | "Your accreditation has been approved." |
| Decision made (rejected) | Vendor | "Your accreditation has been rejected. Reason: [officer's comment]." |
| Submission pending > 48h | Officer | "Submission #1042 has been pending review for 48+ hours." |
| Configuration changed | Admin | "System threshold updated by [Admin User]." |

### Email Notifications (Configurable)

The implementing organization can enable email notifications for critical events (submission received, decision made, high-risk flag). This would be implemented via Laravel's built-in Mail system with configurable toggles in System Settings. The thesis does not mandate email notifications, but the architecture supports them as an optional layer.

### No Mobile Push

The thesis explicitly states the system "will not provide mobile access" within the current scope, so mobile push notifications are out of scope.

---

## 8. Database and Storage Considerations

### Document Storage

| Data Type | Storage Location | Format |
|---|---|---|
| Uploaded raw files (PDF, PNG, JPG) | Server filesystem or cloud storage (e.g., Laravel's `storage/app/private/submissions/`) | Original format |
| Converted PNG pages (from PDF) | Same storage, under a processing subdirectory | PNG at 300 DPI |
| Preprocessed images | Temporary storage during processing; may be discarded after pipeline completion or retained for audit | PNG |

All file paths are stored as references in the database, not the files themselves. Files are organized by vendor ID and submission ID for easy retrieval.

### Reference Embeddings

| Data Type | Storage | Format |
|---|---|---|
| Signature reference embedding | Database, **per vendor** (`users.signature_path` + `vendor_embeddings.signature_embedding`) | 128-dimensional float vector, serialized as JSON or binary blob |
| Curated logo / stamp / seal references | Versioned `python/logo_and_seals/manifest.json` plus image files, **per issuer** | Ordered image candidates; vectors are embedded lazily and cached by asset timestamp and model identity |
| Enrolled logo / stamp / seal fallback vector | Database, **per issuer** (the `logo_references` table, keyed by `document_type` for national issuers and `(document_type, city)` for LGU issuers) | Float vector per issuer (dimension depends on EfficientNet variant), serialized similarly |

The **signature** reference embedding is created during the vendor's **registration** (step 2, before email verification) — it exists before any document is submitted, so the pipeline only ever *verifies* against it. **Logo / stamp / seal** references are **not** stored per vendor. Validation first uses the curated manifest candidates keyed by the **issuer** — `document_type` for national agencies (BIR/DTI) and `(document_type, city)` for LGUs — and compares every matching image. The database reference seeded through officer approval is used only when no curated candidate can be embedded. Document types with `issuer_scope = null` (e.g. Signed Contract) have no identity reference. The legacy per-vendor `vendor_embeddings.stamp_*` columns are **superseded** by this per-issuer model.

### Global Document Catalog & "Available Documents" Storage

The application exposes a centrally managed, editable catalog of document types and submission requirements that drives the vendor submission UI and officer/admin views. This global catalog is maintained by administrators via the **Document Requirements** admin tab (see Section 4). Key aspects:

- **Persistence**: stored in a new `document_requirements` table with fields: `id`, `code`, `title`, `description`, `vendor_type` (enum: e.g. `franchisee`,`supplier`,`all`), `required` (boolean), `examples` (JSON — image paths / guidance), `effective_at` (datetime), `version` (int), `created_by`, `updated_by`, `created_at`, `updated_at`. Each row defines a single document requirement record. Administrators can create multiple rows per vendor type and update them over time (versioned via `version` + `effective_at`).

- **Cache & availability API**: to make the current set of available/required documents inexpensive to read from the submission UI and officer dashboards, the app writes a derived payload to a cache key (for example `available_documents`) after any admin change. The payload groups requirements by `vendor_type` and exposes the currently effective version for each type. A read-only API endpoint (e.g. `GET /api/catalog/available-documents`) returns the cached payload to web clients; when cache is cold the endpoint recomputes the derived payload from `document_requirements`.

- **UI consumption**: the vendor `Submit Documents` form queries the `available_documents` payload for the authenticated vendor's `vendor_type` and renders required vs optional upload slots, guidance text, and example images. Officers and admins see the same payload in an `Available Documents` pane on their dashboard so they know what documents the vendor was expected to submit when reviewing a submission.

- **Editable (not fixed)**: because the catalog is fully editable through the admin UI, administrators can add/remove document types, change required flags, attach new guidance images, and set effective dates without code deployments. All edits are recorded in the audit trail, and a `preview` or `draft` mode lets admins stage changes before making them effective.

- **Enforcement**: submission Form Requests validate that a vendor has supplied all `required` documents present in the effective catalog for their `vendor_type`. Validation failures return 422 with a helpful list of missing documents. Historical submissions retain the catalog version at the time of submission (the submission record stores `catalog_version`), ensuring audits reference the correct expectation set.

- **Examples**: out-of-the-box vendor types include `franchisee` and `supplier`. Admins can add other types (e.g., `distributor`) as needed. Typical requirements for the two example types might include rows such as: BIR Permit (required for `franchisee`), Business Permit (required for `franchisee` and `supplier`), Product List / Pricelist (required for `supplier`), Food Safety Certificate (optional for `supplier`, required for certain franchise categories). Administrators tailor the catalog to local regulatory needs.

### Model Files

| Model | File | Format |
|---|---|---|
| ResNet-50 | `resnet50_authenticity.h5` | HDF5 (Keras) |
| LabelEncoder | `label_encoder.pkl` | Pickle (scikit-learn) |
| YOLOv8 | `yolov8_detector.pt` | PyTorch |
| Siamese CNN | Model weights file | HDF5 or PyTorch |
| EfficientNet | Pre-trained weights | HDF5 or PyTorch |

Model files are stored on the server filesystem and loaded into memory by the Python inference scripts when processing jobs run.

### Validation Reports and Audit Logs

All validation reports, officer decisions, and audit trail entries are stored in the **relational database** (MySQL, managed via Laravel migrations). These records include timestamps, user attribution, and are designed to be immutable once written (append-only audit trail).

### Data Retention (Configurable)

The thesis's ethical considerations section states that "after the research, the documents will be disposed of appropriately," indicating awareness of data lifecycle management. In production, the admin can configure:

- **Active retention period**: How long submitted documents and reports remain in the primary database (e.g., 3–5 years, aligned with the organization's compliance requirements).
- **Archival policy**: After the active period, records can be moved to cold storage (compressed archives) while retaining the metadata and decision records in the database.
- **Purging policy**: After the archival period, documents can be permanently deleted. Reference embeddings may be retained longer if the vendor remains active.
- **Audit trail**: Audit logs are **never purged** — they represent the compliance record.

---

## 9. Complete Parameter Reference

All configurable parameters that the implementing organization would set:

| Parameter | Default | Description |
|---|---|---|
| `MAX_FILE_SIZE_MB` | 10 | Maximum upload size per file in megabytes |
| `MAX_BATCH_SIZE_MB` | 50 | Maximum total upload size per submission |
| `ACCEPTED_FORMATS` | pdf, png, jpg, jpeg | Allowed file MIME types |
| `MAX_PDF_PAGES` | 2 | Number of PDF pages to extract and process |
| `PDF_DPI` | 300 | Resolution for PDF-to-PNG conversion |
| `RESIZE_DIMENSION` | 512 × 512 | Image resize target for ResNet-50 input |
| `BINARIZATION_THRESHOLD` | 150 | Grayscale threshold for binarization step |
| `MORPH_KERNEL_SIZE` | 2 × 2 | Kernel dimensions for morphological opening |
| `CLASSIFICATION_CONFIDENCE_THRESHOLD` | 0.70 | Minimum ResNet-50 confidence to pass |
| `YOLO_DETECTION_CONFIDENCE` | 0.50 | Minimum YOLOv8 detection confidence |
| `SIGNATURE_ENROLL_DETECTION_CONFIDENCE` | 0.20 | Registration-only minimum confidence for the three-signature capture; benchmarked separately from document detection |
| `SIGNATURE_ENROLL_DETECTION_IMGSZ` | 1280 | Registration-only YOLO inference size for thin handwritten strokes on blank paper |
| `SIGNATURE_DISTANCE_THRESHOLD` | Empirical | Maximum Euclidean distance for signature match |
| `STAMP_SIMILARITY_THRESHOLD` | 0.85 | Minimum cosine similarity for a logo match against the detected city's reference (85%) |
| `RISK_WEIGHT_TEXT` | 0.20 | Weight of text validation in composite risk |
| `RISK_WEIGHT_CLASSIFICATION` | 0.20 | Weight of classification confidence in composite risk |
| `RISK_WEIGHT_SIGNATURE` | 0.20 | Weight of signature score in composite risk |
| `RISK_WEIGHT_STAMP` | 0.20 | Weight of stamp/logo score in composite risk |
| `RISK_WEIGHT_TAMPER` | 0.20 | Weight of Stage T forensic authenticity in composite risk (w5) |
| `MISSING_COMPONENT_PENALTY` | 15 | Additional risk points per missing component |
| `HIGH_RISK_THRESHOLD` | 61 | Score above which submission is classified High Risk |
| `MEDIUM_RISK_THRESHOLD` | 31 | Score above which submission is classified Medium Risk |
| `TAMPER_AUTHENTICITY_THRESHOLD` | 0.50 | Aggregate forensic authenticity below which the Stage T gate fails |
| `TAMPER_HARD_THRESHOLD` | 0.80 | Single-technique tamper confidence that hard-flags the doc / forces High Risk |
| `TAMPER_WEIGHT_METADATA` | 0.20 | Stage T blend weight — metadata (T1) |
| `TAMPER_WEIGHT_ELA` | 0.25 | Stage T blend weight — Error Level Analysis (T2) |
| `TAMPER_WEIGHT_COPY_MOVE` | 0.25 | Stage T blend weight — copy-move detection (T3) |
| `TAMPER_WEIGHT_FONT` | 0.15 | Stage T blend weight — font consistency (T4) |
| `TAMPER_WEIGHT_CROSS_REFERENCE` | 0.15 | Stage T blend weight — OCR cross-reference (T5) |
| `DATA_RETENTION_YEARS` | 5 | Years before documents are eligible for archival |

---

## 10. End-to-End Flow Summary

```
VENDOR                          SYSTEM                              OFFICER / ADMIN
──────                          ──────                              ───────────────

1. Login ──────────────────────► Auth check (Laravel middleware)
                                 Role-based routing

2. Upload documents ───────────► Validate (type, size)
                                 Store to filesystem
                                 Create Submission record
                                 Dispatch processing job

                                3. If PDF → pdf2image (300 DPI,
                                   first 2 pages → PNG)

                                4. Image Preprocessing (OpenCV)
                                   Grayscale → Binarize(150) →
                                   Morph Open(2×2) → Invert

                                5. OCR (PyTesseract)
                                   Extract text → NLP cleanup →
                                   Template matching → Score

                                6. Classification (ResNet-50)
                                   512×512 input → Softmax →
                                   Class label + confidence

                                7. Detection (YOLOv8)
                                   Locate signature + stamp →
                                   Crop regions

                                8a. Signature (Siamese CNN)
                                    Embed → Compare to the per-vendor
                                    registration reference →
                                    Euclidean distance → Score

                                8b. Stamp / Logo (EfficientNet)
                                    Texture check (always) → look up the
                                    OCR city's reference logo; if none →
                                    flag "Unknown/unreferenced logo";
                                    else compare → Cosine sim → Score

                                8c. Forensic Tampering (Stage T) — on the
                                    ORIGINAL file, not the preprocessed image
                                    Metadata + ELA + Copy-move + Font +
                                    Cross-reference → tamper_score / confidence

                                9. Aggregate all scores (incl. Stage T) →
                                   Compute composite risk (5 weighted terms +
                                   tamper hard-override) →
                                   Generate Validation Report →
                                   Status: PENDING_REVIEW

10. Receive "processing                                            11. See submission in
    complete" notification                                             Pending queue

                                                                    12. Open Validation Report
                                                                        Review each component
                                                                        Drill into flagged items

                                                                    13. Decide: Approve / Reject
                                                                        Record decision + comments
                                                                        Status: APPROVED / REJECTED
                                                                        If approved doc carried a logo for
                                                                        a city with no reference yet → seed
                                                                        it as that city's reference logo

14. Receive decision            ◄────────────────────────────────── Decision recorded
    notification                                                    Audit trail updated

15. View updated status
    in My Submissions
```

---

*This document is grounded in the thesis manuscript by Lopez, Inocencio, and Recto (Pamantasan ng Lungsod ng Pasig, August 2025), advised by Riegie Dy Tan, DIT. Implementation details for areas not explicitly specified by the thesis (file size limits, dashboard layout, notification triggers, retention policies) represent reasonable architectural recommendations consistent with the system's stated scope and Laravel 11 technology stack.*


---

## File: error_pages_implementation.md

# Error Pages Implementation Guide

## Overview
This document outlines the error pages required for optimal user experience on the website. Each error page should be custom-designed, branded, and provide clear guidance to users.

## Required Error Pages

### 1. 404 - Page Not Found
**HTTP Status:** 404  
**Trigger:** Invalid URL, broken links, deleted pages

**Requirements:**
- Friendly, non-technical message
- Search functionality
- Links to popular pages/homepage
- Brand personality (humor/illustrations encouraged)
- Clear "Go Home" CTA button

**Content:**

---

# Project Phases and Planning

## File: docs/CLIENT_INTERVIEW_GAP_PLAN.md

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
centralized status tracking, a clean **resubmission** loop, and an **audit trail**. The
food-business context also changes the **document types** (Philippine LGU/food-safety
permits) the system must recognize.

**Approved direction (confirmed with the user):**
1. **Keep the existing ML pipeline**, add a compliance lifecycle layer on top of it.
2. **Vendor-only scope.** The interview raised onboarding employees/personnel, but the
   accreditation **subject is the vendor only** — personnel/employee onboarding is
   **explicitly out of scope** (no personnel subject model, no personnel-specific document
   types). The system accredits the *business*, not its individual staff.
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
client actually prioritized** (expiration, checklists, resubmission, renewals)
**do not exist yet.**

---

## Gap analysis — interview need vs. current build

| # | Client need (from interview) | Today | Gap |
|---|---|---|---|
| G1 | **Expiration monitoring** — flag expired permits; the #1 pain | No expiry/issue-date fields anywhere | New data fields + extraction + status logic |
| G2 | **Renewal reminders** — calendar-driven alerts before expiry | System is explicitly "on-demand, not calendar-driven" | New scheduler + reminder notifications |
| G3 | **Completeness checklist** per vendor type — "detect missing documents" | `document_types.is_required` boolean only | Requirement profiles + per-submission checklist state |
| G4 | **OCR field extraction** — business name, registration #, expiry, permit validity | OCR returns raw text + confidence; no structured fields | Structured field parsing + storage + display |
| G5 | **Personnel/employee onboarding** | Vendor-only | **DESCOPED — out of scope.** Accreditation subjects are vendors only; no personnel subject model and no personnel-specific document types. Kept here only to record the decision. |
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

- **Subject stays the vendor (G5 descoped).** Submissions remain keyed to `vendors` only —
  **no** `personnel`/`accreditation_subjects` table and **no** `subject_type`/`subject_id`
  polymorphism. The vendor-centric model is the final shape.
- **Expiration fields (G1/G4).** Add to `documents` (or `validation_results`):
  `issue_date` (nullable date), `expiry_date` (nullable date),
  `document_number` (nullable string), `extracted_fields` (json),
  and a derived `validity_status` (`valid` / `expiring_soon` / `expired` / `unknown`).
- **Checklist / requirement profiles (G3).** New tables:
  - `requirement_profiles` — a named checklist per vendor category
    (e.g., "Food Supplier", "Beverage Distributor").
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
Vendor submits
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
- **Vendor portal:** show checklist progress ("4 of 6 required documents"),
  expiry badges, and a re-upload affordance for resubmission-requested items.
- **Admin:** CRUD for **requirement profiles** and their items; food-domain document types.
- Wire the above to **real persistence**, replacing the demo store incrementally.

### 5. Document types (G7) — re-seed for the food domain

Replace/extend `DocumentTypeSeeder` with Negofood-relevant types, each tagged with
`requires_expiry` and `issuer_scope`:

- Mayor's / Business Permit (LGU, expires) · BIR Certificate of Registration (national) ·
  DTI or SEC registration (national) · **Sanitary Permit** (LGU, expires) ·
  **Food Handler Certificate** (expires) · **FDA registration/certificate** (national, expires) ·
  Valid Government ID (of the authorized representative) · Supplier Accreditation Form · Bank details.

> Personnel-specific document types (NBI/police clearance, health/medical certificate,
> employment contract) are **out of scope** (vendor-only) and are intentionally **not** seeded.

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
3. The system **tracks completeness** against a **per-vendor-type checklist** and flags
   missing documents.
4. The system **extracts key fields** (name, registration #, dates) and shows them to the reviewer.
5. The system onboards **vendors** against **per-vendor-type requirement profiles** (vendor-only; no personnel onboarding).
6. The system supports a **resubmission loop** (request → re-upload flagged items only).
7. **Human officer makes the final decision**; every decision is **audit-logged**.
8. Document types reflect **Philippine food-business compliance** (LGU + food-safety permits).

---

## Suggested phasing (incremental, staging-first)

> **Now elaborated as three concern-split phase plans** — Phases A–E below are reorganized by concern and
> carried into them: backend/pipeline → [phases/PIPELINE_INTEGRATION_PHASES.md](phases/PIPELINE_INTEGRATION_PHASES.md),
> ML training → [phases/MODEL_TRAINING_PHASES.md](phases/MODEL_TRAINING_PHASES.md),
> UI functions → [phases/UI_FUNCTION_PHASES.md](phases/UI_FUNCTION_PHASES.md).

- **Phase A — Data & domain:** migrations (expiry fields, requirement profiles,
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
  panels; officer + vendor views. *DoD:* end-to-end click-through works.

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
- **Government IDs = PII:** uploaded representative IDs are sensitive — keep under
  `storage/app/documents/` (private), honor retention policies, role-gate access.
- **Scope discipline:** ML pipeline stays as-is this round; resist re-tuning models.
  **Vendor-only** subject — do not reintroduce personnel/employee onboarding (see G5, descoped).

---

## Verification (for the eventual implementation phases)

- `php artisan test --compact` green, including new tests:
  expiration status transitions, checklist completeness, resubmission flow,
  real upload happy-path + `Queue::assertPushed(ProcessDocumentJob)`.
- `pytest python/tests/ -v` green for new inference + field-extraction scripts.
- Manual click-through: vendor upload (expired permit) → officer sees "expired" +
  "incomplete" flags → requests resubmission → vendor re-uploads → approval logged →
  renewal reminder scheduled.

---

## Status of this document

This is a **planning document only** — no feature code accompanies it. It captures the
gap between the current build and the Negofood Solution interview, and lays out Phases A–E
for the actual implementation. Implementation begins once this plan is reviewed/approved.


---

## File: docs/phases/MODEL_TRAINING_PHASES.md

# ADVS — Python Model Training Phases

> **One of three concern-split phase plans.** This file owns the **Python ML training track**: the
> datasets, training scripts, weights, and thresholds behind the four (soon five) models the pipeline
> consumes. It does **not** cover Laravel wiring or UI — those are the companion plans.
>
> Companions:
> - Pipeline integration → [PIPELINE_INTEGRATION_PHASES.md](./PIPELINE_INTEGRATION_PHASES.md)
> - UI functions → [UI_FUNCTION_PHASES.md](./UI_FUNCTION_PHASES.md)
>
> Sources this plan integrates:
> - **Model architectures & I/O contracts** — [ADVS reference](../ADVS_REFERENCE.md) (Stages 3, 4, 4a, 4b, T; §8 model files; §9 thresholds).
> - **Historical training spec** — [archived training brief](../archive/TRAINING_SCRIPT_LEGACY.md).
> - **How we build (Python)** — [README.md](../../README.md), [AGENTS.md](../../AGENTS.md), and [python/README.md](../../python/README.md).
> - **New client direction** — [CLIENT_INTERVIEW_GAP_PLAN.md](../CLIENT_INTERVIEW_GAP_PLAN.md): the food-business domain changes the **document-type classes**; the compliance features (expiry/checklist/renewal) need **no new ML model** (field extraction is OCR + rules).
> - **Existing dataset plans** — [bir-synthetic-dataset-generator](../superpowers/plans/2026-06-19-bir-synthetic-dataset-generator.md) and [business-permit-classifier-dataset](../superpowers/plans/2026-06-28-business-permit-classifier-dataset.md).

---

## Negofood impact on the ML track (read first)

The interview changes **what the classifier must recognize**, not the model zoo:

- **New classifier classes** for the food-business domain (G7): Mayor's/**Business Permit** (LGU),
  **BIR Certificate of Registration** (national), DTI/SEC (national), **Sanitary Permit** (LGU),
  **Food Handler Certificate**, **FDA registration** (national), the **Government IDs** of the
  authorized representative, plus a `fake` class. (Vendor-only — personnel/employee document types
  such as NBI/police clearance and health certificates are **out of scope**; see gap plan G5.)
- **`issuer_scope` per class** must match the DB taxonomy (`document_types.issuer_scope`) so Stage 4b
  keys the logo reference correctly (`national` by type; `lgu` by type+city; `null` no logo). The class
  codes are **shared** with [PIPELINE_INTEGRATION_PHASES.md](./PIPELINE_INTEGRATION_PHASES.md) Phase P1's
  `DocumentTypeSeeder` — keep them in lockstep.
- **No new model for compliance.** Expiry/document-number/business-name extraction is **OCR + regex**
  (`ocr_template_rules`), delivered as a Python contract here, not a trained network.
- Everything else (signature/stamp/tamper) is unchanged by the domain shift.

---

## Global constraints (the ML environment)

- **Interpreter:** always the repo ML venv `python/env/Scripts/python.exe` (python.org **3.12.10**, full
  TF/Torch/Ultralytics/OpenCV stack installed & verified). **Never** bare `python` (MSYS2 build, no
  wheels) and **do not** rebuild on `py` (3.14, too new for TensorFlow). `onnx` pinned `< 1.17`.
- **Run from project root** `c:\xampp\htdocs\projects\advs`.
- **Generated training data is gitignored** (`python/data/training/**` except scaffold + `.gitkeep`).
  Synthetic images + `_synthetic_manifest.json` are **local artifacts — never `git add` them**. Only
  source code, fonts (`python/data/fonts/`), and templates are committed.
- **Model weights are gitignored** (`python/models/`) — document how to obtain/produce them; never commit.
- **ASCII-only `print()` logging** (Windows cp1252 console safe); keep the `[*-gen]` / script log style.
- **Tests:** `pytest`, modules loaded by path via `importlib.util` (repo convention). Tests must never
  write into the real data folder — use `tmp_path`.

---

## Models & their pipeline consumers

| # | Model | Trains for | Consumed by (pipeline stage) | Weights file |
|---|---|---|---|---|
| 1 | **ResNet-50** | Document type / authenticity classification (multi-class incl. `fake`) | Stage 3 — `classify_document.py` | `resnet50_authenticity.h5` + `label_encoder.pkl` / `class_names.json` |
| 2 | **YOLOv8** | Detect `signature` + `stamp` regions | Stage 4 — `signature_verify.py` / `stamp_verify.py` | `yolov8_document.pt` (and/or ONNX export) |
| 3 | **Siamese CNN** (ResNet-50 backbone) | Signature verification (128-D embedding) | Stage 4a — `signature_verify.py` | `siamese_signature.h5` + `siamese_encoder.h5` + `signature_threshold.txt` |
| 4 | **EfficientNet-B0** | Stamp/logo feature extraction + tamper texture | Stage 4b — `stamp_verify.py` | `efficientnet_stamp.h5` + `stamp_classifier.pkl` / `stamp_threshold.txt` |
| 5 | **Tamper fusion** *(planned)* | Fuse the 5 Stage-T forensic signals into one authenticity score | Stage T — `tamper_analyze.py` | *(deterministic blend today; ML model is a future phase)* |

> Thresholds live in [ADVS reference §9](../ADVS_REFERENCE.md) and are **configurable**,
> not hard-coded magic numbers — e.g. `CLASSIFICATION_CONFIDENCE_THRESHOLD=0.70`,
> `STAMP_SIMILARITY_THRESHOLD=0.85`, `YOLO_DETECTION_CONFIDENCE=0.50`, `SIGNATURE_DISTANCE_THRESHOLD`
> (empirical, set by EER). Training **produces** the empirical ones; the rest are operator-set.

---

## Current state

| Asset | Status | Notes |
|---|---|---|
| Synthetic dataset generators | 🟡 Partial | BIR (Form 2303) + Business Permit generators implemented & unit-tested; emit clean + Augraphy-degraded variants with a dedup manifest. Food-domain types not yet generated. |
| OCR dry-run / field specs | 🟡 Partial | `ocr_dryrun.py` with `FIELD_SPECS` regexes exists (validated harness); not yet the production `ocr_runner.py`. |
| Stage-T forensics | ✅ Built (deterministic) | `tamper_analyze.py` over `python/forensics`; weighted blend of 5 techniques; ML fusion is the planned next step. |
| Training scripts | 🟡 Partial | Model-specific trainers exist; packaging and evaluation remain tracked in this document. |
| Named inference contracts | ❌ Missing | `preprocess.py`, `ocr_runner.py`, `classify_document.py`, `signature_verify.py`, `stamp_verify.py`, `enroll_reference.py` (the orchestrator expects these — see pipeline track P0). |
| Trained weights | ❌ Not present | gitignored; must be produced by these phases or obtained from the team drive. |

---

## Phase M0 — Data taxonomy & layout lock-in *(prerequisite)*

**Goal:** Fix the class taxonomy and on-disk layout **before** generating data, so classifier folders,
the DB `document_types` codes, and `issuer_scope` all agree.

**Tasks:**
- Finalize the class list (food-domain + government IDs + `fake`) and freeze the **canonical folder names**
  under `python/data/training/classifier_data/<class>` (e.g. `bir_certificate`, `business_permit`,
  `financial_statement`, `fake`, …). There is **one** name per class — no phantom folders (a prior bug
  pointed a generator at a non-existent `business_registration`; the canonical code is `business_permit`).
- Confirm each class's `issuer_scope` and `requires_expiry` match Phase P1's `DocumentTypeSeeder`.
- Confirm the detection/signature/stamp layouts from the [archived training brief](../archive/TRAINING_SCRIPT_LEGACY.md):
  `data/detection/{images,labels}` (YOLO txt, class 0=signature, 1=stamp), `data/signatures/raw/<vendor>`,
  `data/stamps/{genuine,forged}`.

**Definition of Done:** a written class↔folder↔`issuer_scope` table that matches the DB seeder; no
reference to a non-canonical class name remains in generators/tests.

---

## Phase M1 — Synthetic dataset generation (classifier corpus)

**Goal:** Populate every classifier class with balanced clean + scan-degraded images using the
template-fill generators, extended to the food domain.

**Tasks:**
- Run the existing generators for `bir_certificate` and `business_permit` (clean PNG + Augraphy scan JPG,
  dedup manifest, `synthetic_*` prefix). **Generate ~10 samples first for visual QA/approval**, then the
  full batch.
- Build generators (reusing the field-agnostic `bir_dataset_generator` helpers — text-fit, asset
  compositing, Augraphy degrade, atomic manifest) for the **new food-domain types**: Sanitary Permit
  (LGU), FDA registration (national), Food Handler Certificate, plus government IDs as templates allow.
  **Do not duplicate machinery** — import the shared helpers.
- Ensure generated field VALUES satisfy the OCR regexes in `ocr_dryrun.py` `FIELD_SPECS` so documents
  read back the way Stage 2 / field extraction expects.
- Populate a matching **validation** split per class (`python/data/validation/classifier_data/<class>`)
  — `train_classifier.py` requires it before training.

**Definition of Done:** each class folder holds the target clean+scan counts with a valid
`_synthetic_manifest.json` (unique hashes, incrementing `next_index`); `train_classifier.py --dry-run`
exits 0 (layout valid); generated artifacts confirmed **gitignored** (`git status --short` clean for the
data dirs). Generator unit tests green.

---

## Phase M2 — ResNet-50 document classifier

**Goal:** Train the multi-class authenticity/type classifier per the current dataset and API contract.

**Tasks:**
- 512×512 RGB input via `image_dataset_from_directory`; in-pipeline augmentation (flip, ±10° rotation, ±10% zoom).
- Base `ResNet50(weights='imagenet', include_top=False, input_shape=(512,512,3))`, frozen first.
- Head: `GlobalAveragePooling2D → Dropout(0.5) → Dense(512, relu, l2=1e-4) → Dropout(0.3) → Dense(num_classes, softmax)`.
- **Two-phase training:** phase 1 frozen base, `Adam(1e-4)`, ≤ 20 epochs; phase 2 unfreeze last 30 layers,
  `Adam(1e-5)`, ≤ 10 epochs. Callbacks: `ModelCheckpoint(best)`, `EarlyStopping(patience=5, restore_best)`,
  `ReduceLROnPlateau(factor=0.2, patience=3)`. Class weights via `compute_class_weight` for imbalance.
- Save `resnet50_authenticity.h5` + the label mapping (`class_names.json` / `label_encoder.pkl`).

**Definition of Done:** `pytest python/tests/test_classify.py` green — output is `{label, confidence}`;
a known BIR fixture scores `confidence ≥ 0.80`; a noise/unknown image returns the lowest-confidence class
without crashing. Validation accuracy recorded for the ML Model Management UI (see [UI_FUNCTION_PHASES.md](./UI_FUNCTION_PHASES.md)).

---

## Phase M3 — YOLOv8 signature/stamp detector

**Goal:** Detect `signature` and `stamp` regions anywhere on a document (no ROI/homography needed).

**Tasks:**
- Dynamically write `data/detection/data.yaml`; train `yolov8n.pt`, 50 epochs, `imgsz=640`, `batch=16`,
  `patience=10`, GPU device 0 if available, `cache=True`.
- Evaluate mAP@0.5 on the val split; export best to `yolov8_document.pt` (and ONNX
  `yolov8_stamp_signature.onnx`, honoring the `onnx < 1.17` pin).

**Definition of Done:** mAP@0.5 printed and recorded; crops route correctly (signature → Stage 4a,
stamp/logo → Stage 4b); the no-detection path returns a graceful JSON flag
(`{"reason": "no_signature_detected"}` / `no_stamp_detected`) consumed by the pipeline track.

---

## Phase M4 — Siamese CNN signature verification

**Goal:** Produce the 128-D signature encoder + the empirical distance threshold used in Stage 4a.

**Tasks:**
- **Pairing data:** synthesize forgeries (elastic deform + rotation + noise on ~30% of each vendor's
  samples); build genuine pairs (same vendor) and forged pairs (genuine vs synthetic). Custom generator
  yields random pairs per batch (don't pre-store all pairs). Hold out ~20% of **vendors** for validation.
- **Architecture:** shared `ResNet50(include_top=False, pooling='avg')` backbone → `Dense(128)`
  (no activation) + L2 normalize; twin towers → Euclidean distance → `Dense(1, sigmoid)` (or contrastive
  loss — document which). Compile BCE + `Adam(1e-4)`, ~20 epochs / early stopping.
- Save `siamese_signature.h5` + `siamese_encoder.h5`; compute **EER** on validation pairs and write
  `signature_threshold.txt` (this is reference §9 `SIGNATURE_DISTANCE_THRESHOLD`).

> Pipeline note: this model only ever **verifies** — the per-vendor reference is enrolled at
> **registration** (reference §4a), so there is no first-submission enrollment branch.

**Definition of Done:** `pytest python/tests/test_signature_verify.py` green — genuine pair
`similarity ≥ 0.85`, forged pair `< 0.85`, embedding length exactly 128. The enroll path used by
registration is exercised separately.

---

## Phase M5 — EfficientNet stamp/logo verifier

**Goal:** Produce the logo feature extractor + threshold for Stage 4b's **issuer-keyed** comparison and
the wet-ink-vs-reproduction tamper texture check.

**Tasks:**
- `EfficientNetB0(weights='imagenet', include_top=False, pooling='avg')` (1280-D) as a fixed feature
  extractor; load `data/stamps/{genuine,forged}` (resize 224×224), extract vectors.
- Either a small classifier (`stamp_classifier.pkl`) **or** a cosine-similarity threshold
  (`stamp_threshold.txt`) — pick the simpler that yields a clean threshold; save
  `efficientnet_stamp.h5` (`efficientnet_feature_extractor.h5`).
- Verify the **issuer-keyed** logic the pipeline relies on: compare a query vector to the issuer's
  reference (by `document_type`, or `document_type`+city for LGU); an issuer with **no reference yet**
  returns `{"match": false, "reason": "unreferenced_logo"}` (or `no_issuer_logo` when `issuer_scope` is
  null). The **tamper texture check runs regardless** of whether a reference exists.

**Definition of Done:** `pytest python/tests/test_stamp_verify.py` green — genuine stamp pair
`similarity_score ≥ 0.85`, photocopy/forged `< 0.85`; `enroll_reference.py` seeds an issuer reference
(by type, or type+city) and returns a `vector_path`.

---

## Phase M6 — Named inference contracts (handoff to the pipeline)

**Goal:** Deliver the exact CLI scripts the Laravel orchestrator calls, matching the
[python/README.md](../../python/README.md) API contract — this is the integration consumed by the pipeline track.

**Tasks — implement each as `--input <json>`/`--output <json>`, exit 0 / non-zero, errors to stderr:**
- `preprocess.py` — grayscale → binarize(150) → morph-open(2×2) → invert → save PNG.
- `ocr_runner.py` — PyTesseract (`--psm 6`) + NLP cleanup → `{text, confidence}`; **plus** the structured
  **field extraction** (`{expiry_date, issue_date, document_number, business_name, …}`) driven by
  `ocr_template_rules` (Pipeline Phase P3) — OCR + regex, **no new model**. (May split into `extract_fields.py`.)
- `classify_document.py` — ResNet-50 → `{label, confidence}`.
- `signature_verify.py` — YOLOv8 crop → Siamese embed → Euclidean vs registration reference → `{match, similarity, embedding}`.
- `stamp_verify.py` — YOLOv8 crop → EfficientNet → tamper check + issuer lookup → `{match, similarity_score}` / reason flags.
- `enroll_reference.py` — seed an issuer's reference logo (type, or type+city) → `{vector_path}`.
- Singleton model loading in `utils/model_loader.py` (load `.h5`/`.pt` once per process).

**Definition of Done:** `pytest python/tests/ -v` green across all five+ contracts; manual runs match the
contract examples in [python/README.md](../../python/README.md); the pipeline track can drive a live document through
all stages.

---

## Phase M7 — Tamper ML fusion *(planned / stretch)*

**Goal:** Replace Stage T's deterministic weighted blend with a model trained on genuine/forged documents
that ingests the five forensic signals (metadata, ELA, copy-move, font, OCR cross-ref).

**Tasks:**
- Assemble a labelled tampered/clean dataset (the current blocker).
- Train the fusion model; keep the deterministic blend as the fallback; preserve the `hard_flag` override
  (`TAMPER_HARD_THRESHOLD`) so strong localized fraud is never averaged away.

**Definition of Done:** fusion model improves separation over the blend on a held-out set without
regressing the hard-override behavior; weights documented (gitignored) and registered in ML Model Management.

---

## Verification (whole track)

- `pytest python/tests/ -v --tb=short` green (preprocess, OCR/fields, classify, signature, stamp, generators).
- A manual run of each contract on a sample document returns the documented JSON shape.
- Empirical thresholds (`signature_threshold.txt`, stamp threshold) written and surfaced as the
  reference §9 defaults; operator-set thresholds remain configurable.
- Weights present under `python/models/` (gitignored) with a recorded validation metric per model for the
  ML Model Management UI in [UI_FUNCTION_PHASES.md](./UI_FUNCTION_PHASES.md).


---

## File: docs/phases/PIPELINE_INTEGRATION_PHASES.md

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
> - **What the system does** — [ADVS reference](../ADVS_REFERENCE.md) (Stages 0–T, risk score, §9 params).
> - **How we build** — [README.md](../../README.md) and [AGENTS.md](../../AGENTS.md).
> - **New client direction** — [CLIENT_INTERVIEW_GAP_PLAN.md](../CLIENT_INTERVIEW_GAP_PLAN.md) (Negofood Solution interview, 2026-06-29).
>
> **Branch/integration:** work is staging-first (lead/integration branch `staging`); land each phase as a
> reviewable slice. Stack/version facts follow the installed code and root README; product behavior
> follows `docs/ADVS_REFERENCE.md`; flag conflicts explicitly.

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

> **Approved conceptual departure:**
> [ADVS reference §1](../ADVS_REFERENCE.md) states the system is *"on-demand, not
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
- Map `ProcessDocumentAction` against the [ADVS reference §5](../ADVS_REFERENCE.md) stage list; record which stages are implemented vs stubbed.
- Inventory the current FastAPI and Laravel HTTP contract described in [README.md](../../README.md) and [python/README.md](../../python/README.md).
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
  alongside (not inside) the composite risk score from [ADVS reference §5](../ADVS_REFERENCE.md).
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
  ([ADVS reference §7](../ADVS_REFERENCE.md)); window is configurable per
  requirement-profile item (`renewal_window_days`) with a system-settings default.
- Idempotent: re-running the daily scan must not duplicate reminders for the same document/window.

**Definition of Done:** a document expiring within its window produces exactly one `expiring_soon`
notification + reminder; an expired document flips to `expired`; a second same-day run produces no
duplicates. Feature test drives the command with frozen time across the window boundary.

---

## Phase P6 — Hardening & integration verification

**Goal:** Prove the full path end-to-end and harden the integration described in [README.md](../../README.md).

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

- `php artisan test --compact` green — including new tests: expiration status transitions, checklist
  completeness, resubmission flow, real upload happy-path +
  `Queue::assertPushed(ProcessDocumentJob)`, renewal scheduler across the window boundary.
- Manual end-to-end click-through (the Phase P6 DoD scenario) passes.
- Python inference contracts exist and pass their tests (owned by [MODEL_TRAINING_PHASES.md](./MODEL_TRAINING_PHASES.md)).

[schema-first-modeling]: ../../AGENTS.md


---

## File: docs/phases/UI_FUNCTION_PHASES.md

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


---

## File: python/DEVELOPMENT_PHASES.md

# ADVS — Python ML Development Phases (top to end)

> **Scope:** the **complete, standalone development lifecycle of the `python/` track** — environment →
> data taxonomy → dataset preparation → model training → OCR/field extraction → forensics → the named
> inference contracts Laravel calls → evaluation/packaging → retraining. It is the authoritative
> reference for work done *inside this directory*. The Laravel pipeline wiring and the UI are covered by
> their own plans (linked below); this doc owns everything Python.
>
> Read alongside:
> - **What the system does** — [`../docs/ADVS_REFERENCE.md`](../docs/ADVS_REFERENCE.md) (Stages 1–T, §9 thresholds, §8 model files).
> - **How we build** — [`../README.md`](../README.md) and [`../AGENTS.md`](../AGENTS.md).
> - **Historical training brief** — [`../docs/archive/TRAINING_SCRIPT_LEGACY.md`](../docs/archive/TRAINING_SCRIPT_LEGACY.md).
> - **Concern-split phase plans** — [`../docs/phases/MODEL_TRAINING_PHASES.md`](../docs/phases/MODEL_TRAINING_PHASES.md) (the M-phases this doc expands), [`../docs/phases/PIPELINE_INTEGRATION_PHASES.md`](../docs/phases/PIPELINE_INTEGRATION_PHASES.md), [`../docs/phases/UI_FUNCTION_PHASES.md`](../docs/phases/UI_FUNCTION_PHASES.md).
> - **Client direction** — [`../docs/CLIENT_INTERVIEW_GAP_PLAN.md`](../docs/CLIENT_INTERVIEW_GAP_PLAN.md) (Negofood Solution, 2026-06-29): the food-business interview that **expanded the document-type taxonomy** — the dominant driver of the remaining dataset work.

**Legend:** ✅ done · 🟡 partial · ⚠️ present but inadequate · ❌ not started · ⏸ deferred/stretch.

---

## 0. Global constraints (read once, obey always)

- **Interpreter:** always the repo ML venv **`python/env/Scripts/python.exe`** (python.org **3.12.10**,
  full TF / Torch / Ultralytics / OpenCV stack installed & verified). **Never** bare `python` (PATH
  resolves to an MSYS2 build with no wheels); **do not** rebuild on `py` (3.14, too new for TensorFlow).
  `onnx` is pinned `< 1.17`.
- **Run from the project root** `c:\xampp\htdocs\projects\advs`.
- **Generated data is gitignored** (`python/data/training/**`, `python/data/validation/**` except the
  scaffold + `.gitkeep`). Synthetic images + `_synthetic_manifest.json` are **local artifacts — never
  `git add` them**. Committed: source, tests, vendored fonts (`data/fonts/`), templates, seals/logos.
- **Model weights are gitignored** (`python/models/`). Document how to produce/obtain them; never commit.
- **ASCII-only `print()`** (Windows cp1252 console safe); keep the `[*-gen]` / `[classifier]` log style.
- **Tests** use `pytest`, modules loaded by path via `importlib.util` (repo convention). Tests must
  **never** write into the real data folders — use `tmp_path`.

---

## Directory map (`python/`, current)

```
python/
├── env/                       # ML venv (python.org 3.12.10) — the ONLY interpreter to use
├── requirements.txt           # 31 deps (TF/Keras, Ultralytics, OpenCV, pytesseract, pdf2image, Augraphy, …)
├── scripts/
│   ├── annotate_boxes.py                    # template box calibration tool
│   ├── business_permit_annotator.py
│   ├── bir_dataset_generator.py             # ✅ BIR Form 2303 synthetic generator (+ tests)
│   ├── business_permit_dataset_generator.py # ✅ LGU business permit generator (+ tests)
│   ├── financial_statement_generator.py     # ✅ financial statement generator (+ tests)
│   ├── ocr_dryrun.py                         # 🟡 OCR + field extraction harness (BIR fields; NO expiry yet)
│   ├── tamper_analyze.py                     # ✅ Stage T forensic aggregator (deterministic blend)
│   ├── train_classifier.py                   # ResNet-50 trainer (ready; not yet run to weights)
│   ├── train_detector.py                     # YOLOv8 trainer
│   ├── train_signature.py                    # Siamese trainer
│   └── train_stamp.py                        # EfficientNet trainer
├── notebooks/                 # 01_resnet50 · 02_yolov8 · 03_siamese · 04_efficientnet
├── forensics/                 # Stage T package: metadata · ela · copy_move · font_consistency · cross_reference
├── tests/                     # pytest (generators, ocr_dryrun, tamper_analyze, annotators)
├── models/                    # ❌ only .gitkeep — NO trained weights yet
├── data/
│   ├── fonts/  seal/  stamps/  logo/  template/{bir_permit,business_permits,reference}   # committed assets
│   ├── training/   {classifier_data, detector_data, signature_data, stamp_data}          # gitignored
│   └── validation/ {classifier_data, detector_data, signature_data, stamp_data}          # gitignored
└── json_data/                 # misc JSON payloads
```

> **Missing entirely (to be built in Phase 10):** the named inference contracts the Laravel orchestrator
> calls — `preprocess.py`, `ocr_runner.py`, `classify_document.py`, `signature_verify.py`,
> `stamp_verify.py`, `enroll_reference.py`, and `utils/model_loader.py`. None exist yet.

---

## The five models and their consumers

| # | Model | Trains for | Pipeline stage | Weights (gitignored) | Threshold (reference §9) |
|---|---|---|---|---|---|
| 1 | **ResNet-50** | Doc type / authenticity (multi-class incl. `fake`) | Stage 3 | `resnet50_authenticity.h5` + `class_names.json` | `CLASSIFICATION_CONFIDENCE_THRESHOLD = 0.70` |
| 2 | **YOLOv8** | Detect `signature` + `stamp` | Stage 4 | `yolov8_document.pt` (+ ONNX) | `YOLO_DETECTION_CONFIDENCE = 0.50` |
| 3 | **Siamese CNN** | Signature verify (128-D) | Stage 4a | `siamese_signature.h5` + `siamese_encoder.h5` (the API loads the encoder) + `signature_threshold.txt` | `SIGNATURE_DISTANCE_THRESHOLD` (empirical / EER) |
| 4 | **EfficientNet-B0** | Stamp/logo feature + tamper texture | Stage 4b | `efficientnet_feature_extractor.h5` + `stamp_classifier.pkl` + `stamp_threshold.txt` | `STAMP_SIMILARITY_THRESHOLD = 0.85` |
| 5 | **Tamper fusion** ⏸ | Fuse 5 forensic signals | Stage T | *(deterministic blend today)* | `TAMPER_*` thresholds |

Thresholds are **configurable**, not hard-coded; training produces the empirical ones (signature EER,
stamp), operators set the rest.

---

## Where we are now (honest current state)

### Classifier corpus — original domain only
| Class folder | Train images | Val images | Note |
|---|---:|---:|---|
| `bir_certificate` | **1014** | 2 | real + synthetic; well-populated |
| `financial_statement` | **1005** | 2 | well-populated (not client-prioritized) |
| `business_permit` | **200** | 2 | synthetic batch landed |
| `fake` | **7** | 2 | ⚠️ far too few for a fraud class |
| *(food-business types)* | **0** | 0 | ❌ none exist |

> Validation split is **2 images/class** — a smoke-test placeholder, **not trainable**. A real val split
> is required before training.

### Detection / signature / stamp — fixtures only
`data/training/{detector_data, signature_data, stamp_data}` hold **~16 train / 8 val files each** —
**smoke-test fixtures**, not real corpora. signature_data has 4 vendor subdirs; detector/stamp have 2.
These exist so the training scripts and pipeline smoke-run, not to produce usable models.

### Models, contracts, OCR
- **Trained weights:** ❌ none (`models/` is empty but for `.gitkeep`).
- **Named inference contracts:** ❌ none of the six exist.
- **OCR field extraction:** 🟡 `ocr_dryrun.py` models BIR fields incl. `date_issued` — but **no
  `expiry_date`** for any type, which is the client's #1 need.
- **Stage T forensics:** ✅ deterministic 5-technique blend built and tested; ML fusion ⏸.

### Taxonomy drift (must fix in Phase 1)
Three layers disagree on document codes:
| Layer | Codes |
|---|---|
| Classifier folders | `bir_certificate`, `financial_statement`, `business_permit`, `fake` |
| `DocumentTypeSeeder` | `bir_permit`, `gis`, `financial_stmt`, `business_permit`, `signed_contract` |
| `train_classifier.py` docstring | references a **phantom** `business_registration` |

### Negofood per-document-type coverage (the interview gap)
| Client document type | Subject | `issuer_scope` | Classifier data | Generator |
|---|---|---|---|---|
| BIR Certificate of Registration | vendor | national | ✅ 1014 | ✅ |
| Mayor's / Business Permit | vendor | lgu | 🟡 200 | ✅ |
| DTI / SEC registration | vendor | national | ❌ | ❌ |
| **Sanitary Permit** | vendor | lgu | ❌ | ❌ |
| **Food Handler Certificate** | vendor | lgu | ❌ | ❌ |
| **FDA registration** | vendor | national | ❌ | ❌ |
| Financial Statement | vendor | null | ✅ 1005 | ✅ |
| Government ID (authorized representative) | vendor | national | ❌ | ❌ |
| `fake` (negative class) | — | — | ⚠️ 7 | ❌ |

**~2 of ~8 client-relevant types have classifier data; the rest are unbuilt.** This is the long pole.

---

## Phase 0 — Environment & repo setup ✅ (maintain)

**Goal:** A reproducible ML environment every contributor can run.
**Tasks:** venv at `python/env/` on python.org 3.12.x; `pip install -r requirements.txt`; verify
`python/env/Scripts/python.exe -c "import tensorflow, cv2, pytesseract, ultralytics; print('OK')"`;
confirm `.gitignore` excludes `data/training/**`, `data/validation/**`, `models/`.
**DoD:** import check passes; `pytest python/tests/ -q` runs; no generated artifacts tracked by git.
**Status:** ✅ done (3.12.10 verified). Keep `requirements.txt` authoritative; honor the `onnx < 1.17` pin.

---

## Phase 1 — Data taxonomy & layout lock-in ❌ → *do this first*  · (M0)

**Goal:** One canonical document-type taxonomy shared by the classifier folders, `DocumentTypeSeeder`,
and `ocr_template_rules`, extended to the Negofood food-business (vendor) domain. Nothing else should be
built on a moving taxonomy.

**Tasks:**
- Decide the final class list (food-business + `fake`) and freeze **one** canonical folder name per
  class under `data/training/classifier_data/<class>` and `data/validation/classifier_data/<class>`.
- Make folder names **==** `DocumentTypeSeeder` codes (resolve `bir_certificate`↔`bir_permit`,
  `financial_statement`↔`financial_stmt`). Coordinate the seeder change with pipeline Phase P1.
- Set each type's `issuer_scope` (`national` / `lgu` / `null`) and `requires_expiry`.
- Remove the phantom `business_registration` references ([scripts/train_classifier.py](scripts/train_classifier.py), notebooks, fixtures, `data/README.md`).

**DoD:** a written class ↔ folder ↔ `issuer_scope` ↔ `requires_expiry` table that matches the DB seeder;
no non-canonical class name remains anywhere; `train_classifier.py --dry-run` exits 0.

---

## Phase 2 — Synthetic classifier dataset generation 🟡 · (M1)

**Goal:** Balanced clean + scan-degraded images for **every** class, including the new food-business
types, using the template-fill generators.

**Tasks:**
- Run the existing generators ([bir](scripts/bir_dataset_generator.py), [business_permit](scripts/business_permit_dataset_generator.py), [financial_statement](scripts/financial_statement_generator.py)) — **10 samples first for visual QA**, then the full batch.
- Build generators for the **new types** (Sanitary Permit, FDA, Food Handler, DTI/SEC, government IDs)
  by **reusing** `bir_dataset_generator`'s helpers (text-fit, white-keyed asset compositing, Augraphy
  degrade, atomic manifest). **Do not reimplement** the machinery. Vendor each new blank template +
  seal/logo under `data/template/` / `data/seal/` / `logo/`.
- Make generated field VALUES satisfy the OCR regexes (Phase 8) — especially a **printed expiry date**
  for expiring types — so documents read back the way extraction expects.
- Bulk up `fake` (degraded/edited/wrong-template negatives).
- Populate a **real validation split** per class (not 2 images).

**DoD:** every class folder hits its target clean+scan counts with a valid `_synthetic_manifest.json`
(unique hashes, incrementing `next_index`); a real val split exists; generated artifacts confirmed
gitignored (`git status --short` clean for data dirs); generator tests green.

---

## Phase 3 — Detection / signature / stamp dataset assembly ⚠️ (fixtures only)

**Goal:** Replace the smoke-test fixtures with real labelled corpora for models 2–4.
**Tasks:**
- **Detection** (`data/training/detector_data`): full document pages + YOLO `.txt` labels
  (class 0 = signature, 1 = stamp). Compose from generated documents with known signature/stamp boxes
  (the generators already place these) → auto-emit YOLO labels.
- **Signature** (`data/training/signature_data/<vendor>/`): genuine signatures per vendor, plus an
  optional `<vendor>/forged/` subfolder of REAL skilled forgeries (the CEDAR path in notebook 03);
  the Siamese trainer falls back to synthetic forgeries (elastic + rotation + noise) when absent.
- **Stamp** (`data/training/stamp_data/{genuine,forged}`): generated by
  [scripts/stamp_dataset_generator.py](scripts/stamp_dataset_generator.py) from the real issuer
  artwork (BIR stamp/seal, DTI logo, LGU seals) — benign scan variance for genuine, photocopy/hue/
  warp/rescale/erase perturbations for forged.
- Mirror a real validation split for each.

**DoD:** each corpus is at training scale (not fixture scale) with a val split; `train_detector.py`,
`train_signature.py`, `train_stamp.py` each pass `--dry-run`.

---

## Phase 4 — ResNet-50 document classifier ❌ · (M2)

**Goal:** Train the multi-class type/authenticity classifier ([scripts/train_classifier.py](scripts/train_classifier.py), [historical training brief §1](../docs/archive/TRAINING_SCRIPT_LEGACY.md)).
**Tasks:** 512×512 input; augment (flip, ±10° rotate, ±10% zoom); frozen-base phase 1 (`Adam 1e-4`, ≤20
epochs) → unfreeze last 30 layers phase 2 (`Adam 1e-5`, ≤10 epochs); class weights for imbalance;
callbacks (checkpoint/early-stop/reduce-LR); save `resnet50_authenticity.h5` + `class_names.json`.
**DoD:** `pytest python/tests/test_classify.py` green (output `{label, confidence}`; BIR fixture
`confidence ≥ 0.80`; noise → lowest-confidence class, no crash); validation accuracy recorded for ML Model Management.

---

## Phase 5 — YOLOv8 signature/stamp/logo detector ❌ · (M3)

**Goal:** Detect `signature` + `stamp` + `logo` anywhere on a page (no ROI/homography).
**Tasks:** write `data.yaml`; train `yolov8n.pt` (50 epochs, `imgsz=640`, `batch=16`, `patience=10`,
GPU if available, `cache=True`); report mAP@0.5; export `yolov8_document.pt` + ONNX (honor `onnx < 1.17`).
**DoD:** mAP@0.5 recorded; crops route correctly (sig → 4a, stamp/logo → 4b); no-detection returns a graceful
JSON flag (`no_signature_detected` / `no_stamp_detected` / `no_logo_detected`).

---

## Phase 6 — Siamese CNN signature verification 🟡 · (M4) — awaiting the Colab run

**Goal:** 128-D signature encoder + empirical distance threshold (Stage 4a, **verify-only**; the
per-vendor reference is enrolled at **registration**, not in the pipeline).
**Tasks:** train on **CEDAR** (notebook 03 downloads + reshapes it; real skilled forgeries in
`<signer>/forged/`, synthetic elastic/rotation/noise forgeries as fallback); hold out ~20% of signers;
shared `ResNet50(pooling='avg')` → `Dense(128)` + `UnitNormalization` twin towers → L1 distance →
`Dense(1, sigmoid)`; preprocessing is **RGB + `resnet50.preprocess_input`**, identical to the API's
serve path; save `siamese_signature.h5` + `siamese_encoder.h5`; compute **EER** →
`signature_threshold.txt`.
**Done so far:** trainer + notebook CEDAR-ready, preprocessing aligned with `api/routers/signature.py`,
`--dry-run`/`--smoke` green, smoke artefacts load via the registry's `load_model` path. **Remaining:**
run notebook 03 on Colab GPU, drop the three artefacts into `python/models/`.
**DoD:** `pytest python/tests/test_signature_verify.py` green (genuine ≥ 0.85, forged < 0.85, embedding
length 128).

---

## Phase 7 — EfficientNet stamp/logo verifier ✅ · (M5) — trained 2026-07-18

**Goal:** Logo feature extractor + threshold for Stage 4b's **issuer-keyed** comparison and the
wet-ink-vs-reproduction texture check.
**Tasks:** `EfficientNetB0(include_top=False, pooling='avg')` (1280-D, frozen ImageNet); load
`stamp_data/{genuine,forged}` (224×224, generated by `stamp_dataset_generator.py` from the real issuer
artwork); classifier (`stamp_classifier.pkl`) **and** calibrated cosine threshold
(`stamp_threshold.txt`); save `efficientnet_feature_extractor.h5`. Verify issuer logic: compare query
vs the issuer reference (by `document_type`, or `+city` for LGU); no reference yet →
`{"match": false, "reason": "unreferenced_logo"}` (or `no_issuer_logo` when `issuer_scope` is null);
**tamper texture check runs regardless**.
**Result:** genuine/forged classifier val accuracy **0.90**; calibrated `STAMP_SIMILARITY_THRESHOLD`
**0.9358**; live-API end-to-end verified (genuine bir_seal crop sim 0.94 → match, hue-shifted forgery
sim 0.89 → no match, missing reference → `unreferenced_logo`).
**DoD:** `pytest python/tests/test_stamp_verify.py` green (genuine ≥ 0.85, photocopy < 0.85);
`enroll_reference.py` seeds an issuer reference and returns a `vector_path`.

---

## Phase 8 — OCR + field extraction (incl. expiry) 🟡

**Goal:** Turn OCR text into the structured fields the compliance layer reads — above all **`expiry_date`**.
**Tasks:** evolve [scripts/ocr_dryrun.py](scripts/ocr_dryrun.py) (or a new `extract_fields.py`) to emit
`{expiry_date, issue_date, document_number, business_name, …}` driven by per-type `ocr_template_rules`
(regex; **no new ML model**). Add `expiry_date` specs for every expiring type (Sanitary, FDA, Food
Handler, Business Permit). Degrade gracefully — low confidence / no date → `unknown`, **never a false
`valid`**.
**DoD:** field-extraction unit tests cover each type's regex + date math (boundary: expires today /
within window / past); a generated permit's printed expiry is recovered; a blurry scan yields `unknown`.
Consumed by pipeline Phase P3.

---

## Phase 9 — Stage T forensic tampering ✅ (deterministic) · ML fusion ⏸ · (M7)

**Goal:** Document-wide tamper signals feeding the risk score's 5th component.
**State:** ✅ [forensics/](forensics/) (metadata · ELA · copy-move · font · cross-reference) +
[scripts/tamper_analyze.py](scripts/tamper_analyze.py) aggregate to `tamper_score` / `tamper_authenticity`
/ `hard_flag` via a deterministic weighted blend; tested.
**Stretch (M7):** train an ML fusion model once a labelled tampered/clean set exists; keep the blend as
fallback and preserve the `hard_flag` override (`TAMPER_HARD_THRESHOLD`).
**DoD (fusion):** improves separation over the blend on a held-out set without regressing the hard override.

---

## Phase 10 — Named inference contracts (Laravel handoff) ❌ · (M6)

**Goal:** Maintain inference interfaces that match the current FastAPI contract in [README.md](README.md)
`--input <json>` / `--output <json>` contract (exit 0 / non-zero, errors → stderr). **This is the seam
that turns trained models into a working pipeline.**
**Build:**
- `preprocess.py` — grayscale → binarize(150) → morph-open(2×2) → invert → PNG.
- `ocr_runner.py` — PyTesseract (`--psm 6`) + NLP cleanup → `{text, confidence}` **+ field extraction**
  (Phase 8).
- `classify_document.py` — ResNet-50 → `{label, confidence}`.
- `signature_verify.py` — YOLO crop → Siamese embed → Euclidean vs registration ref → `{match, similarity, embedding}`.
- `stamp_verify.py` — YOLO crop → EfficientNet → tamper + issuer lookup → `{match, similarity_score}` / reason flags.
- `enroll_reference.py` — seed an issuer reference → `{vector_path}`.
- `utils/model_loader.py` — load each `.h5`/`.pt` **once per process** (singleton).
**DoD:** `pytest python/tests/ -v` is green; manual runs match the [FastAPI contract](README.md)
examples; pipeline Phase P0/P3 can drive a live document end-to-end.

---

## Phase 11 — Evaluation, thresholds & packaging ❌

**Goal:** Lock the empirical thresholds and make weights reproducible/portable.
**Tasks:** record per-model metrics (classifier accuracy, mAP@0.5, signature EER/FAR-FRR, stamp
accuracy); write `signature_threshold.txt` / stamp threshold and surface as the reference §9 defaults;
document how to obtain/produce weights (training command + data provenance) since `models/` is gitignored;
register metrics for the **ML Model Management** UI.
**DoD:** a metrics table + a "how to reproduce the weights" note exist; weights load via `model_loader.py`.

---

## Phase 12 — Retraining & maintenance ⏸

**Goal:** Keep models current as new document samples and types arrive.
**Tasks:** a retrain runbook (data refresh → generator run → train → eval → threshold update → drop
weights into `models/`); triggered from the admin **ML Model Management** UI (`RetrainModelJob`); version
weights and keep the prior version swappable.
**DoD:** a documented, repeatable retrain produces a swappable weight set without code changes.

---

## End-to-end build order (dependencies)

```
Phase 0 env ✅
   │
Phase 1 taxonomy lock-in ❌  ◄── do first; everything keys off it
   │
   ├─► Phase 2 classifier datasets 🟡 ──► Phase 4 ResNet-50 ❌
   │
   └─► Phase 3 det/sig/stamp datasets ⚠️ ─┬─► Phase 5 YOLOv8 ❌
       (stamp ✅ generated · sig = CEDAR   ├─► Phase 6 Siamese 🟡 (awaiting Colab run)
        via notebook 03)                   └─► Phase 7 EfficientNet ✅
Phase 8 OCR + expiry extraction 🟡  (parallel; rules, no model)
Phase 9 Stage T forensics ✅ (fusion ⏸)
   │
   ▼
Phase 10 named inference contracts ❌  ◄── needs trained weights from 4–7
   │
   ▼
Phase 11 eval/thresholds/packaging ❌  ──►  Phase 12 retrain/maintain ⏸
```

**Critical path to a live pipeline:** Phase 1 → 2/3 → 4–7 → 10. Phases 8 and 9 run in parallel.

---

## Verification (whole Python track)

- `python/env/Scripts/python.exe -m pytest python/tests/ -v --tb=short` green (generators, OCR/fields,
  tamper, annotators) — and the new per-model contract tests as Phases 4–7/10 land.
- Each named contract returns the documented JSON shape on a sample document.
- Empirical thresholds written and surfaced as reference §9 defaults; operator thresholds stay configurable.
- Weights present under `python/models/` (gitignored) with a recorded validation metric per model.
- A document drives Stages 1 → T end-to-end via the Phase 10 contracts.

---

## Open decisions to confirm before Phase 1

1. **Final taxonomy** — the exact food-business class list + each type's `issuer_scope` / `requires_expiry`.
2. **Canonical codes** — adopt classifier folder names or the `DocumentTypeSeeder` codes as the single source.
3. **Government-ID templates** — which government IDs get synthetic generators vs. real-sample collection
   (PII-sensitive).
4. **`fake` strategy** — how negatives are synthesized/sourced at scale.

*Tracks the M-phases in [`../docs/phases/MODEL_TRAINING_PHASES.md`](../docs/phases/MODEL_TRAINING_PHASES.md); current-state figures are a snapshot as of this writing — re-count the data folders before acting.*


---

# Superpowers and Specs

## File: docs/superpowers/plans/2026-06-18-registered-name-positional-fuzzy.md

# Registered-Name Positional + Fuzzy Header Extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `registered_name` extraction in `python/scripts/ocr_dryrun.py` read the value from the **NAME column's word boxes** (positional), and tolerate up to 4 OCR character-typos in the `TIN | NAME | REGISTRATION DATE` header caption (so `REGISTRAUION` still identifies the header row).

**Architecture:** Add two small fuzzy-matching primitives (`_levenshtein`, `_fuzzy_token_eq`), give the existing box-finder `_find_phrase` an optional `max_typos` parameter, add a positional extractor `_positional_registered_name` wired into `_positional_fields` (so it overrides the text result whenever word boxes exist), and make the text-only fallback `_extract_registered_name` detect its header row fuzzily. The positional path is the new primary; the fuzzy text path is the fallback for callers without word boxes.

**Tech Stack:** Python 3.12, OpenCV + PyTesseract (already wired), stdlib `re`. No new dependencies. Tests are plain assert-based and run via the test file's built-in runner.

## Global Constraints

- Python interpreter is the project venv ONLY: `python/env/Scripts/python.exe` (python.org 3.12). Never use bare `python` (MSYS2, no wheels) or `py` (3.14).
- **No new dependencies.** pytest is NOT installed; tests run via the file's `__main__` runner: `env/Scripts/python.exe tests/test_ocr_dryrun.py` (prints `PASS/FAIL` per test + an `N/M passed` summary; exit 0 = all pass, exit 1 = a failure).
- All paths below are relative to `c:/xampp/htdocs/projects/advs`. Run test commands from `c:/xampp/htdocs/projects/advs/python`.
- Changes are confined to the dry-run harness (`scripts/ocr_dryrun.py`) and its test (`tests/test_ocr_dryrun.py`). Do NOT touch production `ocr_runner.py` or any §9 path.
- Every existing test in `tests/test_ocr_dryrun.py` must still pass after each task (run the whole file, not just the new test).
- Match existing code style: PEP-type-hinted functions, PHPDoc-style docstrings explaining *why*, no inline `# obvious` comments.

---

## File Structure

- `python/scripts/ocr_dryrun.py` — add `_levenshtein`, `_fuzzy_token_eq` (after `_norm_token`, ~line 325); extend `_find_phrase` (~line 513); add `_positional_registered_name` + wire into `_positional_fields` (~line 551–577); make `_extract_registered_name` header match fuzzy via a new `_line_is_name_header` (~line 430–454).
- `python/tests/test_ocr_dryrun.py` — append new tests for each piece. Reuses the existing `_WORDS` real-OCR box fixture (`tests/fixtures/bir1_words.json`) and a small synthetic header word-list helper for the typo case.

---

### Task 1: Fuzzy-matching primitives (`_levenshtein`, `_fuzzy_token_eq`)

**Files:**
- Modify: `python/scripts/ocr_dryrun.py` (insert after `_norm_token`, currently lines 324–325)
- Test: `python/tests/test_ocr_dryrun.py` (append)

**Interfaces:**
- Produces:
  - `_levenshtein(a: str, b: str) -> int` — classic edit distance.
  - `_fuzzy_token_eq(a: str, b: str, max_typos: int = 0) -> bool` — normalises both tokens (via existing `_norm_token`), returns `True` if within `max_typos` edits; tolerance is capped at `min(len(a), len(b)) - 1` so short captions can't match unrelated tokens; `max_typos=0` means exact.

- [ ] **Step 1: Write the failing tests**

Append to `python/tests/test_ocr_dryrun.py`:

```python
# --- fuzzy caption matching primitives ----------------------------------------

def test_levenshtein_counts_single_substitution() -> None:
    assert ocr_dryrun._levenshtein("REGISTRATION", "REGISTRAUION") == 1


def test_levenshtein_zero_for_identical() -> None:
    assert ocr_dryrun._levenshtein("NAME", "NAME") == 0


def test_fuzzy_token_eq_tolerates_long_caption_typo() -> None:
    # REGISTRATION read as REGISTRAUION is one substitution -> within a 4 budget.
    assert ocr_dryrun._fuzzy_token_eq("REGISTRAUION", "REGISTRATION", 4) is True


def test_fuzzy_token_eq_exact_when_budget_zero() -> None:
    assert ocr_dryrun._fuzzy_token_eq("NAME", "NAME", 0) is True
    assert ocr_dryrun._fuzzy_token_eq("NAME", "NAVE", 0) is False


def test_fuzzy_token_eq_short_token_needs_low_budget() -> None:
    # With a 1-typo budget NAME must NOT match the unrelated DATE (distance 2).
    assert ocr_dryrun._fuzzy_token_eq("DATE", "NAME", 1) is False


def test_fuzzy_token_eq_caps_tolerance_below_token_length() -> None:
    # A 4 budget on a 3-letter caption is capped to 2, so TIN can't match FOR.
    assert ocr_dryrun._fuzzy_token_eq("TIN", "FOR", 4) is False
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: the run aborts with `AttributeError: module 'ocr_dryrun' has no attribute '_levenshtein'` (the built-in runner only catches `AssertionError`, so a missing symbol surfaces as a traceback and a non-zero exit).

- [ ] **Step 3: Add the primitives**

In `python/scripts/ocr_dryrun.py`, immediately after the `_norm_token` function (currently ends at line 325), insert:

```python
def _levenshtein(a: str, b: str) -> int:
    """Character-level edit distance (substitutions/insertions/deletions)."""
    if a == b:
        return 0
    if not a:
        return len(b)
    if not b:
        return len(a)
    prev = list(range(len(b) + 1))
    for i, ca in enumerate(a, 1):
        cur = [i]
        for j, cb in enumerate(b, 1):
            cur.append(min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (ca != cb)))
        prev = cur
    return prev[-1]


def _fuzzy_token_eq(a: str, b: str, max_typos: int = 0) -> bool:
    """True if two tokens are equal within ``max_typos`` character edits.

    Both sides are normalised with ``_norm_token`` first. The tolerance is capped
    at one less than the shorter token's length, so a short caption ("NAME",
    "TIN", "DATE") can never fuzzy-match a wholly different token even with a large
    budget - long captions get the full 1-4 budget, short ones are called with a
    small one. ``max_typos=0`` means exact match (the default).
    """
    a, b = _norm_token(a), _norm_token(b)
    if not a or not b:
        return False
    if a == b:
        return True
    if max_typos < 1:
        return False
    tol = min(max_typos, min(len(a), len(b)) - 1)
    if tol < 1:
        return False
    return _levenshtein(a, b) <= tol
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: all tests `PASS`, summary ends `N/N passed`, exit 0. (The six new tests now pass; every previously passing test still passes.)

- [ ] **Step 5: Commit**

```bash
git add python/tests/test_ocr_dryrun.py python/scripts/ocr_dryrun.py
git commit -m "feat(ocr): add fuzzy token-match primitives for BIR caption matching

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Optional fuzzy matching in `_find_phrase`

**Files:**
- Modify: `python/scripts/ocr_dryrun.py` — `_find_phrase` (currently lines 513–526)
- Test: `python/tests/test_ocr_dryrun.py` (append)

**Interfaces:**
- Consumes: `_fuzzy_token_eq(a, b, max_typos)` from Task 1.
- Produces: `_find_phrase(words: list[dict], phrase: str, max_typos: int = 0)` — unchanged return `(left, top, right, bottom)` tuple or `None`; with `max_typos=0` (default) behaviour is identical to before (exact); `max_typos>0` matches each caption token fuzzily.

- [ ] **Step 1: Write the failing tests**

Append to `python/tests/test_ocr_dryrun.py`:

```python
# --- fuzzy phrase finder over word boxes --------------------------------------

def _word(text: str, left: int, top: int, width: int = 80, height: int = 20) -> dict:
    return {"text": text, "conf": 90, "left": left, "top": top,
            "width": width, "height": height}


def test_find_phrase_exact_by_default() -> None:
    words = [_word("TRADE", 10, 10, 50), _word("NAME", 70, 10, 50)]
    assert ocr_dryrun._find_phrase(words, "TRADE NAME") == (10, 10, 120, 30)
    assert ocr_dryrun._find_phrase(words, "TRADE NAMEX") is None  # exact: no match


def test_find_phrase_fuzzy_tolerates_caption_typo() -> None:
    words = [_word("REGISTRAUION", 100, 50, 180), _word("DATE", 290, 50, 60)]
    assert ocr_dryrun._find_phrase(words, "REGISTRATION DATE", max_typos=4) == (100, 50, 350, 70)
    assert ocr_dryrun._find_phrase(words, "REGISTRATION DATE") is None  # exact fails
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: `FAIL  test_find_phrase_fuzzy_tolerates_caption_typo` (current `_find_phrase` ignores the `max_typos` kwarg — actually raises `TypeError: _find_phrase() got an unexpected keyword argument 'max_typos'`, aborting the run with a traceback and exit 1).

- [ ] **Step 3: Extend `_find_phrase`**

Replace the existing `_find_phrase` (lines 513–526) with:

```python
def _find_phrase(words: list[dict], phrase: str, max_typos: int = 0):
    """Box (left, top, right, bottom) of the first consecutive run of words whose
    normalised text matches the phrase tokens; None if absent. ``max_typos`` > 0
    allows fuzzy per-token matching (tolerates OCR typos in a caption); the
    default 0 is an exact match, preserving the original behaviour."""
    tokens = [_norm_token(t) for t in phrase.split()]
    for i in range(len(words) - len(tokens) + 1):
        seg = words[i:i + len(tokens)]
        if all(_fuzzy_token_eq(w["text"], tok, max_typos)
               for w, tok in zip(seg, tokens)):
            return (
                min(w["left"] for w in seg),
                min(w["top"] for w in seg),
                max(w["left"] + w["width"] for w in seg),
                max(w["top"] + w["height"] for w in seg),
            )
    return None
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: all `PASS`, exit 0. The existing positional tests (`test_positional_trade_name_is_left_column`, `..._line_of_business...`, `..._tax_types`, `..._district_officer...`) still pass because the default `max_typos=0` keeps exact matching.

- [ ] **Step 5: Commit**

```bash
git add python/tests/test_ocr_dryrun.py python/scripts/ocr_dryrun.py
git commit -m "feat(ocr): make _find_phrase tolerate OCR caption typos via max_typos

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Positional `registered_name` extraction (primary path)

**Files:**
- Modify: `python/scripts/ocr_dryrun.py` — add `_positional_registered_name` just before `_positional_fields` (before line 551); add one line inside `_positional_fields` (before its `return`, line 577)
- Test: `python/tests/test_ocr_dryrun.py` (append)

**Interfaces:**
- Consumes: `_find_phrase(words, phrase, max_typos)` (Task 2), and existing `_words_in_box`, `_join_clean`, `_TIN_TOKEN`, `_DATE_TOKEN`.
- Produces: `_positional_registered_name(words: list[dict]) -> str | None`. Wired so `_positional_fields(words)["registered_name"]` is present whenever the NAME + REGISTRATION DATE header captions are found, and `refine_fields_with_positions` therefore overrides the text-derived `registered_name` with this value.

**Geometry reference (real `bir1` boxes, the Otsu+2x pixel space the fixture is in):** header captions sit at `top≈378` (`TIN` left 31/right 73, `NAME` left 398, `REGISTRATION` left 1008, `DATE` left 1199); the name value row is `top≈412` (`009-028-463-000` right 355, `CENTER` left 409 … `AND` right 932, `06/01/2015` left 1036); the wrap `PROFESSIONAL DEVT.` is `top≈440`; `REGISTERED ADDRESS` is `top≈470`. The band therefore runs `x∈[NAME.left-40, DATE-column-start]`, `y∈[header_bottom+6, just above REGISTERED ADDRESS]`, with any flanking TIN/date stripped by regex.

- [ ] **Step 1: Write the failing tests**

Append to `python/tests/test_ocr_dryrun.py`:

```python
# --- positional registered_name (middle column of the header table) -----------

def _name_header_words(registration_token: str = "REGISTRATION") -> list:
    """Synthetic TIN | NAME | REGISTRATION DATE header with a two-row name value,
    plus the REGISTERED ADDRESS caption that bounds the band below. Mirrors the
    bir2.jpg layout; ``registration_token`` lets a test inject the OCR typo."""
    return [
        _word("TIN", 30, 100, 40), _word("NAME", 400, 100, 70),
        _word(registration_token, 1000, 100, 180), _word("DATE", 1200, 100, 60),
        _word("000-132-541-000", 100, 140, 250),
        _word("MINING", 410, 140, 110), _word("AND", 530, 140, 60),
        _word("PETROLEUM", 600, 140, 150), _word("SERVICES", 760, 140, 130),
        _word("08/12/1998", 1040, 140, 160),
        _word("CORPORATION", 410, 175, 200),
        _word("REGISTERED", 400, 215, 200), _word("ADDRESS", 610, 215, 130),
    ]


def test_positional_registered_name_reads_middle_column() -> None:
    name = ocr_dryrun._positional_fields(_WORDS)["registered_name"].upper()
    assert "CENTER FOR LOCAL GOVERNANCE" in name
    assert "PROFESSIONAL DEVT" in name
    assert "009-028-463-000" not in name   # flanking TIN column excluded
    assert "06/01/2015" not in name        # flanking date column excluded
    assert "REGISTERED ADDRESS" not in name  # next section not pulled in


def test_positional_registered_name_tolerates_header_typo() -> None:
    name = ocr_dryrun._positional_registered_name(
        _name_header_words(registration_token="REGISTRAUION")
    ).upper()
    assert "MINING AND PETROLEUM SERVICES" in name
    assert "CORPORATION" in name
    assert "000-132-541-000" not in name
    assert "08/12/1998" not in name


def test_positional_registered_name_none_without_header() -> None:
    # No NAME/REGISTRATION DATE captions -> nothing to anchor on -> None.
    assert ocr_dryrun._positional_registered_name(
        [_word("PUROK", 100, 100, 80), _word("ORIENTAL", 190, 100, 120)]
    ) is None
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: the run aborts with `AttributeError: module 'ocr_dryrun' has no attribute '_positional_registered_name'` (and `_positional_fields(...)["registered_name"]` would `KeyError`), exit 1.

- [ ] **Step 3: Add the positional extractor**

In `python/scripts/ocr_dryrun.py`, insert this function immediately before `def _positional_fields(` (before line 551):

```python
def _positional_registered_name(words: list[dict]) -> str | None:
    """Read the registrant name from the TIN | NAME | REGISTRATION DATE header
    table using word boxes: the name sits in the middle column, below the NAME
    caption and between the TIN (left) and REGISTRATION DATE (right) columns.

    A plain text scan returns the neighbouring caption because the three columns
    flatten into one line; reading the column band beneath the NAME caption keeps
    only the name. Fuzzy caption matching tolerates an OCR typo in the long
    REGISTRATION caption (e.g. REGISTRAUION). The band stops just above the
    REGISTERED ADDRESS caption so the name's wrap line is kept but the next
    section is not, and any TIN/date that bleeds into the band is stripped.
    """
    name_cap = _find_phrase(words, "NAME", max_typos=1)
    date_cap = _find_phrase(words, "REGISTRATION DATE", max_typos=4)
    if not (name_cap and date_cap):
        return None
    if abs(name_cap[1] - date_cap[1]) > 40:  # captions must share the header row
        return None
    x0 = name_cap[0] - 40                     # left of NAME caption, past TIN column
    x1 = date_cap[0] - 30                     # right edge = start of the DATE column
    header_bottom = max(name_cap[3], date_cap[3])
    addr_cap = _find_phrase(words, "REGISTERED ADDRESS", max_typos=4)
    y0 = header_bottom + 6
    y1 = addr_cap[1] - 5 if addr_cap and addr_cap[1] > header_bottom else header_bottom + 80
    value = _join_clean(_words_in_box(words, x0, x1, y0, y1))
    if value:
        value = _TIN_TOKEN.sub(" ", value)
        value = _DATE_TOKEN.sub(" ", value)
        value = re.sub(r"\s+", " ", value).strip(" :|-")
    return value or None
```

- [ ] **Step 4: Wire it into `_positional_fields`**

In `_positional_fields`, change the final return block (currently line 577):

```python
    return {k: v for k, v in out.items() if v}
```

to:

```python
    out["registered_name"] = _positional_registered_name(words)

    return {k: v for k, v in out.items() if v}
```

(The dict-comprehension already drops `None`/empty values, so a missed header leaves the text-derived value in place.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: all `PASS`, exit 0. In particular the real-fixture `test_positional_registered_name_reads_middle_column` returns `CENTER FOR LOCAL GOVERNANCE AND PROFESSIONAL DEVT`, and the synthetic-typo test returns `MINING AND PETROLEUM SERVICES CORPORATION`.

- [ ] **Step 6: Commit**

```bash
git add python/tests/test_ocr_dryrun.py python/scripts/ocr_dryrun.py
git commit -m "feat(ocr): extract registered_name positionally from the NAME column

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: Fuzzy header match in the text-only fallback `_extract_registered_name`

**Files:**
- Modify: `python/scripts/ocr_dryrun.py` — add `_line_is_name_header` before `_extract_registered_name` (before line 430); change the header-row test inside `_extract_registered_name` (lines 438–440)
- Test: `python/tests/test_ocr_dryrun.py` (append)

**Interfaces:**
- Consumes: `_fuzzy_token_eq` (Task 1).
- Produces: `_line_is_name_header(line: str) -> bool`. `_extract_registered_name` now detects the header row fuzzily, so the text-only path (callers with no word boxes) also tolerates the `REGISTRAUION`-type typo.

- [ ] **Step 1: Write the failing test**

Append to `python/tests/test_ocr_dryrun.py`:

```python
# --- text-only fallback tolerates the header typo too -------------------------

def test_extract_registered_name_text_fallback_tolerates_typo() -> None:
    text = (
        "TIN NAME REGISTRAUION DATE\n"
        "000-132-541-000 MINING AND PETROLEUM SERVICES 08/12/1998\n"
        "CORPORATION\n"
        "REGISTERED ADDRESS\n"
    )
    val = ocr_dryrun.extract_fields(text, {})["registered_name"]["value"]
    assert val is not None                      # header found despite REGISTRAUION
    name = val.upper()
    assert "MINING AND PETROLEUM SERVICES" in name
    assert "CORPORATION" in name
    assert "000-132-541-000" not in name        # flanking TIN stripped
    assert "08/12/1998" not in name             # flanking date stripped


def test_extract_registered_name_text_fallback_still_matches_clean_header() -> None:
    # Regression: the existing clean-header path must keep working.
    name = ocr_dryrun.extract_fields(SAMPLE_OCR_TEXT, {})["registered_name"]["value"].upper()
    assert "GOVERNANCE" in name
    assert "REGISTRATION DATE" not in name
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: `FAIL  test_extract_registered_name_text_fallback_tolerates_typo` with an `AssertionError` on `assert val is not None` — the current exact substring check `"REGISTRATION DATE" in upper` does not match `REGISTRAUION DATE`, so the field is `None`. Exit 1. (`..._still_matches_clean_header` passes already.)

- [ ] **Step 3: Add `_line_is_name_header` and use it**

In `python/scripts/ocr_dryrun.py`, insert immediately before `def _extract_registered_name(` (before line 430):

```python
def _line_is_name_header(line: str) -> bool:
    """True if a line is the 'TIN | NAME | REGISTRATION DATE' header row, matched
    fuzzily so an OCR typo in the long caption (e.g. REGISTRAUION) still flags it.
    NAME/DATE use a 1-typo budget (short, only checked for presence); the long
    REGISTRATION caption gets the full 4."""
    toks = line.split()
    return (
        any(_fuzzy_token_eq(t, "NAME", 1) for t in toks)
        and any(_fuzzy_token_eq(t, "REGISTRATION", 4) for t in toks)
        and any(_fuzzy_token_eq(t, "DATE", 1) for t in toks)
    )
```

Then inside `_extract_registered_name`, replace these two lines (currently 439–440):

```python
        upper = line.upper()
        if "NAME" in upper and "REGISTRATION DATE" in upper:  # the caption row
```

with:

```python
        if _line_is_name_header(line):  # the TIN|NAME|REGISTRATION DATE caption row
```

(The removed `upper` local is unused elsewhere in the loop — the break condition on line 443 uses `cont.upper()`, not `upper`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: all `PASS`, exit 0. Both new tests pass; the original `test_registered_name_reads_value_row_not_column_header` (clean header) still passes.

- [ ] **Step 5: Commit**

```bash
git add python/tests/test_ocr_dryrun.py python/scripts/ocr_dryrun.py
git commit -m "feat(ocr): fuzzy header-row match in registered_name text fallback

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: End-to-end verification on the real bir2.jpg sample

**Files:** none (verification only)

**Interfaces:** Consumes the full default run of `ocr_dryrun.py` against the bundled bir2.jpg, which previously reported `Registered Name = MISSING`.

- [ ] **Step 1: Run the full harness on bir2.jpg**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe scripts/ocr_dryrun.py --input data/training/classifier_data/bir_certificate/bir2.jpg`
Expected: in the "Structured fields" table, the `* Registered Name` row now shows a value containing `MINING AND PETROLEUM SERVICES` with status `ok` (no longer `MISSING`), and the `missing_required_fields` flag no longer lists `registered_name`.

- [ ] **Step 2: Confirm the full test suite is green**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: summary line `N/N passed`, exit 0.

- [ ] **Step 3 (only if Step 1 shows the field still MISSING): diagnose, do not guess**

If the field is still missing on bir2.jpg, the live OCR boxes differ from the `bir1` geometry. Add `--save-preprocessed /tmp/bir2_pre.png` and inspect, then widen the band tolerances in `_positional_registered_name` (the `name_cap[0]-40` / `header_bottom+80` constants) to match bir2's scale — then re-run Task 3's tests to confirm no regression. Commit any tuning with message `fix(ocr): tune registered_name band for bir2 geometry`.

---

## Notes for the implementer

- The text file `tests/test_ocr_dryrun.py` defines `_word(...)` in Task 2's test block; Tasks 3 reuse it. If executing tasks out of order, ensure `_word` exists before running Task 3's tests.
- `python/tests/` is currently untracked (`?? python/tests/` in git status) — the first `git add python/tests/test_ocr_dryrun.py` will start tracking it.
- Do not add `pytest` to `requirements.txt`; the `__main__` runner is the sanctioned path for this harness.
- This work is intentionally scoped to the dry-run harness. Porting any of it into production `ocr_runner.py` is a separate, sign-off-gated effort (see the standby note in project memory).


---

## File: docs/superpowers/plans/2026-06-19-bir-synthetic-dataset-generator.md

# BIR Certificate Synthetic Dataset Generator — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `python/scripts/bir_dataset_generator.py`, a standalone CLI that fills the blank BIR Form 2303 template with OCR-realistic synthetic field data + composited dry seal and officer signature, emitting clean **and** scan-degraded labelled images into the ResNet-50 classifier's `bir_certificate` class folder, while a JSON ledger prevents duplicate data across runs.

**Architecture:** Pillow renders text into the 15 calibrated bounding boxes (Courier Prime, size 12→11 auto-fit with wrapping) and alpha-composites the two image regions (white keyed to transparency). Faker `en_PH` produces field values constrained to the OCR regexes in `ocr_dryrun.py`. Each base certificate is emitted as a clean PNG and an Augraphy-degraded JPG. A per-folder `_synthetic_manifest.json` records every record's TIN + content-hash + filenames so reruns never duplicate data and filenames keep incrementing. Pure logic (record generation, hashing, manifest, font-fit, alpha) is separated from I/O so it is unit-testable without writing the real dataset.

**Tech Stack:** Python 3.12 (venv at `python/env/`), Pillow 12.2, NumPy, Faker (`en_PH`), Augraphy 8.2.6, pytest 8.

## Global Constraints

- **Interpreter:** always `python/env/Scripts/python.exe` — never bare `python` (PATH resolves to an MSYS2 build with no stack).
- **Target script:** `python/scripts/bir_dataset_generator.py` (currently empty — fill it).
- **Output folder (flat):** `python/data/training/classifier_data/bir_certificate/`. It already holds real images — **never overwrite or collide**; all generated files use the `synthetic_bir_` prefix.
- **Batch size:** `--count` default **100 base certificates per run**. Both variants ⇒ **200 image files per run** (100 `*_clean.png` + 100 `*_scan.jpg`).
- **Duplication ledger:** `_synthetic_manifest.json` in the output folder tracks `used_tins`, `used_hashes`, `records`, and `next_index`. Reruns generate only novel records and continue filename numbering.
- **Font:** Courier Prime (SIL OFL), vendored + committed under `python/data/fonts/`. Prefer size **12**, then **11**; only shrink below 11 or wrap when a value cannot fit at 11.
- **Template:** `python/data/template/BIR_PERMIT_TEMPLATE.png` — 700×887 RGBA. Boxes are absolute pixels in that space.
- **Image assets (both RGB, white background → key white to alpha):** dry seal `python/data/seal/BIR_SEAL.png`; officer signature `python/data/stamps/BIR_OFFICER_STAMP.png`.
- **Field realism:** every generated value must satisfy the corresponding regex in `python/scripts/ocr_dryrun.py` `FIELD_SPECS` (`form_no`=2303, `tin`, `ocn`, `registration_date`, `date_issued`, `revenue_region_no`, `rdo_code`).
- **Tests:** pytest, loaded by path via `importlib.util` (repo convention — see `python/tests/test_ocr_dryrun.py`). Tests must **never write into the real data folder** — use `tmp_path`.
- **Console output:** ASCII only in `print()` (Windows cp1252 console mojibakes non-ASCII).
- **`.gitignore`:** `python/data/training/**` is ignored, so generated images + manifest are NOT committed (correct). `python/data/fonts/` is NOT ignored, so the font IS committed.

---

## File Structure

- **Create + commit:** `python/data/fonts/CourierPrime-Regular.ttf`, `python/data/fonts/CourierPrime-Bold.ttf`, `python/data/fonts/OFL.txt` — vendored font (one responsibility: the render typeface + its license).
- **Fill:** `python/scripts/bir_dataset_generator.py` — the generator. Single module, sectioned: constants/boxes → font → record generation → manifest → text rendering → asset compositing → certificate render → degradation → batch/CLI.
- **Create:** `python/tests/test_bir_dataset_generator.py` — pure-logic + small-batch tests (uses `tmp_path`, never the real folder).
- **Runtime output (gitignored):** `python/data/training/classifier_data/bir_certificate/synthetic_bir_NNNNN_clean.png`, `..._scan.jpg`, `_synthetic_manifest.json`.

**Module public API (names are fixed across tasks):**

```
Constants: PY_ROOT, TEMPLATE_PATH, SEAL_PATH, SIGNATURE_PATH, FONT_DIR, OUTPUT_DIR,
           FIELD_BOXES, IMAGE_FIELD_ASSETS, TEXT_FIELD_KEYS,
           PREFERRED_FONT_SIZES=(12,11), MIN_FONT_SIZE=8, WHITE_KEY_THRESHOLD=235, TEXT_COLOR
resolve_font(font_dir=FONT_DIR, *, bold=False) -> Path
load_font(size:int, bold=False, font_dir=str(FONT_DIR)) -> ImageFont.FreeTypeFont   # lru_cache
boxes_out_of_bounds(template_size:tuple[int,int], boxes=FIELD_BOXES) -> list[str]
generate_record(faker, rng) -> dict[str,str]
record_hash(record:dict[str,str]) -> str
load_manifest(path) -> dict ; save_manifest(path, manifest) -> None
generate_unique_record(faker, rng, manifest, max_tries=1000) -> dict
register_record(manifest, record, files:list[str]) -> int
fit_text(text, box_w, box_h, font_dir=FONT_DIR, sizes=PREFERRED_FONT_SIZES, min_size=MIN_FONT_SIZE) -> tuple[int,list[str]]
draw_text_in_box(draw, text, box, font_dir=FONT_DIR, rng=None) -> None
build_alpha(asset_rgb, threshold=WHITE_KEY_THRESHOLD) -> PIL.Image  # mode "L"
paste_asset(base, asset_path, box, rng=None) -> None
render_certificate(record, *, template_path=TEMPLATE_PATH, assets=IMAGE_FIELD_ASSETS, font_dir=FONT_DIR, rng=None) -> PIL.Image  # RGB
degrade(image, rng=None) -> PIL.Image  # RGB
validate_assets(template_path=TEMPLATE_PATH, assets=IMAGE_FIELD_ASSETS, font_dir=FONT_DIR) -> list[str]
run_batch(count, out_dir=OUTPUT_DIR, *, variants=("clean","scan"), seed=None, template_path=TEMPLATE_PATH, assets=IMAGE_FIELD_ASSETS, font_dir=FONT_DIR, manifest_name="_synthetic_manifest.json") -> dict
parse_args(argv=None) ; main(argv=None) -> int
```

---

### Task 1: Vendor the Courier Prime font + ready the test runner

**Files:**
- Create: `python/data/fonts/CourierPrime-Regular.ttf`, `python/data/fonts/CourierPrime-Bold.ttf`, `python/data/fonts/OFL.txt`
- Test: `python/tests/test_bir_dataset_generator.py` (first test only)

**Interfaces:**
- Produces: the committed font files every later rendering task depends on; a working `python/env/Scripts/python.exe -m pytest` invocation.

- [ ] **Step 1: Install pytest into the venv (dev tool; do not edit requirements.txt)**

Run:
```bash
python/env/Scripts/python.exe -m pip install pytest
```
Expected: ends with `Successfully installed ... pytest-8.x ...` (or "Requirement already satisfied").

- [ ] **Step 2: Fetch Courier Prime (SIL OFL) from the Google Fonts repo**

Run (PowerShell):
```powershell
$dir = "C:\xampp\htdocs\projects\advs\python\data\fonts"
New-Item -ItemType Directory -Force $dir | Out-Null
$base = "https://github.com/google/fonts/raw/main/ofl/courierprime"
Invoke-WebRequest "$base/CourierPrime-Regular.ttf" -OutFile "$dir\CourierPrime-Regular.ttf"
Invoke-WebRequest "$base/CourierPrime-Bold.ttf"    -OutFile "$dir\CourierPrime-Bold.ttf"
Invoke-WebRequest "$base/OFL.txt"                  -OutFile "$dir\OFL.txt"
Get-ChildItem $dir | Select-Object Name, Length
```
Expected: three files listed, each `.ttf` > 80 000 bytes.

- [ ] **Step 3: Write the failing test (font is present and loadable at size 12)**

Create `python/tests/test_bir_dataset_generator.py`:
```python
"""Unit + small-batch tests for scripts/bir_dataset_generator.py.

Pure logic (record generation, hashing, manifest dedup, font-fit, alpha keying)
is exercised in isolation; the batch test writes only into tmp_path, never the
real classifier folder. The module is loaded by path (repo convention).
"""
import importlib.util
import re
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

_SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "bir_dataset_generator.py"
_spec = importlib.util.spec_from_file_location("bir_dataset_generator", _SCRIPT)
gen = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(gen)

FONT_DIR = Path(__file__).resolve().parents[1] / "data" / "fonts"


def test_courier_prime_is_vendored_and_loadable():
    path = FONT_DIR / "CourierPrime-Regular.ttf"
    assert path.exists(), f"vendor Courier Prime into {FONT_DIR}"
    font = ImageFont.truetype(str(path), 12)
    assert font.size == 12
```

- [ ] **Step 4: Run the test — expect FAIL (module is empty / has no loadable content yet)**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -v
```
Expected: FAIL — the empty target script makes `exec_module` produce a module with nothing, but the import line itself succeeds; the test fails on the assertion only if the font is missing. If the font downloaded in Step 2, this single test PASSES. (The import of an empty module does not error.) Treat **PASS** here as success for Task 1.

- [ ] **Step 5: Commit**

```bash
git add python/data/fonts/CourierPrime-Regular.ttf python/data/fonts/CourierPrime-Bold.ttf python/data/fonts/OFL.txt python/tests/test_bir_dataset_generator.py
git commit -m "chore(ml): vendor Courier Prime (OFL) for BIR dataset generator"
```

---

### Task 2: Module skeleton — constants, boxes, font resolution

**Files:**
- Modify: `python/scripts/bir_dataset_generator.py` (create the header + constants + font helpers)
- Test: `python/tests/test_bir_dataset_generator.py`

**Interfaces:**
- Consumes: vendored font from Task 1.
- Produces: `FIELD_BOXES` (15 keys), `IMAGE_FIELD_ASSETS`, `TEXT_FIELD_KEYS`, `resolve_font`, `load_font`, `boxes_out_of_bounds`, `log`.

- [ ] **Step 1: Write the failing tests**

Append to `python/tests/test_bir_dataset_generator.py`:
```python
def test_field_boxes_cover_all_fifteen_regions():
    expected = {
        "form_no", "ocn", "tin", "registered_name", "registration_date",
        "registered_address", "revenue_region_no", "rdo_code", "line_of_business",
        "trade_name", "tax_types", "revenue_district_officer",
        "signature_over_name", "dry_seal", "date_issued",
    }
    assert set(gen.FIELD_BOXES) == expected
    assert set(gen.IMAGE_FIELD_ASSETS) == {"dry_seal", "signature_over_name"}
    assert "dry_seal" not in gen.TEXT_FIELD_KEYS
    assert "tin" in gen.TEXT_FIELD_KEYS


def test_all_boxes_fit_inside_the_template():
    assert gen.boxes_out_of_bounds((700, 887)) == []


def test_resolve_font_returns_the_vendored_ttf():
    assert gen.resolve_font(FONT_DIR).name == "CourierPrime-Regular.ttf"
    assert gen.load_font(12, font_dir=str(FONT_DIR)).size == 12
```

- [ ] **Step 2: Run — expect FAIL**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -v
```
Expected: FAIL with `AttributeError: module 'bir_dataset_generator' has no attribute 'FIELD_BOXES'`.

- [ ] **Step 3: Write the implementation**

Write `python/scripts/bir_dataset_generator.py`:
```python
"""ADVS - synthetic BIR Certificate of Registration (Form 2303) generator.

Fills the blank Form 2303 template with OCR-realistic synthetic field data and
composites the BIR dry seal + officer signature, then emits a CLEAN image and an
Augraphy-degraded SCAN image into the ResNet-50 classifier's `bir_certificate`
class folder. A per-folder JSON ledger (`_synthetic_manifest.json`) records every
record's TIN + content hash + filenames so reruns never duplicate data and
filenames keep incrementing.

Field VALUES are constrained to the OCR regexes in scripts/ocr_dryrun.py
(FIELD_SPECS) so generated documents read back the way the production OCR stage
expects. Bounding boxes are the calibrated pixel boxes from annotate_boxes.py
(IMAGE space of the 700x887 template).

Usage (always the venv interpreter):
    python/env/Scripts/python.exe python/scripts/bir_dataset_generator.py --dry-run
    python/env/Scripts/python.exe python/scripts/bir_dataset_generator.py            # 100 base -> 200 files
    python/env/Scripts/python.exe python/scripts/bir_dataset_generator.py --count 50 --clean-only
"""
from __future__ import annotations

import argparse
import hashlib
import json
import random
import string
import sys
from datetime import datetime, timezone
from functools import lru_cache
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

PY_ROOT = Path(__file__).resolve().parents[1]            # .../python
TEMPLATE_PATH = PY_ROOT / "data" / "template" / "BIR_PERMIT_TEMPLATE.png"
SEAL_PATH = PY_ROOT / "data" / "seal" / "BIR_SEAL.png"
SIGNATURE_PATH = PY_ROOT / "data" / "stamps" / "BIR_OFFICER_STAMP.png"
FONT_DIR = PY_ROOT / "data" / "fonts"
OUTPUT_DIR = PY_ROOT / "data" / "training" / "classifier_data" / "bir_certificate"

# Calibrated bounding boxes (annotate_boxes.py) in template IMAGE pixels.
FIELD_BOXES: dict[str, dict[str, int]] = {
    "form_no": {"x": 69, "y": 72, "w": 122, "h": 36},
    "ocn": {"x": 566, "y": 89, "w": 128, "h": 30},
    "tin": {"x": 18, "y": 200, "w": 171, "h": 28},
    "registered_name": {"x": 198, "y": 203, "w": 297, "h": 25},
    "registration_date": {"x": 502, "y": 204, "w": 184, "h": 24},
    "registered_address": {"x": 14, "y": 249, "w": 674, "h": 43},
    "revenue_region_no": {"x": 418, "y": 65, "w": 55, "h": 19},
    "rdo_code": {"x": 424, "y": 86, "w": 71, "h": 20},
    "line_of_business": {"x": 339, "y": 431, "w": 311, "h": 117},
    "trade_name": {"x": 51, "y": 431, "w": 241, "h": 113},
    "tax_types": {"x": 333, "y": 331, "w": 316, "h": 55},
    "revenue_district_officer": {"x": 295, "y": 773, "w": 221, "h": 43},
    "signature_over_name": {"x": 469, "y": 725, "w": 180, "h": 99},
    "dry_seal": {"x": 18, "y": 721, "w": 176, "h": 99},
    "date_issued": {"x": 554, "y": 799, "w": 114, "h": 23},
}

# The two non-text detection regions are filled with image assets, not text.
IMAGE_FIELD_ASSETS: dict[str, Path] = {
    "dry_seal": SEAL_PATH,
    "signature_over_name": SIGNATURE_PATH,
}
TEXT_FIELD_KEYS: list[str] = [k for k in FIELD_BOXES if k not in IMAGE_FIELD_ASSETS]

PREFERRED_FONT_SIZES = (12, 11)   # spec: size 12, drop to 11 to fit
MIN_FONT_SIZE = 8                 # only used when a value cannot fit at 11
WHITE_KEY_THRESHOLD = 235         # luminance >= this in an asset -> transparent
TEXT_COLOR = (25, 28, 38)         # near-black ink (not pure black)


def log(msg: str) -> None:
    """ASCII-only status line (Windows cp1252 console safe)."""
    print(f"[bir-gen] {msg}", flush=True)


def resolve_font(font_dir: Path = FONT_DIR, *, bold: bool = False) -> Path:
    """Path to the vendored Courier Prime face; fail loudly with how to get it."""
    name = "CourierPrime-Bold.ttf" if bold else "CourierPrime-Regular.ttf"
    path = Path(font_dir) / name
    if not path.exists():
        raise FileNotFoundError(
            f"Courier Prime not found at {path}. Vendor it into {font_dir} "
            "(Google Fonts OFL: ofl/courierprime/CourierPrime-Regular.ttf)."
        )
    return path


@lru_cache(maxsize=None)
def load_font(size: int, bold: bool = False, font_dir: str = str(FONT_DIR)) -> ImageFont.FreeTypeFont:
    """Cached TrueType face at a pixel size (font_dir is str so args stay hashable)."""
    return ImageFont.truetype(str(resolve_font(Path(font_dir), bold=bold)), size)


def boxes_out_of_bounds(template_size: tuple[int, int], boxes: dict | None = None) -> list[str]:
    """Keys whose box strays outside an (w, h) template - guards box calibration."""
    boxes = boxes if boxes is not None else FIELD_BOXES
    tw, th = template_size
    bad = []
    for key, b in boxes.items():
        if b["x"] < 0 or b["y"] < 0 or b["x"] + b["w"] > tw or b["y"] + b["h"] > th:
            bad.append(key)
    return bad
```

- [ ] **Step 4: Run — expect PASS**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -v
```
Expected: 4 passed.

- [ ] **Step 5: Commit**

```bash
git add python/scripts/bir_dataset_generator.py python/tests/test_bir_dataset_generator.py
git commit -m "feat(ml): scaffold BIR dataset generator (boxes, font resolution)"
```

---

### Task 3: Field-value generation + content hashing

**Files:**
- Modify: `python/scripts/bir_dataset_generator.py`
- Test: `python/tests/test_bir_dataset_generator.py`

**Interfaces:**
- Consumes: nothing new.
- Produces: `generate_record(faker, rng) -> dict[str,str]` (keys = `TEXT_FIELD_KEYS`), `record_hash(record) -> str`. Values satisfy the `ocr_dryrun.py` regexes.

- [ ] **Step 1: Write the failing tests**

Append to the test file:
```python
import random as _random
from faker import Faker


def _fresh_faker():
    f = Faker("en_PH")
    Faker.seed(12345)
    return f, _random.Random(12345)


def test_generated_values_match_ocr_regexes():
    faker, rng = _fresh_faker()
    rec = gen.generate_record(faker, rng)
    assert set(rec) == set(gen.TEXT_FIELD_KEYS)
    assert rec["form_no"] == "2303"
    assert re.fullmatch(r"\d{3}-\d{3}-\d{3}-\d{3,4}", rec["tin"])
    assert re.search(r"\d[A-Z]{1,3}\d{7,}", rec["ocn"])
    assert re.fullmatch(r"\d{1,2}/\d{1,2}/\d{2,4}", rec["registration_date"])
    assert re.fullmatch(r"\d{1,3}[A-Z]?", rec["revenue_region_no"])
    assert re.fullmatch(r"\d{1,3}", rec["rdo_code"])
    assert re.search(
        r"\b(?:JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)\.?\s+\d{1,2},?\s+(?:19|20)\d{2}\b",
        rec["date_issued"],
    )
    assert rec["registered_name"] and rec["registered_address"]


def test_record_hash_is_stable_and_order_independent():
    a = {"tin": "1", "form_no": "2303"}
    b = {"form_no": "2303", "tin": "1"}
    assert gen.record_hash(a) == gen.record_hash(b)
    assert gen.record_hash(a) != gen.record_hash({"tin": "2", "form_no": "2303"})


def test_many_records_have_unique_tins():
    faker, rng = _fresh_faker()
    tins = {gen.generate_record(faker, rng)["tin"] for _ in range(300)}
    assert len(tins) > 290  # near-unique; dedup layer (Task 4) guarantees the rest
```

- [ ] **Step 2: Run — expect FAIL**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "generated_values or record_hash or unique_tins" -v
```
Expected: FAIL with `AttributeError: ... has no attribute 'generate_record'`.

- [ ] **Step 3: Write the implementation**

Append to `python/scripts/bir_dataset_generator.py`:
```python
# ---------------------------------------------------------------------------
# Synthetic field values - constrained to the OCR FIELD_SPECS regexes so a
# generated form reads back the way the production OCR stage expects.
# ---------------------------------------------------------------------------
REVENUE_REGIONS = ["4A", "5", "6", "7", "7A", "8", "8B", "9", "9A", "10",
                   "11", "12", "13", "16", "19"]
TAX_TYPE_POOL = ["INCOME TAX", "VALUE-ADDED TAX", "PERCENTAGE TAX",
                 "WITHHOLDING TAX - COMPENSATION", "WITHHOLDING TAX - EXPANDED",
                 "REGISTRATION FEE"]
LINE_OF_BUSINESS_POOL = [
    "RETAIL SALE IN NON-SPECIALIZED STORES",
    "WHOLESALE OF OTHER HOUSEHOLD GOODS",
    "COMPUTER PROGRAMMING ACTIVITIES",
    "RESTAURANTS AND MOBILE FOOD SERVICE ACTIVITIES",
    "CONSTRUCTION OF RESIDENTIAL BUILDINGS",
    "FREIGHT TRANSPORT BY ROAD",
    "OTHER BUSINESS SUPPORT SERVICE ACTIVITIES",
    "MANUFACTURE OF BAKERY PRODUCTS",
]
MONTHS = ["JAN", "FEB", "MAR", "APR", "MAY", "JUN",
          "JUL", "AUG", "SEP", "OCT", "NOV", "DEC"]


def _tin(rng: random.Random) -> str:
    body = "-".join(f"{rng.randint(0, 999):03d}" for _ in range(3))
    branch = f"{rng.choice([0, 0, 0, rng.randint(1, 25)]):04d}"  # usually 0000 (head office)
    return f"{body}-{branch}"


def _ocn(rng: random.Random) -> str:
    head = rng.randint(1, 9)
    letters = "".join(rng.choice(string.ascii_uppercase) for _ in range(rng.randint(2, 3)))
    digits = "".join(str(rng.randint(0, 9)) for _ in range(rng.randint(9, 10)))
    return f"{head}{letters}{digits}"


def generate_record(faker, rng: random.Random) -> dict[str, str]:
    """One synthetic Form 2303 record (text fields only); image regions excluded."""
    is_company = rng.random() < 0.7
    name = (faker.company() if is_company else faker.name()).upper()
    reg = faker.date_between(start_date="-12y", end_date="-1y")
    return {
        "form_no": "2303",
        "ocn": _ocn(rng),
        "tin": _tin(rng),
        "registered_name": name,
        "registration_date": f"{reg.month}/{reg.day}/{reg.year}",
        "registered_address": faker.address().replace("\n", ", ").upper(),
        "revenue_region_no": rng.choice(REVENUE_REGIONS),
        "rdo_code": str(rng.randint(1, 213)),
        "line_of_business": rng.choice(LINE_OF_BUSINESS_POOL),
        "trade_name": (faker.company() if is_company else name.title()).upper(),
        "tax_types": ", ".join(sorted(rng.sample(TAX_TYPE_POOL, k=rng.randint(2, 4)))),
        "revenue_district_officer": faker.name().upper(),
        "date_issued": f"{rng.choice(MONTHS)} {reg.day:02d} {reg.year}",
    }


def record_hash(record: dict[str, str]) -> str:
    """Stable, order-independent SHA-1 of a record's fields (dedup key)."""
    blob = json.dumps(record, sort_keys=True, ensure_ascii=False)
    return hashlib.sha1(blob.encode("utf-8")).hexdigest()
```

- [ ] **Step 4: Run — expect PASS**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "generated_values or record_hash or unique_tins" -v
```
Expected: 3 passed.

- [ ] **Step 5: Commit**

```bash
git add python/scripts/bir_dataset_generator.py python/tests/test_bir_dataset_generator.py
git commit -m "feat(ml): OCR-realistic field-value generation + content hashing"
```

---

### Task 4: Manifest ledger + duplication guard

**Files:**
- Modify: `python/scripts/bir_dataset_generator.py`
- Test: `python/tests/test_bir_dataset_generator.py`

**Interfaces:**
- Consumes: `generate_record`, `record_hash`.
- Produces: `load_manifest(path)`, `save_manifest(path, manifest)`, `generate_unique_record(faker, rng, manifest, max_tries=1000)`, `register_record(manifest, record, files) -> int`. Manifest dict shape: `{"version", "next_index", "records", "used_tins", "used_hashes"}`.

- [ ] **Step 1: Write the failing tests**

Append to the test file:
```python
def test_manifest_roundtrip_and_increment(tmp_path):
    path = tmp_path / "_synthetic_manifest.json"
    m = gen.load_manifest(path)
    assert m["next_index"] == 1 and m["records"] == []
    idx = gen.register_record(m, {"tin": "111-111-111-0000", "form_no": "2303"},
                              ["synthetic_bir_00001_clean.png"])
    assert idx == 1 and m["next_index"] == 2
    gen.save_manifest(path, m)

    reloaded = gen.load_manifest(path)
    assert reloaded["next_index"] == 2
    assert reloaded["used_tins"] == ["111-111-111-0000"]
    assert len(reloaded["used_hashes"]) == 1


def test_unique_record_never_reuses_a_known_tin(tmp_path):
    faker, rng = _fresh_faker()
    m = gen.load_manifest(tmp_path / "m.json")
    # Pre-load the manifest with the TIN the seeded generator will produce first.
    faker2, rng2 = _fresh_faker()
    first = gen.generate_record(faker2, rng2)
    gen.register_record(m, first, ["x.png"])
    # The same seed would reproduce `first`; dedup must skip it and return a new one.
    nxt = gen.generate_unique_record(faker, rng, m)
    assert nxt["tin"] != first["tin"]
```

- [ ] **Step 2: Run — expect FAIL**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "manifest_roundtrip or unique_record_never" -v
```
Expected: FAIL with `AttributeError: ... has no attribute 'load_manifest'`.

- [ ] **Step 3: Write the implementation**

Append to `python/scripts/bir_dataset_generator.py`:
```python
# ---------------------------------------------------------------------------
# Duplication ledger: a per-folder JSON manifest of every generated record so
# reruns never duplicate data and filenames keep incrementing.
# ---------------------------------------------------------------------------
def load_manifest(path: Path) -> dict:
    p = Path(path)
    if p.exists():
        data = json.loads(p.read_text(encoding="utf-8"))
        data.setdefault("version", 1)
        data.setdefault("records", [])
        data.setdefault("used_tins", [])
        data.setdefault("used_hashes", [])
        data.setdefault("next_index", len(data["records"]) + 1)
        return data
    return {"version": 1, "next_index": 1, "records": [],
            "used_tins": [], "used_hashes": []}


def save_manifest(path: Path, manifest: dict) -> None:
    """Atomic write (temp file then replace) so an interrupted run can't corrupt it."""
    p = Path(path)
    p.parent.mkdir(parents=True, exist_ok=True)
    tmp = p.with_suffix(p.suffix + ".tmp")
    tmp.write_text(json.dumps(manifest, indent=2, ensure_ascii=False), encoding="utf-8")
    tmp.replace(p)


def generate_unique_record(faker, rng: random.Random, manifest: dict,
                           max_tries: int = 1000) -> dict:
    """A record whose TIN and content hash are absent from the manifest."""
    used_tins = set(manifest["used_tins"])
    used_hashes = set(manifest["used_hashes"])
    for _ in range(max_tries):
        rec = generate_record(faker, rng)
        if rec["tin"] in used_tins or record_hash(rec) in used_hashes:
            continue
        return rec
    raise RuntimeError(
        "Could not generate a unique record in "
        f"{max_tries} tries (manifest saturated or seed too constrained)."
    )


def register_record(manifest: dict, record: dict, files: list[str]) -> int:
    """Append a record to the manifest, return its assigned index, bump next_index."""
    idx = manifest["next_index"]
    h = record_hash(record)
    manifest["records"].append({
        "index": idx,
        "tin": record["tin"],
        "hash": h,
        "fields": record,
        "files": files,
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
    })
    manifest["used_tins"].append(record["tin"])
    manifest["used_hashes"].append(h)
    manifest["next_index"] = idx + 1
    return idx
```

- [ ] **Step 4: Run — expect PASS**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "manifest_roundtrip or unique_record_never" -v
```
Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
git add python/scripts/bir_dataset_generator.py python/tests/test_bir_dataset_generator.py
git commit -m "feat(ml): manifest ledger + cross-run duplication guard"
```

---

### Task 5: Font-fit + text rendering into a box

**Files:**
- Modify: `python/scripts/bir_dataset_generator.py`
- Test: `python/tests/test_bir_dataset_generator.py`

**Interfaces:**
- Consumes: `load_font`, `FIELD_BOXES`.
- Produces: `fit_text(text, box_w, box_h, ...) -> (size, lines)` (size ∈ candidate set, block fits when possible) and `draw_text_in_box(draw, text, box, font_dir=FONT_DIR, rng=None)`.

- [ ] **Step 1: Write the failing tests**

Append to the test file:
```python
def test_fit_text_short_value_uses_preferred_size():
    size, lines = gen.fit_text("123-456-789-0000", 171, 28, font_dir=FONT_DIR)
    assert size in (12, 11)
    assert lines == ["123-456-789-0000"]


def test_fit_text_long_value_wraps_and_fits_a_tall_box():
    box = gen.FIELD_BOXES["registered_address"]   # 674 x 43
    text = "1234 EXAMPLE STREET, BARANGAY SAMPLE, QUEZON CITY, METRO MANILA, 1100"
    size, lines = gen.fit_text(text, box["w"] - 4, box["h"] - 4, font_dir=FONT_DIR)
    scratch = ImageDraw.Draw(Image.new("RGB", (box["w"], box["h"])))
    font = gen.load_font(size, font_dir=str(FONT_DIR))
    for ln in lines:
        l, t, r, b = scratch.textbbox((0, 0), ln, font=font)
        assert (r - l) <= box["w"] - 4


def test_draw_text_in_box_marks_pixels():
    img = Image.new("RGB", (700, 887), "white")
    draw = ImageDraw.Draw(img)
    gen.draw_text_in_box(draw, "HELLO 2303", gen.FIELD_BOXES["form_no"], font_dir=FONT_DIR)
    assert img.getcolors(maxcolors=1_000_000) is None or img.getextrema() != ((255, 255), (255, 255), (255, 255))
```

- [ ] **Step 2: Run — expect FAIL**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "fit_text or draw_text_in_box" -v
```
Expected: FAIL with `AttributeError: ... has no attribute 'fit_text'`.

- [ ] **Step 3: Write the implementation**

Append to `python/scripts/bir_dataset_generator.py`:
```python
# ---------------------------------------------------------------------------
# Text rendering: prefer size 12, drop to 11, then shrink/wrap to fit the box.
# ---------------------------------------------------------------------------
def _text_size(draw: ImageDraw.ImageDraw, text: str, font) -> tuple[int, int]:
    l, t, r, b = draw.textbbox((0, 0), text or " ", font=font)
    return r - l, b - t


def _wrap_to_width(draw, text: str, font, box_w: int) -> list[str]:
    words = text.split()
    if not words:
        return [""]
    lines, cur = [], words[0]
    for word in words[1:]:
        trial = f"{cur} {word}"
        if _text_size(draw, trial, font)[0] <= box_w:
            cur = trial
        else:
            lines.append(cur)
            cur = word
    lines.append(cur)
    return lines


def fit_text(text: str, box_w: int, box_h: int, font_dir: Path = FONT_DIR,
             sizes=PREFERRED_FONT_SIZES, min_size: int = MIN_FONT_SIZE) -> tuple[int, list[str]]:
    """Largest candidate size whose wrapped block fits (box_w, box_h).

    Tries the preferred sizes (12, 11) first, then shrinks toward min_size. Returns
    the smallest tried size + its wrapping if nothing fits (text then slightly
    overflows rather than vanishing - acceptable for a synthetic scan)."""
    scratch = ImageDraw.Draw(Image.new("RGB", (max(1, box_w), max(1, box_h))))
    candidate_sizes = list(sizes) + list(range(min(sizes) - 1, min_size - 1, -1))
    fallback = None
    for size in candidate_sizes:
        font = load_font(size, font_dir=str(font_dir))
        lines = _wrap_to_width(scratch, text, font, box_w)
        line_h = _text_size(scratch, "Ag", font)[1] + 2
        total_h = line_h * len(lines)
        widest = max((_text_size(scratch, ln, font)[0] for ln in lines), default=0)
        if widest <= box_w and total_h <= box_h:
            return size, lines
        fallback = (size, lines)
    return fallback


def draw_text_in_box(draw: ImageDraw.ImageDraw, text: str, box: dict,
                     font_dir: Path = FONT_DIR, rng: random.Random | None = None) -> None:
    """Render `text` top-left inside `box` with a small pad + optional 1px jitter."""
    if not text:
        return
    pad = 2
    x, y, w, h = box["x"], box["y"], box["w"], box["h"]
    size, lines = fit_text(text, w - 2 * pad, h - 2 * pad, font_dir=font_dir)
    font = load_font(size, font_dir=str(font_dir))
    line_h = _text_size(draw, "Ag", font)[1] + 2
    jx, jy = (rng.randint(-1, 1), rng.randint(-1, 1)) if rng else (0, 0)
    ty = y + pad + jy
    for ln in lines:
        draw.text((x + pad + jx, ty), ln, fill=TEXT_COLOR, font=font)
        ty += line_h
```

- [ ] **Step 4: Run — expect PASS**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "fit_text or draw_text_in_box" -v
```
Expected: 3 passed.

- [ ] **Step 5: Commit**

```bash
git add python/scripts/bir_dataset_generator.py python/tests/test_bir_dataset_generator.py
git commit -m "feat(ml): Courier Prime box-fit text rendering (12->11, wrap)"
```

---

### Task 6: Asset compositing (white-keyed seal + signature)

**Files:**
- Modify: `python/scripts/bir_dataset_generator.py`
- Test: `python/tests/test_bir_dataset_generator.py`

**Interfaces:**
- Consumes: `FIELD_BOXES`, `IMAGE_FIELD_ASSETS`.
- Produces: `build_alpha(asset_rgb, threshold=WHITE_KEY_THRESHOLD) -> Image("L")` and `paste_asset(base, asset_path, box, rng=None)` (composites onto an RGBA `base` in place).

- [ ] **Step 1: Write the failing tests**

Append to the test file:
```python
def test_build_alpha_keys_white_transparent_and_ink_opaque():
    swatch = Image.new("RGB", (2, 1))
    swatch.putpixel((0, 0), (255, 255, 255))   # white
    swatch.putpixel((1, 0), (10, 10, 10))      # ink
    alpha = gen.build_alpha(swatch)
    assert alpha.mode == "L"
    assert alpha.getpixel((0, 0)) == 0          # white -> transparent
    assert alpha.getpixel((1, 0)) > 200         # ink -> opaque


def test_paste_asset_composites_into_the_box_region():
    base = Image.new("RGBA", (700, 887), (255, 255, 255, 255))
    before = base.copy()
    gen.paste_asset(base, gen.SEAL_PATH, gen.FIELD_BOXES["dry_seal"])
    assert base.size == (700, 887)
    box = gen.FIELD_BOXES["dry_seal"]
    crop = base.crop((box["x"], box["y"], box["x"] + box["w"], box["y"] + box["h"]))
    crop_before = before.crop((box["x"], box["y"], box["x"] + box["w"], box["y"] + box["h"]))
    assert list(crop.getdata()) != list(crop_before.getdata())
```

- [ ] **Step 2: Run — expect FAIL**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "build_alpha or paste_asset" -v
```
Expected: FAIL with `AttributeError: ... has no attribute 'build_alpha'`.

- [ ] **Step 3: Write the implementation**

Append to `python/scripts/bir_dataset_generator.py`:
```python
# ---------------------------------------------------------------------------
# Image regions: the seal + signature PNGs are ink on a white background (RGB,
# no alpha). Key near-white to transparent so only the ink composites.
# ---------------------------------------------------------------------------
def build_alpha(asset_rgb: Image.Image, threshold: int = WHITE_KEY_THRESHOLD) -> Image.Image:
    """L-mode alpha: white/near-white -> 0 (transparent), darker ink -> opaque."""
    import numpy as np

    arr = np.asarray(asset_rgb.convert("RGB")).astype(np.int16)
    lum = arr.mean(axis=2)
    alpha = np.clip((threshold - lum) * (255.0 / max(1, threshold)), 0, 255).astype("uint8")
    return Image.fromarray(alpha, mode="L")


def paste_asset(base: Image.Image, asset_path: Path, box: dict,
                rng: random.Random | None = None) -> None:
    """Scale an asset to fit `box` (aspect-preserved, centered, light jitter) and
    alpha-composite it onto an RGBA `base` in place."""
    asset = Image.open(asset_path).convert("RGB")
    asset.putalpha(build_alpha(asset))

    x, y, w, h = box["x"], box["y"], box["w"], box["h"]
    scale = min(w / asset.width, h / asset.height)
    if rng:
        scale *= rng.uniform(0.88, 1.0)
    nw, nh = max(1, int(asset.width * scale)), max(1, int(asset.height * scale))
    asset = asset.resize((nw, nh), Image.LANCZOS)
    if rng:
        asset = asset.rotate(rng.uniform(-3, 3), expand=True, resample=Image.BICUBIC)

    ox = max(x, x + (w - asset.width) // 2)
    oy = max(y, y + (h - asset.height) // 2)
    base.alpha_composite(asset, (ox, oy))
```

- [ ] **Step 4: Run — expect PASS**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "build_alpha or paste_asset" -v
```
Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
git add python/scripts/bir_dataset_generator.py python/tests/test_bir_dataset_generator.py
git commit -m "feat(ml): white-keyed seal + signature compositing"
```

---

### Task 7: Render one complete clean certificate

**Files:**
- Modify: `python/scripts/bir_dataset_generator.py`
- Test: `python/tests/test_bir_dataset_generator.py`

**Interfaces:**
- Consumes: `generate_record`, `draw_text_in_box`, `paste_asset`, `TEXT_FIELD_KEYS`, `IMAGE_FIELD_ASSETS`.
- Produces: `render_certificate(record, *, template_path=TEMPLATE_PATH, assets=IMAGE_FIELD_ASSETS, font_dir=FONT_DIR, rng=None) -> Image` (RGB, template-sized).

- [ ] **Step 1: Write the failing test**

Append to the test file:
```python
def test_render_certificate_fills_text_and_image_regions():
    faker, rng = _fresh_faker()
    rec = gen.generate_record(faker, rng)
    blank = Image.open(gen.TEMPLATE_PATH).convert("RGB")
    out = gen.render_certificate(rec, font_dir=FONT_DIR)
    assert out.mode == "RGB" and out.size == blank.size

    def region_changed(key):
        b = gen.FIELD_BOXES[key]
        crop_a = blank.crop((b["x"], b["y"], b["x"] + b["w"], b["y"] + b["h"]))
        crop_b = out.crop((b["x"], b["y"], b["x"] + b["w"], b["y"] + b["h"]))
        return list(crop_a.getdata()) != list(crop_b.getdata())

    assert region_changed("tin")            # a text field was drawn
    assert region_changed("dry_seal")       # the seal was composited
    assert region_changed("signature_over_name")
```

- [ ] **Step 2: Run — expect FAIL**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "render_certificate" -v
```
Expected: FAIL with `AttributeError: ... has no attribute 'render_certificate'`.

- [ ] **Step 3: Write the implementation**

Append to `python/scripts/bir_dataset_generator.py`:
```python
# ---------------------------------------------------------------------------
# Compose a full certificate: text fields, then the two image regions on top.
# ---------------------------------------------------------------------------
def render_certificate(record: dict, *, template_path: Path = TEMPLATE_PATH,
                       assets: dict = IMAGE_FIELD_ASSETS, font_dir: Path = FONT_DIR,
                       rng: random.Random | None = None) -> Image.Image:
    """Render a single clean Form 2303 image (RGB) from a field record."""
    base = Image.open(template_path).convert("RGBA")
    draw = ImageDraw.Draw(base)
    for key in TEXT_FIELD_KEYS:
        draw_text_in_box(draw, record.get(key, ""), FIELD_BOXES[key],
                         font_dir=font_dir, rng=rng)
    for key, asset_path in assets.items():
        paste_asset(base, asset_path, FIELD_BOXES[key], rng=rng)
    return base.convert("RGB")
```

- [ ] **Step 4: Run — expect PASS**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "render_certificate" -v
```
Expected: 1 passed.

- [ ] **Step 5: Commit**

```bash
git add python/scripts/bir_dataset_generator.py python/tests/test_bir_dataset_generator.py
git commit -m "feat(ml): render complete clean BIR certificate"
```

---

### Task 8: Augraphy scan/photocopy degradation

**Files:**
- Modify: `python/scripts/bir_dataset_generator.py`
- Test: `python/tests/test_bir_dataset_generator.py`

**Interfaces:**
- Consumes: a clean `Image` from `render_certificate`.
- Produces: `degrade(image, rng=None) -> Image` (RGB, same size; falls back to the clean image if the Augraphy pipeline errors).

- [ ] **Step 1: Write the failing tests**

Append to the test file:
```python
def test_degrade_returns_same_size_rgb_and_changes_pixels():
    clean = Image.new("RGB", (240, 320), "white")
    d = ImageDraw.Draw(clean)
    d.rectangle((20, 20, 200, 80), outline="black")
    d.text((30, 100), "BIR 2303 SAMPLE", fill=(0, 0, 0))
    out = gen.degrade(clean, _random.Random(7))
    assert out.mode == "RGB" and out.size == clean.size
    assert list(out.getdata()) != list(clean.getdata())


def test_degrade_falls_back_to_clean_on_pipeline_error(monkeypatch):
    clean = Image.new("RGB", (64, 64), "white")
    monkeypatch.setattr(gen, "_build_augraphy_pipeline", lambda: (_ for _ in ()).throw(RuntimeError("boom")))
    out = gen.degrade(clean)
    assert out.size == clean.size and out.mode == "RGB"
```

- [ ] **Step 2: Run — expect FAIL**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "degrade" -v
```
Expected: FAIL with `AttributeError: ... has no attribute 'degrade'`.

- [ ] **Step 3: Write the implementation**

Append to `python/scripts/bir_dataset_generator.py`:
```python
# ---------------------------------------------------------------------------
# Scan/photocopy realism (Augraphy). Imported lazily so the pure-logic tests and
# --dry-run do not pay the heavy import. Any pipeline failure falls back to the
# clean image so a single bad frame can't abort a 100-doc batch.
# ---------------------------------------------------------------------------
def _build_augraphy_pipeline():
    """A modest ink/post pipeline. Verified import set on augraphy 8.2.6."""
    from augraphy import (AugraphyPipeline, Brightness, Gamma, Geometric,
                          InkBleed, Jpeg, SubtleNoise)

    ink_phase = [InkBleed()]
    paper_phase = []
    post_phase = [Brightness(), Gamma(), SubtleNoise(),
                  Geometric(rotate_range=(-2, 2)), Jpeg()]
    return AugraphyPipeline(ink_phase=ink_phase, paper_phase=paper_phase,
                            post_phase=post_phase)


def degrade(image: Image.Image, rng: random.Random | None = None) -> Image.Image:
    """Augraphy scan/photocopy degradation; clean image on any failure."""
    try:
        import numpy as np

        pipeline = _build_augraphy_pipeline()
        arr = np.asarray(image.convert("RGB"))
        result = pipeline(arr)
        out = result["output"] if isinstance(result, dict) else result
        out = np.asarray(out).astype("uint8")
        if out.ndim == 2:
            out = np.stack([out] * 3, axis=-1)
        return Image.fromarray(out[:, :, :3]).convert("RGB")
    except Exception as exc:  # noqa: BLE001 - never let one frame kill the batch
        log(f"WARN: augraphy degradation failed ({exc}); using clean image as scan.")
        return image.convert("RGB")
```

- [ ] **Step 4: Run — expect PASS**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "degrade" -v
```
Expected: 2 passed. (If `test_degrade_..._changes_pixels` fails because every default-`p` augmentation rolled "off", set explicit always-on probabilities: `Jpeg(p=1.0)` and `SubtleNoise(p=1.0)` in `_build_augraphy_pipeline`, then re-run.)

- [ ] **Step 5: Commit**

```bash
git add python/scripts/bir_dataset_generator.py python/tests/test_bir_dataset_generator.py
git commit -m "feat(ml): Augraphy scan degradation with clean-image fallback"
```

---

### Task 9: CLI, batch orchestration, `--dry-run`

**Files:**
- Modify: `python/scripts/bir_dataset_generator.py`
- Test: `python/tests/test_bir_dataset_generator.py`

**Interfaces:**
- Consumes: everything above.
- Produces: `validate_assets(...) -> list[str]`, `run_batch(count, out_dir=OUTPUT_DIR, *, variants=("clean","scan"), seed=None, ...) -> dict`, `parse_args(argv)`, `main(argv) -> int`.

- [ ] **Step 1: Write the failing tests**

Append to the test file:
```python
def test_run_batch_writes_variants_and_manifest(tmp_path):
    out = tmp_path / "bir_certificate"
    summary = gen.run_batch(2, out_dir=out, seed=99, font_dir=FONT_DIR)
    pngs = sorted(out.glob("synthetic_bir_*_clean.png"))
    jpgs = sorted(out.glob("synthetic_bir_*_scan.jpg"))
    assert len(pngs) == 2 and len(jpgs) == 2
    assert (out / "_synthetic_manifest.json").exists()
    assert summary["count"] == 2 and summary["next_index"] == 3


def test_second_run_appends_without_duplicates(tmp_path):
    out = tmp_path / "bir_certificate"
    gen.run_batch(2, out_dir=out, seed=1, font_dir=FONT_DIR)
    gen.run_batch(2, out_dir=out, seed=2, font_dir=FONT_DIR)
    manifest = gen.load_manifest(out / "_synthetic_manifest.json")
    assert manifest["next_index"] == 5
    assert len(manifest["used_tins"]) == len(set(manifest["used_tins"]))  # no dup TINs
    assert sorted(p.name for p in out.glob("synthetic_bir_0000*_clean.png")) == [
        "synthetic_bir_00001_clean.png", "synthetic_bir_00002_clean.png",
        "synthetic_bir_00003_clean.png", "synthetic_bir_00004_clean.png",
    ]


def test_dry_run_writes_nothing(tmp_path):
    out = tmp_path / "bir_certificate"
    out.mkdir()
    rc = gen.main(["--dry-run", "--out-dir", str(out), "--font-dir", str(FONT_DIR)])
    assert rc == 0
    assert list(out.iterdir()) == []
```

- [ ] **Step 2: Run — expect FAIL**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "run_batch or second_run or dry_run" -v
```
Expected: FAIL with `AttributeError: ... has no attribute 'run_batch'`.

- [ ] **Step 3: Write the implementation**

Append to `python/scripts/bir_dataset_generator.py`:
```python
# ---------------------------------------------------------------------------
# Validation, batch orchestration, CLI.
# ---------------------------------------------------------------------------
def validate_assets(template_path: Path = TEMPLATE_PATH, assets: dict = IMAGE_FIELD_ASSETS,
                    font_dir: Path = FONT_DIR) -> list[str]:
    """Human-readable problems blocking generation (empty list = good to go)."""
    problems = []
    if not Path(template_path).exists():
        problems.append(f"template missing: {template_path}")
    else:
        with Image.open(template_path) as im:
            bad = boxes_out_of_bounds(im.size)
        if bad:
            problems.append(f"boxes outside template: {', '.join(bad)}")
    for key, path in assets.items():
        if not Path(path).exists():
            problems.append(f"{key} asset missing: {path}")
    try:
        resolve_font(font_dir)
    except FileNotFoundError as exc:
        problems.append(str(exc))
    return problems


def run_batch(count: int, out_dir: Path = OUTPUT_DIR, *,
              variants: tuple[str, ...] = ("clean", "scan"), seed: int | None = None,
              template_path: Path = TEMPLATE_PATH, assets: dict = IMAGE_FIELD_ASSETS,
              font_dir: Path = FONT_DIR,
              manifest_name: str = "_synthetic_manifest.json") -> dict:
    """Generate `count` unique base certificates, each emitted in the requested
    variants, appending to the per-folder manifest."""
    from faker import Faker

    out_dir = Path(out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    manifest_path = out_dir / manifest_name
    manifest = load_manifest(manifest_path)

    rng = random.Random(seed)
    faker = Faker("en_PH")
    if seed is not None:
        Faker.seed(seed)

    written: list[str] = []
    for n in range(count):
        record = generate_unique_record(faker, rng, manifest)
        idx = manifest["next_index"]
        stem = f"synthetic_bir_{idx:05d}"
        clean = render_certificate(record, template_path=template_path,
                                   assets=assets, font_dir=font_dir, rng=rng)
        files: list[str] = []
        if "clean" in variants:
            fp = out_dir / f"{stem}_clean.png"
            clean.save(fp)
            files.append(fp.name)
        if "scan" in variants:
            fp = out_dir / f"{stem}_scan.jpg"
            degrade(clean, rng).save(fp, quality=85)
            files.append(fp.name)
        register_record(manifest, record, files)
        written.extend(files)
        if (n + 1) % 25 == 0:
            log(f"generated {n + 1}/{count} certificates")

    save_manifest(manifest_path, manifest)
    return {"count": count, "files": written, "manifest": str(manifest_path),
            "next_index": manifest["next_index"]}


def parse_args(argv=None):
    p = argparse.ArgumentParser(description="Generate synthetic BIR Form 2303 training images.")
    p.add_argument("--count", type=int, default=100,
                   help="base certificates per run (each -> clean + scan = 2 files). Default 100.")
    p.add_argument("--out-dir", default=str(OUTPUT_DIR))
    p.add_argument("--seed", type=int, default=None, help="reproducible run (use a fresh out-dir).")
    p.add_argument("--clean-only", action="store_true", help="emit only the clean PNG.")
    p.add_argument("--scan-only", action="store_true", help="emit only the degraded JPG.")
    p.add_argument("--template", default=str(TEMPLATE_PATH))
    p.add_argument("--seal", default=str(SEAL_PATH))
    p.add_argument("--signature", default=str(SIGNATURE_PATH))
    p.add_argument("--font-dir", default=str(FONT_DIR))
    p.add_argument("--dry-run", action="store_true",
                   help="validate assets/boxes/font + render one cert in memory; write nothing.")
    return p.parse_args(argv)


def main(argv=None) -> int:
    args = parse_args(argv)
    assets = {"dry_seal": Path(args.seal), "signature_over_name": Path(args.signature)}
    font_dir = Path(args.font_dir)
    template = Path(args.template)

    problems = validate_assets(template, assets, font_dir)
    if problems:
        for prob in problems:
            log(f"ERROR: {prob}")
        return 2

    if args.dry_run:
        from faker import Faker
        rng = random.Random(0)
        faker = Faker("en_PH"); Faker.seed(0)
        record = generate_record(faker, rng)
        img = render_certificate(record, template_path=template, assets=assets, font_dir=font_dir)
        log(f"DRY RUN ok - rendered 1 certificate in memory at {img.size}, wrote nothing.")
        log(f"fields: TIN={record['tin']} OCN={record['ocn']} issued={record['date_issued']}")
        return 0

    variants = ("clean", "scan")
    if args.clean_only:
        variants = ("clean",)
    elif args.scan_only:
        variants = ("scan",)

    summary = run_batch(args.count, out_dir=Path(args.out_dir), variants=variants,
                        seed=args.seed, template_path=template, assets=assets, font_dir=font_dir)
    log(f"wrote {len(summary['files'])} files ({args.count} base certs) -> {args.out_dir}")
    log(f"manifest -> {summary['manifest']} (next_index={summary['next_index']})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
```

- [ ] **Step 4: Run — expect PASS**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "run_batch or second_run or dry_run" -v
```
Expected: 3 passed.

- [ ] **Step 5: Run the whole test file + compile check**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -v
python/env/Scripts/python.exe -m py_compile python/scripts/bir_dataset_generator.py
```
Expected: all tests pass; compile is silent (exit 0).

- [ ] **Step 6: Commit**

```bash
git add python/scripts/bir_dataset_generator.py python/tests/test_bir_dataset_generator.py
git commit -m "feat(ml): CLI + batch orchestration + dry-run for BIR generator"
```

---

### Task 10: Generate the first real batch + eyeball QA

**Files:**
- Output (gitignored): `python/data/training/classifier_data/bir_certificate/synthetic_bir_*.{png,jpg}` + `_synthetic_manifest.json`

**Interfaces:**
- Consumes: the finished, tested generator.

- [ ] **Step 1: Dry-run against the real assets**

Run:
```bash
python/env/Scripts/python.exe python/scripts/bir_dataset_generator.py --dry-run
```
Expected: `[bir-gen] DRY RUN ok - rendered 1 certificate in memory at (700, 887), wrote nothing.` (exit 0).

- [ ] **Step 2: Generate a small visual sample (10) and inspect**

Run:
```bash
python/env/Scripts/python.exe python/scripts/bir_dataset_generator.py --count 10
```
Expected: `[bir-gen] wrote 20 files (10 base certs) -> ...bir_certificate`.

Open 2–3 `synthetic_bir_000NN_clean.png` and `_scan.jpg` and confirm: text sits inside each box (no overflow into neighbours), the seal + signature land in their regions, the scan variant looks plausibly photocopied. If any field overflows, adjust that box's `pad`/size handling in `fit_text` and re-run a fresh `--seed` sample into a tmp dir.

- [ ] **Step 3: Generate the full 100-base batch**

Run:
```bash
python/env/Scripts/python.exe python/scripts/bir_dataset_generator.py --count 100
```
Expected: `[bir-gen] wrote 200 files (100 base certs) -> ...` and a manifest `next_index` of 111 (10 from Step 2 + 100). Total in folder: 220 synthetic files + the pre-existing real images.

- [ ] **Step 4: Verify counts + manifest integrity**

Run (PowerShell):
```powershell
$dir = "C:\xampp\htdocs\projects\advs\python\data\training\classifier_data\bir_certificate"
"clean: " + (Get-ChildItem "$dir\synthetic_bir_*_clean.png").Count
"scan:  " + (Get-ChildItem "$dir\synthetic_bir_*_scan.jpg").Count
$m = Get-Content "$dir\_synthetic_manifest.json" -Raw | ConvertFrom-Json
"records: " + $m.records.Count + "  unique TINs: " + ($m.used_tins | Sort-Object -Unique).Count
```
Expected: `clean: 110`, `scan: 110`, `records: 110`, `unique TINs: 110` (no duplicates).

- [ ] **Step 5: Confirm the classifier sees the data (no commit — data is gitignored)**

Run:
```bash
python/env/Scripts/python.exe python/scripts/train_classifier.py --dry-run
```
Expected: exit 0 (the `bir_certificate` class now has the real + synthetic images; layout still valid). No `git add` of the images/manifest — `python/data/training/**` is gitignored by design.

---

## Self-Review

**1. Spec coverage**
- Fill `python/scripts/bir_dataset_generator.py` → Tasks 2–9. ✓
- Output to `python/data/training/classifier_data/bir_certificate` → `OUTPUT_DIR`, Tasks 9–10. ✓
- Courier Prime, size 11/12 to fit box → Task 1 (vendor) + Task 5 (`PREFERRED_FONT_SIZES=(12,11)`, fit/wrap). ✓
- `dry_seal` ← `BIR_SEAL.png`, `signature_over_name` ← `BIR_OFFICER_STAMP.png` → `IMAGE_FIELD_ASSETS`, Task 6. ✓
- All 15 boxes consumed → `FIELD_BOXES` (13 text + 2 image), Tasks 2/5/6. ✓
- "100 per run" + dedup JSON ledger → `--count` default 100, manifest (Task 4), cross-run test (Task 9). ✓
- Both clean + degraded → Task 7 (clean) + Task 8 (Augraphy) + Task 9 (variants). ✓
- "Ask if unclear" → resolved up front (font source, count+ledger, realism). ✓

**2. Placeholder scan:** No TBD/TODO; every code step contains full, runnable code and real test bodies. ✓

**3. Type consistency:** `FIELD_BOXES` dict-of-dicts (`x/y/w/h`) used identically in `boxes_out_of_bounds`, `draw_text_in_box`, `paste_asset`, `render_certificate`. `fit_text` returns `(size, lines)` consumed by `draw_text_in_box`. Manifest keys (`next_index`, `used_tins`, `used_hashes`, `records`) consistent across `load/save/register/generate_unique`. `render_certificate` → RGB `Image` consumed by `degrade`. `main`/`run_batch`/`parse_args` signatures match the test calls (`gen.main([...])`, `gen.run_batch(2, out_dir=..., seed=..., font_dir=...)`). ✓

**Applicable skills:** `advs-system-reference` (classifier training-data domain) + `run-advs-training` (data layout, venv interpreter) drove this plan; `superpowers:test-driven-development` governs execution. The user-suggested `/laravel-best-practices` and `/analyzing-data` do **not** apply (pure Python; no Laravel or warehouse SQL).


---

## File: docs/superpowers/plans/2026-06-22-theme-consistency-and-search-bar-fill.md

# Theme Consistency, 7-Day Cookie Persistence & Search-Bar Background Fill — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the interface theme (Light/Dark/System) apply consistently across every page for every role, persist the choice in a 7-day client cookie, and fix the page background so it always fills the viewport regardless of how many rows a filter/search returns.

**Architecture:** Three layers. (1) **Tokens** — the existing semantic `cu-*` CSS variables become theme-flipping (light values by default, dark values under `.dark`), so every `bg-cu-*` / `text-cu-*` / `border-cu-*` utility already in the views switches automatically. (2) **Persistence** — replace Flux's localStorage appearance script with a tiny inline `window.advsTheme` runtime that reads/writes a 7-day `theme` cookie and toggles the `.dark` class before first paint (no flash). (3) **Layout** — a shared `<x-page>` wrapper paints a theme-responsive, viewport-filling background using a flex column, replacing the brittle `min-h-full` wrapper duplicated across 19 views. After the infrastructure lands, a deterministic class-mapping sweep converts the per-view neutral literals (`text-white`, `bg-white/5`, `bg-zinc-50`, `text-zinc-950`, …) to the semantic tokens so cards/text/borders flip too.

**Tech Stack:** Laravel 12, Livewire 4 + Volt 1, Flux UI 2, Tailwind CSS v4 (config-in-CSS via `@theme`), Alpine (bundled with Flux), PHPUnit 11.

## Global Constraints

- **Persistence mechanism (verbatim from the requester):** client-only cookie, **7-day expiry** (`max-age = 60 * 60 * 24 * 7 = 604800` seconds). Cookie name: `theme`. Values: `light` | `dark` | `system`. Default when absent/invalid: `system`. No server/Laravel changes for applying the theme; no database column.
- **Scope (verbatim from the requester):** Full light + dark, **all pages, all user types** (vendor, compliance_officer, admin). The toggle must drive the whole page (shell *and* content), not just the chrome.
- **No new dependencies.** No JS test runner is installed and none is to be added (CLAUDE.md: Vitest is "optional"; Boost rules: do not change dependencies without approval). Programmatic verification of view/CSS/JS changes is done with PHPUnit feature tests that assert rendered markup, plus file-content assertions for the CSS/JS files. State this limitation honestly in test docblocks.
- **Brand & status colors never flip.** Leave `cu-purple`, `cu-pink`, `cu-blue`, `cu-yellow`, the `cu-gradient`/`cu-gradient-text` utilities, all `rose-*`/`emerald-*`/`amber-*`/`red-*`/`green-*` status colors, and any `white/x` overlay that sits **on** a gradient/brand background exactly as-is. Only neutral page/surface/text/border literals are converted.
- **Formatting:** run `vendor/bin/pint --dirty --format agent` before every commit that touches PHP.
- **Tests:** PHPUnit class-based tests only (`php artisan make:test --phpunit`). Run the minimal filtered set after each change: `php artisan test --compact --filter=<name>`.
- **Tailwind rebuild:** CSS/Blade class changes only appear after `npm run build` (or `npm run dev`). Tests assert source markup/CSS, so they do not require a build, but any manual visual check does.

---

## Theme Class-Mapping Reference (canonical — used by every Phase B task)

"Apply the mapping" = in the named file, replace each literal on the left with the token/variant on the right; leave the keep-list untouched. The `cu-*` tokens flip automatically (Task 1), so the right-hand side is the **post-conversion** form. Conversions in section **B** intentionally retain a `dark:…white/x` variant — the repo-wide guard (Task 10) therefore scans only for the `zinc-*` literals from section **A**, which have no surviving form.

**A. Neutral surfaces / text / borders**

| Find | Replace with |
|---|---|
| `text-white` as body/heading/value text (not on a gradient/accent bg) | `text-cu-text` |
| `text-zinc-950`, `text-zinc-900` (primary text) | `text-cu-text` |
| `text-zinc-200` | `text-cu-text` |
| `text-zinc-500`, `text-zinc-400`, `text-zinc-300` (muted/secondary) | `text-cu-muted` |
| `text-zinc-700` used as a neutral chip label (vendor-status default) | `text-cu-text` |
| `bg-white` as a card/panel surface | `bg-cu-surface` |
| `bg-zinc-50` as an inner subtle surface (segmented control, chip) | `bg-black/5 dark:bg-white/5` |
| `border-zinc-200`, `border-zinc-100` | `border-cu-border` |
| `border-white/5`, `border-white/10` | `border-cu-border` |
| `hover:border-white/10` | `hover:border-cu-border` |
| `divide-white/5`, `divide-white/10` | `divide-cu-border` |
| `shadow-sm` | unchanged |
| any `bg-cu-*` / `text-cu-*` / `border-cu-border` / `text-cu-muted/60` | unchanged (token flips) |

**B. Neutral white-opacity overlays (retain a `dark:` form)**

| Find | Replace with |
|---|---|
| `bg-white/5` (subtle chip/surface, not on a gradient) | `bg-black/5 dark:bg-white/5` |
| `bg-white/[0.03]` | `bg-black/[0.03] dark:bg-white/[0.03]` |
| `hover:bg-white/[0.03]` | `hover:bg-black/[0.03] dark:hover:bg-white/[0.03]` |
| `text-white/10` (e.g. gauge track) | `text-black/10 dark:text-white/10` |
| `hover:text-white` / `group-hover:text-white` on a **neutral** surface (a `cu-surface`/`cu-bg`/black-or-white-opacity element, e.g. an inactive segmented/filter button or a table-row link) | `hover:text-cu-text` / `group-hover:text-cu-text` |

> Why: on a light surface, `hover:text-white` turns the label invisible (white-on-white) on hover. Convert it only when the hovered element's own background is neutral. **Keep** `hover:text-white` when the hovered element sits on an accent — e.g. the active filter button is `bg-cu-purple text-white`; a `text-cu-muted hover:text-white` segmented control whose hover background is an accent stays. In the existing pages the inactive segmented/filter buttons hover on a neutral container, so they convert.

**C. Status-color tints — keep the hue, fix light-mode contrast**

Translucent status backgrounds/borders/rings/dots read on **both** themes — **keep** `bg-{c}-500/15`, `bg-{c}-400/5`, `ring-{c}-500/30`, `border-{c}-500/40`, and dots `bg-{c}-400`. Only the pale **text tints** (the `-200`/`-300` shades) are illegible on a light surface, and the solid light chips (`-50` backgrounds) are wrong on a dark surface:

| Find (`c` ∈ `rose`, `amber`, `emerald`, `sky`, `yellow`) | Replace with |
|---|---|
| `text-{c}-300` | `text-{c}-700 dark:text-{c}-300` |
| `text-{c}-200` | `text-{c}-700 dark:text-{c}-200` |
| `bg-{c}-50 text-{c}-700` (solid light chip) | `bg-{c}-500/15 text-{c}-700 dark:text-{c}-300` |
| `border-{c}-300` (light chip border) | `border-{c}-500/40` |

> `-400` icon tints (`text-rose-400`, `text-emerald-400`, …) are saturated enough to read on both themes — **leave them**. Rule C only touches `-200`/`-300` text and `-50` chips.

**Keep-list (never change):**
- Page-wrapper backgrounds (`bg-zinc-50` / `bg-cu-bg` on the `-m-6` wrapper) — removed by the `<x-page>` swap in Task 4, not mapped here.
- Anything inside an element whose own background is `cu-gradient`, `bg-cu-purple`, `bg-cu-pink`, or a solid accent: keep its `text-white`, `text-white/80`, `bg-white/15`, `bg-white/20`, `ring-white/30`, and white-on-gradient buttons (`bg-white … text-cu-purple`).
- Brand/accent utilities (`cu-purple`, `cu-pink`, `cu-blue`, `cu-yellow`, `cu-gradient*`) and the translucent accent backgrounds/borders/dots named in rule C.

---

## File Structure

**Created:**
- `resources/views/components/page.blade.php` — `<x-page>`: the single themed, viewport-filling page wrapper (replaces 19 duplicated wrappers; carries the search-bar background fix).
- `resources/views/components/theme-toggle.blade.php` — `<x-theme-toggle>`: the Light/Dark/System segmented control wired to `window.advsTheme` (shared by the Preference and Appearance settings pages).
- `tests/Feature/Theme/ThemeTokensTest.php` — guards the flipping `cu-*` token definitions in `app.css`.
- `tests/Feature/Theme/ThemeBootstrapTest.php` — guards the inline cookie runtime in the page `<head>` and the removal of `@fluxAppearance`.
- `tests/Feature/Theme/PageWrapperTest.php` — guards that no view uses the old brittle wrappers and that `<x-page>` renders the fill/theme classes.
- `tests/Feature/Theme/ThemeComponentSweepTest.php` — guards the 9 shared display components are theme-responsive (Task 5).
- `tests/Feature/Theme/ContentThemeSweepTest.php` — per-page-group `assertViewConverted()` scans for the admin + vendor views (created Task 6, extended Tasks 7–9).
- `tests/Feature/Theme/ViewThemeGuardTest.php` — repo-wide catch-all guard against theme-locked neutral literals (Task 10).

**Modified:**
- `resources/css/app.css` — `cu-*` tokens flip per theme; add `--color-cu-border`.
- `resources/views/partials/head.blade.php` — inline `window.advsTheme` runtime; remove `@fluxAppearance`.
- `resources/views/components/layouts/app.blade.php` — make `flux:main` a flex column so `<x-page>` can fill height.
- `resources/views/livewire/settings/preference.blade.php` and `resources/views/livewire/settings/appearance.blade.php` — use `<x-theme-toggle>` instead of `x-model="$flux.appearance"`.
- 19 content views (the `-m-6 min-h-{full,svh} …` wrappers) — swap wrapper for `<x-page>`, then apply the mapping. Enumerated in Tasks 4–9.

---

## Phase A — Infrastructure (no visual regression in dark mode; ships the search-bar fix and a working, persistent toggle)

### Task 1: Flip the `cu-*` theme tokens

**Files:**
- Modify: `resources/css/app.css:30-42` (the `cu-*` block in `@theme`) and `resources/css/app.css:56-62` (the `@layer theme { .dark { … } }` block)
- Test: `tests/Feature/Theme/ThemeTokensTest.php`

**Interfaces:**
- Produces: light defaults for `--color-cu-bg`, `--color-cu-surface`, `--color-cu-text`, `--color-cu-muted`, and a new `--color-cu-border`; dark overrides for all five under `.dark`. Every existing `bg-cu-bg` / `bg-cu-surface` / `text-cu-text` / `text-cu-muted` utility and the new `border-cu-border` utility now flip with the `.dark` class.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Theme;

use Tests\TestCase;

class ThemeTokensTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    public function test_cu_tokens_have_light_defaults_in_theme_block(): void
    {
        $css = $this->css();

        // Light page background is no longer near-black.
        $this->assertStringContainsString('--color-cu-bg: #f8f8fb;', $css);
        $this->assertStringContainsString('--color-cu-surface: #ffffff;', $css);
        $this->assertStringContainsString('--color-cu-text: #18181b;', $css);
        $this->assertStringContainsString('--color-cu-border: #e5e7eb;', $css);
    }

    public function test_cu_tokens_have_dark_overrides(): void
    {
        $css = $this->css();

        // The original dark surfaces now live under the .dark override.
        $this->assertMatchesRegularExpression(
            '/\.dark\s*\{[^}]*--color-cu-bg:\s*#0d0d0f;[^}]*--color-cu-surface:\s*#1a1a2e;/s',
            $css,
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ThemeTokensTest`
Expected: FAIL — `app.css` currently defines the dark values directly in `@theme` and has no `--color-cu-border`.

- [ ] **Step 3: Edit `resources/css/app.css`**

Replace the existing `cu-*` surface block (currently lines 37-41):

```css
    /* ClickUp dark theme surfaces. */
    --color-cu-bg: #0d0d0f;
    --color-cu-surface: #1a1a2e;
    --color-cu-text: #ffffff;
    --color-cu-muted: #a0a0b0;
```

with theme-neutral **light defaults** (dark values move to the `.dark` layer below):

```css
    /* ClickUp semantic surfaces — light defaults; dark overrides live in
       `@layer theme { .dark { … } }` below so every `cu-*` utility flips
       with the `.dark` class set by window.advsTheme. */
    --color-cu-bg: #f8f8fb;
    --color-cu-surface: #ffffff;
    --color-cu-text: #18181b;
    --color-cu-muted: #6b7280;
    --color-cu-border: #e5e7eb;
```

Then extend the existing `.dark` block (currently lines 56-62) to add the dark surface overrides:

```css
@layer theme {
    .dark {
        --color-accent: var(--color-white);
        --color-accent-content: var(--color-white);
        --color-accent-foreground: var(--color-neutral-800);

        /* Dark ClickUp surfaces (original values). */
        --color-cu-bg: #0d0d0f;
        --color-cu-surface: #1a1a2e;
        --color-cu-text: #ffffff;
        --color-cu-muted: #a0a0b0;
        --color-cu-border: rgb(255 255 255 / 0.08);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ThemeTokensTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/css/app.css tests/Feature/Theme/ThemeTokensTest.php
git commit -m "feat(theme): make cu-* surface tokens flip between light and dark"
```

---

### Task 2: Client-only 7-day cookie runtime in `<head>`

**Files:**
- Modify: `resources/views/partials/head.blade.php` (add inline script; remove `@fluxAppearance` on line 14)
- Test: `tests/Feature/Theme/ThemeBootstrapTest.php`

**Interfaces:**
- Produces: a global `window.advsTheme` object with `read(): string`, `set(value: string): void`, `apply(value: string): void`. `set` writes the `theme` cookie (`max-age=604800`, `path=/`, `SameSite=Lax`) and toggles `document.documentElement.classList` `dark`. The runtime applies the saved theme synchronously on load (no flash) and live-updates when the OS theme changes while in `system` mode. Consumed by `<x-theme-toggle>` (Task 3).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Theme;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_head_renders_the_cookie_theme_runtime(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_VENDOR]);

        $response = $this->actingAs($user)->get(route('vendor.dashboard'));

        $response->assertOk()
            ->assertSee('window.advsTheme', false)
            ->assertSee('max-age=604800', false)
            ->assertSee("theme=", false);
    }

    public function test_flux_localStorage_appearance_directive_is_removed(): void
    {
        // @fluxAppearance injects Flux's localStorage-based applier, which we
        // replace with the cookie runtime. Its absence is asserted via the head partial.
        $head = (string) file_get_contents(resource_path('views/partials/head.blade.php'));

        $this->assertStringNotContainsString('@fluxAppearance', $head);
        $this->assertStringContainsString('window.advsTheme', $head);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ThemeBootstrapTest`
Expected: FAIL — head still contains `@fluxAppearance` and no `window.advsTheme`.

- [ ] **Step 3: Edit `resources/views/partials/head.blade.php`**

Replace the file's contents with:

```blade
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $title ?? 'Laravel' }}</title>

{{-- Theme runtime: client-only, 7-day `theme` cookie (light|dark|system).
     Runs synchronously before first paint to avoid a flash of the wrong theme,
     and live-updates with the OS setting while in `system` mode. The settings
     toggle (<x-theme-toggle>) drives this via window.advsTheme.set(). --}}
<script>
    window.advsTheme = (function () {
        var KEY = 'theme';
        var MAX_AGE = 60 * 60 * 24 * 7; // 7 days in seconds
        var VALID = ['light', 'dark', 'system'];

        function read() {
            var match = document.cookie.match(/(?:^|;\s*)theme=([^;]+)/);
            var value = match ? decodeURIComponent(match[1]) : 'system';
            return VALID.indexOf(value) === -1 ? 'system' : value;
        }

        function isDark(value) {
            if (value === 'system') {
                return window.matchMedia('(prefers-color-scheme: dark)').matches;
            }
            return value === 'dark';
        }

        function apply(value) {
            document.documentElement.classList.toggle('dark', isDark(value));
        }

        function set(value) {
            if (VALID.indexOf(value) === -1) { value = 'system'; }
            document.cookie = KEY + '=' + encodeURIComponent(value) + ';path=/;max-age=' + MAX_AGE + ';SameSite=Lax';
            apply(value);
        }

        apply(read());

        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            if (read() === 'system') { apply('system'); }
        });

        return { read: read, set: set, apply: apply };
    })();
</script>

<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
{{-- Load the webfont stylesheet without blocking first paint; swap in once loaded. --}}
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" media="print" onload="this.media='all'" />
<noscript>
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
</noscript>

@vite(['resources/css/app.css', 'resources/js/app.js'])
```

(Note: `@fluxAppearance` is removed; `@fluxScripts` in the layout `<body>` is **kept** — Flux components still style off the `.dark` class.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ThemeBootstrapTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/views/partials/head.blade.php tests/Feature/Theme/ThemeBootstrapTest.php
git commit -m "feat(theme): apply theme from a 7-day cookie before paint, drop Flux localStorage applier"
```

---

### Task 3: `<x-theme-toggle>` component + rewire the settings pages

**Files:**
- Create: `resources/views/components/theme-toggle.blade.php`
- Modify: `resources/views/livewire/settings/preference.blade.php:13-21`
- Modify: `resources/views/livewire/settings/appearance.blade.php:13-17`
- Test: extend `tests/Feature/Settings/PreferenceTest.php` (existing file)

**Interfaces:**
- Consumes: `window.advsTheme.read()` / `.set()` from Task 2.
- Produces: `<x-theme-toggle>` — a Flux segmented radio group bound to a local Alpine `theme` value, persisting via `window.advsTheme.set()` on change. Forwards arbitrary attributes (e.g. `label="Interface theme"`).

- [ ] **Step 1: Add a failing assertion to `tests/Feature/Settings/PreferenceTest.php`**

Add this method inside the existing `PreferenceTest` class:

```php
    public function test_preference_toggle_is_wired_to_the_cookie_runtime(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.preference'))
            ->assertOk()
            ->assertSee('advsTheme.set', false)
            ->assertDontSee('$flux.appearance', false);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PreferenceTest`
Expected: FAIL on the new method — the page still uses `x-model="$flux.appearance"` and has no `advsTheme.set`.

- [ ] **Step 3: Create `resources/views/components/theme-toggle.blade.php`**

```blade
{{-- Light / Dark / System segmented control. Persists to a 7-day `theme`
     cookie and applies the `.dark` class via window.advsTheme (see
     resources/views/partials/head.blade.php). Pass `label="…"` to label it. --}}
<flux:radio.group
    x-data="{ theme: window.advsTheme.read() }"
    x-init="$watch('theme', value => window.advsTheme.set(value))"
    x-model="theme"
    variant="segmented"
    {{ $attributes }}
>
    <flux:radio value="light" icon="sun">Light</flux:radio>
    <flux:radio value="dark" icon="moon">Dark</flux:radio>
    <flux:radio value="system" icon="computer-desktop">System</flux:radio>
</flux:radio.group>
```

- [ ] **Step 4: Rewire `resources/views/livewire/settings/preference.blade.php`**

Replace the `<flux:radio.group …>…</flux:radio.group>` block (lines 13-17) with:

```blade
        <x-theme-toggle label="Interface theme" />
```

The surrounding `<x-settings.layout …>`, the `<flux:text>` helper line, and the component PHP block stay unchanged.

- [ ] **Step 5: Rewire `resources/views/livewire/settings/appearance.blade.php`**

Replace the `<flux:radio.group …>…</flux:radio.group>` block (lines 13-17) with:

```blade
        <x-theme-toggle />
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter=PreferenceTest`
Expected: PASS (4 tests — the 3 original + the new wiring test).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/theme-toggle.blade.php resources/views/livewire/settings/preference.blade.php resources/views/livewire/settings/appearance.blade.php tests/Feature/Settings/PreferenceTest.php
git commit -m "feat(theme): share <x-theme-toggle> across settings pages, drop \$flux.appearance"
```

---

### Task 4: `<x-page>` wrapper + viewport-filling background (the search-bar fix)

**Files:**
- Create: `resources/views/components/page.blade.php`
- Modify: `resources/views/components/layouts/app.blade.php:2`
- Modify (wrapper swap only — inner markup untouched): all 19 views listed below
- Test: `tests/Feature/Theme/PageWrapperTest.php`

**Interfaces:**
- Consumes: the `flux:main` flex column from `app.blade.php`.
- Produces: `<x-page>` — a slot wrapper rendering `-m-6 flex min-h-full flex-1 flex-col bg-white p-6 text-zinc-900 lg:-m-8 lg:p-8 dark:bg-cu-bg dark:text-cu-text`. Because it is a `flex-1` child of a flex-column `flux:main` (which is `min-h-svh`), it always fills the viewport height — the background no longer collapses to content height when a filter returns few/no rows.

**Why this fixes the bug:** the old wrapper used `min-h-full` (`min-height:100%`), which cannot resolve against `flux:main`'s `min-h-svh` (a *min-height*, not a definite height), so it collapsed to content height. Making `flux:main` a flex column and the wrapper `flex-1` forces the wrapper to consume the full column height deterministically.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Theme;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageWrapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_page_uses_filling_themed_wrapper(): void
    {
        $officer = User::factory()->create(['role' => User::ROLE_COMPLIANCE_OFFICER]);

        $response = $this->actingAs($officer)->get(route('admin.pending'));

        $response->assertOk()
            // New wrapper fills height and is theme-responsive.
            ->assertSee('flex-1', false)
            ->assertSee('dark:bg-cu-bg', false)
            // Old brittle wrapper is gone.
            ->assertDontSee('min-h-full bg-cu-bg', false);
    }

    public function test_no_view_uses_the_old_collapsing_wrappers(): void
    {
        $offenders = [];
        $needles = [
            '-m-6 min-h-full bg-cu-bg',
            '-m-6 min-h-svh bg-zinc-50',
        ];

        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($dir as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            foreach ($needles as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'Views still using the old collapsing wrapper: '.implode(', ', $offenders));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PageWrapperTest`
Expected: FAIL — `<x-page>` does not exist and 19 views still use the old wrappers.

- [ ] **Step 3: Create `resources/views/components/page.blade.php`**

```blade
{{-- Themed, viewport-filling page wrapper. As a flex-1 child of the flex-column
     <flux:main> (min-h-svh), it always fills the viewport so the background never
     collapses to content height when a search/filter returns few or no rows.
     The -m-6/lg:-m-8 bleed + matching padding paints edge-to-edge under the
     layout's own padding. --}}
<div {{ $attributes->class('-m-6 flex min-h-full flex-1 flex-col bg-white p-6 text-zinc-900 lg:-m-8 lg:p-8 dark:bg-cu-bg dark:text-cu-text') }}>
    {{ $slot }}
</div>
```

- [ ] **Step 4: Make `flux:main` a flex column in `resources/views/components/layouts/app.blade.php`**

Replace line 2:

```blade
    <flux:main class="min-h-svh bg-white text-zinc-950 dark:bg-cu-bg dark:text-cu-text">
```

with:

```blade
    <flux:main class="flex min-h-svh flex-col bg-white text-zinc-950 dark:bg-cu-bg dark:text-cu-text">
```

- [ ] **Step 5: Swap the wrapper in all 19 views (inner markup unchanged)**

In each file below, replace the **opening** wrapper `<div class="-m-6 min-h-full bg-cu-bg p-6 text-cu-text lg:-m-8 lg:p-8">` (admin) or `<div class="-m-6 min-h-svh bg-zinc-50 p-6 text-zinc-950 lg:-m-8 lg:p-8">` (vendor) with `<x-page>`, and replace its matching **closing** `</div>` with `</x-page>`. Leave everything inside untouched (this task is structural only; the inner literals are converted in Phase B).

Admin (open tag = `-m-6 min-h-full bg-cu-bg p-6 text-cu-text lg:-m-8 lg:p-8`):
- `resources/views/livewire/admin/dashboard.blade.php`
- `resources/views/livewire/admin/pending.blade.php`
- `resources/views/livewire/admin/archived.blade.php`
- `resources/views/livewire/admin/risk-logs.blade.php`
- `resources/views/livewire/admin/notifications.blade.php`
- `resources/views/livewire/admin/settings/index.blade.php`
- `resources/views/livewire/admin/vendors/index.blade.php`
- `resources/views/livewire/admin/vendors/show.blade.php`
- `resources/views/livewire/admin/submissions/show.blade.php`
- `resources/views/livewire/admin/users/index.blade.php`
- `resources/views/livewire/admin/users/create.blade.php`
- `resources/views/livewire/admin/users/edit.blade.php`
- `resources/views/livewire/admin/audit/index.blade.php`
- `resources/views/admin/audit/show.blade.php` (this file indents the wrapper inside an `<x-layouts.app>` — match its exact indentation when swapping)

Vendor (open tag = `-m-6 min-h-svh bg-zinc-50 p-6 text-zinc-950 lg:-m-8 lg:p-8`):
- `resources/views/livewire/vendor/dashboard.blade.php`
- `resources/views/livewire/vendor/submit.blade.php`
- `resources/views/livewire/vendor/submissions.blade.php`
- `resources/views/livewire/vendor/notifications.blade.php`
- `resources/views/livewire/vendor/profile.blade.php`

> Each file has exactly one such wrapper as its content root; its matching close is the file's last `</div>` (for Volt views) or the `</div>` before `</x-layouts.app>` (for `admin/audit/show.blade.php`). Verify the open/close pair by reading the file before editing.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=PageWrapperTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Manual visual check of the search-bar fix**

Run `npm run build`, then as a compliance officer visit `/admin/pending`, type a search term that returns 0 rows, and confirm the page background fills the full viewport (no short colored panel with a different background below it). Repeat with the theme set to Light via Settings → Preference.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/page.blade.php resources/views/components/layouts/app.blade.php resources/views/livewire resources/views/admin/audit/show.blade.php tests/Feature/Theme/PageWrapperTest.php
git commit -m "fix(ui): fill page background via <x-page> flex wrapper, fixing search-bar collapse"
```

---

## Phase B — Per-view light/dark sweep (apply the Class-Mapping Reference)

After Phase A, dark mode is unchanged and the page background + shell already flip. Phase B converts the **inner** neutral literals so cards, text, and borders flip too. Every task applies the **Theme Class-Mapping Reference** at the top of this plan and honors the keep-list.

> Procedure for each file in a Phase B task: (1) read the file; (2) replace each neutral literal per the mapping table; (3) leave brand/status colors and gradient-overlay whites untouched; (4) run the task's test. Because the mapping is a fixed find→replace table, the conversions are deterministic — there is no per-file judgement beyond the documented keep-list.

### Task 5: Shared display components

**Files (Modify):**
- `resources/views/components/kpi-card.blade.php`
- `resources/views/components/risk-badge.blade.php`
- `resources/views/components/risk-gauge.blade.php`
- `resources/views/components/signature-compare.blade.php`
- `resources/views/components/stamp-compare.blade.php`
- `resources/views/components/stamp-mark.blade.php`
- `resources/views/components/pass-fail.blade.php`
- `resources/views/components/activity-icon.blade.php`
- `resources/views/components/vendor-status-badge.blade.php`
- Test: `tests/Feature/Theme/ThemeComponentSweepTest.php`

**Interfaces:**
- Consumes: the flipping `cu-*` tokens (Task 1).
- Produces: shared components whose neutral surfaces/text/borders are token-based and whose status text tints carry a `dark:` variant; brand/accent colors preserved. These components render inside the pages converted in Tasks 6–9.

> This is a self-contained task: its test scans only these 9 component files, so it goes RED before and GREEN after this task's edits (it does not depend on later tasks).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Theme;

use Tests\TestCase;

class ThemeComponentSweepTest extends TestCase
{
    /**
     * Shared display components must be theme-responsive. Verified by scanning
     * source for the post-conversion classes (no JS/visual test runner exists;
     * see the plan's Global Constraints). Each needle is absent before the
     * conversion and present after it.
     */
    public function test_shared_components_are_theme_responsive(): void
    {
        $expectations = [
            'kpi-card' => ['text-cu-text', 'border-cu-border'],
            'risk-badge' => ['dark:text-rose-300'],
            'signature-compare' => ['border-cu-border', 'text-cu-text', 'dark:bg-white/[0.03]'],
            'stamp-compare' => ['border-cu-border', 'text-cu-text', 'dark:bg-white/[0.03]'],
            'pass-fail' => ['dark:text-emerald-300'],
            'activity-icon' => ['bg-black/5', 'dark:text-sky-300'],
            'risk-gauge' => ['dark:text-white/10', 'dark:text-rose-300'],
            'vendor-status-badge' => ['border-cu-border', 'dark:text-emerald-300'],
        ];

        foreach ($expectations as $name => $needles) {
            $contents = (string) file_get_contents(resource_path("views/components/{$name}.blade.php"));
            foreach ($needles as $needle) {
                $this->assertStringContainsString(
                    $needle,
                    $contents,
                    "{$name}.blade.php is missing theme-responsive class '{$needle}'",
                );
            }
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ThemeComponentSweepTest`
Expected: FAIL — e.g. `kpi-card.blade.php is missing theme-responsive class 'text-cu-text'` (it currently uses `text-white`).

- [ ] **Step 3: Apply the exact edits**

These are the complete conversions for each component (`stamp-mark.blade.php` is pure SVG using `currentColor` and needs **no change**):

**`kpi-card.blade.php`** — line 20 and line 24:

```blade
{{-- line 20: border-white/5 → border-cu-border, hover:border-white/10 → hover:border-cu-border --}}
<div {{ $attributes->merge(['class' => 'relative overflow-hidden rounded-2xl border border-cu-border bg-cu-surface p-5 transition hover:border-cu-border']) }}>
```
```blade
{{-- line 24: text-white → text-cu-text --}}
            <p class="mt-2 text-3xl font-semibold tracking-tight text-cu-text">{{ $value }}</p>
```

**`risk-badge.blade.php`** — the `$map` tints (lines 9-11):

```php
        'high' => 'bg-rose-500/15 text-rose-700 ring-rose-500/30 dark:text-rose-300',
        'medium' => 'bg-amber-400/15 text-amber-700 ring-amber-400/30 dark:text-amber-300',
        'low' => 'bg-emerald-500/15 text-emerald-700 ring-emerald-500/30 dark:text-emerald-300',
```

**`signature-compare.blade.php`:**
- line 7 `$queryInk`: `text-emerald-300`/`text-rose-300` → `text-emerald-700 dark:text-emerald-300` / `text-rose-700 dark:text-rose-300`
- line 12 amber icon: `text-amber-300` → `text-amber-700 dark:text-amber-300`
- line 13: `text-zinc-200` → `text-cu-text`
- line 19: `border border-white/10` → `border border-cu-border`
- line 24: `bg-white/[0.03] text-zinc-300` → `bg-black/[0.03] text-cu-muted dark:bg-white/[0.03]`
- line 37: `bg-white/[0.03] {{ $queryInk }}` → `bg-black/[0.03] dark:bg-white/[0.03] {{ $queryInk }}`
- lines 47, 51, 55 (3×): `rounded-lg bg-white/[0.03] px-3 py-2` → `rounded-lg bg-black/[0.03] px-3 py-2 dark:bg-white/[0.03]`
- lines 53, 57: `text-lg font-semibold text-white` → `text-lg font-semibold text-cu-text`

**`stamp-compare.blade.php`:**
- line 7 `$queryInk`: same split as signature-compare
- line 12 amber icon: `text-amber-300` → `text-amber-700 dark:text-amber-300`
- line 13: `text-zinc-200` → `text-cu-text`
- line 19: `border border-white/10` → `border border-cu-border`
- line 24: `bg-white/[0.03] text-cu-blue` → `bg-black/[0.03] dark:bg-white/[0.03] text-cu-blue`
- line 35: `bg-white/[0.03] {{ $queryInk }}` → `bg-black/[0.03] dark:bg-white/[0.03] {{ $queryInk }}`
- lines 43, 47, 51 (3×): `rounded-lg bg-white/[0.03] px-3 py-2` → `rounded-lg bg-black/[0.03] px-3 py-2 dark:bg-white/[0.03]`
- lines 49, 53: `text-lg font-semibold text-white` → `text-lg font-semibold text-cu-text`

**`pass-fail.blade.php`** — lines 7 and 12:

```blade
{{-- line 7 --}}
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300']) }}>
```
```blade
{{-- line 12 --}}
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full bg-rose-500/15 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:text-rose-300']) }}>
```

**`activity-icon.blade.php`** — the `$colors` map (lines 9-13):

```php
        'rose' => 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
        'amber' => 'bg-amber-400/15 text-amber-700 dark:text-amber-300',
        'sky' => 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
        'emerald' => 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
        'zinc' => 'bg-black/5 text-cu-muted dark:bg-white/5',
```

**`risk-gauge.blade.php`:**
- line 11 `$textClass`: `text-rose-300`/`text-amber-300`/`text-emerald-300` → `text-rose-700 dark:text-rose-300` / `text-amber-700 dark:text-amber-300` / `text-emerald-700 dark:text-emerald-300` (both the map entries and the `?? 'text-emerald-700 dark:text-emerald-300'` default)
- line 16: track circle `class="text-white/10"` → `class="text-black/10 dark:text-white/10"`

**`vendor-status-badge.blade.php`** — the `$classes` match (lines 5-9):

```php
        'Processing' => 'border-cu-blue/30 bg-cu-blue/10 text-sky-700 dark:text-sky-300',
        'Pending Review' => 'border-cu-yellow/60 bg-cu-yellow/20 text-yellow-700 dark:text-yellow-300',
        'Approved' => 'border-emerald-500/40 bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
        'Rejected' => 'border-rose-500/40 bg-rose-500/15 text-rose-700 dark:text-rose-300',
        default => 'border-cu-border bg-black/5 text-cu-text dark:bg-white/5',
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ThemeComponentSweepTest`
Expected: PASS (1 test).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components tests/Feature/Theme/ThemeComponentSweepTest.php
git commit -m "feat(theme): make shared display components theme-responsive"
```

---

### Task 6: Admin dashboard, pending & archived

**Files (Modify):**
- `resources/views/livewire/admin/dashboard.blade.php`
- `resources/views/livewire/admin/pending.blade.php`
- `resources/views/livewire/admin/archived.blade.php`
- Create: `tests/Feature/Theme/ContentThemeSweepTest.php`

**Interfaces:** Consumes flipping tokens + `<x-page>`. Produces three theme-responsive officer pages and the reusable `ContentThemeSweepTest::assertViewConverted()` helper that Tasks 7–9 extend.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Theme;

use Tests\TestCase;

class ContentThemeSweepTest extends TestCase
{
    /**
     * A content view is "converted" when it no longer carries theme-locked
     * neutral literals (these have no surviving form after the mapping — unlike
     * the `dark:…white/x` overlays, which are intentionally kept) and shows at
     * least one semantic cu-* token. Verified by scanning source; no JS/visual
     * runner exists (see the plan's Global Constraints).
     */
    private function assertViewConverted(string $relative): void
    {
        $contents = (string) file_get_contents(resource_path("views/{$relative}"));

        $banned = [
            'border-white/5', 'border-white/10', 'divide-white/5', 'divide-white/10',
            'text-zinc-950', 'text-zinc-900', 'text-zinc-700',
            'text-zinc-500', 'text-zinc-400', 'text-zinc-300', 'text-zinc-200',
            'border-zinc-200', 'border-zinc-100', 'bg-zinc-50',
        ];

        foreach ($banned as $literal) {
            $this->assertStringNotContainsString($literal, $contents, "{$relative} still uses theme-locked '{$literal}'");
        }

        $this->assertTrue(
            str_contains($contents, 'border-cu-border')
                || str_contains($contents, 'text-cu-text')
                || str_contains($contents, 'bg-cu-surface'),
            "{$relative} shows no sign of semantic-token conversion",
        );
    }

    public function test_dashboard_pending_archived_converted(): void
    {
        $this->assertViewConverted('livewire/admin/dashboard.blade.php');
        $this->assertViewConverted('livewire/admin/pending.blade.php');
        $this->assertViewConverted('livewire/admin/archived.blade.php');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ContentThemeSweepTest`
Expected: FAIL — e.g. `livewire/admin/pending.blade.php still uses theme-locked 'border-white/5'`.

- [ ] **Step 3: Apply the mapping to `dashboard.blade.php`**

Apply the Class-Mapping Reference. Specific conversions in this file:
- Table/heading/value `text-white` (e.g. the "Welcome back" heading is on the gradient hero — **keep** its `text-white`; the KPI/section headings and activity text **outside** the gradient → `text-cu-text`).
- `border-white/5`, `border-white/10` → `border-cu-border`; `divide-white/5` → `divide-cu-border`.
- `bg-white/5` chips not on the gradient → `bg-black/5 dark:bg-white/5`.
- `hover:bg-white/[0.03]` → `hover:bg-black/[0.03] dark:hover:bg-white/[0.03]`.
- **Keep** the entire `cu-gradient` hero block's inner `text-white`, `text-white/80`, `bg-white/15`, `bg-white/20`, `ring-white/30`, and the `bg-white … text-cu-purple` "Review queue" button (white button on gradient is intentional in both themes).

- [ ] **Step 4: Apply the mapping to `pending.blade.php`**

Specific conversions in this file (see lines 47-160 as read):
- `text-white` on the `<h1>` (line 58), the table cell company name (line 110), and the "Review" link (line 133) → `text-cu-text`.
- `border-white/5` (filter bar line 70, table container line 92, thead border line 96) → `border-cu-border`; `divide-white/5` (line 105) → `divide-cu-border`.
- `bg-white/5` (status pill line 61) → `bg-black/5 dark:bg-white/5`.
- `bg-cu-bg` on the search `<input>` (line 77) and the segmented control container (line 80) → `bg-black/5 dark:bg-white/5` (so the input/segmented control reads as a subtle inset in both themes; `bg-cu-bg` would match the page and disappear). The input's `text-white` → `text-cu-text`; `placeholder:text-cu-muted` stays.
- `hover:bg-white/[0.03]` (row, line 107) → `hover:bg-black/[0.03] dark:hover:bg-white/[0.03]`.
- **Keep:** `bg-cu-purple text-white` active filter button (line 85), `text-cu-purple`, `text-rose-400`, `text-emerald-400`, `text-cu-muted*` (token flips).

- [ ] **Step 5: Apply the mapping to `archived.blade.php`**

Apply the same conversions as `pending.blade.php` for its equivalent header/filter/table/search-input markup (this page has the same search-bar + filter layout). Convert `text-white` (non-gradient), `border-white/{5,10}`, `divide-white/5`, the search input's `bg-cu-bg`→`bg-black/5 dark:bg-white/5` and `text-white`→`text-cu-text`, and the row `hover:bg-white/[0.03]`. Keep brand/status colors.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=ContentThemeSweepTest`
Expected: PASS (`test_dashboard_pending_archived_converted`). If it still fails, the message names the file and the literal still present — fix that occurrence.

- [ ] **Step 7: Manual visual check** — visit `/admin/dashboard`, `/admin/pending`, `/admin/archived` in both Light and Dark; confirm headings/tables/search inputs are readable in both, and the gradient hero is unchanged.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/livewire/admin/dashboard.blade.php resources/views/livewire/admin/pending.blade.php resources/views/livewire/admin/archived.blade.php tests/Feature/Theme/ContentThemeSweepTest.php
git commit -m "feat(theme): theme officer dashboard, pending and archived pages"
```

---

### Task 7: Admin vendors, risk-logs, notifications & submission detail

**Files (Modify):**
- `resources/views/livewire/admin/vendors/index.blade.php`
- `resources/views/livewire/admin/vendors/show.blade.php`
- `resources/views/livewire/admin/risk-logs.blade.php`
- `resources/views/livewire/admin/notifications.blade.php`
- `resources/views/livewire/admin/submissions/show.blade.php`
- Test: extend `tests/Feature/Theme/ContentThemeSweepTest.php` with the method below

**Interfaces:** Consumes flipping tokens + `<x-page>` + the `assertViewConverted()` helper from Task 6. Produces five theme-responsive officer pages. `submissions/show.blade.php` is the largest (32 hardcoded literals) and embeds the shared compare components from Task 5.

- [ ] **Step 1: Add a failing test method to `ContentThemeSweepTest`**

```php
    public function test_vendors_risklogs_notifications_submission_converted(): void
    {
        $this->assertViewConverted('livewire/admin/vendors/index.blade.php');
        $this->assertViewConverted('livewire/admin/vendors/show.blade.php');
        $this->assertViewConverted('livewire/admin/risk-logs.blade.php');
        $this->assertViewConverted('livewire/admin/notifications.blade.php');
        $this->assertViewConverted('livewire/admin/submissions/show.blade.php');
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=test_vendors_risklogs_notifications_submission_converted`
Expected: FAIL — the named file still carries a theme-locked literal (e.g. `border-white/5`).

- [ ] **Step 3: Apply the mapping to all five files**

For each file apply the Class-Mapping Reference: `text-white`(non-accent)→`text-cu-text`; `border-white/{5,10}`→`border-cu-border`; `divide-white/{5,10}`→`divide-cu-border`; `bg-white/5`(subtle)→`bg-black/5 dark:bg-white/5`; `bg-white/[0.03]`→`bg-black/[0.03] dark:bg-white/[0.03]`; any search `<input>`/segmented control using `bg-cu-bg`→`bg-black/5 dark:bg-white/5` and its `text-white`→`text-cu-text`; `hover:bg-white/[0.03]`→`hover:bg-black/[0.03] dark:hover:bg-white/[0.03]`; status text tints `text-{c}-300`/`text-{c}-200`→`text-{c}-700 dark:text-{c}-{300,200}` (rule C). Keep gradient-hero whites, `bg-cu-purple text-white` buttons, translucent accent backgrounds/dots, and `-400` icon tints.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ContentThemeSweepTest`
Expected: PASS (both Task 6 and Task 7 methods green).

- [ ] **Step 5: Manual visual check** of `/admin/vendors`, a vendor detail page, `/admin/risk-logs`, `/admin/notifications`, and a submission detail page in both themes.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/livewire/admin/vendors resources/views/livewire/admin/risk-logs.blade.php resources/views/livewire/admin/notifications.blade.php resources/views/livewire/admin/submissions/show.blade.php tests/Feature/Theme/ContentThemeSweepTest.php
git commit -m "feat(theme): theme vendor profiles, risk logs, notifications and submission detail"
```

---

### Task 8: Admin settings, user management & audit

**Files (Modify):**
- `resources/views/livewire/admin/settings/index.blade.php`
- `resources/views/livewire/admin/users/index.blade.php`
- `resources/views/livewire/admin/users/create.blade.php`
- `resources/views/livewire/admin/users/edit.blade.php`
- `resources/views/livewire/admin/audit/index.blade.php`
- `resources/views/admin/audit/show.blade.php`
- Test: extend `tests/Feature/Theme/ContentThemeSweepTest.php` with the method below

**Interfaces:** Consumes flipping tokens + `<x-page>` + the `assertViewConverted()` helper from Task 6. Produces six theme-responsive admin pages. (Some `users/*` and `audit/show` are controller-rendered Blade, not Volt — the mapping is identical.)

- [ ] **Step 1: Add a failing test method to `ContentThemeSweepTest`**

```php
    public function test_settings_users_audit_converted(): void
    {
        $this->assertViewConverted('livewire/admin/settings/index.blade.php');
        $this->assertViewConverted('livewire/admin/users/index.blade.php');
        $this->assertViewConverted('livewire/admin/users/create.blade.php');
        $this->assertViewConverted('livewire/admin/users/edit.blade.php');
        $this->assertViewConverted('livewire/admin/audit/index.blade.php');
        $this->assertViewConverted('admin/audit/show.blade.php');
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=test_settings_users_audit_converted`
Expected: FAIL — the named file still carries a theme-locked literal.

- [ ] **Step 3: Apply the mapping to all six files**

Apply the Class-Mapping Reference to each (same neutral + rule-C conversions as Tasks 6–7). The `users/*` forms and the audit views use the same `text-white` / `border-white/{5,10}` / `bg-white/5` vocabulary; convert per table. Keep status colors (audit action `-400` tints, role badges) and any `bg-cu-purple text-white` actions.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ContentThemeSweepTest`
Expected: PASS (Tasks 6–8 methods green).

- [ ] **Step 5: Manual visual check** of `/admin/settings`, `/admin/users`, the create/edit user forms, `/admin/audit`, and an audit detail page in both themes.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/livewire/admin/settings resources/views/livewire/admin/users resources/views/livewire/admin/audit resources/views/admin/audit/show.blade.php tests/Feature/Theme/ContentThemeSweepTest.php
git commit -m "feat(theme): theme admin settings, user management and audit pages"
```

---

### Task 9: Vendor portal pages

**Files (Modify):**
- `resources/views/livewire/vendor/dashboard.blade.php`
- `resources/views/livewire/vendor/submit.blade.php`
- `resources/views/livewire/vendor/submissions.blade.php`
- `resources/views/livewire/vendor/notifications.blade.php`
- `resources/views/livewire/vendor/profile.blade.php`
- Test: extend `tests/Feature/Theme/ContentThemeSweepTest.php` with the method below

**Interfaces:** Consumes flipping tokens + `<x-page>` + the `assertViewConverted()` helper from Task 6. Produces five theme-responsive vendor pages. These are **light-authored**, so they use the light→semantic half of the mapping (`bg-white`→`bg-cu-surface`, `text-zinc-950`→`text-cu-text`, `text-zinc-500`→`text-cu-muted`, `border-zinc-200`→`border-cu-border`, inner `bg-zinc-50`→`bg-black/5 dark:bg-white/5`).

- [ ] **Step 1: Add a failing test method to `ContentThemeSweepTest`**

```php
    public function test_vendor_pages_converted(): void
    {
        $this->assertViewConverted('livewire/vendor/dashboard.blade.php');
        $this->assertViewConverted('livewire/vendor/submit.blade.php');
        $this->assertViewConverted('livewire/vendor/submissions.blade.php');
        $this->assertViewConverted('livewire/vendor/notifications.blade.php');
        $this->assertViewConverted('livewire/vendor/profile.blade.php');
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=test_vendor_pages_converted`
Expected: FAIL — the named file still carries a theme-locked literal (e.g. `text-zinc-950`).

- [ ] **Step 3: Apply the mapping to all five files**

For each file apply the light-authored half of the Class-Mapping Reference. Specific conversions (confirmed in `submissions.blade.php` lines 41-60, representative of the set):
- Card panel `bg-white` → `bg-cu-surface`.
- Heading `text-zinc-950` / `text-zinc-900` → `text-cu-text`.
- Sub-text `text-zinc-500` / `text-zinc-400` → `text-cu-muted`.
- `border-zinc-200` / `border-zinc-100` → `border-cu-border`.
- Inner segmented-control / chip `bg-zinc-50` → `bg-black/5 dark:bg-white/5`.
- Status text tints per rule C; keep `shadow-sm`, `cu-gradient` buttons (`cu-gradient … text-white` "New submission" stays), and all brand colors. (The `<x-vendor-status-badge>` itself was converted in Task 5 — don't touch its invocations.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ContentThemeSweepTest`
Expected: PASS (all Phase B methods green).

- [ ] **Step 5: Manual visual check** — as a vendor, set the theme to Dark in Settings → Preference and confirm the dashboard, submit, submissions (with search), notifications and profile pages all render dark consistently; switch to Light and confirm they revert. Confirm the choice survives a hard refresh (7-day cookie) and applies on the very first paint (no flash).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/livewire/vendor tests/Feature/Theme/ContentThemeSweepTest.php
git commit -m "feat(theme): make vendor portal pages theme-responsive"
```

---

### Task 10: Repo-wide guard + full-suite regression pass

**Files:**
- Create: `tests/Feature/Theme/ViewThemeGuardTest.php`

**Interfaces:** A catch-all guard asserting no content view (admin + vendor livewire, controller-rendered admin views, shared display components) retains a no-survivor neutral literal. Goes green only after Tasks 5–9.

- [ ] **Step 1: Write the repo-wide guard test**

```php
<?php

namespace Tests\Feature\Theme;

use Tests\TestCase;

class ViewThemeGuardTest extends TestCase
{
    /**
     * No content view may keep a theme-locked neutral literal that has no
     * surviving form after the mapping (zinc shades, solid white borders/
     * dividers). The `dark:…white/x` overlays are intentionally retained and
     * are NOT scanned. Source scan — no JS/visual runner (Global Constraints).
     *
     * Excluded: the `<x-page>` wrapper and the app/auth layout shells, which
     * deliberately pair a light literal (e.g. `text-zinc-900`/`bg-white`) with
     * a `dark:` token — that IS theme-responsive and must not be flagged.
     */
    public function test_no_content_view_keeps_theme_locked_neutral_literals(): void
    {
        $roots = [
            resource_path('views/livewire/admin'),
            resource_path('views/livewire/vendor'),
            resource_path('views/admin'),
            resource_path('views/components'),
        ];

        $banned = [
            'border-white/5', 'border-white/10', 'divide-white/5', 'divide-white/10',
            'text-zinc-950', 'text-zinc-900', 'text-zinc-700',
            'text-zinc-500', 'text-zinc-400', 'text-zinc-300', 'text-zinc-200',
            'border-zinc-200', 'border-zinc-100', 'bg-zinc-50',
        ];

        // The deliberate light/dark-pair shells carry light literals on purpose.
        $isExcluded = static fn (string $path): bool => str_contains(
            str_replace('\\', '/', $path),
            '/views/components/layouts/',
        ) || str_ends_with(str_replace('\\', '/', $path), '/views/components/page.blade.php');

        $offenders = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php' || $isExcluded($file->getPathname())) {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                foreach ($banned as $literal) {
                    // Precise token match: `(?![0-9])` stops `bg-zinc-50` from
                    // matching `bg-zinc-500`, `border-white/5` from `border-white/50`, etc.
                    if (preg_match('/'.preg_quote($literal, '/').'(?![0-9])/', $contents) === 1) {
                        $offenders[] = $file->getPathname().' :: '.$literal;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Theme-locked literals remain:\n".implode("\n", $offenders));
    }
}
```

- [ ] **Step 2: Run the guard**

Run: `php artisan test --compact --filter=ViewThemeGuardTest`
Expected: PASS. If it lists offenders, convert each named `file :: literal` per the mapping (or, if it's a deliberate light/dark pair in a shell, confirm the exclusion covers it), then re-run.

- [ ] **Step 3: Run the whole theme suite**

Run: `php artisan test --compact --filter=Theme`
Expected: PASS (ThemeTokensTest, ThemeBootstrapTest, PageWrapperTest, ThemeComponentSweepTest, ContentThemeSweepTest, ViewThemeGuardTest).

- [ ] **Step 4: Run the full test suite**

Run: `php artisan test --compact`
Expected: PASS — no regressions in Auth/Settings/Dashboard/Admin tests. (If `AuditTrailTest` or `AuditLogFactory` — already modified in the working tree before this plan — interact with `audit/show.blade.php`, confirm those tests still pass after the Task 8 markup swap.)

- [ ] **Step 5: Production build sanity**

Run: `npm run build`
Expected: builds with no errors; `bg-cu-*`, `text-cu-*`, `border-cu-border`, and `bg-black/5` utilities are present in the compiled CSS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Feature/Theme/ViewThemeGuardTest.php
git commit -m "test(theme): repo-wide guard against theme-locked neutral literals"
```

> Do not `git add -A` — the working tree carries unrelated pre-existing edits (`database/factories/AuditLogFactory.php`, `tests/Feature/Admin/AuditTrailTest.php`). Stage only this task's files.

---

## Self-Review

**1. Spec coverage:**
- "fix preference theme on all user type / consistent throughout tab" → Tasks 1 (tokens flip), 4 (shell+page wrapper), 5–9 (every admin + vendor view converted). ✅ Covered for vendor, compliance_officer, admin.
- "store user preference in a cache or cookies with expiry of 7 days" → Task 2 (`theme` cookie, `max-age=604800`, client-only) + Task 3 (toggle writes it). ✅
- "UI breaking on tabs that has search bar … background fills the whole background … stretches depending on filter result" → Task 4 (`<x-page>` flex-1 in flex-column `flux:main`, replacing the collapsing `min-h-full`), with explicit empty-result manual checks in Tasks 4, 6, 9. ✅

**2. Placeholder scan:** Infrastructure tasks (1–4) and the shared component logic carry complete, exact code. Phase B tasks reference the single canonical **Theme Class-Mapping Reference** (a fixed find→replace table, not "add appropriate classes") plus per-file enumerations of the literals that occur in each file; the keep-list removes ambiguity. No "TBD/handle edge cases/similar to Task N" placeholders.

**3. Type/name consistency:**
- `window.advsTheme` with `read()`/`set()`/`apply()` — defined in Task 2, consumed identically in Task 3's `<x-theme-toggle>`.
- Token names `--color-cu-bg/-surface/-text/-muted/-border` — defined in Task 1, used as `bg-cu-bg`/`bg-cu-surface`/`text-cu-text`/`text-cu-muted`/`border-cu-border` throughout Phase B and `<x-page>`.
- `<x-page>` (Task 4) and `<x-theme-toggle>` (Task 3) — component file names (`page.blade.php`, `theme-toggle.blade.php`) match their `<x-…>` invocations.
- Cookie name `theme` and `max-age=604800` are identical across the head runtime (Task 2) and the `ThemeBootstrapTest` assertions.

**Known limitation (stated honestly):** No JS test runner exists, so the cookie-write behavior and `.dark` toggling are not unit-tested in a DOM; they are guarded by asserting the rendered `<head>` runtime markup and the toggle wiring, plus the documented manual visual checks. Visual/CSS correctness of each converted page is guarded by markup assertions (semantic tokens present, old literals absent) and explicit manual light/dark walkthroughs — not pixel snapshots.


---

## File: docs/superpowers/plans/2026-06-28-business-permit-classifier-dataset.md

# Business Permit Classifier Dataset Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate 100 synthetic City Business Permit documents (each as a clean "digital" PNG + an Augraphy-degraded "scan" JPG = 200 files) into the ResNet-50 classifier's canonical `business_permit` class folder, after correcting the generator's stale output target.

**Architecture:** The generator `python/scripts/business_permit_dataset_generator.py` is already fully implemented and unit-tested — it fills the blank Digos permit template, composites the City seal + two officer signatures, and emits clean + scan variants with a content-hash dedup manifest. The only defect is its default `OUTPUT_DIR`, which points at a **phantom** `business_registration` class folder that exists in neither the DB taxonomy (`DocumentTypeSeeder`) nor on disk. The real, canonical class folder (`business_permit`, currently empty) is what the classifier and `DocumentTypeSeeder` use. Task 1 repoints the generator (code + test, committed); Task 2 runs the batch to populate the folder (local, gitignored artifacts).

**Tech Stack:** Python 3.12 (the repo's ML venv), Pillow, Faker (`en_PH`), Augraphy (via the reused `bir_dataset_generator` helpers), pytest.

## Global Constraints

- **Interpreter:** ALWAYS use the repo's ML venv interpreter `python/env/Scripts/python.exe`. Bare `python` is MSYS2 (no wheels) and will fail; `py` is 3.14 (too new for the stack). Run all commands from the project root `c:\xampp\htdocs\projects\advs`.
- **Canonical class folder:** output target is `python/data/training/classifier_data/business_permit` — matches `DocumentTypeSeeder` (`code => 'business_permit'`) and the existing on-disk class folders (`bir_certificate`, `business_permit`, `fake`, `financial_statement`). There is NO `business_registration` class.
- **Batch size:** 100 base permits → 200 files (100 `*_clean.png` digital + 100 `*_scan.jpg` scan-like). This is the script's `--count 100` default; do not pass `--clean-only`/`--scan-only`.
- **Generated data is gitignored.** `.gitignore` lines 40–45 ignore everything under `python/data/training/**` except the directory scaffold + `.gitkeep`. The 200 images + `_synthetic_manifest.json` are LOCAL artifacts — do NOT attempt to `git add` them. Only the Task 1 source-code change is committed.
- **Do not duplicate machinery.** The field-agnostic helpers (text fitting, asset compositing, Augraphy degrade, manifest atomic-write) are imported from the sibling `bir_dataset_generator`; do not reimplement them here.
- **ASCII-only logging** (Windows cp1252 console safe) — preserve the existing `[permit-gen] ...` log style.
- **Current branch is `staging`** (not the `main` default), so commit directly on `staging`; no new branch required.

---

## File Structure

- `python/scripts/business_permit_dataset_generator.py` — **Modify.** Repoint `OUTPUT_DIR` (line 54) + the docstring (line 8) from the phantom `business_registration` to the canonical `business_permit`. No behavioral/logic change.
- `python/tests/test_business_permit_dataset_generator.py` — **Modify.** Add one regression test pinning `OUTPUT_DIR` to the canonical class folder; rename the three cosmetic `tmp_path` subfolders from `business_registration` → `business_permit` so the test file is self-consistent.
- `python/data/training/classifier_data/business_permit/` — **Populate (local, gitignored).** Receives 200 images + `_synthetic_manifest.json`.

---

### Task 1: Repoint the generator to the canonical `business_permit` class folder

**Files:**
- Modify: `python/scripts/business_permit_dataset_generator.py` (docstring line 8; `OUTPUT_DIR` line 54)
- Test: `python/tests/test_business_permit_dataset_generator.py` (add one test; rename 3 tmp folders)

**Interfaces:**
- Consumes: nothing (first task).
- Produces: `business_permit_dataset_generator.OUTPUT_DIR` (a `pathlib.Path`) now resolves to `.../python/data/training/classifier_data/business_permit`. Task 2's batch run relies on this corrected default.

- [ ] **Step 1: Write the failing regression test**

In `python/tests/test_business_permit_dataset_generator.py`, add this function immediately after `test_load_field_boxes_reads_the_exported_json` (right before the `# ----- field values ---` section comment):

```python
def test_output_dir_targets_the_canonical_business_permit_class_folder():
    # The classifier reads the class label from the folder name. The canonical
    # code in DocumentTypeSeeder is `business_permit` (LGU business permit); there
    # is no `business_registration` class on disk or in the DB taxonomy, so the
    # generator's default output must land in the real `business_permit` folder.
    assert gen.OUTPUT_DIR.name == "business_permit"
    assert gen.OUTPUT_DIR.parts[-4:] == ("data", "training", "classifier_data", "business_permit")
```

- [ ] **Step 2: Run the test to verify it fails**

Run:
```bash
python/env/Scripts/python.exe -m pytest "python/tests/test_business_permit_dataset_generator.py::test_output_dir_targets_the_canonical_business_permit_class_folder" -v
```
Expected: FAIL with `AssertionError: assert 'business_registration' == 'business_permit'` (the default still points at the phantom folder).

- [ ] **Step 3: Fix the `OUTPUT_DIR` constant**

In `python/scripts/business_permit_dataset_generator.py`, change the output target (line 54):

```python
OUTPUT_DIR = PY_ROOT / "data" / "training" / "classifier_data" / "business_permit"
```
(was `... / "classifier_data" / "business_registration"`)

- [ ] **Step 4: Fix the stale docstring reference**

In the same file's module docstring, change:

```python
ResNet-50 classifier's ``business_permit`` class folder. A per-folder JSON
```
(was `ResNet-50 classifier's ``business_registration`` class folder. A per-folder JSON`)

- [ ] **Step 5: Make the test file self-consistent (rename cosmetic tmp folders)**

Still in `python/tests/test_business_permit_dataset_generator.py`, replace **all three** occurrences of:

```python
    out = tmp_path / "business_registration"
```
with:
```python
    out = tmp_path / "business_permit"
```
(These are throwaway `tmp_path` subfolder names in `test_run_batch_writes_variants_and_manifest`, `test_second_run_appends_without_duplicates`, and `test_dry_run_writes_nothing` — cosmetic only, but renamed so no reference to the phantom class name remains.)

- [ ] **Step 6: Run the new test to verify it passes**

Run:
```bash
python/env/Scripts/python.exe -m pytest "python/tests/test_business_permit_dataset_generator.py::test_output_dir_targets_the_canonical_business_permit_class_folder" -v
```
Expected: PASS.

- [ ] **Step 7: Run the full generator test file to confirm no regressions**

Run:
```bash
python/env/Scripts/python.exe -m pytest "python/tests/test_business_permit_dataset_generator.py" -v
```
Expected: all tests PASS (the 13 existing tests + the 1 new = 14 passed).

- [ ] **Step 8: Commit**

```bash
git add python/scripts/business_permit_dataset_generator.py python/tests/test_business_permit_dataset_generator.py
git commit -m "$(cat <<'EOF'
fix(dataset): point business-permit generator at canonical business_permit class folder

The generator's default OUTPUT_DIR (and its docstring) pointed at a phantom
`business_registration` class that exists in neither DocumentTypeSeeder nor on
disk; the real classifier folder is `business_permit` (currently empty). Repoint
the default, add a regression test pinning OUTPUT_DIR, and drop the stale name
from the test file's tmp folders.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Generate and verify the 100-permit batch (200 files)

**Files:**
- Populate (local, gitignored): `python/data/training/classifier_data/business_permit/` — 100 `synthetic_permit_*_clean.png`, 100 `synthetic_permit_*_scan.jpg`, 1 `_synthetic_manifest.json`.

**Interfaces:**
- Consumes: the corrected `OUTPUT_DIR` default from Task 1; the script's existing `--count` / `--dry-run` CLI (`main()` in `business_permit_dataset_generator.py`).
- Produces: 200 verified classifier training images in the `business_permit` class folder + a dedup manifest with `next_index == 101`.

- [ ] **Step 1: Confirm the target folder is empty (clean baseline)**

Run:
```bash
find "python/data/training/classifier_data/business_permit" -type f
```
Expected: NO output (empty folder, no pre-existing `_synthetic_manifest.json`). This guarantees the run starts at index 1 and ends at `next_index=101`. (If files already exist, the run will safely APPEND and dedup; in that case expect cumulative counts and `next_index = prior + 100` instead.)

- [ ] **Step 2: Dry-run to validate assets, boxes, font, and one in-memory render**

Run:
```bash
python/env/Scripts/python.exe python/scripts/business_permit_dataset_generator.py --dry-run
```
Expected: exit code 0 with log lines like:
```
[permit-gen] DRY RUN ok - rendered 1 permit in memory at (1600, 1203), wrote nothing.
[permit-gen] fields: proprietor=... kind=... issued=... ...
```
(If instead you see `[permit-gen] ERROR: ...` for a missing template/logo/signature/font, STOP and resolve that asset before continuing.)

- [ ] **Step 3: Generate the full batch (100 base permits → 200 files)**

Run:
```bash
python/env/Scripts/python.exe python/scripts/business_permit_dataset_generator.py --count 100
```
Expected: exit code 0 with progress + summary logs:
```
[permit-gen] generated 25/100 permits
[permit-gen] generated 50/100 permits
[permit-gen] generated 75/100 permits
[permit-gen] generated 100/100 permits
[permit-gen] wrote 200 files (100 base permits) -> python/data/training/classifier_data/business_permit
[permit-gen] manifest -> python/data/training/classifier_data/business_permit/_synthetic_manifest.json (next_index=101)
```

- [ ] **Step 4: Verify file counts**

Run:
```bash
echo "clean PNGs:" $(find "python/data/training/classifier_data/business_permit" -name "*_clean.png" | wc -l)
echo "scan JPGs:"  $(find "python/data/training/classifier_data/business_permit" -name "*_scan.jpg"  | wc -l)
```
Expected:
```
clean PNGs: 100
scan JPGs: 100
```

- [ ] **Step 5: Verify manifest integrity, no duplicate content, and image validity**

Run:
```bash
python/env/Scripts/python.exe - <<'PY'
import json
from pathlib import Path
from PIL import Image

d = Path("python/data/training/classifier_data/business_permit")
m = json.loads((d / "_synthetic_manifest.json").read_text(encoding="utf-8"))

assert m["next_index"] == 101, f"next_index={m['next_index']} (expected 101)"
assert len(m["records"]) == 100, f"records={len(m['records'])} (expected 100)"
assert len(m["used_hashes"]) == len(set(m["used_hashes"])), "duplicate content hashes in manifest!"

cleans = sorted(d.glob("synthetic_permit_*_clean.png"))
scans = sorted(d.glob("synthetic_permit_*_scan.jpg"))
assert len(cleans) == 100 and len(scans) == 100, f"clean={len(cleans)} scan={len(scans)} (expected 100/100)"

# spot-check that first/last clean+scan images decode without corruption
for p in (cleans[0], cleans[-1], scans[0], scans[-1]):
    Image.open(p).verify()

print("OK: 100 clean + 100 scan, manifest next_index=101, 100 unique records, no dup hashes, sampled images valid")
PY
```
Expected final line:
```
OK: 100 clean + 100 scan, manifest next_index=101, 100 unique records, no dup hashes, sampled images valid
```

- [ ] **Step 6: Confirm the generated data is gitignored (nothing to commit)**

Run:
```bash
git status --short "python/data/training/classifier_data/business_permit/"
```
Expected: NO output — all 200 images + the manifest are ignored by `.gitignore` (lines 40–45). There is no Task 2 commit; the only version-controlled change in this plan was Task 1.

---

## Out of Scope / Follow-ups (not part of this plan)

These stale `business_registration` references exist elsewhere but are NOT required to deliver the 100 business-permit training images. Flagged for a future cleanup pass (each would need its own decision/sign-off):
- `python/scripts/train_classifier.py:9` (docstring example class list)
- `python/notebooks/01_resnet50_classifier.ipynb:55` (same docstring, copied)
- `python/.claude/skills/run-advs-training/make_fixtures.py:30` (smoke-test fixture class list)
- `python/data/README.md:20` and `AGENTS.md:149` (documentation)
- A matching **validation** set: `train_classifier.py` requires `python/data/validation/classifier_data/business_permit/` to be populated too before the classifier can train; generating that (e.g. a smaller `--count` into the validation folder via `--out-dir`) is a separate task.


10 samples first for approval


---

## File: docs/superpowers/plans/2026-07-01-vendor-registration-update.md

# Vendor Registration Update — Declared Profile & Cross-Check Reference Data Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capture the franchisee's declared owner/representative and business information at registration as a new gated step, store it as structured reference data (the data the system and officers later cross-check uploaded documents against), and display it read-only on the vendor's own profile.

**Architecture:** A new gated registration step (mirroring the existing signature-enrollment step) sits **between Account and Signature**: `Account → Business & Owner Details → Signature → Verify email`. A Volt form collects ~22 declared fields, a `CreateVendorProfile` action persists them across the `vendors` table (business profile) and a new `vendor_representatives` table (owner identity) inside one transaction, and a new `EnsureVendorProfileComplete` middleware gates vendors to that step until it is done. A single `users.vendor_profile_completed_at` timestamp is the gate signal (consistent with `signature_enrolled_at`).

**Tech Stack:** Laravel 12, Livewire 4 + Volt 1, Flux UI 2 (free), Tailwind v4, Fortify (auth), MySQL 8, PHPUnit 11. Format validation reuses the existing `App\Support\TinValidator` and `RegistrationNumberValidator`.

## Global Constraints

- **PHP 8.2** — curly braces on all control structures; constructor property promotion; explicit return types and param type hints; PHPDoc array shapes over inline comments.
- **Laravel 12 streamlined structure** — middleware registered in `bootstrap/app.php` (not a Kernel); model casts go in a `casts()` method, not a `$casts` property. When **modifying** a migration column, restate **all** prior attributes or they are dropped.
- **Create files with Artisan** — `php artisan make:migration|model|middleware|test|factory|class|volt … --no-interaction`. Do not hand-author files that have a generator.
- **UI is Flux-first** — `<flux:input>`, `<flux:select>`, `<flux:textarea>`, `<flux:button>`, etc. No inline `style=""`. Tailwind v4 utility classes only; no `tailwind.config.js`, no `@tailwindcss/forms`.
- **Tests are PHPUnit classes** (a Pest runner is configured but write PHPUnit). `RefreshDatabase`. **Run `php artisan config:clear` once before any test run** — a cached `bootstrap/cache/config.php` overrides phpunit's sqlite `:memory:` env and makes tests hit the down MySQL `advs` DB (project memory: *tests-need-config-clear*).
- **Format before every commit:** `vendor/bin/pint --dirty --format agent`.
- **Scope is vendor-only.** This plan captures + stores + displays declared data. The automated *declared-vs-extracted* cross-check engine (OCR field extraction → mismatch flags → risk score) is **explicitly out of scope** — it depends on OCR field extraction not yet in production. Officer-facing display of declared data is also deferred (see "Out of Scope" below).
- **No new dependencies.** Reuse existing packages and the existing `App\Support` validators.

## Out of Scope (do not build here)

- Automated comparison of declared values against OCR-extracted document values, and any mismatch flags feeding the risk score.
- Surfacing declared data on the **officer** vendor profile (`admin/vendors/{vendor}`). That page is currently backed entirely by `DemoStore::findVendor()` (session demo data); real registered vendors 404 there. Wiring real vendors into the officer UI is a separate demo-store refactor.
- Cooperative-specific (CDA) registration number capture — the spec's conditional logic only covers DTI (sole proprietorship) vs SEC (partnership/corporation). Cooperatives capture neither conditional number in this plan.

## Spec → Storage Coverage Map

| Declared field (spec) | Column | Table | Task |
|---|---|---|---|
| Business name / Trade name | `company_name` (required) + `trade_name` (optional DBA) | vendors | 2 |
| Type of business entity | `business_entity_type` enum | vendors | 2 |
| TIN | `tin` | vendors | 2 |
| DTI Registration No. (if sole prop) | `dti_registration_number` | vendors | 2 |
| SEC Registration No. (if partnership/corp) | `sec_registration_number` | vendors | 2 |
| Business Permit No. | `business_permit_number` | vendors | 2 |
| Business address (street, barangay, city, province, zip) | `business_street`, `business_barangay`, `business_city`, `business_province`, `business_postal_code` | vendors | 2 |
| Nature/line of business | `nature_of_business` | vendors | 2 |
| Owner full name (first, middle, last, suffix) | `first_name`, `middle_name`, `last_name`, `suffix` | vendor_representatives | 3 |
| Date of birth | `date_of_birth` | vendor_representatives | 3 |
| Gender | `gender` enum | vendor_representatives | 3 |
| Contact number | `contact_number` | vendor_representatives | 3 |
| Government ID type | `government_id_type` (document-type code) | vendor_representatives | 3 |
| Government ID number | `government_id_number` | vendor_representatives | 3 |
| Home address (complete) | `home_address` | vendor_representatives | 3 |
| Email address | `users.email` (already captured at Account step) | users | — |
| Document validity / expiry fields | *Not declared — extracted by the ML pipeline (out of scope)* | — | — |

## File Structure

**Create:**
- `database/migrations/XXXX_add_vendor_profile_completed_at_to_users_table.php` — gate-signal timestamp.
- `database/migrations/XXXX_add_declared_business_fields_to_vendors_table.php` — business reference columns.
- `database/migrations/XXXX_create_vendor_representatives_table.php` — owner/representative identity.
- `app/Models/VendorRepresentative.php` — owner model + gender/gov-ID constants.
- `database/factories/VendorRepresentativeFactory.php`.
- `app/Actions/Vendor/CreateVendorProfile.php` — single use-case orchestrator (transaction).
- `app/Http/Middleware/EnsureVendorProfileComplete.php` — gate to the new step.
- `resources/views/livewire/auth/business-details.blade.php` — the new Volt step (form).
- `tests/Feature/Vendor/CreateVendorProfileTest.php`.
- `tests/Feature/Auth/VendorProfileStepTest.php` — gate + Volt form behavior.

**Modify:**
- `app/Models/User.php` — `vendor()` relation, `hasCompletedVendorProfile()`, cast.
- `app/Models/Vendor.php` — fillable, entity-type constants/helpers, `representative()` relation, `businessAddress` accessor.
- `database/factories/UserFactory.php` — default `vendor_profile_completed_at` + `withoutVendorProfile()` state.
- `database/factories/VendorFactory.php` — new business fields in `definition()`.
- `app/Http/Middleware/EnsureSignatureEnrolled.php` — guard so it only fires after the profile is complete.
- `bootstrap/app.php` — register the new gate before the signature gate.
- `app/Http/Responses/RegisterResponse.php` — redirect to the new step.
- `routes/web.php` — register `business.create` route.
- `resources/views/auth/register.blade.php` — 4-step indicator.
- `resources/views/livewire/vendor/profile.blade.php` — read-only declared-info card.
- `database/seeders/DatabaseSeeder.php` — seeded vendor gets a complete profile.
- `tests/Feature/Auth/RegistrationTest.php` — updated post-register redirect assertion.

---

### Task 1: Gate-signal timestamp + User model + factory

**Files:**
- Create: `database/migrations/XXXX_add_vendor_profile_completed_at_to_users_table.php`
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Test: `tests/Feature/Auth/VendorProfileStepTest.php` (created here, extended later)

**Interfaces:**
- Produces: `User::hasCompletedVendorProfile(): bool`; `User::vendor(): HasOne` (→ `Vendor`); `users.vendor_profile_completed_at` (nullable datetime, cast); `UserFactory::withoutVendorProfile(): static`; default factory users have `vendor_profile_completed_at = now()`.

- [ ] **Step 1: Create the migration**

Run: `php artisan make:migration add_vendor_profile_completed_at_to_users_table --no-interaction`

- [ ] **Step 2: Fill in the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks the moment a vendor finished the business/owner-details step (the
     * second registration step, before signature enrollment). Presence of this
     * timestamp is the EnsureVendorProfileComplete gate signal — kept on `users`
     * (like signature_enrolled_at) so the gate reads it off the already-loaded
     * user with no extra query.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('vendor_profile_completed_at')->nullable()->after('signature_enrolled_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('vendor_profile_completed_at');
        });
    }
};
```

- [ ] **Step 3: Add the relation, helper, and cast to `User`**

In `app/Models/User.php`, add the import near the other `Illuminate\Database\Eloquent` imports:

```php
use Illuminate\Database\Eloquent\Relations\HasOne;
```

Add `'vendor_profile_completed_at' => 'datetime',` to the array returned by `casts()` (alongside `signature_enrolled_at`):

```php
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'signature_enrolled_at' => 'datetime',
            'vendor_profile_completed_at' => 'datetime',
        ];
```

Add these methods (place `vendor()` near the other relations; `hasCompletedVendorProfile()` next to `hasEnrolledSignature()`):

```php
    /**
     * The vendor company profile owned by this user (vendors are the only role
     * with one).
     *
     * @return HasOne<Vendor, $this>
     */
    public function vendor(): HasOne
    {
        return $this->hasOne(Vendor::class);
    }

    /**
     * Whether the vendor has completed the business/owner-details step of
     * registration (see the EnsureVendorProfileComplete middleware).
     */
    public function hasCompletedVendorProfile(): bool
    {
        return $this->vendor_profile_completed_at !== null;
    }
```

- [ ] **Step 4: Update `UserFactory`**

In `database/factories/UserFactory.php`, add to the array returned by `definition()` (right after the `signature_enrolled_at` line):

```php
            // Factory users default to having finished the business/owner-details
            // step too; use withoutVendorProfile() to test that gate.
            'vendor_profile_completed_at' => now(),
```

Add this state method after `unenrolled()`:

```php
    /**
     * Indicate that the vendor has not completed the business/owner-details step.
     */
    public function withoutVendorProfile(): static
    {
        return $this->state(fn (array $attributes) => [
            'vendor_profile_completed_at' => null,
        ]);
    }
```

- [ ] **Step 5: Write the failing test**

Run: `php artisan make:test --phpunit Auth/VendorProfileStepTest --no-interaction`

Replace the file body with:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorProfileStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_factory_vendor_has_a_completed_profile(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->hasCompletedVendorProfile());
        $this->assertNotNull($user->vendor_profile_completed_at);
    }

    public function test_without_vendor_profile_state_marks_the_profile_incomplete(): void
    {
        $user = User::factory()->withoutVendorProfile()->create();

        $this->assertFalse($user->hasCompletedVendorProfile());
        $this->assertNull($user->vendor_profile_completed_at);
    }
}
```

- [ ] **Step 6: Run the test to verify it fails, then passes**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorProfileStepTest`
Expected first run: FAIL (`vendor_profile_completed_at` column / cast missing). After Steps 2–4: PASS (2 tests).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/User.php database/factories/UserFactory.php database/migrations tests/Feature/Auth/VendorProfileStepTest.php
git commit -m "feat(vendor-reg): add vendor_profile_completed_at gate signal to users"
```

---

### Task 2: Declared business fields on `vendors` + Vendor model + factory

**Files:**
- Create: `database/migrations/XXXX_add_declared_business_fields_to_vendors_table.php`
- Modify: `app/Models/Vendor.php`
- Modify: `database/factories/VendorFactory.php`
- Test: `tests/Unit/VendorBusinessProfileTest.php`

**Interfaces:**
- Produces: `vendors` columns `trade_name, business_entity_type, tin, dti_registration_number, sec_registration_number, business_permit_number, nature_of_business, business_street, business_barangay, business_city, business_province, business_postal_code`; `Vendor::BUSINESS_ENTITY_TYPES` (`array<string,string>`); `Vendor::entityRequiresDti(?string): bool`; `Vendor::entityRequiresSec(?string): bool`; `$vendor->business_address` accessor (string). The `representative()` relation is added in Task 3.

- [ ] **Step 1: Create the migration**

Run: `php artisan make:migration add_declared_business_fields_to_vendors_table --no-interaction`

- [ ] **Step 2: Fill in the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Declared business reference data captured at registration (step 2). These
     * are the franchisee's self-declared values that uploaded documents are later
     * cross-checked against (TIN vs BIR 2303, business name vs DTI/SEC/Mayor's
     * Permit, etc.). The legacy `registration_number` and `address` columns are
     * left in place but are not written by the new flow.
     */
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('trade_name', 150)->nullable()->after('company_name');
            $table->enum('business_entity_type', [
                'sole_proprietorship', 'partnership', 'corporation', 'cooperative',
            ])->nullable()->after('trade_name');
            $table->string('tin', 20)->nullable()->after('business_entity_type');
            $table->string('dti_registration_number', 50)->nullable()->after('tin');
            $table->string('sec_registration_number', 50)->nullable()->after('dti_registration_number');
            $table->string('business_permit_number', 50)->nullable()->after('sec_registration_number');
            $table->string('nature_of_business', 150)->nullable()->after('business_permit_number');
            $table->string('business_street', 255)->nullable()->after('nature_of_business');
            $table->string('business_barangay', 120)->nullable()->after('business_street');
            $table->string('business_city', 120)->nullable()->after('business_barangay');
            $table->string('business_province', 120)->nullable()->after('business_city');
            $table->string('business_postal_code', 10)->nullable()->after('business_province');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'trade_name', 'business_entity_type', 'tin',
                'dti_registration_number', 'sec_registration_number',
                'business_permit_number', 'nature_of_business',
                'business_street', 'business_barangay', 'business_city',
                'business_province', 'business_postal_code',
            ]);
        });
    }
};
```

- [ ] **Step 3: Update the `Vendor` model**

In `app/Models/Vendor.php`, add the import:

```php
use Illuminate\Database\Eloquent\Casts\Attribute;
```

Add the entity-type constants after the existing `STATUS_*` constants:

```php
    /**
     * Business entity types and their display labels. Sole proprietors register
     * with the DTI; partnerships/corporations register with the SEC.
     *
     * @var array<string, string>
     */
    public const BUSINESS_ENTITY_TYPES = [
        'sole_proprietorship' => 'Sole Proprietorship',
        'partnership' => 'Partnership',
        'corporation' => 'Corporation',
        'cooperative' => 'Cooperative',
    ];
```

Replace the `$fillable` array with:

```php
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'company_name',
        'trade_name',
        'business_entity_type',
        'tin',
        'dti_registration_number',
        'sec_registration_number',
        'business_permit_number',
        'nature_of_business',
        'business_street',
        'business_barangay',
        'business_city',
        'business_province',
        'business_postal_code',
        'registration_number',
        'phone_number',
        'address',
        'risk_score',
        'status',
    ];
```

Add these methods (place the static helpers after `casts()`, and the accessor below them):

```php
    /**
     * Whether the given entity type registers its business name with the DTI.
     */
    public static function entityRequiresDti(?string $entityType): bool
    {
        return $entityType === 'sole_proprietorship';
    }

    /**
     * Whether the given entity type registers with the SEC.
     */
    public static function entityRequiresSec(?string $entityType): bool
    {
        return in_array($entityType, ['partnership', 'corporation'], true);
    }

    /**
     * The composed, human-readable business address from its structured parts.
     */
    protected function businessAddress(): Attribute
    {
        return Attribute::make(
            get: fn (): string => collect([
                $this->business_street,
                $this->business_barangay,
                $this->business_city,
                $this->business_province,
                $this->business_postal_code,
            ])->filter()->implode(', '),
        );
    }
```

- [ ] **Step 4: Update `VendorFactory`**

In `database/factories/VendorFactory.php`, replace the array returned by `definition()` with:

```php
        return [
            'user_id' => User::factory(),
            'company_name' => $this->faker->company(),
            'trade_name' => null,
            'business_entity_type' => 'sole_proprietorship',
            'tin' => $this->faker->numerify('###-###-###-000'),
            'dti_registration_number' => $this->faker->numerify('DTI-#######'),
            'sec_registration_number' => null,
            'business_permit_number' => $this->faker->numerify('BP-#######'),
            'nature_of_business' => 'Food retail and distribution',
            'business_street' => $this->faker->streetAddress(),
            'business_barangay' => 'Barangay '.$this->faker->numberBetween(1, 200),
            'business_city' => $this->faker->city(),
            'business_province' => 'Metro Manila',
            'business_postal_code' => $this->faker->numerify('1###'),
            'registration_number' => $this->faker->numerify('###-###-###-000'),
            'phone_number' => $this->faker->unique()->numerify('+63 9## ### ####'),
            'address' => $this->faker->address(),
            'risk_score' => 0,
            'status' => Vendor::STATUS_PENDING,
        ];
```

- [ ] **Step 5: Write the failing test**

Run: `php artisan make:test --phpunit --unit VendorBusinessProfileTest --no-interaction`

Replace the file body with:

```php
<?php

namespace Tests\Unit;

use App\Models\Vendor;
use Tests\TestCase;

class VendorBusinessProfileTest extends TestCase
{
    public function test_entity_requires_dti_only_for_sole_proprietorship(): void
    {
        $this->assertTrue(Vendor::entityRequiresDti('sole_proprietorship'));
        $this->assertFalse(Vendor::entityRequiresDti('corporation'));
        $this->assertFalse(Vendor::entityRequiresDti(null));
    }

    public function test_entity_requires_sec_for_partnerships_and_corporations(): void
    {
        $this->assertTrue(Vendor::entityRequiresSec('partnership'));
        $this->assertTrue(Vendor::entityRequiresSec('corporation'));
        $this->assertFalse(Vendor::entityRequiresSec('sole_proprietorship'));
        $this->assertFalse(Vendor::entityRequiresSec('cooperative'));
    }

    public function test_business_address_accessor_joins_structured_parts(): void
    {
        $vendor = new Vendor([
            'business_street' => '123 Mabini St',
            'business_barangay' => 'Barangay San Jose',
            'business_city' => 'Pasig',
            'business_province' => 'Metro Manila',
            'business_postal_code' => '1600',
        ]);

        $this->assertSame(
            '123 Mabini St, Barangay San Jose, Pasig, Metro Manila, 1600',
            $vendor->business_address,
        );
    }
}
```

- [ ] **Step 6: Run the test (fails → passes)**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorBusinessProfileTest`
Expected first run: FAIL (methods/columns missing). After Steps 2–4: PASS (3 tests).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Vendor.php database/factories/VendorFactory.php database/migrations tests/Unit/VendorBusinessProfileTest.php
git commit -m "feat(vendor-reg): add declared business fields to vendors + entity helpers"
```

---

### Task 3: `vendor_representatives` table + model + factory + relation

**Files:**
- Create: `database/migrations/XXXX_create_vendor_representatives_table.php` (via `make:model -mf`)
- Create: `app/Models/VendorRepresentative.php`
- Create: `database/factories/VendorRepresentativeFactory.php`
- Modify: `app/Models/Vendor.php` (add `representative()`)
- Test: `tests/Unit/VendorRepresentativeTest.php`

**Interfaces:**
- Consumes: `Vendor` model (Task 2).
- Produces: `VendorRepresentative` model; `VendorRepresentative::GENDERS` and `::GOVERNMENT_ID_TYPES` (`array<string,string>`); `Vendor::representative(): HasOne` (→ `VendorRepresentative`); `VendorRepresentative::vendor(): BelongsTo`; `VendorRepresentativeFactory`.

- [ ] **Step 1: Generate model + migration + factory**

Run: `php artisan make:model VendorRepresentative -mf --no-interaction`

- [ ] **Step 2: Fill in the migration**

Edit `database/migrations/XXXX_create_vendor_representatives_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The authorized owner/representative of a vendor, one per vendor. These
     * declared identity fields are cross-checked against the submitted
     * government ID and the name printed on the business documents.
     */
    public function up(): void
    {
        Schema::create('vendor_representatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('suffix', 20)->nullable();
            $table->date('date_of_birth');
            $table->enum('gender', ['male', 'female']);
            $table->string('contact_number', 20);
            $table->string('government_id_type', 40);
            $table->string('government_id_number', 60);
            $table->text('home_address');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_representatives');
    }
};
```

- [ ] **Step 3: Write the `VendorRepresentative` model**

Replace `app/Models/VendorRepresentative.php` with:

```php
<?php

namespace App\Models;

use Database\Factories\VendorRepresentativeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The authorized owner/representative declared at vendor registration.
 *
 * @property int $id
 * @property int $vendor_id
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property string|null $suffix
 * @property string $gender
 * @property string $government_id_type
 */
class VendorRepresentative extends Model
{
    /** @use HasFactory<VendorRepresentativeFactory> */
    use HasFactory;

    /**
     * Genders and their display labels (match the values on government IDs used
     * for cross-checking).
     *
     * @var array<string, string>
     */
    public const GENDERS = [
        'male' => 'Male',
        'female' => 'Female',
    ];

    /**
     * Accepted government ID types. Keys are the `document_types.code` values
     * (see DocumentTypeSeeder) so a declared ID type maps onto the uploaded ID
     * document; values are display labels for the dropdown.
     *
     * @var array<string, string>
     */
    public const GOVERNMENT_ID_TYPES = [
        'national_id' => 'PhilSys National ID (PhilID)',
        'drivers_license' => "Driver's License",
        'passport' => 'Passport',
        'umid' => 'UMID',
        'sss_id' => 'SSS ID',
        'philhealth_id' => 'PhilHealth ID',
        'postal_id' => 'Postal ID',
        'prc_id' => 'PRC ID',
        'voters_id' => "Voter's ID",
        'tin_id' => 'TIN ID',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'vendor_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'date_of_birth',
        'gender',
        'contact_number',
        'government_id_type',
        'government_id_number',
        'home_address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * The representative's full declared name, including any suffix.
     */
    public function fullName(): string
    {
        return collect([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])->filter()->implode(' ');
    }
}
```

- [ ] **Step 4: Write the factory**

Replace `database/factories/VendorRepresentativeFactory.php` with:

```php
<?php

namespace Database\Factories;

use App\Models\Vendor;
use App\Models\VendorRepresentative;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VendorRepresentative>
 */
class VendorRepresentativeFactory extends Factory
{
    protected $model = VendorRepresentative::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'first_name' => $this->faker->firstName(),
            'middle_name' => $this->faker->lastName(),
            'last_name' => $this->faker->lastName(),
            'suffix' => null,
            'date_of_birth' => $this->faker->dateTimeBetween('-60 years', '-21 years')->format('Y-m-d'),
            'gender' => $this->faker->randomElement(array_keys(VendorRepresentative::GENDERS)),
            'contact_number' => $this->faker->numerify('+63 9## ### ####'),
            'government_id_type' => $this->faker->randomElement(array_keys(VendorRepresentative::GOVERNMENT_ID_TYPES)),
            'government_id_number' => $this->faker->numerify('############'),
            'home_address' => $this->faker->address(),
        ];
    }
}
```

- [ ] **Step 5: Add the relation to `Vendor`**

In `app/Models/Vendor.php`, add the import:

```php
use Illuminate\Database\Eloquent\Relations\HasOne;
```

Add the relation method (next to `submissions()`):

```php
    /**
     * @return HasOne<VendorRepresentative, $this>
     */
    public function representative(): HasOne
    {
        return $this->hasOne(VendorRepresentative::class);
    }
```

- [ ] **Step 6: Write the failing test**

Run: `php artisan make:test --phpunit Vendor/VendorRepresentativeRelationTest --no-interaction`

Replace the file body with:

```php
<?php

namespace Tests\Feature\Vendor;

use App\Models\Vendor;
use App\Models\VendorRepresentative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorRepresentativeRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_vendor_has_one_representative(): void
    {
        $vendor = Vendor::factory()->create();
        $rep = VendorRepresentative::factory()->for($vendor)->create([
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
            'last_name' => 'Cruz',
            'suffix' => 'Jr.',
        ]);

        $this->assertTrue($vendor->refresh()->representative->is($rep));
        $this->assertTrue($rep->vendor->is($vendor));
        $this->assertSame('Maria Santos Cruz Jr.', $rep->fullName());
    }
}
```

- [ ] **Step 7: Run the test (fails → passes)**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorRepresentativeRelationTest`
Expected first run: FAIL (table/relation missing). After Steps 2–5: PASS (1 test).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/VendorRepresentative.php app/Models/Vendor.php database/factories/VendorRepresentativeFactory.php database/migrations tests/Feature/Vendor/VendorRepresentativeRelationTest.php
git commit -m "feat(vendor-reg): add vendor_representatives table, model, factory, relation"
```

---

### Task 4: `CreateVendorProfile` action

**Files:**
- Create: `app/Actions/Vendor/CreateVendorProfile.php`
- Test: `tests/Feature/Vendor/CreateVendorProfileTest.php`

**Interfaces:**
- Consumes: `User`, `Vendor`, `VendorRepresentative` models (Tasks 1–3).
- Produces: `CreateVendorProfile::execute(User $user, array<string,mixed> $data): Vendor`. Persists one `Vendor` + one `VendorRepresentative` in a transaction and sets `$user->vendor_profile_completed_at`. Idempotent (re-running updates rather than duplicates). Nullifies the non-applicable conditional registration number based on entity type, and coerces blank optionals to null.

- [ ] **Step 1: Generate the action class**

Run: `php artisan make:class Actions/Vendor/CreateVendorProfile --no-interaction`

- [ ] **Step 2: Write the failing test**

Run: `php artisan make:test --phpunit Vendor/CreateVendorProfileTest --no-interaction`

Replace the file body with:

```php
<?php

namespace Tests\Feature\Vendor;

use App\Actions\Vendor\CreateVendorProfile;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateVendorProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Negofood Trading',
            'trade_name' => '',
            'business_entity_type' => 'sole_proprietorship',
            'tin' => '123-456-789-000',
            'dti_registration_number' => 'DTI-2026001',
            'sec_registration_number' => '',
            'business_permit_number' => 'BP-2026-555',
            'nature_of_business' => 'Food retail',
            'business_street' => '12 Ortigas Ave',
            'business_barangay' => 'Barangay San Antonio',
            'business_city' => 'Pasig',
            'business_province' => 'Metro Manila',
            'business_postal_code' => '1600',
            'first_name' => 'Jose',
            'middle_name' => '',
            'last_name' => 'Rizal',
            'suffix' => '',
            'date_of_birth' => '1990-06-19',
            'gender' => 'male',
            'contact_number' => '+63 917 000 0000',
            'government_id_type' => 'national_id',
            'government_id_number' => '1234-5678-9012',
            'home_address' => '37 Real St, Calamba',
        ], $overrides);
    }

    public function test_it_creates_vendor_and_representative_and_marks_profile_complete(): void
    {
        $user = User::factory()->withoutVendorProfile()->create();

        $vendor = app(CreateVendorProfile::class)->execute($user, $this->payload());

        $this->assertSame('Negofood Trading', $vendor->company_name);
        $this->assertSame('sole_proprietorship', $vendor->business_entity_type);
        $this->assertSame('DTI-2026001', $vendor->dti_registration_number);
        $this->assertNull($vendor->sec_registration_number);
        $this->assertNull($vendor->trade_name);
        $this->assertSame('Jose', $vendor->representative->first_name);
        $this->assertNull($vendor->representative->middle_name);
        $this->assertTrue($user->refresh()->hasCompletedVendorProfile());
        $this->assertDatabaseCount('vendors', 1);
        $this->assertDatabaseCount('vendor_representatives', 1);
    }

    public function test_it_nullifies_dti_number_for_a_corporation(): void
    {
        $user = User::factory()->withoutVendorProfile()->create();

        $vendor = app(CreateVendorProfile::class)->execute($user, $this->payload([
            'business_entity_type' => 'corporation',
            'dti_registration_number' => 'DTI-LEFTOVER',
            'sec_registration_number' => 'SEC-CS202600123',
        ]));

        $this->assertNull($vendor->dti_registration_number);
        $this->assertSame('SEC-CS202600123', $vendor->sec_registration_number);
    }

    public function test_it_is_idempotent_and_does_not_duplicate_rows(): void
    {
        $user = User::factory()->withoutVendorProfile()->create();
        $action = app(CreateVendorProfile::class);

        $action->execute($user, $this->payload());
        $action->execute($user, $this->payload(['company_name' => 'Renamed Trading']));

        $this->assertDatabaseCount('vendors', 1);
        $this->assertDatabaseCount('vendor_representatives', 1);
        $this->assertSame('Renamed Trading', $user->vendor->refresh()->company_name);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan config:clear && php artisan test --compact --filter=CreateVendorProfileTest`
Expected: FAIL (`execute()` not implemented).

- [ ] **Step 4: Implement the action**

Replace `app/Actions/Vendor/CreateVendorProfile.php` with:

```php
<?php

namespace App\Actions\Vendor;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Persists the business/owner-details step of vendor registration: one Vendor
 * profile row plus its one VendorRepresentative, in a single transaction, and
 * stamps the user's vendor_profile_completed_at so the onboarding gate releases.
 */
class CreateVendorProfile
{
    /**
     * @param  array<string, mixed>  $data  Validated business + representative fields.
     */
    public function execute(User $user, array $data): Vendor
    {
        return DB::transaction(function () use ($user, $data): Vendor {
            $entityType = $data['business_entity_type'];

            $vendor = Vendor::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'company_name' => $data['company_name'],
                    'trade_name' => $this->nullable($data, 'trade_name'),
                    'business_entity_type' => $entityType,
                    'tin' => $data['tin'],
                    'dti_registration_number' => Vendor::entityRequiresDti($entityType)
                        ? $this->nullable($data, 'dti_registration_number')
                        : null,
                    'sec_registration_number' => Vendor::entityRequiresSec($entityType)
                        ? $this->nullable($data, 'sec_registration_number')
                        : null,
                    'business_permit_number' => $data['business_permit_number'],
                    'nature_of_business' => $data['nature_of_business'],
                    'business_street' => $data['business_street'],
                    'business_barangay' => $data['business_barangay'],
                    'business_city' => $data['business_city'],
                    'business_province' => $data['business_province'],
                    'business_postal_code' => $data['business_postal_code'],
                    'status' => Vendor::STATUS_PENDING,
                ],
            );

            $vendor->representative()->updateOrCreate([], [
                'first_name' => $data['first_name'],
                'middle_name' => $this->nullable($data, 'middle_name'),
                'last_name' => $data['last_name'],
                'suffix' => $this->nullable($data, 'suffix'),
                'date_of_birth' => $data['date_of_birth'],
                'gender' => $data['gender'],
                'contact_number' => $data['contact_number'],
                'government_id_type' => $data['government_id_type'],
                'government_id_number' => $data['government_id_number'],
                'home_address' => $data['home_address'],
            ]);

            $user->forceFill(['vendor_profile_completed_at' => now()])->save();

            return $vendor->load('representative');
        });
    }

    /**
     * Return the value for $key, or null when it is absent/blank.
     *
     * @param  array<string, mixed>  $data
     */
    private function nullable(array $data, string $key): ?string
    {
        return filled($data[$key] ?? null) ? (string) $data[$key] : null;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan config:clear && php artisan test --compact --filter=CreateVendorProfileTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Vendor/CreateVendorProfile.php tests/Feature/Vendor/CreateVendorProfileTest.php
git commit -m "feat(vendor-reg): add CreateVendorProfile action (transactional, idempotent)"
```

---

### Task 5: Business-details route + Volt form (the new step)

**Files:**
- Modify: `routes/web.php`
- Create: `resources/views/livewire/auth/business-details.blade.php`
- Test: `tests/Feature/Auth/VendorProfileStepTest.php` (extend with Volt tests)

**Interfaces:**
- Consumes: `CreateVendorProfile` (Task 4); `Vendor::BUSINESS_ENTITY_TYPES`, `Vendor::entityRequiresDti/Sec` (Task 2); `VendorRepresentative::GENDERS/GOVERNMENT_ID_TYPES` (Task 3); `TinValidator`, `RegistrationNumberValidator` (existing).
- Produces: route name `business.create` at `register/business`; Volt component `auth.business-details` with a `save(CreateVendorProfile $action): void` method that validates, persists, and redirects to `signature.create`.

- [ ] **Step 1: Register the route**

In `routes/web.php`, inside the existing `Route::middleware(['auth'])->group(...)` block (right next to the `signature/enroll` route), add:

```php
    // Step 2 of vendor registration: declare business + owner details before
    // signature enrollment. Auth-only (the user is not verified yet) and exempt
    // from the EnsureVendorProfileComplete gate by its route name.
    Volt::route('register/business', 'auth.business-details')->name('business.create');
```

- [ ] **Step 2: Write the failing tests**

Append these methods to `tests/Feature/Auth/VendorProfileStepTest.php` (add the imports `use App\Models\Vendor;`, `use Livewire\Volt\Volt;` at the top):

```php
    public function test_business_step_renders_for_a_vendor_without_a_profile(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        $this->actingAs($user)
            ->get(route('business.create'))
            ->assertOk()
            ->assertSee('Business & owner details');
    }

    public function test_vendor_can_submit_the_business_step(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.business-details')
            ->set('company_name', 'Negofood Trading')
            ->set('business_entity_type', 'sole_proprietorship')
            ->set('tin', '123-456-789-000')
            ->set('dti_registration_number', 'DTI-2026001')
            ->set('business_permit_number', 'BP-2026-555')
            ->set('nature_of_business', 'Food retail')
            ->set('business_street', '12 Ortigas Ave')
            ->set('business_barangay', 'Barangay San Antonio')
            ->set('business_city', 'Pasig')
            ->set('business_province', 'Metro Manila')
            ->set('business_postal_code', '1600')
            ->set('first_name', 'Jose')
            ->set('last_name', 'Rizal')
            ->set('date_of_birth', '1990-06-19')
            ->set('gender', 'male')
            ->set('contact_number', '+63 917 000 0000')
            ->set('government_id_type', 'national_id')
            ->set('government_id_number', '1234-5678-9012')
            ->set('home_address', '37 Real St, Calamba')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('signature.create'));

        $user->refresh();
        $this->assertTrue($user->hasCompletedVendorProfile());
        $this->assertSame('Negofood Trading', $user->vendor->company_name);
    }

    public function test_business_step_requires_core_fields(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.business-details')
            ->call('save')
            ->assertHasErrors(['company_name', 'business_entity_type', 'tin', 'first_name', 'last_name']);
    }

    public function test_sole_proprietorship_requires_a_dti_number(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.business-details')
            ->set('business_entity_type', 'sole_proprietorship')
            ->set('sec_registration_number', '')
            ->set('dti_registration_number', '')
            ->call('save')
            ->assertHasErrors(['dti_registration_number']);
    }

    public function test_corporation_requires_a_sec_number(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.business-details')
            ->set('business_entity_type', 'corporation')
            ->set('dti_registration_number', '')
            ->set('sec_registration_number', '')
            ->call('save')
            ->assertHasErrors(['sec_registration_number']);
    }

    public function test_malformed_tin_is_rejected(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.business-details')
            ->set('tin', '12')
            ->call('save')
            ->assertHasErrors(['tin']);
    }
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorProfileStepTest`
Expected: FAIL (component `auth.business-details` does not exist).

- [ ] **Step 4: Create the Volt component**

Run: `php artisan make:volt auth/business-details --class --no-interaction`

Replace `resources/views/livewire/auth/business-details.blade.php` with:

```blade
<?php

use App\Actions\Vendor\CreateVendorProfile;
use App\Models\Vendor;
use App\Models\VendorRepresentative;
use App\Support\RegistrationNumberValidator;
use App\Support\TinValidator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    // Business
    public string $company_name = '';
    public string $trade_name = '';
    public string $business_entity_type = '';
    public string $tin = '';
    public string $dti_registration_number = '';
    public string $sec_registration_number = '';
    public string $business_permit_number = '';
    public string $nature_of_business = '';
    public string $business_street = '';
    public string $business_barangay = '';
    public string $business_city = '';
    public string $business_province = '';
    public string $business_postal_code = '';

    // Owner / representative
    public string $first_name = '';
    public string $middle_name = '';
    public string $last_name = '';
    public string $suffix = '';
    public string $date_of_birth = '';
    public string $gender = '';
    public string $contact_number = '';
    public string $government_id_type = '';
    public string $government_id_number = '';
    public string $home_address = '';

    public function requiresDti(): bool
    {
        return Vendor::entityRequiresDti($this->business_entity_type);
    }

    public function requiresSec(): bool
    {
        return Vendor::entityRequiresSec($this->business_entity_type);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:150'],
            'trade_name' => ['nullable', 'string', 'max:150'],
            'business_entity_type' => ['required', Rule::in(array_keys(Vendor::BUSINESS_ENTITY_TYPES))],
            'tin' => ['required', 'string', 'max:20', $this->tinRule()],
            'dti_registration_number' => ['nullable', 'required_if:business_entity_type,sole_proprietorship', 'string', 'max:50', $this->registrationNumberRule()],
            'sec_registration_number' => ['nullable', 'required_if:business_entity_type,partnership,corporation', 'string', 'max:50', $this->registrationNumberRule()],
            'business_permit_number' => ['required', 'string', 'max:50'],
            'nature_of_business' => ['required', 'string', 'max:150'],
            'business_street' => ['required', 'string', 'max:255'],
            'business_barangay' => ['required', 'string', 'max:120'],
            'business_city' => ['required', 'string', 'max:120'],
            'business_province' => ['required', 'string', 'max:120'],
            'business_postal_code' => ['required', 'string', 'max:10'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::in(array_keys(VendorRepresentative::GENDERS))],
            'contact_number' => ['required', 'string', 'max:20'],
            'government_id_type' => ['required', Rule::in(array_keys(VendorRepresentative::GOVERNMENT_ID_TYPES))],
            'government_id_number' => ['required', 'string', 'max:60'],
            'home_address' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * Closure rule: a declared TIN must be a well-formed Philippine TIN.
     */
    protected function tinRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! TinValidator::isValid((string) $value)) {
                $fail(__('Enter a valid TIN (9–14 digits, e.g. 123-456-789-000).'));
            }
        };
    }

    /**
     * Closure rule: validate a DTI/SEC certificate number's format, but only when
     * a value was supplied (the field is conditional on entity type).
     */
    protected function registrationNumberRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (filled($value) && ! RegistrationNumberValidator::isValid((string) $value)) {
                $fail(__('Enter a valid registration number (at least 5 digits).'));
            }
        };
    }

    public function save(CreateVendorProfile $action): void
    {
        $validated = $this->validate();

        $action->execute(Auth::user(), $validated);

        $this->redirectRoute('signature.create', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        :title="__('Business & owner details')"
        :description="__('Step 2 of 4 — declare your business and representative information. This is what we cross-check your uploaded documents against.')"
    />

    {{-- Step indicator --}}
    <ol class="flex items-center gap-2 text-xs font-medium">
        <li class="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
            <flux:icon icon="check-circle" variant="micro" class="size-4" /> {{ __('Account') }}
        </li>
        <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
        <li class="flex items-center gap-1.5 text-cu-purple">
            <span class="flex size-4 items-center justify-center rounded-full bg-cu-purple text-[10px] text-white">2</span>
            {{ __('Details') }}
        </li>
        <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
        <li class="flex items-center gap-1.5 text-zinc-400 dark:text-zinc-500">
            <span class="flex size-4 items-center justify-center rounded-full border border-current text-[10px]">3</span>
            {{ __('Signature') }}
        </li>
        <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
        <li class="flex items-center gap-1.5 text-zinc-400 dark:text-zinc-500">
            <span class="flex size-4 items-center justify-center rounded-full border border-current text-[10px]">4</span>
            {{ __('Verify') }}
        </li>
    </ol>

    <form wire:submit="save" class="flex flex-col gap-6">
        {{-- Business information --}}
        <section class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Business information') }}</flux:heading>

            <flux:input wire:model="company_name" :label="__('Registered business name')" required />
            <flux:input wire:model="trade_name" :label="__('Trade name / DBA (optional)')" />

            <flux:select wire:model.live="business_entity_type" :label="__('Type of business entity')" :placeholder="__('Select entity type')" required>
                @foreach (\App\Models\Vendor::BUSINESS_ENTITY_TYPES as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="tin" :label="__('TIN (Tax Identification Number)')" placeholder="123-456-789-000" required />

            @if ($this->requiresDti())
                <flux:input wire:model="dti_registration_number" :label="__('DTI Registration Number')" required />
            @endif

            @if ($this->requiresSec())
                <flux:input wire:model="sec_registration_number" :label="__('SEC Registration Number')" required />
            @endif

            <flux:input wire:model="business_permit_number" :label="__('Business Permit Number')" required />
            <flux:input wire:model="nature_of_business" :label="__('Nature / line of business')" required />

            <flux:heading size="sm" class="mt-2">{{ __('Business address') }}</flux:heading>
            <flux:input wire:model="business_street" :label="__('Street')" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="business_barangay" :label="__('Barangay')" required />
                <flux:input wire:model="business_city" :label="__('City / Municipality')" required />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="business_province" :label="__('Province')" required />
                <flux:input wire:model="business_postal_code" :label="__('ZIP / Postal code')" required />
            </div>
        </section>

        {{-- Owner / representative --}}
        <section class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Owner / authorized representative') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="first_name" :label="__('First name')" required />
                <flux:input wire:model="middle_name" :label="__('Middle name (optional)')" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="last_name" :label="__('Last name')" required />
                <flux:input wire:model="suffix" :label="__('Suffix (optional)')" placeholder="Jr., Sr., III" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="date_of_birth" type="date" :label="__('Date of birth')" required />
                <flux:select wire:model="gender" :label="__('Gender')" :placeholder="__('Select')" required>
                    @foreach (\App\Models\VendorRepresentative::GENDERS as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:input wire:model="contact_number" :label="__('Contact number')" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="government_id_type" :label="__('Government ID type')" :placeholder="__('Select ID type')" required>
                    @foreach (\App\Models\VendorRepresentative::GOVERNMENT_ID_TYPES as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="government_id_number" :label="__('Government ID number')" required />
            </div>

            <flux:textarea wire:model="home_address" :label="__('Home address (complete)')" rows="2" required />
        </section>

        <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">{{ __('Save & continue') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </flux:button>
    </form>

    <div class="text-center">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:link as="button" type="submit" class="cursor-pointer text-sm">{{ __('Log out') }}</flux:link>
        </form>
    </div>
</div>
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorProfileStepTest`
Expected: PASS (all VendorProfileStepTest methods, including the 6 new ones).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/livewire/auth/business-details.blade.php tests/Feature/Auth/VendorProfileStepTest.php
git commit -m "feat(vendor-reg): add business/owner-details Volt step with conditional DTI/SEC"
```

---

### Task 6: Onboarding gate (route incomplete vendors to the new step)

**Files:**
- Create: `app/Http/Middleware/EnsureVendorProfileComplete.php`
- Modify: `app/Http/Middleware/EnsureSignatureEnrolled.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Auth/VendorProfileStepTest.php` (extend)

**Interfaces:**
- Consumes: `User::hasCompletedVendorProfile()` (Task 1); route `business.create` (Task 5).
- Produces: `EnsureVendorProfileComplete` middleware appended to the `web` group **before** `EnsureSignatureEnrolled`. Order guarantees: incomplete-profile vendors → `business.create`; complete-but-unenrolled vendors → `signature.create`; the signature gate no longer fires while the profile is incomplete.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Auth/VendorProfileStepTest.php`:

```php
    public function test_vendor_without_a_profile_is_gated_to_the_business_step(): void
    {
        $user = User::factory()->withoutVendorProfile()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('business.create'));
        $this->actingAs($user)->get(route('vendor.dashboard'))->assertRedirect(route('business.create'));
    }

    public function test_signature_gate_does_not_fire_before_the_profile_is_complete(): void
    {
        // No profile and no signature: the business gate wins; the user must not
        // be bounced to the signature step yet.
        $user = User::factory()->withoutVendorProfile()->unenrolled()->create();

        $this->actingAs($user)->get(route('vendor.dashboard'))->assertRedirect(route('business.create'));
    }

    public function test_complete_profile_but_unenrolled_vendor_is_gated_to_signature(): void
    {
        $user = User::factory()->unenrolled()->create(); // profile complete by default

        $this->actingAs($user)->get(route('vendor.dashboard'))->assertRedirect(route('signature.create'));
    }

    public function test_livewire_endpoints_are_never_gated_to_the_business_step(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        $response = $this->actingAs($user)->post(route('default-livewire.update'));

        $this->assertNotSame(
            route('business.create'),
            $response->headers->get('Location'),
            'Livewire update requests must not be redirected by the profile gate.'
        );
    }

    public function test_officers_and_admins_are_never_gated_to_the_business_step(): void
    {
        $officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->withoutVendorProfile()->create();

        $this->actingAs($officer)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_guests_cannot_access_the_business_step(): void
    {
        $this->get(route('business.create'))->assertRedirect('/login');
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorProfileStepTest`
Expected: the new gating tests FAIL (no gate yet — incomplete vendors currently reach the dashboard or are bounced to signature).

- [ ] **Step 3: Create the gate middleware**

Run: `php artisan make:middleware EnsureVendorProfileComplete --no-interaction`

Replace `app/Http/Middleware/EnsureVendorProfileComplete.php` with:

```php
<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces a registered vendor through the business/owner-details step (the second
 * registration step) before signature enrollment, email verification, or any app
 * page.
 *
 * Appended to the `web` group BEFORE EnsureSignatureEnrolled, so business details
 * are collected before the signature. It no-ops for guests, non-vendors, and
 * vendors who have completed their profile, and exempts the business-step routes,
 * logout, and Livewire's own endpoints to avoid a redirect loop.
 */
class EnsureVendorProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && $user->hasRole(User::ROLE_VENDOR)
            && ! $user->hasCompletedVendorProfile()
            && ! $request->routeIs('business.*', 'logout', 'livewire.*', 'default-livewire.*')
        ) {
            return redirect()->route('business.create');
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Guard the signature gate so it fires only after the profile is complete**

In `app/Http/Middleware/EnsureSignatureEnrolled.php`, add the `hasCompletedVendorProfile()` condition to the `if`:

```php
        if ($user instanceof User
            && $user->hasRole(User::ROLE_VENDOR)
            && $user->hasCompletedVendorProfile()
            && ! $user->hasEnrolledSignature()
            && ! $request->routeIs('signature.*', 'logout', 'livewire.*', 'default-livewire.*')
        ) {
            return redirect()->route('signature.create');
        }
```

Update that method's doc comment to note the new ordering (one line): `// Runs after EnsureVendorProfileComplete; only fires once the business profile is complete.`

- [ ] **Step 5: Register the gate before the signature gate**

In `bootstrap/app.php`, add the import:

```php
use App\Http\Middleware\EnsureVendorProfileComplete;
```

Replace the `$middleware->web(append: [...])` call with:

```php
        // Gate every web route. Order matters: a registered vendor declares
        // business/owner details first, then enrolls a signature, before reaching
        // email verification or the app.
        $middleware->web(append: [
            EnsureVendorProfileComplete::class,
            EnsureSignatureEnrolled::class,
        ]);
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorProfileStepTest`
Expected: PASS (all methods). Then run the existing signature suite to confirm no regression:

Run: `php artisan test --compact --filter=SignatureEnrollmentTest`
Expected: PASS (unchanged — default factory users have a complete profile, so signature gating still resolves to `signature.create`).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/EnsureVendorProfileComplete.php app/Http/Middleware/EnsureSignatureEnrolled.php bootstrap/app.php tests/Feature/Auth/VendorProfileStepTest.php
git commit -m "feat(vendor-reg): gate vendors to the business-details step before signature"
```

---

### Task 7: Wire the new step into the registration entrypoint

**Files:**
- Modify: `app/Http/Responses/RegisterResponse.php`
- Modify: `resources/views/auth/register.blade.php`
- Modify: `tests/Feature/Auth/RegistrationTest.php`

**Interfaces:**
- Consumes: route `business.create` (Task 5).
- Produces: post-registration redirect now lands on `business.create`; the register screen shows a 4-step indicator.

- [ ] **Step 1: Update the failing assertion in `RegistrationTest`**

In `tests/Feature/Auth/RegistrationTest.php`, inside `test_new_users_can_register_as_vendors`, change the redirect assertion and add a profile-state assertion:

```php
        // Step 2 of registration: declare business + owner details before signature.
        $response->assertRedirect(route('business.create'));

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'role' => User::ROLE_VENDOR,
            'signature_enrolled_at' => null,
            'vendor_profile_completed_at' => null,
        ]);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan config:clear && php artisan test --compact --filter=RegistrationTest`
Expected: `test_new_users_can_register_as_vendors` FAILS (still redirects to `signature.create`).

- [ ] **Step 3: Update `RegisterResponse`**

In `app/Http/Responses/RegisterResponse.php`, change the redirect target and the docblock:

```php
/**
 * After registration, send vendors to the business/owner-details step (the
 * second registration step) instead of straight to the dashboard. The
 * EnsureVendorProfileComplete middleware keeps them there until it is completed,
 * then EnsureSignatureEnrolled forwards them to signature enrollment.
 */
class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        return $request->wantsJson()
            ? new JsonResponse('', 201)
            : redirect()->route('business.create');
    }
}
```

- [ ] **Step 4: Update the register screen step indicator to 4 steps**

In `resources/views/auth/register.blade.php`, replace the existing `<ol …>` step-indicator block (the 3-step list) with:

```blade
        {{-- Step indicator: account → details → signature → verify email --}}
        <ol class="flex items-center gap-2 text-xs font-medium">
            <li class="flex items-center gap-1.5 text-cu-purple">
                <span class="flex size-4 items-center justify-center rounded-full bg-cu-purple text-[10px] text-white">1</span>
                {{ __('Account') }}
            </li>
            <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
            <li class="flex items-center gap-1.5 text-zinc-400 dark:text-zinc-500">
                <span class="flex size-4 items-center justify-center rounded-full border border-current text-[10px]">2</span>
                {{ __('Details') }}
            </li>
            <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
            <li class="flex items-center gap-1.5 text-zinc-400 dark:text-zinc-500">
                <span class="flex size-4 items-center justify-center rounded-full border border-current text-[10px]">3</span>
                {{ __('Signature') }}
            </li>
            <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
            <li class="flex items-center gap-1.5 text-zinc-400 dark:text-zinc-500">
                <span class="flex size-4 items-center justify-center rounded-full border border-current text-[10px]">4</span>
                {{ __('Verify email') }}
            </li>
        </ol>
```

Also update the heads-up panel copy: change its heading from `{{ __('Next: enroll your signature') }}` to `{{ __('Next: your business & owner details') }}` and its body text to `{{ __('After creating your account you will declare your business and representative information, then enroll your reference signature.') }}`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan config:clear && php artisan test --compact --filter=RegistrationTest`
Expected: PASS (3 tests). The register screen still renders (`test_registration_screen_can_be_rendered`).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Responses/RegisterResponse.php resources/views/auth/register.blade.php tests/Feature/Auth/RegistrationTest.php
git commit -m "feat(vendor-reg): redirect registration to the business-details step (4-step flow)"
```

---

### Task 8: Read-only declared-info card on the vendor's own profile

**Files:**
- Modify: `resources/views/livewire/vendor/profile.blade.php`
- Test: `tests/Feature/Vendor/VendorProfilePageTest.php`

**Interfaces:**
- Consumes: `User::vendor()` + `Vendor::representative()` + `Vendor::$business_address` (Tasks 1–3); `Vendor::BUSINESS_ENTITY_TYPES`, `VendorRepresentative::GENDERS/GOVERNMENT_ID_TYPES` (display labels).
- Produces: the vendor profile page renders a read-only "Declared information" panel of the captured data when a vendor profile exists.

- [ ] **Step 1: Write the failing test**

Run: `php artisan make:test --phpunit Vendor/VendorProfilePageTest --no-interaction`

Replace the file body with:

```php
<?php

namespace Tests\Feature\Vendor;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRepresentative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorProfilePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_shows_declared_business_and_owner_data(): void
    {
        $user = User::factory()->create();
        $vendor = Vendor::factory()->for($user)->create([
            'company_name' => 'Negofood Trading',
            'business_entity_type' => 'sole_proprietorship',
            'tin' => '123-456-789-000',
            'business_city' => 'Pasig',
        ]);
        VendorRepresentative::factory()->for($vendor)->create([
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
            'government_id_type' => 'national_id',
        ]);

        $this->actingAs($user)
            ->get(route('vendor.profile'))
            ->assertOk()
            ->assertSee('Negofood Trading')
            ->assertSee('123-456-789-000')
            ->assertSee('Sole Proprietorship')
            ->assertSee('Jose Rizal')
            ->assertSee('Pasig');
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorProfilePageTest`
Expected: FAIL (the page does not render declared data yet).

- [ ] **Step 3: Add the declared-info card to the profile page**

Replace `resources/views/livewire/vendor/profile.blade.php` with:

```blade
<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        return [
            'vendor' => Auth::user()->vendor?->load('representative'),
        ];
    }
}; ?>

<x-page>
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 text-cu-text">
        @if ($vendor)
            <div class="rounded-2xl border border-cu-border bg-cu-surface p-5">
                <flux:heading size="lg">{{ __('Declared information') }}</flux:heading>
                <p class="text-xs text-cu-muted">{{ __('What your uploaded documents are cross-checked against. Contact a compliance officer to correct any of these.') }}</p>

                <h3 class="mt-5 text-sm font-semibold text-cu-text">{{ __('Business') }}</h3>
                <dl class="mt-3 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-cu-muted">{{ __('Registered business name') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ $vendor->company_name }}</dd>
                    </div>
                    @if ($vendor->trade_name)
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Trade name') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->trade_name }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs text-cu-muted">{{ __('Entity type') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ \App\Models\Vendor::BUSINESS_ENTITY_TYPES[$vendor->business_entity_type] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-cu-muted">{{ __('TIN') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ $vendor->tin ?? '—' }}</dd>
                    </div>
                    @if ($vendor->dti_registration_number)
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('DTI Registration No.') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->dti_registration_number }}</dd>
                        </div>
                    @endif
                    @if ($vendor->sec_registration_number)
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('SEC Registration No.') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->sec_registration_number }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs text-cu-muted">{{ __('Business Permit No.') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ $vendor->business_permit_number ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-cu-muted">{{ __('Nature of business') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ $vendor->nature_of_business ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-cu-muted">{{ __('Business address') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ $vendor->business_address ?: '—' }}</dd>
                    </div>
                </dl>

                @if ($vendor->representative)
                    <h3 class="mt-6 text-sm font-semibold text-cu-text">{{ __('Owner / representative') }}</h3>
                    <dl class="mt-3 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Full name') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->representative->fullName() }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Date of birth') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->representative->date_of_birth?->format('F j, Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Gender') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ \App\Models\VendorRepresentative::GENDERS[$vendor->representative->gender] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Contact number') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->representative->contact_number }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Government ID') }}</dt>
                            <dd class="mt-0.5 text-sm">
                                {{ \App\Models\VendorRepresentative::GOVERNMENT_ID_TYPES[$vendor->representative->government_id_type] ?? '—' }}
                                · {{ $vendor->representative->government_id_number }}
                            </dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-cu-muted">{{ __('Home address') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->representative->home_address }}</dd>
                        </div>
                    </dl>
                @endif
            </div>
        @endif

        <livewire:settings.profile />
    </div>
</x-page>
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorProfilePageTest`
Expected: PASS (1 test).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/livewire/vendor/profile.blade.php tests/Feature/Vendor/VendorProfilePageTest.php
git commit -m "feat(vendor-reg): show declared business/owner data on the vendor profile"
```

---

### Task 9: Seed a complete profile for the demo vendor + full-suite verification

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: full suite

**Interfaces:**
- Consumes: `Vendor`/`VendorRepresentative` factories + models (Tasks 2–3). Without this, the seeded `vendor@advs.test` account would be bounced to `business.create` on login because it has no profile.

- [ ] **Step 1: Update the seeder**

In `database/seeders/DatabaseSeeder.php`, add the imports:

```php
use App\Models\Vendor;
use App\Models\VendorRepresentative;
```

Replace the seeded-vendor block (the `if ($role === User::ROLE_VENDOR && ! $user->hasEnrolledSignature()) { … }` block) with:

```php
            // Seeded vendors skip the onboarding gates so the demo account lands
            // on the dashboard: a complete declared profile + an enrolled
            // signature. (signature_path is a placeholder; no real reference
            // image exists for seeded data.)
            if ($role === User::ROLE_VENDOR) {
                $user->forceFill([
                    'signature_path' => "signatures/{$user->id}/seeded-reference.jpg",
                    'signature_enrolled_at' => now(),
                    'vendor_profile_completed_at' => now(),
                ])->save();

                $vendor = Vendor::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'company_name' => 'Negofood Demo Trading',
                        'business_entity_type' => 'sole_proprietorship',
                        'tin' => '123-456-789-000',
                        'dti_registration_number' => 'DTI-2026000',
                        'business_permit_number' => 'BP-2026-000',
                        'nature_of_business' => 'Food retail and distribution',
                        'business_street' => '1 Caruncho Ave',
                        'business_barangay' => 'Barangay San Nicolas',
                        'business_city' => 'Pasig',
                        'business_province' => 'Metro Manila',
                        'business_postal_code' => '1600',
                        'status' => Vendor::STATUS_PENDING,
                    ],
                );

                VendorRepresentative::firstOrCreate(
                    ['vendor_id' => $vendor->id],
                    [
                        'first_name' => 'Demo',
                        'last_name' => 'Vendor',
                        'date_of_birth' => '1990-01-01',
                        'gender' => 'male',
                        'contact_number' => '+63 917 000 0000',
                        'government_id_type' => 'national_id',
                        'government_id_number' => '1234-5678-9012',
                        'home_address' => '1 Caruncho Ave, Pasig, Metro Manila',
                    ],
                );
            }
```

- [ ] **Step 2: Verify the seed runs end to end**

Run: `php artisan migrate:fresh --seed`
Expected: completes with no errors; `vendors` and `vendor_representatives` each contain one seeded row for `vendor@advs.test`.

> Note: this runs against the real MySQL `advs` DB, so it requires the DB to be up. If it is down, skip this manual step and rely on the test in Step 3.

- [ ] **Step 3: Add a seeder regression test**

Run: `php artisan make:test --phpunit DatabaseSeederTest --no-interaction`

Replace the file body with:

```php
<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_vendor_has_a_complete_profile_and_is_not_gated(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = \App\Models\User::where('email', 'vendor@advs.test')->firstOrFail();

        $this->assertTrue($user->hasCompletedVendorProfile());
        $this->assertTrue($user->hasEnrolledSignature());
        $this->assertNotNull($user->vendor);
        $this->assertNotNull($user->vendor->representative);

        $this->actingAs($user)->get(route('vendor.dashboard'))->assertOk();
    }
}
```

- [ ] **Step 4: Run the seeder test, then the full suite**

Run: `php artisan config:clear && php artisan test --compact --filter=DatabaseSeederTest`
Expected: PASS (1 test).

Run: `php artisan config:clear && php artisan test --compact`
Expected: the entire suite is green (auth, dashboard, signature, vendor, and the new vendor-registration tests).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/DatabaseSeeder.php tests/Feature/DatabaseSeederTest.php
git commit -m "feat(vendor-reg): seed a complete declared profile for the demo vendor"
```

---

## Self-Review Notes

- **Spec coverage:** Every declared field in the spec maps to a column in the Coverage Map and a task. Document-validity/expiry fields are intentionally excluded (extracted by the ML pipeline, not declared). Conditional DTI/SEC handled in Task 5 (form `@if`) + Task 4 (action nullifies the inapplicable one) + validation `required_if`.
- **Type consistency:** `hasCompletedVendorProfile()`, `vendor_profile_completed_at`, `entityRequiresDti/Sec`, `business_address`, `BUSINESS_ENTITY_TYPES`, `GENDERS`, `GOVERNMENT_ID_TYPES`, `CreateVendorProfile::execute(User, array): Vendor`, and route name `business.create` are used identically everywhere they appear.
- **Gate ordering proof:** business gate first (redirects to `business.create` for any non-exempt route while the profile is incomplete) → a vendor cannot reach `signature.create` until the profile exists; the signature gate's added `hasCompletedVendorProfile()` guard stops it from yanking a vendor off the half-finished business step. Existing signature tests stay green because the default factory user has a completed profile.
- **No placeholders:** every code step contains the full artifact or an exact, unique replacement target.

## Open follow-ups (not in this plan)

1. **Officer-facing display** of declared data on `admin/vendors/{vendor}` — blocked on replacing `DemoStore` with real `Vendor` reads.
2. **Automated declared-vs-extracted cross-check** — depends on OCR field extraction reaching production (project memory: *ocr-preprocessing-otsu-vs-fixed150*), then a comparison service + mismatch flags feeding the Stage 5 risk score and the officer report.


---

## File: docs/superpowers/plans/2026-07-15-e2e-submission-wiring.md

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


---

## File: docs/superpowers/plans/2026-08-12-stage3-4b-pipeline-gaps.md

# Python Main Pipeline — Stage 3 / Stage 4b Gap Closure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the three spec-mandated behaviours missing from `POST /v1/validate` — the Stage 4b stamp tamper check, LGU city-scoped logo lookup, and the Stage 3 `fake`/type-mismatch signals — so every trained model actually influences a document's risk score.

**Architecture:** The Python ML API (`python/api`) runs the whole pipeline fail-forward in one request and returns per-stage components plus merged flags; Laravel's `MlPipelineService` projects those onto `ValidationResult` columns and `RiskScoreService` blends them. Each task here adds a stage output on the Python side and the minimal Laravel mapping that consumes it, so each task ships working software end to end.

**Tech Stack:** Python 3.12 · FastAPI · TensorFlow/Keras 2.16 · scikit-learn (LogisticRegression) · Ultralytics YOLOv8 · pytest — and PHP 8.2 · Laravel 12 · PHPUnit 11.

---

## Background — why these three

Verified against `ADVS_System_Reference.md` §5 and the current code:

1. **The Stage 4b tamper texture check never runs.** The spec says it runs "always … reference or not" (§5 Stage 4b, "Tamper check (always runs, reference or not)" and failure path "Tampering detected → … raised even before any reference exists"). Today `run_stamp_verify()` returns `unreferenced_logo` *before* embedding the crop, so nothing is analysed. `python/models/stamp_classifier.pkl` (genuine/forged, val accuracy 0.90, trained 2026-07-18) is loaded nowhere, and the `validation_results.stamp_tampered` column added by migration `2026_06_26_160504` has never been written.

2. **The OCR-detected city never reaches Stage 4b.** §5 Stage 2 says the detected city "is passed to Stage 4b, which uses it to look up the correct reference logo(s)"; §5 Stage 4b keys an `lgu` issuer by `(document type, detected city)` and requires a `City not identified` flag when OCR reads none. Today `validate.py` passes only the caller's `city` form field, and `MlPipelineService::validate()` can only resolve a reference for `national` issuers (the city does not exist until Stage 2 runs, inside the same call). Result: **every** LGU document — Business Permit, Sanitary Permit, Food Handler Certificate — reports `unreferenced_logo`, and `city_not_identified` does not exist anywhere in the codebase.

3. **A document the classifier calls `fake` scores as clean.** `class_names.json` is `["bir_certificate", "business_permit", "dti_registration", "fake"]`. `validate.py` flags only `low_classification_confidence`, so a document classified `fake` at 0.98 confidence produces no flag, and `RiskScoreService` receives `classification_confidence = 0.98` as an **authenticity** score → near-zero classification risk. A confident forgery detection currently *lowers* risk.

---

## Global Constraints

- **Interpreter:** always `python/env/Scripts/python.exe` (python.org 3.12.10). Never bare `python` (MSYS2 build, no wheels); never `py` (3.14, too new for TensorFlow).
- **Run every command from the project root** `c:\xampp\htdocs\projects\advs`.
- **Never invent thresholds or weights.** Defaults come from `ADVS_System_Reference.md` §9 (`CLASSIFICATION_CONFIDENCE_THRESHOLD=0.70`, `STAMP_SIMILARITY_THRESHOLD=0.85`, `MISSING_COMPONENT_PENALTY=15`, bands Low 0–30 / Medium 31–60 / High 61–100). Any new tunable must be justified as a model's own decision boundary, not a new §9 parameter.
- **The pipeline is fail-forward.** A stage that cannot run records `{"skipped": true, "reason": ...}` and contributes a flag. Never add an early `throw`/abort the spec does not call for.
- **Human-in-the-loop is mandatory.** These stages only produce flags and scores; nothing auto-approves or auto-rejects.
- **ASCII-only `print()`** in Python scripts (Windows cp1252 console).
- **Model weights are gitignored** (`python/models/`). Never `git add` anything under it.
- **`php artisan config:clear` before `php artisan test`** — a cached `bootstrap/cache/config.php` overrides phpunit's sqlite `:memory:` env and the suite then hits MySQL.
- **The FastAPI service has no `--reload`.** Python changes require restarting it: `python/env/Scripts/python.exe -m uvicorn api.main:app --port 7860` from `python/` (token `devtoken`, `/health` returns 200 after ~100s).
- **Run `vendor/bin/pint --dirty --format agent`** before committing any PHP change.

---

## File Structure

**Python — modified:**

| File | Responsibility after this plan |
|---|---|
| `python/api/config.py` | Adds the stamp-classifier weight path + its decision-boundary setting. |
| `python/api/registry.py` | Loads `stamp_classifier.pkl` once at startup, gated on the file existing. |
| `python/api/routers/stamp.py` | Always embeds the crop; runs the texture check before the reference gate; accepts a caller-supplied "why there is no reference" reason. |
| `python/api/routers/classify.py` | Adds `authenticity` (= 1 − P(fake)) to the Stage 3 result. |
| `python/api/routers/validate.py` | Owns issuer-reference resolution (national sentinel vs LGU city), city canonicalisation, and the new Stage 3 / Stage 4b flags. |
| `python/api/schemas.py` | Declares the new response fields. |
| `python/tests/test_api.py` | Contract tests for all of the above. |

**Laravel — modified:**

| File | Responsibility after this plan |
|---|---|
| `app/Services/Document/MlPipelineService.php` | Sends `issuer_scope` + the full city→vector reference map; persists `stamp_tampered`, `classification_authenticity`, and the `logo_references` row the API actually matched. |
| `app/Actions/ProcessDocumentAction.php` | Feeds `classification_authenticity` (not raw confidence) into the risk blend. |
| `app/Models/ValidationResult.php` | Fillable + cast for the new column. |
| `database/migrations/2026_08_12_000001_add_classification_authenticity_to_validation_results_table.php` | **Create** — the new column. |
| `tests/Feature/Document/MlPipelineServiceTest.php`, `tests/Feature/Document/ProcessDocumentActionTest.php` | Tests for the mapping and the risk input. |

---

## Task 1: Stage 4b tamper texture check always runs

**Files:**
- Modify: `python/api/config.py` (add settings field + property)
- Modify: `python/api/registry.py:22`, `python/api/registry.py:34-50` (register the classifier)
- Modify: `python/api/routers/stamp.py:75-101` (`run_stamp_verify`)
- Modify: `python/api/schemas.py:89-95` (`StampVerifyResponse`)
- Modify: `python/api/routers/validate.py:192-211` (Stage 4b block)
- Modify: `python/api/routers/stamp.py:160-171` (`/v1/stamp/verify` route passes the classifier)
- Modify: `app/Services/Document/MlPipelineService.php:219-242` (`mapStages` Stage 4b block)
- Test: `python/tests/test_api.py`, `tests/Feature/Document/MlPipelineServiceTest.php`

**Interfaces:**
- Consumes: `python/scripts/train_stamp.py`'s artefact — a pickled `sklearn.linear_model.LogisticRegression` fitted over 1280-D EfficientNet features with **class 1 = genuine wet ink, class 0 = reproduction/forgery**.
- Produces:
  - `run_tamper_check(classifier, vector: list[float], threshold: float) -> dict` returning `{"stamp_tampered": bool, "genuine_probability": float}`.
  - `run_stamp_verify(model, image, reference, settings, document_type=None, city=None, classifier=None, missing_reason="unreferenced_logo") -> dict` — the returned dict always carries `stamp_tampered` and `genuine_probability` keys (both `None` when the classifier is not loaded). Task 2 relies on the `missing_reason` parameter.
  - `Settings.stamp_classifier_path -> Path` and `Settings.stamp_tamper_threshold: float`.
  - Registry key `"stamp_classifier"`.
  - Flag string `stamp_tampered`.

- [ ] **Step 1: Write the failing test for the tamper verdict without a reference**

Add to `python/tests/test_api.py`, immediately after `class _StubEmbedderModel` (around line 486):

```python
class _StubStampClassifier:
    """sklearn-like binary classifier matching train_stamp.py's labelling:
    column 1 = P(genuine wet ink), column 0 = P(reproduction)."""

    def __init__(self, genuine_probability: float):
        self._genuine = genuine_probability

    def predict_proba(self, features):
        assert np.asarray(features).shape[0] == 1
        return np.array([[1.0 - self._genuine, self._genuine]])
```

Then add this test directly after `test_stamp_verify_without_reference_flags_unreferenced_logo`:

```python
def test_stamp_verify_runs_the_tamper_check_without_a_reference(tmp_path, jpeg_bytes):
    """§5 Stage 4b: 'Tamper check (always runs, reference or not)'. An issuer with
    no reference logo yet must still get the wet-ink-vs-reproduction verdict."""
    pytest.importorskip("tensorflow")  # efficientnet preprocess_input

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["stamp"] = _StubEmbedderModel()
        app.state.registry._models["stamp_classifier"] = _StubStampClassifier(0.10)

        body = client.post(
            "/v1/stamp/verify", headers=AUTH, files=_upload(jpeg_bytes),
            data={"document_type": "business_permit", "city": "Makati"},
        ).json()

    assert body["reason"] == "unreferenced_logo"      # unchanged
    assert body["similarity_score"] is None           # unchanged
    assert body["stamp_tampered"] is True
    assert body["genuine_probability"] == pytest.approx(0.10)
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py::test_stamp_verify_runs_the_tamper_check_without_a_reference -v`
Expected: FAIL with `KeyError: 'stamp_tampered'` (the response model has no such field).

- [ ] **Step 3: Add the classifier path and decision boundary to Settings**

In `python/api/config.py`, add this field right after `stamp_model_path` (line 38) — the `*_model_path` field / `*_path` property pairing is the convention every other weight follows:

```python
    stamp_classifier_model_path: Path | None = None  # default: MODEL_DIR/stamp_classifier.pkl
```

Add this tunable right after `stamp_similarity_threshold` (line 59):

```python
    # NOT a new §9 parameter. train_stamp.py fits a binary LogisticRegression
    # (class 1 = genuine wet ink, class 0 = photocopy/edit) whose own predict()
    # boundary is 0.50; this exposes that boundary so an operator can trade
    # false accepts against false rejects without retraining.
    stamp_tamper_threshold: float = 0.50
```

Add this property right after the `stamp_path` property (line 119):

```python
    @property
    def stamp_classifier_path(self) -> Path:
        # train_stamp.py's genuine/forged LogisticRegression over the 1280-D
        # EfficientNet features — the §5 Stage 4b texture check.
        return self.stamp_classifier_model_path or self.model_dir / "stamp_classifier.pkl"
```

- [ ] **Step 4: Register the classifier in the model registry**

In `python/api/registry.py`, change `MODEL_NAMES` (line 22) to:

```python
MODEL_NAMES = ("classifier", "detector", "siamese", "stamp", "stamp_classifier",
               "rapid_detector", "trocr", "trocr_accurate")
```

Add this entry to the `loaders` dict in `load_all()`, right after the `"stamp"` line (line 38):

```python
            "stamp_classifier": (str(self.settings.stamp_classifier_path),
                                 self._load_stamp_classifier),
```

Add this loader right after `_load_stamp` (line 141):

```python
    def _load_stamp_classifier(self) -> Any:
        # Trusted artefact: produced and consumed only by ADVS's own code
        # (train_stamp.py). Never unpickle a stamp_classifier.pkl from an
        # untrusted source.
        import pickle

        with open(self.settings.stamp_classifier_path, "rb") as fh:
            return pickle.load(fh)
```

- [ ] **Step 5: Always embed the crop and run the texture check**

In `python/api/routers/stamp.py`, replace `run_stamp_verify` (lines 75–101) with:

```python
def run_tamper_check(classifier, vector: list[float], threshold: float) -> dict:
    """EfficientNet texture verdict for one logo crop (§5 Stage 4b).

    train_stamp.py fits a LogisticRegression over the 1280-D feature vector with
    class 1 = genuine wet ink and class 0 = a photocopied/scanned/edited
    reproduction, so column 1 of predict_proba is the genuine probability.
    """
    import numpy as np

    probabilities = np.asarray(classifier.predict_proba(np.asarray([vector], dtype=np.float64)))
    genuine = float(probabilities[0][1])

    return {"stamp_tampered": genuine < threshold, "genuine_probability": round(genuine, 6)}


def run_stamp_verify(
    model,
    image: Image.Image,
    reference: list[float] | None,
    settings: Settings,
    document_type: str | None = None,
    city: str | None = None,
    classifier=None,
    missing_reason: str = "unreferenced_logo",
) -> dict:
    """Stage 4b: texture check first, issuer comparison second.

    The crop is ALWAYS embedded, reference or not — §5 Stage 4b runs the
    wet-ink-vs-reproduction check "even before any reference exists", so the
    fraud signal does not wait on an issuer being seeded. ``missing_reason``
    lets the caller say WHY there is no reference (no issuer seeded yet, or an
    LGU city that OCR could not read) without losing that check.
    """
    vector = embed_stamp(model, image)
    tamper = (
        run_tamper_check(classifier, vector, settings.stamp_tamper_threshold)
        if classifier is not None
        else {"stamp_tampered": None, "genuine_probability": None}
    )
    base = {"document_type": document_type, "city": city, **tamper}

    if reference is None:
        return {**base, "match": False, "reason": missing_reason,
                "similarity_score": None, "threshold": None}

    emb.require_same_length(reference, vector, "reference_vector")

    similarity = emb.cosine_similarity(vector, reference)
    threshold = settings.resolved_stamp_similarity_threshold()

    return {
        **base,
        "match": similarity >= threshold,
        "similarity_score": similarity,
        "threshold": threshold,
        "reason": None,
    }
```

- [ ] **Step 6: Declare the new response fields**

In `python/api/schemas.py`, replace `class StampVerifyResponse` (lines 89–95) with:

```python
class StampVerifyResponse(BaseModel):
    match: bool
    similarity_score: float | None = None
    threshold: float | None = None
    reason: str | None = None
    document_type: str | None = None
    city: str | None = None
    # §5 Stage 4b texture check — runs reference or not. Both are None when
    # stamp_classifier.pkl is not loaded (the check could not run at all).
    stamp_tampered: bool | None = None
    genuine_probability: float | None = None
```

- [ ] **Step 7: Pass the classifier from the /v1/stamp/verify route**

In `python/api/routers/stamp.py`, replace the body of the `stamp_verify` route (lines 168–171) with:

```python
    model = request.app.state.registry.require("stamp")
    reference = emb.parse_reference(reference_vector, "reference_vector") if reference_vector else None
    image = await _read_crop(file)

    return run_stamp_verify(
        model, image, reference, request.app.state.settings, document_type, city,
        classifier=request.app.state.registry.get("stamp_classifier"),
    )
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py::test_stamp_verify_runs_the_tamper_check_without_a_reference -v`
Expected: PASS.

- [ ] **Step 9: Fix the pre-existing test that no longer holds**

`test_stamp_verify_without_reference_flags_unreferenced_logo` previously never reached the embedder, so it lacks a TensorFlow guard. It now embeds. In `python/tests/test_api.py`, add the guard as the first line of that test's body and assert the null verdict:

```python
def test_stamp_verify_without_reference_flags_unreferenced_logo(tmp_path, jpeg_bytes):
    pytest.importorskip("tensorflow")  # the crop is now always embedded

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["stamp"] = _StubEmbedderModel()
        body = client.post(
            "/v1/stamp/verify", headers=AUTH, files=_upload(jpeg_bytes),
            data={"document_type": "business_permit", "city": "Makati"},
        ).json()

    assert body["match"] is False
    assert body["reason"] == "unreferenced_logo"
    assert body["similarity_score"] is None
    assert body["city"] == "Makati"
    # No stamp_classifier loaded -> the check could not run; not "clean".
    assert body["stamp_tampered"] is None
```

Then update `test_health_is_open_and_reports_missing_models` — the registry now reports one more model. Change the expected set to:

```python
    assert set(body["models"]) == {
        "classifier", "detector", "siamese", "stamp", "stamp_classifier",
        "rapid_detector", "trocr", "trocr_accurate",
    }
    # File/dir-gated models: an empty tmp model_dir means none of these are
    # configured, so all report "not loaded" the same way.
    for name in ("classifier", "detector", "siamese", "stamp", "stamp_classifier",
                 "trocr", "trocr_accurate"):
```

- [ ] **Step 10: Run the whole API test file to verify nothing else regressed**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -v`
Expected: all PASS.

- [ ] **Step 11: Write the failing test for the pipeline-level flag**

Add to `python/tests/test_api.py`, in the validate section after `test_validate_is_fail_forward_without_models`:

```python
class _StubBox:
    def __init__(self, cls: int, conf: float, xyxy: list[float]):
        self.cls = cls
        self.conf = conf
        # ultralytics hands back a tensor/array row, and run_detection calls
        # .tolist() on it — a bare list would not survive that.
        self.xyxy = [np.asarray(xyxy)]


class _StubResult:
    def __init__(self, names: dict[int, str], boxes: list[_StubBox]):
        self.names = names
        self.boxes = boxes


class _StubDetector:
    """Just enough of the ultralytics YOLO surface for run_detection."""

    names = {0: "signature", 1: "stamp"}

    def predict(self, source=None, conf=0.0, verbose=False):
        return [_StubResult(self.names, [_StubBox(1, 0.9, [5.0, 5.0, 60.0, 60.0])])]


def test_validate_flags_a_tampered_stamp(tmp_path, jpeg_bytes):
    """A reproduction detected on the crop must reach the officer as a flag even
    though the issuer has no reference logo yet (§5 Stage 4b)."""
    pytest.importorskip("tensorflow")

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["detector"] = _StubDetector()
        app.state.registry._models["stamp"] = _StubEmbedderModel()
        app.state.registry._models["stamp_classifier"] = _StubStampClassifier(0.05)

        body = client.post("/v1/validate", headers=AUTH, files=_upload(jpeg_bytes)).json()

    assert body["stages"]["stamp"]["stamp_tampered"] is True
    assert "stamp_tampered" in body["flags"]
    assert "unreferenced_logo" in body["flags"]
```

- [ ] **Step 12: Run it to verify it fails**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py::test_validate_flags_a_tampered_stamp -v`
Expected: FAIL — `assert None is True` (validate.py does not pass the classifier), or `KeyError: 'stamp_tampered'`.

- [ ] **Step 13: Wire the classifier and the flag into validate.py**

In `python/api/routers/validate.py`, replace the Stage 4b `else` branch body (lines 201–211) with:

```python
        else:
            try:
                stages["stamp"] = run_stamp_verify(
                    stamp_model, _crop(first_page, stamp_box["box"]),
                    logo_reference, settings, document_type, city,
                    classifier=registry.get("stamp_classifier"),
                )
                if stages["stamp"].get("reason"):
                    flags.append(stages["stamp"]["reason"])
                # A reproduction is a fraud signal in its own right, independent
                # of whether an issuer reference existed to compare against.
                if stages["stamp"].get("stamp_tampered") is True:
                    flags.append("stamp_tampered")
            except Exception as exc:
                logger.exception("stamp stage failed")
                stages["stamp"] = _skipped(f"error: {exc}")
```

- [ ] **Step 14: Run the test to verify it passes**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -v`
Expected: all PASS.

- [ ] **Step 15: Write the failing Laravel test for persisting the verdict**

Add to `tests/Feature/Document/MlPipelineServiceTest.php`, in the `mapStages()` section:

```php
    public function test_persists_the_stage_4b_tamper_verdict_without_an_issuer_reference(): void
    {
        $stages = [
            'stamp' => [
                'match' => false,
                'reason' => 'unreferenced_logo',
                'similarity_score' => null,
                'stamp_tampered' => true,
                'genuine_probability' => 0.05,
            ],
        ];

        $mapped = $this->service()->mapStages($stages, ['issuer_scope' => 'lgu']);

        $this->assertTrue($mapped['columns']['stamp_tampered']);
        $this->assertFalse($mapped['columns']['stamp_detected']);
        $this->assertContains('unreferenced_logo', $mapped['flags']);
    }

    public function test_leaves_the_tamper_verdict_untouched_when_the_classifier_did_not_run(): void
    {
        $stages = [
            'stamp' => [
                'match' => true, 'similarity_score' => 0.95, 'reason' => null,
                'stamp_tampered' => null, 'genuine_probability' => null,
            ],
        ];

        $mapped = $this->service()->mapStages($stages, ['issuer_scope' => 'national']);

        // Null is "could not run", not "clean" — never overwrite an earlier verdict.
        $this->assertArrayNotHasKey('stamp_tampered', $mapped['columns']);
    }
```

- [ ] **Step 16: Run them to verify they fail**

Run: `php artisan config:clear && php artisan test --compact --filter=MlPipelineServiceTest`
Expected: FAIL — `Undefined array key "stamp_tampered"` on the first test.

- [ ] **Step 17: Map the verdict onto the column**

In `app/Services/Document/MlPipelineService.php`, insert this immediately after `$issuerScope = $context['issuer_scope'] ?? null;` (line 221) — before the `if ($this->ran($stamp) …)` branch, so it applies whether or not a reference existed:

```php
        // §5 Stage 4b's texture check runs reference or not, so its verdict is
        // read before the reference branch below. Null means the classifier
        // could not run — leave the column alone rather than recording "clean".
        if ($this->ran($stamp) && ($stamp['stamp_tampered'] ?? null) !== null) {
            $columns['stamp_tampered'] = (bool) $stamp['stamp_tampered'];
        }
```

- [ ] **Step 18: Run the Laravel tests to verify they pass**

Run: `php artisan config:clear && php artisan test --compact --filter=MlPipelineServiceTest`
Expected: all PASS.

- [ ] **Step 19: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add python/api/config.py python/api/registry.py python/api/routers/stamp.py python/api/routers/validate.py python/api/schemas.py python/tests/test_api.py app/Services/Document/MlPipelineService.php tests/Feature/Document/MlPipelineServiceTest.php
git commit -m "Run the Stage 4b stamp tamper check with or without a reference"
```

---

## Task 2: LGU issuer logos resolved by the OCR-detected city

**Files:**
- Modify: `python/api/routers/validate.py:78-96` (form signature), `:126-153` (OCR block), `:192-211` (Stage 4b block); add module-level helpers
- Test: `python/tests/test_api.py`

**Interfaces:**
- Consumes: `run_stamp_verify(..., missing_reason=...)` from Task 1.
- Produces:
  - `canonical_city(value: str | None) -> str | None` — squish + title-case, the single canonical form shared with Laravel's `Str::title(Str::squish(...))`.
  - `parse_reference_map(raw: str | None) -> dict[str, list[float]]` — parses the `stamp_references` form field, keys canonicalised, `""` = the national sentinel.
  - `resolve_issuer_reference(references, single, issuer_scope, city) -> tuple[list[float] | None, str | None]` — returns `(vector, flag)`; flag is `"city_not_identified"` or `None`.
  - Two new `/v1/validate` form fields: `issuer_scope` (`"national"` | `"lgu"` | absent) and `stamp_references` (JSON object `{city: vector}`).
  - `stages["stamp"]["city"]` now reports the **resolved** city (caller-supplied, else OCR-detected) — Task 3's Laravel step reads it to pick the matched `logo_references` row.
  - Flag string `city_not_identified`.

- [ ] **Step 1: Write the failing tests for the pure resolution helpers**

Add to `python/tests/test_api.py`, in the validate section:

```python
def test_canonical_city_matches_the_form_laravel_stores():
    from api.routers.validate import canonical_city

    assert canonical_city("CITY OF DIGOS") == "City Of Digos"
    assert canonical_city("City of  Digos ") == "City Of Digos"
    assert canonical_city("") is None
    assert canonical_city(None) is None


def test_resolve_issuer_reference_uses_the_national_sentinel():
    """logo_references.city is '' for a national issuer (one logo agency-wide),
    so the city read off the page is irrelevant to the lookup (§5 Stage 4b)."""
    from api.routers.validate import resolve_issuer_reference

    references = {"": [1.0, 2.0], "Pasig": [3.0, 4.0]}

    vector, flag = resolve_issuer_reference(references, None, "national", "Pasig")

    assert vector == [1.0, 2.0]
    assert flag is None


def test_resolve_issuer_reference_scopes_an_lgu_issuer_by_city():
    from api.routers.validate import resolve_issuer_reference

    references = {"Pasig": [3.0, 4.0], "Quezon City": [5.0, 6.0]}

    assert resolve_issuer_reference(references, None, "lgu", "quezon  CITY")[0] == [5.0, 6.0]
    # An LGU city with no seeded reference yet is a miss, not a mis-scope.
    assert resolve_issuer_reference(references, None, "lgu", "Makati") == (None, None)


def test_resolve_issuer_reference_flags_an_unreadable_lgu_city():
    """§5 Stage 4b failure path: 'City not identified' — the lookup cannot be
    scoped, so verification is skipped rather than compared to a wrong city."""
    from api.routers.validate import resolve_issuer_reference

    assert resolve_issuer_reference({"Pasig": [3.0]}, None, "lgu", None) == (None, "city_not_identified")
```

- [ ] **Step 2: Run them to verify they fail**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -k "canonical_city or resolve_issuer_reference" -v`
Expected: FAIL with `ImportError: cannot import name 'canonical_city' from 'api.routers.validate'`.

- [ ] **Step 3: Implement the helpers**

In `python/api/routers/validate.py`, add these after `_parse_forensics` (line 57):

```python
def canonical_city(value: str | None) -> str | None:
    """The single canonical form of a city name, shared with Laravel.

    ``logo_references.city`` is half of the unique ``(document_type_id, city)``
    key and Laravel writes it as ``Str::title(Str::squish($city))``, so "CITY OF
    DIGOS" and "City of  Digos" must resolve to one issuer here too. Returns
    ``None`` for an empty read — note that ``''`` is NOT "no city", it is the
    national-issuer sentinel.
    """
    if value is None:
        return None
    squished = " ".join(str(value).split())

    return squished.title() if squished else None


def parse_reference_map(raw: str | None) -> dict[str, list[float]]:
    """Every reference logo the caller holds for this issuer, keyed by city.

    An ``lgu`` issuer's city is printed on the document, so it does not exist
    until Stage 2 runs INSIDE this call — the caller therefore sends the whole
    set and this service picks with the city it read. The ``''`` key is the
    national sentinel, exactly as stored in ``logo_references.city``.
    """
    if not raw:
        return {}
    try:
        parsed = json.loads(raw)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=f"Invalid stamp_references: {exc}") from exc
    if not isinstance(parsed, dict):
        raise HTTPException(status_code=422, detail="stamp_references must be a JSON object.")

    return {
        (canonical_city(key) or ""): [float(v) for v in value]
        for key, value in parsed.items()
        if isinstance(value, list) and value
    }


def resolve_issuer_reference(
    references: dict[str, list[float]],
    single: list[float] | None,
    issuer_scope: str | None,
    city: str | None,
) -> tuple[list[float] | None, str | None]:
    """The issuer's reference logo for this document (§5 Stage 4b), or why not.

    Returns ``(vector, flag)``. ``flag`` is ``city_not_identified`` when an LGU
    document's issuing city could not be read: the lookup cannot be scoped, so
    the comparison is skipped rather than run against another city's seal. A
    ``None`` vector with no flag simply means this issuer has no reference yet.
    """
    if issuer_scope == "lgu":
        key = canonical_city(city)
        if key is None:
            return None, "city_not_identified"

        return references.get(key), None

    # national (the '' sentinel) or an unscoped caller; `single` is the legacy
    # one-vector form of the same thing.
    return references.get("", single), None
```

- [ ] **Step 4: Run them to verify they pass**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -k "canonical_city or resolve_issuer_reference" -v`
Expected: all PASS.

- [ ] **Step 5: Write the failing test for the routing through /v1/validate**

Add to `python/tests/test_api.py` (it reuses `_StubDetector`, `_StubEmbedderModel` from Task 1):

```python
def test_validate_verifies_an_lgu_logo_against_the_matching_city_reference(tmp_path, jpeg_bytes):
    """The whole city→vector set travels with the request; this service picks
    the one for the document's city (§5 Stage 2 → Stage 4b)."""
    pytest.importorskip("tensorflow")

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["detector"] = _StubDetector()
        app.state.registry._models["stamp"] = _StubEmbedderModel()

        vector = client.post(
            "/v1/stamp/embed", headers=AUTH, files=_upload(jpeg_bytes)
        ).json()["vector"]

        body = client.post(
            "/v1/validate", headers=AUTH, files=_upload(jpeg_bytes),
            data={
                "issuer_scope": "lgu",
                "city": "PASIG  CITY",
                "stamp_references": json.dumps({
                    "Pasig City": vector,
                    "Makati": [-v for v in vector],
                }),
            },
        ).json()

    stamp = body["stages"]["stamp"]
    assert stamp["city"] == "Pasig City"
    assert stamp["match"] is True
    assert stamp["similarity_score"] == pytest.approx(1.0)
    assert "unreferenced_logo" not in body["flags"]


def test_validate_flags_an_lgu_document_with_no_readable_city(tmp_path, jpeg_bytes):
    """§5 Stage 4b: no city → 'City not identified'; the tamper check still ran."""
    pytest.importorskip("tensorflow")

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["detector"] = _StubDetector()
        app.state.registry._models["stamp"] = _StubEmbedderModel()

        body = client.post(
            "/v1/validate", headers=AUTH, files=_upload(jpeg_bytes),
            data={"issuer_scope": "lgu",
                  "stamp_references": json.dumps({"Pasig City": [1.0, 2.0, 3.0]})},
        ).json()

    assert body["stages"]["stamp"]["reason"] == "city_not_identified"
    assert "city_not_identified" in body["flags"]
    assert "stamp_tampered" in body["stages"]["stamp"]  # the check still ran


def test_validate_rejects_a_malformed_reference_map(client, jpeg_bytes):
    response = client.post(
        "/v1/validate", headers=AUTH, files=_upload(jpeg_bytes),
        data={"stamp_references": "[1, 2, 3]"},
    )
    assert response.status_code == 422
```

- [ ] **Step 6: Run them to verify they fail**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -k "lgu or malformed_reference_map" -v`
Expected: FAIL — the extra form fields are ignored, so `stamp["city"]` is `None` and `reason` is `unreferenced_logo`.

- [ ] **Step 7: Accept the new form fields**

In `python/api/routers/validate.py`, add these two parameters to the `validate` signature, after `city` (line 84):

```python
    issuer_scope: str | None = Form(None),
    stamp_references: str | None = Form(None),
```

Then replace the reference-parsing lines (93–94) with:

```python
    sig_reference = emb.parse_reference(signature_reference, "signature_reference") if signature_reference else None
    single_logo_reference = emb.parse_reference(stamp_reference, "stamp_reference") if stamp_reference else None
    logo_references = parse_reference_map(stamp_references)
```

- [ ] **Step 8: Resolve the city from OCR and route Stage 4b through it**

In `python/api/routers/validate.py`, add this immediately after the Stage 2 `try/except` block (after line 153, before the `# ── Stage 4: detection` comment):

```python
        # §5 Stage 2 → 4b: the issuing city is printed on the document, so an
        # LGU issuer can only be scoped after OCR has read it. An explicit
        # caller value wins (the '' national sentinel canonicalises to None).
        detected_city = canonical_city(ocr_context.get("fields", {}).get("city_issued"))
        resolved_city = canonical_city(city) or detected_city
```

Then replace the Stage 4b block (lines 192–211, as left by Task 1) with:

```python
        # ── Stage 4b: issuer logo verify ───────────────────────────────────
        stamp_model = registry.get("stamp")
        stamp_box = _best_box(detections, ("stamp", "logo"))
        logo_reference, reference_flag = resolve_issuer_reference(
            logo_references, single_logo_reference, issuer_scope, resolved_city
        )
        if stamp_model is None:
            stages["stamp"] = _skipped("model_not_loaded")
        elif not stages.get("detection") or stages["detection"].get("skipped"):
            stages["stamp"] = _skipped("detection_unavailable")
        elif stamp_box is None:
            stages["stamp"] = _skipped("no_stamp_detected")
        else:
            try:
                stages["stamp"] = run_stamp_verify(
                    stamp_model, _crop(first_page, stamp_box["box"]),
                    logo_reference, settings, document_type, resolved_city,
                    classifier=registry.get("stamp_classifier"),
                    # Why there is no reference: an unseeded issuer, or an LGU
                    # city OCR could not read. Either way the texture check above
                    # still runs — only the comparison is skipped.
                    missing_reason=reference_flag or "unreferenced_logo",
                )
                if stages["stamp"].get("reason"):
                    flags.append(stages["stamp"]["reason"])
                if stages["stamp"].get("stamp_tampered") is True:
                    flags.append("stamp_tampered")
            except Exception as exc:
                logger.exception("stamp stage failed")
                stages["stamp"] = _skipped(f"error: {exc}")
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -v`
Expected: all PASS.

- [ ] **Step 10: Commit**

```bash
git add python/api/routers/validate.py python/tests/test_api.py
git commit -m "Scope Stage 4b logo lookup by the city OCR reads off the page"
```

---

## Task 3: Laravel sends every issuer reference and records the matched one

**Files:**
- Modify: `app/Services/Document/MlPipelineService.php:39-83` (`validate`), `:219-242` (`mapStages` Stage 4b), `:362-384` (`resolveLogoReference` → `resolveLogoReferences`)
- Test: `tests/Feature/Document/MlPipelineServiceTest.php`

**Interfaces:**
- Consumes: Task 2's `issuer_scope` + `stamp_references` form fields and the resolved `stages.stamp.city`.
- Produces:
  - `MlPipelineService::validate()` returns `context` as `array{issuer_scope: string|null, logo_reference_ids: array<string, int>}` — **the `logo_reference_id` key is replaced by `logo_reference_ids`** (city ⇒ row id).
  - `mapStages($stages, $context)` reads `$context['logo_reference_ids']`.

- [ ] **Step 1: Write the failing test for sending the whole reference set**

Add to `tests/Feature/Document/MlPipelineServiceTest.php`:

```php
    public function test_sends_every_city_reference_for_an_lgu_issuer(): void
    {
        Http::fake(['*/v1/validate' => Http::response(['stages' => [], 'flags' => []])]);

        $typeId = DB::table('document_types')->insertGetId([
            'name' => 'Business Permit', 'code' => 'business_permit',
            'issuer_scope' => 'lgu', 'is_required' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['Pasig City', [0.1, 0.2]], ['Makati', [0.3, 0.4]]] as [$city, $vector]) {
            DB::table('logo_references')->insert([
                'document_type_id' => $typeId, 'city' => $city, 'label' => $city,
                'feature_vector' => json_encode($vector),
                // NOT NULL with no default (migration 2026_06_26_160503).
                'reference_image_path' => 'refs/'.Str::slug($city).'.png',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->service()->validate($this->document(['document_type_id' => $typeId]));

        // multipartField() is this file's existing helper for reading one
        // form-data field out of the raw Guzzle body.
        Http::assertSent(function (Request $request) {
            $body = $request->body();

            // == not ===: the rows come back city-ascending off the
            // unique(document_type_id, city) index, and key order is not part
            // of the contract — the API looks the map up by key.
            return $this->multipartField($body, 'issuer_scope') === 'lgu'
                && json_decode($this->multipartField($body, 'stamp_references'), true)
                    == ['Pasig City' => [0.1, 0.2], 'Makati' => [0.3, 0.4]];
        });
    }

    public function test_logo_reference_id_follows_the_city_the_api_matched(): void
    {
        $stages = [
            'stamp' => ['match' => true, 'similarity_score' => 0.95, 'reason' => null,
                'city' => 'Pasig City', 'stamp_tampered' => false],
        ];

        $mapped = $this->service()->mapStages($stages, [
            'issuer_scope' => 'lgu',
            'logo_reference_ids' => ['Pasig City' => 7, 'Makati' => 9],
        ]);

        $this->assertSame(7, $mapped['columns']['logo_reference_id']);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan config:clear && php artisan test --compact --filter=MlPipelineServiceTest`
Expected: FAIL — `stamp_references` is not sent (undefined key), and `logo_reference_id` is `null`.

- [ ] **Step 3: Replace the single-reference lookup with the full set**

In `app/Services/Document/MlPipelineService.php`, replace `resolveLogoReference` (lines 362–384) with:

```php
    /**
     * Every reference logo this issuer has, keyed by city, plus each row's id.
     *
     * A `national` issuer has exactly one row under the `''` city sentinel; an
     * `lgu` issuer has one per city, and which one applies depends on the city
     * printed on the document — which does not exist until Stage 2 OCR runs,
     * inside the same API call. So the whole set travels with the request and
     * the API picks; {@see matchedLogoReferenceId()} then records which.
     *
     * @return array{0: string|null, 1: array<string, int>} [JSON {city: vector}, city => id]
     */
    private function resolveLogoReferences(?int $documentTypeId, ?string $issuerScope): array
    {
        if ($documentTypeId === null || $issuerScope === null) {
            return [null, []];
        }

        $vectors = [];
        $ids = [];

        $rows = DB::table('logo_references')
            ->where('document_type_id', $documentTypeId)
            ->whereNotNull('feature_vector')
            ->get(['id', 'city', 'feature_vector']);

        foreach ($rows as $row) {
            $vector = json_decode((string) $row->feature_vector, true);
            if (! is_array($vector) || $vector === []) {
                continue;
            }
            $vectors[$row->city] = $vector;
            $ids[$row->city] = (int) $row->id;
        }

        return [$vectors === [] ? null : json_encode($vectors, JSON_THROW_ON_ERROR), $ids];
    }

    /**
     * The `logo_references` row the API actually compared against: the `''`
     * sentinel row for a national issuer, or the row for the city Stage 2 read.
     *
     * @param  array<string, int>  $ids  city => logo_references.id
     */
    private function matchedLogoReferenceId(array $ids, ?string $issuerScope, ?string $city): ?int
    {
        if ($issuerScope === 'national') {
            return $ids[''] ?? null;
        }

        $key = Str::title(Str::squish((string) $city));

        return $key === '' ? null : ($ids[$key] ?? null);
    }
```

- [ ] **Step 4: Send the set and the issuer scope**

In `app/Services/Document/MlPipelineService.php`, replace lines 48–59 with:

```php
        [$stampReferences, $logoReferenceIds] = $this->resolveLogoReferences(
            $document->document_type_id, $issuerScope
        );

        $form = array_filter([
            'template' => $this->ocrTemplateFor($type['code'] ?? null),
            'document_type' => $type['code'] ?? null,
            // The API needs the scope to know whether to key the lookup by city.
            'issuer_scope' => $issuerScope,
            'city' => $city,
            'signature_reference' => $this->resolveSignatureReference($document),
            'stamp_references' => $stampReferences,
            'forensics' => json_encode($this->forensicsContext(), JSON_THROW_ON_ERROR),
        ], static fn ($value): bool => $value !== null);
```

Then replace the `context` in the return (line 81) with:

```php
            'context' => ['issuer_scope' => $issuerScope, 'logo_reference_ids' => $logoReferenceIds],
```

- [ ] **Step 5: Record which reference matched**

In `app/Services/Document/MlPipelineService.php`, inside the `mapStages` Stage 4b `if` branch, replace the `logo_reference_id` line (line 228) with:

```php
            $columns['logo_reference_id'] = $this->matchedLogoReferenceId(
                $context['logo_reference_ids'] ?? [], $issuerScope, $stamp['city'] ?? null
            );
```

- [ ] **Step 6: Update the pre-existing tests that used the old context shape**

In `tests/Feature/Document/MlPipelineServiceTest.php`, `test_maps_clean_stages_to_columns` passes `['issuer_scope' => 'national', 'logo_reference_id' => 42]`. Change only that call to the new shape:

```php
        $mapped = $this->service()->mapStages($stages, [
            'issuer_scope' => 'national',
            'logo_reference_ids' => ['' => 42],
        ]);
```

Do **not** add a `'city'` key to that fixture. `matchedLogoReferenceId()` short-circuits on `issuer_scope === 'national'` before it reads the city, so `$stamp['city'] ?? null` being absent is correct — and it matches reality: Task 2 canonicalises the `''` national sentinel to `None`, so the API reports `city: null` for a national issuer, not `''`.

Then convert every other `mapStages(...)` context argument the same way (`['' => N]` for `national`, `['<City>' => N]` for `lgu`):

```bash
grep -rn "logo_reference_id" tests/ app/
```

Also update `test_validate_sends_issuer_references_for_a_national_type` in the same file — the national vector now travels inside the `{"": [...]}` map, so amend the stale comment and assert the new field:

```php
            return str_contains($body, 'name="document_type"')
                && str_contains($body, 'bir_permit')
                && str_contains($body, '[0.1,0.2,0.3]')   // signature_reference
                && str_contains($body, '{"":[0.4,0.5]}')  // stamp_references, '' = national sentinel
                && $this->multipartField($body, 'issuer_scope') === 'national'
                && str_contains($body, 'name="forensics"');
```

- [ ] **Step 7: Run the Laravel tests to verify they pass**

Run: `php artisan config:clear && php artisan test --compact --filter="MlPipelineServiceTest|ProcessDocumentActionTest|EnrollReferenceJobTest"`
Expected: all PASS.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Document/MlPipelineService.php tests/Feature/Document/MlPipelineServiceTest.php
git commit -m "Send every issuer logo reference so LGU permits verify by city"
```

---

## Task 4: A document the classifier calls `fake` raises risk instead of lowering it

**Files:**
- Modify: `python/api/routers/classify.py:19-54` (`run_classification`)
- Modify: `python/api/schemas.py:26-31` (`ClassifyResponse`)
- Modify: `python/api/routers/validate.py:108-124` (Stage 3 block); add a module-level helper
- Create: `database/migrations/2026_08_12_000001_add_classification_authenticity_to_validation_results_table.php`
- Modify: `app/Models/ValidationResult.php` (fillable + casts)
- Modify: `app/Services/Document/MlPipelineService.php:161-166` (`mapStages` Stage 3 block)
- Modify: `app/Actions/ProcessDocumentAction.php:75-82` (risk components)
- Test: `python/tests/test_api.py`, `tests/Feature/Document/MlPipelineServiceTest.php`, `tests/Feature/Document/ProcessDocumentActionTest.php`

**Interfaces:**
- Consumes: `python/models/class_names.json` = `["bir_certificate", "business_permit", "dti_registration", "fake"]`; `document_types.code` uses those same strings for the three real types.
- Produces:
  - `run_classification(...)` result gains `"authenticity": float` = `1.0 - P(fake)` (equal to `confidence` when the model has no `fake` class).
  - `classification_flags(stage: dict, document_type: str | None, class_names: list[str]) -> list[str]` in `validate.py`.
  - Flag strings `classified_as_fake`, `document_type_mismatch`.
  - `validation_results.classification_authenticity` (nullable float).

- [ ] **Step 1: Write the failing test for the authenticity score**

Add to `python/tests/test_api.py`, in the classify section:

```python
def _classifier_entry(probabilities: list[float], class_names: list[str]) -> dict:
    class _StubModel:
        input_shape = (None, 64, 64, 3)

        def predict(self, batch, verbose=0):
            return np.array([probabilities])

    return {"model": _StubModel(), "class_names": class_names}


CLASSES = ["bir_certificate", "business_permit", "dti_registration", "fake"]


def test_classification_authenticity_discounts_the_fake_probability(tmp_path, jpeg_bytes):
    """A confident 'fake' must not reach the risk blend as high authenticity —
    the classification component is (1 - risk), so P(fake) is what matters."""
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["classifier"] = _classifier_entry(
            [0.01, 0.01, 0.00, 0.98], CLASSES
        )
        body = client.post("/v1/classify", headers=AUTH, files=_upload(jpeg_bytes)).json()

    assert body["label"] == "fake"
    assert body["confidence"] == pytest.approx(0.98)
    assert body["authenticity"] == pytest.approx(0.02)


def test_classification_authenticity_equals_confidence_without_a_fake_class(tmp_path, jpeg_bytes):
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["classifier"] = _classifier_entry(
            [0.15, 0.85], ["bir_certificate", "business_permit"]
        )
        body = client.post("/v1/classify", headers=AUTH, files=_upload(jpeg_bytes)).json()

    assert body["authenticity"] == pytest.approx(1.0)
```

- [ ] **Step 2: Run them to verify they fail**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -k "authenticity" -v`
Expected: FAIL with `KeyError: 'authenticity'`.

- [ ] **Step 3: Compute authenticity in run_classification**

In `python/api/routers/classify.py`, replace the return statement (lines 45–54) with:

```python
    # The classifier's `fake` class IS the fraud signal, so the Stage 3
    # component the risk blend consumes is "probability this is a genuine
    # document of a known type" — not the winning class's confidence, which is
    # just as high for a confidently-detected forgery.
    fake_index = class_names.index("fake") if "fake" in class_names else None
    fake_probability = (
        float(probabilities[fake_index])
        if fake_index is not None and fake_index < len(probabilities)
        else 0.0
    )

    return {
        "label": label,
        "confidence": confidence,
        "authenticity": round(1.0 - fake_probability, 6),
        "probabilities": {
            class_names[i] if i < len(class_names) else str(i): float(score)
            for i, score in enumerate(probabilities)
        },
        "threshold": threshold,
        "passed_threshold": confidence >= threshold,
    }
```

In `python/api/schemas.py`, replace `class ClassifyResponse` (lines 26–31) with:

```python
class ClassifyResponse(BaseModel):
    label: str
    confidence: float
    # 1 - P(fake): the Stage 3 authenticity the risk blend consumes. Equals
    # confidence when the model has no `fake` class.
    authenticity: float
    probabilities: dict[str, float]
    threshold: float
    passed_threshold: bool
```

- [ ] **Step 4: Run them to verify they pass**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -k "authenticity" -v`
Expected: PASS.

- [ ] **Step 5: Write the failing tests for the Stage 3 flags**

Add to `python/tests/test_api.py`:

```python
def test_classification_flags_a_fake_verdict():
    from api.routers.validate import classification_flags

    stage = {"label": "fake", "confidence": 0.98, "passed_threshold": True}

    assert classification_flags(stage, "bir_certificate", CLASSES) == ["classified_as_fake"]


def test_classification_flags_a_confident_disagreement_with_the_declared_type():
    from api.routers.validate import classification_flags

    stage = {"label": "business_permit", "confidence": 0.93, "passed_threshold": True}

    assert classification_flags(stage, "bir_certificate", CLASSES) == ["document_type_mismatch"]


def test_classification_does_not_flag_a_type_the_model_never_learned():
    """sanitary_permit has no class, so the model CANNOT agree with it — that is
    an untrained type, not vendor misdeclaration."""
    from api.routers.validate import classification_flags

    stage = {"label": "business_permit", "confidence": 0.93, "passed_threshold": True}

    assert classification_flags(stage, "sanitary_permit", CLASSES) == []


def test_classification_does_not_flag_a_mismatch_it_is_unsure_about():
    from api.routers.validate import classification_flags

    stage = {"label": "business_permit", "confidence": 0.41, "passed_threshold": False}

    assert classification_flags(stage, "bir_certificate", CLASSES) == []
```

- [ ] **Step 6: Run them to verify they fail**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -k "classification_flags or classification_does_not_flag" -v`
Expected: FAIL with `ImportError: cannot import name 'classification_flags'`.

- [ ] **Step 7: Implement the flag helper and wire it in**

In `python/api/routers/validate.py`, add this after `resolve_issuer_reference`:

```python
def classification_flags(stage: dict, document_type: str | None,
                         class_names: list[str]) -> list[str]:
    """Stage 3 signals beyond the confidence gate (§5 Stage 3).

    ``classified_as_fake``     — the model's fraud class won the softmax. Raised
                                 at any confidence: a forgery verdict is news
                                 whether or not it cleared the type-confidence
                                 gate.
    ``document_type_mismatch`` — a CONFIDENT prediction disagrees with the type
                                 the vendor declared. Only meaningful for a
                                 declared type the model was trained on; a type
                                 outside ``class_names`` (sanitary permit, an
                                 ID, a contract) can never match, and flagging
                                 it would punish the vendor for a gap in the
                                 training corpus.
    """
    flags = []
    label = stage.get("label")

    if label == "fake":
        flags.append("classified_as_fake")
    elif (
        stage.get("passed_threshold")
        and document_type is not None
        and document_type in class_names
        and label != document_type
    ):
        flags.append("document_type_mismatch")

    return flags
```

Then in the Stage 3 block, replace lines 115–121 with:

```python
            try:
                stage = run_classification(
                    classifier, first_page, settings.classification_confidence_threshold
                )
                stages["classification"] = stage
                if not stage["passed_threshold"]:
                    flags.append("low_classification_confidence")
                flags.extend(classification_flags(
                    stage, document_type, classifier.get("class_names") or []
                ))
```

- [ ] **Step 8: Run them to verify they pass**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -v`
Expected: all PASS.

- [ ] **Step 9: Commit the Python half**

```bash
git add python/api/routers/classify.py python/api/routers/validate.py python/api/schemas.py python/tests/test_api.py
git commit -m "Flag fake and type-mismatched documents in Stage 3"
```

- [ ] **Step 10: Write the failing Laravel test for the risk input**

Add to `tests/Feature/Document/MlPipelineServiceTest.php`:

```php
    public function test_maps_classification_authenticity_separately_from_confidence(): void
    {
        $stages = [
            'classification' => ['label' => 'fake', 'confidence' => 0.98,
                'authenticity' => 0.02, 'passed_threshold' => true],
        ];

        $columns = $this->service()->mapStages($stages)['columns'];

        // The drill-down still shows what the model was confident ABOUT...
        $this->assertEqualsWithDelta(0.98, $columns['classification_confidence'], 1e-6);
        // ...while the risk blend gets 1 - P(fake).
        $this->assertEqualsWithDelta(0.02, $columns['classification_authenticity'], 1e-6);
    }
```

Add to `tests/Feature/Document/ProcessDocumentActionTest.php` (it uses that file's existing `document()`, `fakeMl()`, and `cleanStages()` helpers, so no new imports are needed):

```php
    public function test_a_document_the_classifier_calls_fake_outranks_a_genuine_one_on_risk(): void
    {
        $genuine = $this->document();
        $this->fakeMl($this->cleanStages());
        $clean = app(ProcessDocumentAction::class)->execute($genuine->fresh());

        $suspect = $this->document();
        $this->fakeMl($this->cleanStages(['classification' => [
            'label' => 'fake', 'confidence' => 0.98, 'authenticity' => 0.02,
            'passed_threshold' => true,
        ]]));
        $result = app(ProcessDocumentAction::class)->execute($suspect->fresh());

        // Both runs are equally CONFIDENT (0.95 vs 0.98) — only authenticity
        // moved. Before this change the forgery scored LOWER risk than the
        // genuine document, because confidence was feeding the blend.
        $this->assertEqualsWithDelta(0.02, $result->classification_authenticity, 1e-6);
        // 0.20 weight × (0.98 - 0.05) ≈ 18.6 points of separation.
        $this->assertGreaterThan($clean->document_risk_score + 15, $result->document_risk_score);
    }
```

- [ ] **Step 11: Run them to verify they fail**

Run: `php artisan config:clear && php artisan test --compact --filter="MlPipelineServiceTest|ProcessDocumentActionTest"`
Expected: FAIL — `Undefined array key "classification_authenticity"`.

- [ ] **Step 12: Create the migration**

Run: `php artisan make:migration add_classification_authenticity_to_validation_results_table --no-interaction`

Then put this in the generated file (rename it to `2026_08_12_000001_...` if the generated timestamp differs — the content is what matters):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 3 reports two different numbers and the pipeline needs both: the
 * winning class's `classification_confidence` (what the officer reads) and
 * `classification_authenticity` = 1 - P(fake) (what the risk blend consumes).
 * They diverge exactly when it matters — a document confidently classified
 * `fake` has high confidence and near-zero authenticity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validation_results', function (Blueprint $table) {
            $table->float('classification_authenticity')->nullable()->after('classification_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('validation_results', function (Blueprint $table) {
            $table->dropColumn('classification_authenticity');
        });
    }
};
```

- [ ] **Step 13: Add the column to the model**

In `app/Models/ValidationResult.php`, add `'classification_authenticity',` to `$fillable` immediately after `'classification_confidence',`, and add this to the `casts()` array next to the other float casts:

```php
            'classification_authenticity' => 'float',
```

- [ ] **Step 14: Map it and feed it to the risk blend**

In `app/Services/Document/MlPipelineService.php`, replace the Stage 3 block (lines 162–166) with:

```php
        $classification = $stages['classification'] ?? null;
        if ($this->ran($classification)) {
            $columns['classification_label'] = $classification['label'] ?? null;
            $columns['classification_confidence'] = $this->float($classification['confidence'] ?? null);
            // 1 - P(fake). Distinct from confidence: a document confidently
            // classified `fake` is 0.98 confident and 0.02 authentic.
            $columns['classification_authenticity'] = $this->float($classification['authenticity'] ?? null);
        }
```

In `app/Actions/ProcessDocumentAction.php`, replace the `'classification'` line in the risk components (line 77) with:

```php
            // Authenticity, not confidence — see MlPipelineService::mapStages().
            // Falls back for rows written before the API reported authenticity.
            'classification' => $result->classification_authenticity ?? $result->classification_confidence,
```

- [ ] **Step 15: Run the Laravel tests to verify they pass**

Run: `php artisan config:clear && php artisan test --compact --filter="MlPipelineServiceTest|ProcessDocumentActionTest|RiskScoreServiceTest"`
Expected: all PASS.

- [ ] **Step 16: Run the full suites**

Run: `php artisan config:clear && php artisan test --compact`
Run: `python\env\Scripts\python.exe -m pytest python/tests/ -q --tb=short`
Expected: both green. Fix anything that broke before committing.

- [ ] **Step 17: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/ValidationResult.php app/Services/Document/MlPipelineService.php app/Actions/ProcessDocumentAction.php tests/Feature/Document/MlPipelineServiceTest.php tests/Feature/Document/ProcessDocumentActionTest.php
git commit -m "Score a fake-classified document as high classification risk"
```

---

## Task 5: Live end-to-end verification against the running API

**Files:**
- Modify: `python/DEVELOPMENT_PHASES.md` (record the closed gaps)

**Interfaces:**
- Consumes: everything Tasks 1–4 produced, running against the real weights in `python/models/`.

This task has no new code — it proves the four preceding tasks work against real models rather than stubs, and records the result. Do not skip it: every test so far used stub models.

- [ ] **Step 1: Restart the ML API so it loads the new code and the classifier**

```bash
cd python && env/Scripts/python.exe -m uvicorn api.main:app --port 7860
```

Wait ~100s for startup (TrOCR is the slow load).

- [ ] **Step 2: Confirm the stamp classifier is now loaded**

```bash
curl -s http://127.0.0.1:7860/health
```

Expected: `models.stamp_classifier.loaded` is `true` with `path` ending `stamp_classifier.pkl`. If it is `false` with `weights_not_found`, the file is missing from `python/models/` — regenerate it with `env/Scripts/python.exe scripts/train_stamp.py` before continuing.

- [ ] **Step 3: Verify the tamper check runs on a real crop with no reference**

```bash
curl -s -X POST http://127.0.0.1:7860/v1/stamp/verify \
  -H "Authorization: Bearer devtoken" \
  -F "file=@python/data/seal/BIR_SEAL.png" \
  -F "document_type=bir_certificate"
```

Expected: `reason` is `unreferenced_logo`, and `stamp_tampered` / `genuine_probability` are non-null. Record the genuine probability — a real BIR seal should sit well above 0.50.

- [ ] **Step 4: Verify an LGU document routes by its OCR city**

Submit a real business permit through the vendor portal (or re-run one via `php artisan queue:work --queue=document-processing,mail,default` after re-queueing), then inspect the row:

```bash
php artisan tinker --execute 'dump(App\Models\ValidationResult::latest()->first()->only(["detected_city","logo_reference_id","stamp_tampered","classification_authenticity","flags"]));'
```

Expected: `detected_city` is the permit's city; `flags` contains `unreferenced_logo` **or** `city_not_identified` (not both), and no longer contains `unreferenced_logo` on a second submission for a city whose reference an officer has approved.

- [ ] **Step 5: Record the outcome in the phase doc**

In `python/DEVELOPMENT_PHASES.md`, update the "Where we are now" section: Phase 7 (EfficientNet) gains "Stage 4b texture check wired into the live pipeline (`stamp_classifier.pkl` loaded by the registry)"; note that LGU city-scoped logo lookup and the Stage 3 `fake`/mismatch flags are now live. Include the genuine-probability figure from Step 3.

- [ ] **Step 6: Commit**

```bash
git add python/DEVELOPMENT_PHASES.md
git commit -m "Record the Stage 3 and Stage 4b gaps as closed"
```

---

## Out of scope (deliberately)

- **Multi-page classification and detection.** `validate.py` still classifies and detects on page 1 only, and only page 1's OCR quality flags propagate (`max_pdf_pages = 2`). Worth its own plan.
- **ML tamper fusion (M7).** Stage T keeps its deterministic five-technique blend.
- **New document-type generators / classifier classes** (sanitary permit, FDA, food handler, government IDs). Until those classes exist, `document_type_mismatch` deliberately stays silent for them — that is why Task 4 Step 5's third test exists.


---

## File: docs/superpowers/specs/2026-07-15-e2e-submission-wiring-design.md

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


---

# Archive

## File: docs/archive/CLAUDE_LEGACY.md

# CLAUDE.md — Automated Document Validation System (ADVS)

> This file is the authoritative reference for Claude Code when working on this project.
> Read it fully before writing any code, generating migrations, or modifying existing files.

> **Domain reference — [`ADVS_System_Reference.md`](ADVS_System_Reference.md).**
> `CLAUDE.md` governs *how* to build (stack, conventions, structure, phases); `ADVS_System_Reference.md` governs *what* the system does (the document-validation pipeline, risk-score math, role permissions, dashboard layout, notifications, storage, and the complete tunable-parameter table). **Before implementing any pipeline stage, risk score, signature/stamp verification, role gate, dashboard view, or threshold, read the relevant section of `ADVS_System_Reference.md`** so behavior matches the thesis design. The `advs-system-reference` skill auto-activates on this domain work. Where the two documents disagree on a detail, prefer `CLAUDE.md` for stack/version facts and `ADVS_System_Reference.md` for product behavior, and flag the conflict.

> **Phased implementation plans (concern-split) live in [`docs/phases/`](docs/phases/).** The original 10-sprint roadmap in §10 is now elaborated into three parallel, concern-scoped phase plans, each folding in the **Negofood Solution** client-interview direction (compliance lifecycle: expiration monitoring, renewal reminders, completeness checklists, OCR field extraction, resubmission, food-business document types — see [`docs/CLIENT_INTERVIEW_GAP_PLAN.md`](docs/CLIENT_INTERVIEW_GAP_PLAN.md)). **Scope is vendor-only — personnel/employee onboarding is explicitly out of scope (gap plan G5, descoped).**
> - **Backend / pipeline integration** → [`docs/phases/PIPELINE_INTEGRATION_PHASES.md`](docs/phases/PIPELINE_INTEGRATION_PHASES.md)
> - **Python model training** → [`docs/phases/MODEL_TRAINING_PHASES.md`](docs/phases/MODEL_TRAINING_PHASES.md)
> - **Functions of the UIs** → [`docs/phases/UI_FUNCTION_PHASES.md`](docs/phases/UI_FUNCTION_PHASES.md)
>
> One approved departure from `ADVS_System_Reference.md §1`: the **renewal scheduler** makes the system partly **calendar-driven** (pipeline Phase P5).

---

## 1. Project Overview

**App Name:** ADVS — Automated Document Validation System for Vendor Accreditation

**One-sentence description:** A desktop-first web application that automates the validation of third-party vendor accreditation documents using image processing, OCR, and machine learning to detect fraud and reduce manual compliance work.

**Main Purpose:**
The ADVS replaces manual document review by automatically classifying uploaded vendor documents (e.g., BIR Permits, Business Permits, DTI Business Name Registrations), extracting text via OCR, and verifying the authenticity of signatures and official stamps through a multi-model ML pipeline (ResNet-50 + YOLOv8 + Siamese CNN + EfficientNet). A risk score is assigned to each submission. Compliance officers review the results on an Admin Dashboard and issue a final approve/reject decision.

**Users:**
- **Vendors (external):** Submit accreditation documents through a secure portal.
- **Compliance Officers / Accrediting Officers (internal):** Review AI-generated validation reports, risk scores, and make final accreditation decisions via the Admin Dashboard.
- **System Administrators (internal):** Manage users, configure thresholds, oversee the platform, and monitor submission trends and compliance status in aggregate.

> **Roles are `vendor`, `compliance_officer`, `admin` — three roles (see [`ADVS_System_Reference.md`](ADVS_System_Reference.md) §3 and the live `users.role` enum).** There is no separate `risk_manager` role; aggregate risk/trend monitoring is an admin/compliance-officer dashboard capability.

---

## 2. Tech Stack & Versions

> The project is built on the **Laravel Livewire starter kit**. The frontend is server-driven Livewire/Volt with Flux UI components (not a separate Alpine SPA — Alpine ships bundled inside Livewire/Flux). Authentication is **Laravel Fortify** (session-based), not JWT.

| Layer | Technology | Version |
|---|---|---|
| Language (backend) | PHP | 8.2.x |
| Framework | Laravel | 12.x |
| Authentication | laravel/fortify | ^1.37 (session / `web` guard) |
| ORM | Eloquent (built-in) | — |
| UI components | Livewire Flux (free) | 2.x |
| Reactivity / full-page components | Livewire + Volt | Livewire 4.x · Volt 1.x |
| Frontend CSS | Tailwind CSS | 4.x (via `@tailwindcss/vite`) |
| Frontend JS (reactivity) | Alpine.js | bundled with Livewire/Flux |
| Asset bundling | Vite | 6.x (via `laravel-vite-plugin`) |
| Templating | Blade + Livewire Volt | — |
| Database | MySQL | 8.0+ |
| Queue driver (local) | Database | — |
| Queue driver (production) | Redis | 7.x |
| Python runtime | Python | 3.11.x |
| ML framework | TensorFlow / Keras | 2.15.x |
| Object detection | Ultralytics YOLOv8 | 8.x |
| Image processing | OpenCV (cv2) | 4.9.x |
| OCR | pytesseract | 0.3.x |
| PDF-to-image | pdf2image | 1.17.x |
| Numerical computing | NumPy | 1.26.x |
| ML utilities | scikit-learn | 1.4.x |
| Python testing | pytest | 8.x |
| PHP testing | PHPUnit | 11.x (Pest runner configured) |
| Local mail testing | Mailpit | latest |
| Process management | Laravel `Process` facade | — |

**Key Composer packages:**
```
laravel/framework      (^12.0)
laravel/fortify        (^1.37)  — authentication backend
livewire/livewire      (^4.0)
livewire/volt          (^1.6)   — single-file Livewire components
livewire/flux          (^2.0)   — UI component library
laravel/tinker
```

**Key npm packages:**
```
tailwindcss            (^4.0)
@tailwindcss/vite      (^4.0)
vite                   (^6.0)
laravel-vite-plugin
axios
```

> Tailwind v4 has no `tailwind.config.js` by default — configuration lives in `resources/css/app.css` via `@import "tailwindcss"` and `@theme`. There is no `@tailwindcss/forms` plugin; Flux components provide form styling. Alpine.js is **not** installed as a direct dependency — do not `import Alpine` manually; use Livewire/Volt + Flux.

---

## 3. Architecture & Folder Structure

The application follows Laravel's MVC pattern. Business logic is extracted into Services and Actions to keep controllers thin.

```
advs/
├── app/
│   ├── Actions/
│   │   └── Fortify/                            # Fortify business logic (customizable)
│   │       ├── CreateNewUser.php               # registration → creates a vendor user
│   │       ├── PasswordValidationRules.php
│   │       ├── ResetUserPassword.php
│   │       ├── UpdateUserPassword.php
│   │       └── UpdateUserProfileInformation.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Controller.php                  # base controller (auth handled by Fortify)
│   │   └── Middleware/
│   │       └── EnsureUserHasRole.php           # "role:" alias — role-based route guard
│   ├── Livewire/
│   │   └── Actions/
│   │       └── Logout.php                      # used by the settings delete-account form
│   ├── Models/
│   │   └── User.php                            # role, dashboardRoute(), MustVerifyEmail
│   └── Providers/
│       ├── AppServiceProvider.php
│       ├── FortifyServiceProvider.php          # auth view callbacks + rate limiters
│       └── VoltServiceProvider.php
│
│   # ── Planned (ML document pipeline — not yet built; see §6–§7) ─────────────
│   #   Http/Controllers/{Vendor,Admin}/...     document submission, reports, accreditation
│   #   Services/{Document,Verification}/...     Process-facade wrappers around python/
│   #   Actions/ProcessDocumentAction.php        orchestrates the full validation pipeline
│   #   Jobs/{ProcessDocumentJob,EnrollReferenceJob,RetrainModelJob}.php
│   #   Models/{Vendor,Document,ValidationReport,SignatureEmbedding,LogoReference}.php
│   #     (SignatureEmbedding = per-vendor, enrolled at registration; LogoReference = per-issuer logo keyed by document_type [+city for LGU] via document_types.issuer_scope, seeded on first approval — NOT per-vendor)
│   #   Notifications/DocumentValidationComplete.php
│
├── python/                                     # ALL Python scripts live here
│   ├── requirements.txt
│   ├── preprocess.py                           # OpenCV: grayscale, binarize, morph open
│   ├── ocr_runner.py                           # pytesseract OCR + NLP cleanup
│   ├── classify_document.py                    # ResNet-50 document classification
│   ├── signature_verify.py                     # YOLOv8 detect + Siamese CNN verify (vs per-vendor registration reference)
│   ├── stamp_verify.py                         # YOLOv8 detect + EfficientNet match vs the issuer's reference logo (by document_type, +city for LGU)
│   ├── enroll_reference.py                     # seeds an issuer's reference logo on officer approval (signature ref is enrolled at registration, not here)
│   ├── utils/
│   │   ├── image_utils.py
│   │   ├── model_loader.py                     # loads .h5 / .pt model files once
│   │   └── json_io.py                          # read/write JSON payloads from Laravel
│   ├── models/                                 # trained model weight files (gitignored)
│   │   ├── resnet50_authenticity.h5
│   │   ├── siamese_signature.h5
│   │   ├── yolov8_document.pt
│   │   └── efficientnet_stamp.h5
│   └── tests/
│       ├── test_preprocess.py
│       ├── test_ocr.py
│       ├── test_classify.py
│       ├── test_signature_verify.py
│       └── test_stamp_verify.py
│
├── resources/
│   ├── views/
│   │   ├── welcome.blade.php                   # public landing page
│   │   ├── auth/                               # Fortify view callbacks → these Blade forms
│   │   │   ├── login.blade.php
│   │   │   ├── register.blade.php
│   │   │   ├── forgot-password.blade.php
│   │   │   ├── reset-password.blade.php
│   │   │   ├── verify-email.blade.php
│   │   │   └── confirm-password.blade.php
│   │   ├── vendor/dashboard.blade.php          # role landing pages (Blade + Flux)
│   │   ├── admin/dashboard.blade.php
│   │   ├── dashboard.blade.php                 # starter-kit default (kept for reference)
│   │   ├── components/
│   │   │   ├── layouts/                        # Flux app shell (sidebar/header) + auth shells
│   │   │   └── ...                             # auth-header, app-logo, etc.
│   │   ├── livewire/settings/                  # Volt full-page settings components
│   │   └── partials/head.blade.php             # <head>: @vite + @fluxAppearance
│   ├── js/
│   │   └── app.js                              # Vite entry (imports app.css)
│   └── css/
│       └── app.css                             # Tailwind v4 entry: @import "tailwindcss"; @theme {…}
│
├── routes/
│   ├── web.php                                 # landing, /dashboard dispatcher, role pages, settings
│   └── console.php                             # (auth routes are registered by Fortify; no api.php)
│
├── database/
│   ├── migrations/                             # users (+ role), cache, jobs, password resets
│   └── seeders/
│       └── DatabaseSeeder.php                  # one verified account per role
│
├── config/
│   ├── fortify.php                             # features, guard, home path, view routes
│   └── livewire.php                            # component_layout override (see §8 note)
│
├── storage/
│   └── app/
│       ├── documents/                          # uploaded vendor documents (private — planned)
│       └── python_payloads/                    # temp JSON I/O Laravel ↔ Python (planned)
│
├── tests/
│   ├── Feature/
│   │   ├── Auth/                               # Authentication, Registration, PasswordReset,
│   │   │                                       #   PasswordConfirmation, EmailVerification
│   │   ├── Settings/                           # ProfileUpdate, PasswordUpdate
│   │   └── DashboardTest.php                   # role dispatch + 403 enforcement
│   └── Unit/
│
├── .env.example
├── CLAUDE.md                                   # ← this file
└── vite.config.js
```

---

## 4. Key Conventions

### Routing
- Auth routes (login, register, password reset, email verification, logout, password confirmation) are **registered by Laravel Fortify** — do not redefine them. Customize via `config/fortify.php` and the view callbacks in `FortifyServiceProvider`.
- Application routes live in `routes/web.php` and use the **session-based `web` guard** (there is no JWT/API layer yet). Protect pages with `auth`, `verified`, and the `role:` middleware.
- Reference routes by **named route helpers**: `route('admin.dashboard')`, `route('vendor.dashboard')` — never hardcoded URLs.
- Role-based landing is centralized: the `/dashboard` route (`name('dashboard')`) redirects to `auth()->user()->dashboardRoute()`.
- When the document pipeline is built, prefer **resource controllers** for CRUD entities: `Route::resource('admin/vendors', VendorAccreditationController::class)`.

### Eloquent
- Define **relationships** explicitly on every model (e.g., `Vendor::hasMany(Document::class)`, `Document::belongsTo(Vendor::class)`).
- Use **local scopes** for repeated query filters:
  - `Document::scopePending($query)` — submissions awaiting ML processing.
  - `Document::scopeFlagged($query)` — submissions with risk score ≥ 70.
  - `Vendor::scopeAccredited($query)` — vendors with `status = 'approved'`.
- Never write raw SQL unless joining across 3+ tables for a report query; use Eloquent with `with()` for eager loading.
- Cast model attributes: `ValidationReport::$casts` should include `ml_results` as `array` and `risk_score` as `float`.

### Blade, Tailwind & Flux
- Build UI from **Livewire Flux components** (`<flux:input>`, `<flux:button>`, `<flux:heading>`, `<flux:badge>`, …) first; drop to raw HTML only when no Flux component fits.
- Apply **Tailwind v4 utility classes directly** in Blade. No inline `style=""` attributes anywhere.
- Tailwind v4 is configured in `resources/css/app.css` (`@import "tailwindcss"` + `@theme`/`@source`) — there is **no `tailwind.config.js`** and **no `@tailwindcss/forms`** (Flux styles form controls).
- Don't create CSS component classes (e.g. `.btn-primary`) unless reused across 5+ templates; prefer Flux, or `@apply` in `app.css` for truly global elements only.
- Risk score badges use conditional Tailwind: `{{ $report->risk_score >= 70 ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}` (or `<flux:badge :color="…">`).
- After changing Blade/CSS, run `npm run dev` (HMR) or `npm run build` — new utility classes only appear after a rebuild.

### Livewire, Volt & Alpine
- Interactive/stateful UI uses **Livewire** — prefer **Volt single-file components** in `resources/views/livewire/...`, registered as full-page routes via `Volt::route(...)` (mirroring the existing settings pages).
- Full-page Volt/Livewire components render inside `components.layouts.app` (set in `config/livewire.php`); plain Blade pages use the `<x-layouts.app>` component directly.
- **Alpine.js ships bundled with Livewire/Flux** — use `x-data`/`x-on` inline for small client-only behavior; do **not** install or `import Alpine` separately, and never use jQuery.
- Forms that post to Fortify are plain `<form method="POST">` with `@csrf` and Flux inputs (`name="…"`); Flux surfaces validation errors from the shared `$errors` bag automatically.

### Controllers
- Authentication has **no application controllers — Fortify owns it**. Customize registration/profile/password logic in `app/Actions/Fortify/*`.
- Application controllers (the document pipeline) are **thin**: validate via Form Requests, call a Service or Action, return a response.
- Never call Python scripts directly from a controller — delegate to a Service, which dispatches a Job. Use `ProcessDocumentAction` as the single orchestrator for the full ML pipeline.

### Services vs Actions
- **Services** handle ongoing concerns (Python/model-calling wrappers, report assembly, risk scoring).
- **Actions** handle single, complete use-case flows (process a document start to finish, accredit a vendor). Fortify's `app/Actions/Fortify/*` follow this same single-responsibility convention.

---

## 5. Authentication & Security

### Authentication (Laravel Fortify)

Fortify is the headless auth backend: it registers all auth routes and controllers, while the app supplies Blade views and customizes behavior through actions + config. **Session-based, `web` guard — there is no JWT.**

**Config (`config/fortify.php`):**
- `guard` => `web`, `home` => `/dashboard`.
- `views` => `true` — Fortify registers the GET view routes; `FortifyServiceProvider` view callbacks point them at `resources/views/auth/*`.
- Enabled features: `registration`, `resetPasswords`, `emailVerification`, `updateProfileInformation`, `updatePasswords`.
- **Scaffolded but disabled (commented out):** `twoFactorAuthentication`, `passkeys` (see below).

**Customizable actions (`app/Actions/Fortify/`):** `CreateNewUser`, `UpdateUserProfileInformation`, `UpdateUserPassword`, `ResetUserPassword`, `PasswordValidationRules`.

**User model (`app/Models/User.php`):**
```php
class User extends Authenticatable implements MustVerifyEmail
{
    public const ROLE_VENDOR = 'vendor';
    public const ROLE_COMPLIANCE_OFFICER = 'compliance_officer';
    public const ROLE_ADMIN = 'admin';

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function dashboardRoute(): string
    {
        return match ($this->role) {
            self::ROLE_ADMIN, self::ROLE_COMPLIANCE_OFFICER => 'admin.dashboard',
            default => 'vendor.dashboard',
        };
    }
}
```

**Login flow:**
1. `GET /login` → `resources/views/auth/login.blade.php` (Flux form, POSTs to `login.store`).
2. `POST /login` → Fortify authenticates against the `web` guard → redirects to `home` (`/dashboard`).
3. `/dashboard` (the dispatcher, `name('dashboard')`) → `redirect()->route(auth()->user()->dashboardRoute())`.
4. The `verified` middleware bounces unverified users to `verification.notice` (`/email/verify`).

**Registration flow:**
1. `GET /register` → `resources/views/auth/register.blade.php`.
2. `POST /register` → `CreateNewUser` validates and creates the user **with `role = vendor`** (public registration is vendor-only), fires `Registered` (queues the verification email), logs the user in, and redirects to `/dashboard`.
3. The user lands on the verify-email screen until they click the link in the email.

> Internal staff (compliance officers, admins) are **not** self-registered — they are provisioned via `DatabaseSeeder` (or future admin tooling).

### Role-Based Access
- `users.role` column: `vendor`, `compliance_officer`, `admin`.
- The **`role:` middleware alias** (`app/Http/Middleware/EnsureUserHasRole`) guards routes and returns 403 on mismatch: `role:vendor`, `role:admin,compliance_officer`.
- Role landing pages: `vendor.dashboard`, `admin.dashboard` (admin + compliance_officer).
- Prefer the `role:` middleware (or a Gate/Policy) over inline `if ($user->role === …)` checks.

### Two-Factor Authentication (scaffolded, not yet enabled)

The original design called for **email-OTP** 2FA. The current build uses Fortify, which ships **TOTP** 2FA (authenticator app + recovery codes) and **passkeys/WebAuthn** — both scaffolded but **disabled** in `config/fortify.php`.

To enable Fortify TOTP 2FA:
1. Uncomment `Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])` in `config/fortify.php`.
2. Add the `Laravel\Fortify\TwoFactorAuthenticatable` trait to `User`.
3. Publish + run the 2FA migration: `php artisan vendor:publish --tag=fortify-migrations && php artisan migrate`.
4. Build the 2FA management UI (QR code, recovery codes) and a `/two-factor-challenge` screen.

> If the thesis specifically requires the **email-OTP** flow from the original design, it would be implemented as a custom challenge layered on top of Fortify's login — it is **not** built today.

### Auth Routes

Fortify owns the auth routes (do not redefine them): `login`/`login.store`, `register`/`register.store`, `logout`, `password.request`/`password.email`, `password.reset`/`password.update`, `verification.notice`/`verification.verify`/`verification.send`, `password.confirm`/`password.confirm.store`. Inspect with `php artisan route:list --except-vendor` plus `php artisan route:list --only-vendor` (Fortify routes show as vendor-owned).

Application route groups live in `routes/web.php`:
```php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn () => redirect()->route(auth()->user()->dashboardRoute()))->name('dashboard');
    Route::view('vendor/dashboard', 'vendor.dashboard')->middleware('role:vendor')->name('vendor.dashboard');
    Route::view('admin/dashboard', 'admin.dashboard')->middleware('role:admin,compliance_officer')->name('admin.dashboard');
});
```

### General Security Rules
- All file uploads are stored in `storage/app/documents/` (private, not public). Never store uploaded vendor documents in `public/`.
- Serve document downloads through a controller that checks ownership + role before streaming.
- Validate MIME type server-side using `$request->file('document')->getMimeType()` — accept only `image/jpeg`, `image/png`, `application/pdf`.
- Enforce max upload size of 10 MB in the upload Form Request and in `php.ini`/nginx config.
- Enforce roles with the `role:` middleware or a Gate/Policy — never ad-hoc inline conditionals.
- Email verification is required: keep the `verified` middleware on authenticated pages.
- Login throttling is handled by Fortify's `login` rate limiter (5/min per email+IP) in `FortifyServiceProvider`.
- Keep `APP_DEBUG=false` in production; never commit `.env`, `python/models/`, or `storage/app/documents/`.

---

## 6. Python Integration

### How Python is Called

Laravel calls Python scripts exclusively through the **`Process` facade** (Laravel 11/12 built-in). Python scripts are never called directly from controllers — they are always dispatched as queued Jobs that use `Process` inside a Service.

**Pattern used in Services:**
```php
// app/Services/Document/ClassificationService.php
use Illuminate\Support\Facades\Process;

public function classify(string $imagePath): array
{
    $payloadPath = storage_path("app/python_payloads/classify_{$jobId}.json");
    $outputPath  = storage_path("app/python_payloads/classify_{$jobId}_result.json");

    // Write input payload
    file_put_contents($payloadPath, json_encode(['image_path' => $imagePath]));

    $result = Process::path(base_path('python'))
        ->timeout(60)
        ->run("python3 classify_document.py --input {$payloadPath} --output {$outputPath}");

    if ($result->failed()) {
        Log::error('Python classify_document.py failed', [
            'exit_code' => $result->exitCode(),
            'stderr'    => $result->errorOutput(),
        ]);
        throw new \RuntimeException('Document classification script failed.');
    }

    $output = json_decode(file_get_contents($outputPath), true);
    @unlink($payloadPath);
    @unlink($outputPath);

    return $output; // e.g., ['label' => 'BIR Permit', 'confidence' => 0.96]
}
```

### Python Script I/O Convention

Every Python script follows this contract:

| Script | Input | Output |
|---|---|---|
| `preprocess.py` | `--input <image_path>` `--output <preprocessed_image_path>` | Preprocessed PNG saved to output path |
| `ocr_runner.py` | `--input <preprocessed_image_path>` `--output <json_path>` | `{"text": "...", "confidence": 0.94}` |
| `classify_document.py` | `--input <json_payload_path>` `--output <json_result_path>` | `{"label": "BIR Permit", "confidence": 0.96}` |
| `signature_verify.py` | `--input <json_payload_path>` `--output <json_result_path>` | `{"match": true, "similarity": 0.91, "embedding": [...]}` |
| `stamp_verify.py` | `--input <json_payload_path>` (includes `document_type` + `city` from OCR) `--output <json_result_path>` | `{"match": true, "similarity_score": 0.952}` — or `{"match": false, "reason": "unreferenced_logo"}` / `{"reason": "no_issuer_logo"}` |
| `enroll_reference.py` | `--input <json_payload_path>` (document_type + city + logo crop) `--output <json_result_path>` | `{"vector_path": "..."}` — seeds the issuer's reference logo |

All scripts exit with code `0` on success, non-zero on failure, and write errors to stderr.

### Python Script Internal Structure

Each script follows this template:
```python
# python/classify_document.py
import argparse, json, sys
from utils.model_loader import load_resnet50
from utils.image_utils import preprocess_for_resnet

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--input', required=True)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()

    try:
        payload = json.load(open(args.input))
        model, label_encoder = load_resnet50()
        result = classify(payload['image_path'], model, label_encoder)
        json.dump(result, open(args.output, 'w'))
        sys.exit(0)
    except Exception as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)
```

### Logging & Error Handling
- PHP side: all `Process::run()` calls are wrapped in try/catch. Failures are logged to `storage/logs/laravel.log` via `Log::error()` with script name, exit code, and stderr.
- Python side: each script uses Python's `logging` module writing to `storage/logs/python_{script_name}.log` (path passed as env var `PYTHON_LOG_DIR` or defaulting to `storage/logs/`).
- Jobs that call Python have `$tries = 3` and `$backoff = [30, 60, 120]` (seconds). After 3 failures, the job is moved to the `failed_jobs` table and an admin notification is triggered.

---

## 7. Queue & Job System

### Queue Driver
- **Local / development:** `QUEUE_CONNECTION=database` (uses `jobs` table, created by `php artisan queue:table`).
- **Production:** `QUEUE_CONNECTION=redis` (Redis 7.x via `predis/predis` or `phpredis`).

### Jobs

| Job | Triggered by | Python script called | Queue |
|---|---|---|---|
| `ProcessDocumentJob` | Document upload | `preprocess.py` → `ocr_runner.py` → `classify_document.py` → `signature_verify.py` / `stamp_verify.py` | `document-processing` |
| `SendOtpEmailJob` | Login (after password verified) | None (pure mail) | `mail` |
| `RetrainModelJob` | Admin trigger via dashboard | Artisan command → Python training script | `ml-training` |
| `EnrollReferenceJob` | First officer-approved document carrying a logo for an issuer with no reference yet → seeds that issuer's reference logo (signature ref is enrolled at registration, not here) | `enroll_reference.py` | `document-processing` |

**`ProcessDocumentJob` outline:**
```php
// app/Jobs/ProcessDocumentJob.php
class ProcessDocumentJob implements ShouldQueue {
    public $tries = 3;
    public $backoff = [30, 60, 120];
    public $timeout = 300; // 5 min; ML inference can be slow

    public function handle(ProcessDocumentAction $action): void {
        $action->execute($this->document);
    }

    public function failed(\Throwable $e): void {
        $this->document->update(['status' => 'failed']);
        Log::error('ProcessDocumentJob failed', ['document_id' => $this->document->id, 'error' => $e->getMessage()]);
        // Notify compliance officer via DB notification
    }
}
```

**`SendOtpEmailJob`:**
```php
// app/Jobs/SendOtpEmailJob.php
class SendOtpEmailJob implements ShouldQueue {
    public $tries = 3;
    public $queue = 'mail';

    public function handle(): void {
        Mail::to($this->user->email)->send(new OtpMail($this->otp));
    }
}
```

### Running Workers
```bash
# Development (process both queues)
php artisan queue:work --queue=mail,document-processing,default

# Production (supervisor recommended; separate workers per queue)
php artisan queue:work redis --queue=mail --tries=3
php artisan queue:work redis --queue=document-processing --tries=3 --timeout=300
```

### Failed Jobs
```bash
php artisan queue:failed           # list
php artisan queue:retry all        # retry all failed
php artisan queue:flush            # clear failed table
```

---

## 8. Testing

### PHP — PHPUnit

Test classes live in `tests/Feature/` and `tests/Unit/`. They are PHPUnit class-based tests (a Pest runner is also configured via `tests/Pest.php`). Run all:
```bash
php artisan test
php artisan test --compact                         # condensed output
php artisan test --filter=DashboardTest            # run specific class
php artisan test tests/Feature/Auth/LoginTest.php  # run a single file
php artisan test --coverage                         # requires Xdebug or PCOV
```

**Current auth/role coverage (Fortify-based, all green):**

`tests/Feature/Auth/RegistrationTest.php`:
- Registration screen renders; new users register **and are assigned role `vendor`**.
- Registration fails on mismatched `password_confirmation` and on duplicate email (session errors).

`tests/Feature/Auth/AuthenticationTest.php`:
- Login screen renders; valid credentials authenticate and redirect to `/dashboard`.
- Wrong password keeps the user a guest; logout redirects to `/`.

`tests/Feature/Auth/{PasswordResetTest, PasswordConfirmationTest, EmailVerificationTest}.php`:
- Reset link request + reset with a valid token; password confirmation success/failure; signed email-verification link verifies the user.

`tests/Feature/DashboardTest.php` (role routing):
- Guests → `/login`; unverified users → `verification.notice`.
- Each role's `/dashboard` redirects to its own dashboard; a vendor hitting `admin.dashboard` gets **403**.

**Planned (document pipeline):** `tests/Feature/Document/DocumentSubmissionTest.php` — vendor upload happy path, 401 for guests, 422 for bad MIME / >10 MB, and `Queue::assertPushed(ProcessDocumentJob::class)`.

**Test database:** the `RefreshDatabase` trait + `phpunit.xml` already set `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync` — tests are isolated from the MySQL `advs` database.

**Livewire v4 / layout note:** full-page Volt components resolve their layout via `config/livewire.php` (`component_layout => components.layouts.app`). Without this override, Livewire v4 defaults to the `layouts::app` namespace and full-page components throw *"No hint path defined for [layouts]"*.

**Mocking queues/mail in tests:**
```php
Queue::fake();
// ...trigger action...
Queue::assertPushed(ProcessDocumentJob::class);

Mail::fake();
// ...trigger mail...
Mail::assertQueued(\App\Mail\OtpMail::class); // when the OTP mailable exists
```

### Python — pytest

Run all Python tests:
```bash
cd python
pip install -r requirements.txt
pytest tests/ -v
pytest tests/ --cov=. --cov-report=term-missing
```

**Test file coverage:**

`tests/test_preprocess.py`:
- Grayscale output is single-channel (shape `(H, W)`).
- Binarization produces only 0 and 255 pixel values.
- Morphological opening removes isolated noise pixels.
- Output image is black text on white background (bitwise inversion confirmed).

`tests/test_ocr.py`:
- OCR extracts known text from a clean test image.
- WER (Word Error Rate) is below 0.10 on clean test samples.
- CER (Character Error Rate) is below 0.05 on clean test samples.

`tests/test_classify.py`:
- Model output is a dict with `label` (string) and `confidence` (float 0–1).
- Confidence for a known document type (BIR Permit fixture) is ≥ 0.80.
- Unknown/noise image returns the lowest-confidence class without crashing.

`tests/test_signature_verify.py`:
- Matching genuine pair returns `similarity ≥ 0.85`.
- Mismatched (forged) pair returns `similarity < 0.85`.
- Embedding vector length is exactly 128 dimensions.

`tests/test_stamp_verify.py`:
- Genuine stamp pair returns `similarity_score ≥ 0.85`.
- Photocopy/forged stamp returns `similarity_score < 0.85`.

### JavaScript (Optional)
If adding Vitest (recommended):
```bash
npm install -D vitest @testing-library/alpinejs
npx vitest run
```
Focus on `otp-timer.js` component: ensure countdown reaches 0 and disables input.

---

## 9. Development Workflow

### Initial Local Setup

```bash
# 1. Clone the repository
git clone <repo-url> advs
cd advs

# 2. Install PHP dependencies
composer install

# 3. Install Node dependencies
npm install

# 4. Copy environment file and configure
cp .env.example .env
# Edit .env: set DB_DATABASE, DB_USERNAME, DB_PASSWORD, MAIL_*, QUEUE_CONNECTION=database

# 5. Generate Laravel application key
php artisan key:generate
# (Auth is Fortify — no JWT secret needed. Fortify is already installed/configured.)

# 6. Run database migrations
php artisan migrate

# 7. Seed the database (one verified account per role — see below)
php artisan db:seed

# 8. Create storage symlink (for public assets if needed)
php artisan storage:link

# 9. Set up Python environment (only needed for the ML pipeline, §6)
cd python
python3 -m venv venv
source venv/bin/activate          # Windows: venv\Scripts\activate
pip install -r requirements.txt
cd ..

# 11. Place trained model files (obtain from team shared drive)
# Copy to python/models/:
#   resnet50_authenticity.h5
#   siamese_signature.h5
#   yolov8_document.pt
#   efficientnet_stamp.h5
```

### Running the Development Server

```bash
# Terminal 1: Laravel dev server
php artisan serve

# Terminal 2: Vite dev server (hot module reload for JS/CSS)
npm run dev

# Terminal 3: Queue worker (processes ML jobs + OTP emails)
php artisan queue:work --queue=mail,document-processing,default

# Terminal 4: Mailpit (local email testing)
# Download from https://github.com/axllent/mailpit/releases
mailpit
# Web UI at http://localhost:8025 — all outgoing emails appear here
```

### Vite — Asset Bundling

`vite.config.js` (standard Laravel setup):
```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
});
```

```bash
npm run dev       # development: HMR, source maps, unminified
npm run build     # production: minified, hashed filenames, written to public/build/
```

In Blade layouts, always use:
```blade
@vite(['resources/css/app.css', 'resources/js/app.js'])
```

### Running Python Scripts Manually

Activate the venv first, then:
```bash
cd python
source venv/bin/activate

# Test preprocessing on a sample image
python3 preprocess.py --input /path/to/document.jpg --output /tmp/preprocessed.png

# Test OCR
python3 ocr_runner.py --input /tmp/preprocessed.png --output /tmp/ocr_result.json
cat /tmp/ocr_result.json

# Test document classification
echo '{"image_path": "/tmp/preprocessed.png"}' > /tmp/classify_input.json
python3 classify_document.py --input /tmp/classify_input.json --output /tmp/classify_result.json
cat /tmp/classify_result.json

# Test signature verification
python3 signature_verify.py --input /tmp/sig_payload.json --output /tmp/sig_result.json
```

### Testing the Auth Flow Locally

Auth is browser/session-based (Fortify), so test it in the browser, not via API tokens.

**Seeded accounts (`php artisan db:seed`) — all use password `password`, all pre-verified:**

| Email | Role | Lands on |
|---|---|---|
| `vendor@advs.test` | vendor | `/vendor/dashboard` |
| `officer@advs.test` | compliance_officer | `/admin/dashboard` |
| `admin@advs.test` | admin | `/admin/dashboard` |

```bash
# 1. Run the app (serve + vite + queue), e.g.:
composer run dev          # concurrently runs serve, queue:listen, and vite
# 2. Visit http://localhost:8000 → Log in / Register
# 3. Log in with a seeded account → you are redirected to that role's dashboard.
```

**Email verification & password reset** (new registrations / "forgot password"): with `MAIL_MAILER=log` the links land in `storage/logs/laravel.log`. For a nicer experience use **Mailpit** (UI at http://localhost:8025):
```bash
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
```

---

## 10. Development Phases

The project follows the Agile cycle: **Plan → Build → Test → Refine** across 10 sprints (2–4 weeks each). Phases 5 and 9 are dedicated debugging and hardening phases.

---

### Phase 1 — Project Scaffolding & Environment Setup

**Goal:** Establish a fully working, version-controlled base project that every team member can run locally.

**Tasks:** *(largely complete — the base is the Laravel 12 Livewire starter kit)*
- ✅ Laravel 12 project init (Livewire starter kit: Livewire 4 + Volt + Flux + Tailwind 4 + Vite 6).
- ✅ Configure MySQL 8 connection in `.env` (database `advs`).
- ✅ Install and configure **Laravel Fortify** (auth backend) — see §5.
- Verify `@vite()` / `@fluxAppearance` / `@fluxScripts` directives work in Blade (they do).
- Install Python 3.11 virtual environment; create `python/requirements.txt` with all ML dependencies *(pending)*.
- Set up Git repository; add `.gitignore` entries for `python/venv/`, `python/models/`, `.env`, `storage/app/documents/`.
- Write `README.md` with local setup steps mirroring Section 9 above.
- Queue tables already exist (`jobs` migration shipped); verify the queue worker starts without errors.

**Definition of Done:** `php artisan serve`, `npm run dev`, and `php artisan queue:work` all run without errors. Python `python3 -c "import tensorflow, cv2, pytesseract; print('OK')"` passes (once the venv is set up).

---

### Phase 2 — Authentication: Registration, Email Verification & Login (Fortify) — ✅ DONE

**Goal:** Full registration → email verification → login flow with role-based redirects, via Laravel Fortify.

**Tasks (completed):**
- ✅ Added `role` column to `users` (`vendor` | `compliance_officer` | `admin`).
- ✅ `User implements MustVerifyEmail` + `role`/`dashboardRoute()` helpers.
- ✅ Installed Fortify; `views => true` with Blade form views in `resources/views/auth/*`.
- ✅ `CreateNewUser` assigns `role = vendor` on public registration.
- ✅ Enabled email verification; `verified` middleware on authenticated pages.
- ✅ `/dashboard` role dispatcher + `role:` middleware + per-role dashboard pages.
- ✅ Seeder creates one verified account per role.
- ✅ Tests: `AuthenticationTest`, `RegistrationTest`, `PasswordResetTest`, `PasswordConfirmationTest`, `EmailVerificationTest`, `DashboardTest`.

**Definition of Done:** ✅ `php artisan test` is green (34 tests). A seeded user can log in and is redirected to the correct role dashboard; verification + reset links arrive in Mailpit / the log.

---

### Phase 3 — Two-Factor Authentication

**Goal:** Add a second factor to the login flow. The build uses Fortify, so the **default path is Fortify TOTP** (authenticator app + recovery codes), which is scaffolded but disabled.

**Option A — Fortify TOTP (recommended, least code):**
- Enable `Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])` in `config/fortify.php`.
- Add `Laravel\Fortify\TwoFactorAuthenticatable` to `User`; publish + run the 2FA migration.
- Build the 2FA setup UI (QR code + recovery codes) and the `/two-factor-challenge` screen.

**Option B — Custom Email-OTP (only if the thesis requires it):**
- `TwoFactorService`: 6-digit OTP (`random_int`), `Hash::make()` in cache (120 s TTL) + UUID challenge token (125 s TTL).
- Intercept Fortify login via `Fortify::authenticateUsing()` / a pipeline step to issue the challenge, then verify before establishing the session.
- `OtpMail` (`ShouldQueue`) + `emails/otp.blade.php`; `SendOtpEmailJob`; `TwoFactorTest`.

**Definition of Done:** Chosen 2FA path works end-to-end locally and is covered by tests; never log OTP values (log only "OTP dispatched/verified/expired for user {id}").

---

### Phase 4 — Database Schema, Models & Core Relationships

**Goal:** Define all production database tables and Eloquent relationships needed for document processing.

**Migrations to create:**
- `vendors`: `id`, `user_id` (FK), `company_name`, `status` (enum: `pending`, `approved`, `rejected`), `risk_score` (float, nullable), timestamps.
- `documents`: `id`, `vendor_id` (FK), `file_path`, `original_filename`, `mime_type`, `file_size_kb`, `status` (enum: `pending`, `processing`, `validated`, `flagged`, `failed`), `document_type` (nullable string), timestamps.
- `validation_reports`: `id`, `document_id` (FK), `ocr_text` (longText), `ml_results` (JSON), `risk_score` (float), `signature_match` (boolean, nullable), `stamp_match` (boolean, nullable), `officer_decision` (enum: `pending`, `approved`, `rejected`, nullable), `decided_at` (nullable timestamp), timestamps.
- `signature_embeddings`: `id`, `vendor_id` (FK), `embedding` (JSON — 128-dim float array), `enrolled_at` (timestamp). Populated at **vendor registration**, not on first document submission.
- `document_types` (+ `issuer_scope`, migration `2026_06_26_160502`): adds `issuer_scope` enum(`lgu`,`national`) **nullable** — who issues the type, driving Stage 4b logo verification (`national` = agency logo e.g. BIR/SEC; `lgu` = per-city seal; `null` = no issuer logo). Seeded per type in `DocumentTypeSeeder`.
- `logo_references` (migration `2026_06_26_160503`): `id`, `document_type_id` (FK), `city` (**NOT NULL, default `''`** — `''` sentinel for national issuers, the city name for LGU), `label`, `feature_vector` (JSON), `reference_image_path`, `seeded_from_document_id` (FK→documents, nullable), `enrolled_by` (FK→users, nullable), `enrolled_at`. **Unique on (`document_type_id`, `city`)** — the `''` sentinel (not NULL) makes this enforce one logo per national document type, since MySQL treats NULL as distinct. Logo/stamp/seal references are keyed by **issuer**, not by vendor; seeded on the first officer-approved document for that issuer. (Replaces the planned per-vendor `stamp_feature_vectors`; the legacy `vendor_embeddings.stamp_*` columns are now dropped.)
- `validation_results` (+ logo fields, migration `2026_06_26_160504`): adds `detected_city`, `stamp_tampered`, `logo_reference_id` (FK→`logo_references`).

**Eloquent setup:**
- `User hasOne Vendor`, `Vendor hasMany Documents`, `Document hasOne ValidationReport`.
- Local scopes on `Document` and `Vendor` (see Section 4).
- Factories for all models; extend `DatabaseSeeder` (the `users.role` column and per-role seeding already exist from Phase 2).

**Definition of Done:** `php artisan migrate:fresh --seed` completes without errors. All model factories produce valid records. `php artisan test` still passes.

---

### Phase 5 — Python Scripts: Preprocessing, OCR & Classification

**Goal:** Implement and unit-test the Python preprocessing, OCR, and ResNet-50 classification pipeline.

**Tasks:**
- Implement `python/preprocess.py`: grayscale → binarization (threshold 150, `THRESH_BINARY_INV`) → morphological opening (2×2 kernel) → bitwise inversion → save output PNG.
- Implement `python/ocr_runner.py`: accept preprocessed image → pytesseract `image_to_string` with `--psm 6` → return JSON with `text` and a WER/confidence estimate.
- Implement `python/classify_document.py`: load `resnet50_authenticity.h5` via `model_loader.py` → resize to 512×512 → add batch dimension → `preprocess_input` → predict → decode with LabelEncoder → return `{label, confidence}`.
- Implement `python/utils/model_loader.py` with module-level singleton loading (load once, reuse).
- Write pytest tests for all three scripts (see Section 8).
- Document the expected JSON I/O contract for each script in the script's docstring.

**Definition of Done:** `pytest python/tests/test_preprocess.py python/tests/test_ocr.py python/tests/test_classify.py -v` — all pass. Manual cURL test of `classify_document.py` on a sample BIR Permit image returns `confidence ≥ 0.80`.

---

### Phase 6 — Python Scripts: Signature & Stamp Verification (YOLOv8 + Siamese + EfficientNet)

**Goal:** Implement and test the YOLOv8 region detection + Siamese CNN signature verification + EfficientNet stamp matching pipeline.

**Tasks:**
- Implement `python/signature_verify.py`:
  - Load `yolov8_document.pt` → run inference on full document image → extract bounding box for class `signature`.
  - Crop region → resize to Siamese input size → normalize → pass through `siamese_signature.h5` → generate 128-dim embedding.
  - **Verify-only** in the pipeline (the reference is enrolled at registration): load the vendor's stored reference embedding → compute Euclidean distance → convert to similarity score → apply threshold → return `{match, similarity}`. (A separate `mode == enroll` path is used by the registration flow, not the document pipeline.)
- Implement `python/stamp_verify.py`:
  - YOLOv8 detect `stamp`/`logo` class → crop region.
  - EfficientNet feature extraction → tamper check (always) → resolve the issuer from the document type's `issuer_scope` → look up the reference logo (by `document_type`, or `document_type` + OCR `city` for LGU) → cosine similarity against the issuer reference vector.
  - Return `{match, similarity_score}`; if the issuer has no reference yet, return `{match: false, reason: "unreferenced_logo"}` (or `no_issuer_logo` when `issuer_scope` is null). Threshold: 0.85.
- Implement `python/enroll_reference.py`: seeds a **city's** reference logo, invoked when an officer approves the first document carrying that city's logo → stores the city feature vector + reference image path. (Signature enrollment happens at registration and does not use this script.)
- Write pytest tests (see Section 8) using fixture images stored in `python/tests/fixtures/`.

**Definition of Done:** `pytest python/tests/test_signature_verify.py python/tests/test_stamp_verify.py -v` — all pass. Manual invocation on a genuine pair returns similarity ≥ 0.85; a forged/mismatched pair returns < 0.85.

---

### Phase 7 — Laravel ↔ Python Integration: Jobs, Services & Document Pipeline

**Goal:** Wire Laravel to the Python scripts through the full queued-job pipeline.

**Tasks:**
- Implement `DocumentPreprocessingService`, `OcrService`, `ClassificationService`, `SignatureVerificationService`, `StampVerificationService` (each using `Process` facade as shown in Section 6).
- Implement `ProcessDocumentAction`: orchestrates `preprocess → OCR → classify → signature_verify → stamp_verify → build ValidationReport → update risk score`.
- Implement `ProcessDocumentJob` (queued, $tries=3, $backoff, $timeout=300).
- Implement `EnrollReferenceJob`: triggered when a compliance officer approves the first document carrying a logo for an issuer that has no reference yet → seeds that issuer's reference logo (keyed by `document_type`, or `document_type` + city for LGU). (The signature reference is captured at registration, so it needs no pipeline enrollment job.)
- Implement `DocumentSubmissionController`: handle file upload, validate MIME/size, store to `storage/app/documents/{vendor_id}/`, create `Document` record, dispatch `ProcessDocumentJob`.
- Write `DocumentSubmissionTest` (Section 8).
- Add JSON payload temp file cleanup: always delete `python_payloads/*.json` after use (in `finally` blocks).

**Definition of Done:** Upload a real vendor PDF via Postman/cURL → `ProcessDocumentJob` runs → `ValidationReport` record created in DB with non-null `ocr_text`, `ml_results`, and `risk_score`. `php artisan test --filter=DocumentSubmissionTest` passes.

---

### Phase 8 — Admin Dashboard & Vendor Portal (Livewire/Volt + Flux)

**Goal:** Build the full UI for both the vendor-facing document portal and the compliance-officer admin dashboard. *(Placeholder role dashboards + landing page already exist from Phase 2; flesh them out here.)*

**Tasks:**
- Reuse the existing Flux app shell (`components/layouts/app` — sidebar/header). Make the sidebar nav role-aware.
- **Vendor views:** a document upload component (Livewire/Volt or a Flux file field) with drag-and-drop, MIME preview, and a 10 MB client guard, plus a submission status list.
- **Admin views:**
  - `admin/dashboard.blade.php`: stats cards (pending count, flagged count, approval rate).
  - `admin/reports/index.blade.php`: paginated table of validation reports, risk score badges, filter by status.
  - `admin/reports/show.blade.php`: full report detail — OCR text preview, ML result breakdown, signature similarity score, logo similarity score (vs the issuer's reference), approve/reject action buttons.
  - `admin/vendors/index.blade.php` and `show.blade.php`.
- Wire all admin action buttons to named routes; gate visibility with `@can`/Gates or `auth()->user()->hasRole(...)`.
- If a 2FA challenge UI is added (Phase 3), build its countdown/resend with a small Volt component or inline Alpine.
- All pages mobile-safe (Tailwind `sm:` breakpoints) even though the system is desktop-primary.

**Definition of Done:** A compliance officer can log in, view the dashboard, open a report, see OCR text and ML results, and click Approve/Reject. All Blade templates pass `php artisan view:cache` without errors.

---

### Phase 9 — Debugging, Bug Fixing & System Hardening

**Goal:** Systematically find and resolve all bugs, edge-case failures, and integration issues before final evaluation.

#### 9a — PHP/Laravel Debugging Checklist
- [ ] Run `php artisan test --coverage`. Identify any test below 80% coverage and write the missing tests.
- [ ] Check all `Process::run()` calls have proper timeout values; test with a deliberately slow Python script.
- [ ] Verify temp JSON payloads in `storage/app/python_payloads/` are always cleaned up (add `finally` if missing).
- [ ] Confirm `failed_jobs` handling: manually fail a `ProcessDocumentJob` 3 times → verify `Document.status` becomes `failed` and admin notification fires.
- [ ] Test file upload with exactly 10 MB file (boundary) and 10.1 MB file (should reject).
- [ ] Test upload of a `.php` file disguised as `.jpg` — verify MIME sniffing rejects it server-side.
- [ ] Verify session auth: login establishes a session; logout (`POST /logout`) ends it and redirects to `/`.
- [ ] Verify the `verified` middleware bounces unverified users to `verification.notice`; the signed verify link works.
- [ ] Verify Fortify login throttling (5/min per email+IP) returns a throttle error after repeated failures.
- [ ] If 2FA is enabled, test the challenge + recovery-code path.
- [ ] Run `php artisan route:list` and confirm no unexpected exposed routes.
- [ ] Verify role-based access: a vendor cannot access `admin.dashboard` (403). *(covered by `DashboardTest`)*

#### 9b — Python Script Debugging Checklist
- [ ] Run `pytest python/tests/ -v --tb=short`. Fix any failures.
- [ ] Test `preprocess.py` with a rotated/skewed scan — confirm output is still processable by OCR.
- [ ] Test `ocr_runner.py` with a low-resolution (72 DPI) image — log WER; document known degradation.
- [ ] Test `classify_document.py` with a corrupted/truncated PNG — confirm it exits with code 1 and writes to stderr (does not crash silently).
- [ ] Test `signature_verify.py` with a document where YOLOv8 detects zero signatures — confirm graceful JSON error output.
- [ ] Test `stamp_verify.py` with a photocopy-quality stamp — confirm similarity < 0.85 threshold correctly rejects.
- [ ] Verify model files load only once per process (singleton in `model_loader.py`); profile startup time.
- [ ] Check that all temp files created by Python scripts during tests are deleted by teardown fixtures.

#### 9c — Integration Debugging Checklist
- [ ] Run the full pipeline (upload → queue → Python → DB) on 5 different real document types. Confirm all 5 produce a `ValidationReport` with non-null fields.
- [ ] Test queue worker restart mid-job (kill worker during Python execution) — confirm job is retried correctly on worker restart.
- [ ] Test concurrent uploads from two vendors simultaneously — confirm no JSON payload file collision (use `$jobId` or `$documentId` in temp filenames).
- [ ] Confirm `storage/app/documents/` files are inaccessible via direct HTTP (not under `public/`).
- [ ] Profile end-to-end pipeline time on a 5-page PDF. Log time per stage. Document baseline.
- [ ] Verify Vite production build (`npm run build`) — no console errors in browser, all assets load with correct hashed URLs.
- [ ] Run `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache` — confirm app still works.

#### 9d — Known Edge Cases to Handle
- PDF with more than 2 pages: `pdf2image` should only convert first 2 pages (already in design; verify code enforces this).
- Document with no detectable signature region: YOLOv8 returns empty detections → `signature_verify.py` should return `{"match": false, "reason": "no_signature_detected"}` — handle this in `SignatureVerificationService`.
- Signature reference always exists (enrolled at registration), so `ProcessDocumentAction` always *verifies* the signature — there is no first-submission enrollment branch for signatures.
- Logo for an issuer with no reference yet: `ProcessDocumentAction` should skip the similarity comparison, raise the `unreferenced_logo` flag, and (only on officer approval) dispatch `EnrollReferenceJob` to seed that issuer's reference — confirm this branching logic.
- `lgu` document type where OCR cannot identify a city: `stamp_verify.py` cannot scope the lookup → raise a `city_not_identified` flag and skip logo verification. For `issuer_scope = null` types (e.g. Signed Contract), logo verification is skipped entirely (`no_issuer_logo`).
- Verification/reset email delivery failure (SMTP down): the queued mail retries; the user can re-request via Fortify's resend (`verification.send`) / forgot-password, which are rate-limited. If a custom email-OTP 2FA is added (Phase 3, Option B), give it the same rate-limited resend.

---

### Phase 10 — ISO 25010 Evaluation, Final Testing & Deployment Prep

**Goal:** Formally evaluate the system against ISO/IEC 25010 quality characteristics; prepare for handover and production deployment.

#### ISO 25010 Evaluation Targets

| Characteristic | Measurement | Target |
|---|---|---|
| Functional Suitability | All 4 statement-of-the-problem objectives met (test cases pass) | 100% test coverage of defined features |
| Performance Efficiency | End-to-end document validation pipeline completes within 60 s for a single-page PDF | Median < 60 s |
| Compatibility | System runs on Chrome 120+, Edge 120+, Firefox 120+ | No JS/CSS errors on all three browsers |
| Usability | Compliance officer can complete a full review in under 3 minutes (timed walkthrough) | < 3 min median task time |
| Reliability | Queue worker runs for 24 h without crashing under simulated load | Zero unhandled crashes |
| Security | Session auth + logout invalidation, MIME type spoofing rejected, role-based access enforced | All security tests pass |
| Maintainability | Code passes `php artisan test --coverage` at ≥ 80%; Python pytest coverage ≥ 75% | Coverage thresholds met |
| Portability | Application runs identically on Windows 11 (WAMP/WSL2) and Ubuntu 22.04 | Setup succeeds on both OS |

#### Final Tasks
- [ ] Complete evaluator walkthrough with compliance officer respondent; collect ISO 25010 survey.
- [ ] Fix any issues found during evaluation walkthrough.
- [ ] Tag release `v1.0.0` in Git.
- [ ] Write deployment `DEPLOY.md`: nginx config, supervisor config for queue workers, PHP-FPM settings, Python venv path for production, model file placement.
- [ ] Ensure `APP_DEBUG=false` and `APP_ENV=production` in production `.env`.
- [ ] Run `php artisan optimize` in production (caches config, routes, views).
- [ ] Confirm `python/models/` directory is never committed to Git (add to `.gitignore`); document how to obtain models (team shared drive path or training instructions).
- [ ] Archive thesis project: export final DB schema (`php artisan schema:dump`), export Python model training notebooks if any, finalize this `CLAUDE.md`.

---

## Quick Reference — Common Commands

```bash
# Laravel
php artisan serve                          # start dev server
composer run dev                           # serve + queue:listen + vite (concurrently)
php artisan migrate:fresh --seed           # reset DB (re-seeds role accounts)
php artisan queue:work                     # start queue worker
php artisan test --compact                 # run all tests
php artisan test --filter=DashboardTest    # run specific test class
php artisan route:list --except-vendor     # app routes (add --only-vendor for Fortify routes)
php artisan make:model Foo -mcsf           # model + migration + controller + seeder + factory
php artisan tinker                         # interactive REPL
vendor/bin/pint --dirty                    # format changed PHP files (run before committing)

# Vite
npm run dev                                # dev server with HMR
npm run build                              # production build

# Python
cd python && source venv/bin/activate      # activate venv
pytest tests/ -v                           # run all tests
pytest tests/ --cov=. --cov-report=term    # with coverage
python3 classify_document.py --input x.json --output y.json  # manual run

# Queue debugging
php artisan queue:failed                   # list failed jobs
php artisan queue:retry all               # retry all failed jobs
php artisan queue:flush                   # clear failed jobs table
```

---

*Last updated: June 2026 — ADVS v1.0 (Laravel 12 Livewire starter kit · Fortify auth) — Pamantasan ng Lungsod ng Pasig, College of Computer Studies*
*Authors: Lopez, Marvin S. · Inocencio, Ron Alexander A. · Recto, Jason Jay M.*
*Adviser: Riegie Dy Tan, DIT*

===

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


---

## File: docs/archive/COLOR_PALETTE_LEGACY.md

/* ClickUp brand */
--cu-purple: #7B68EE;
--cu-pink: #FD71AF;
--cu-blue: #49CCF9;
--cu-yellow: #FFC800;

/* Dark website theme */
--cu-bg: #0D0D0F;
--cu-surface: #1A1A2E;
--cu-text: #FFFFFF;
--cu-muted: #A0A0B0;

/* Signature gradient */
--cu-gradient: linear-gradient(90deg, #7B68EE, #FD71AF);

---

## File: docs/archive/HUGGINGFACE_DEPLOYMENT_LEGACY.md

# ADVS ML Pipeline → Hugging Face Spaces Deployment Guide

Architecture: **Laravel 11 (ADVS)** → HTTPS/JSON → **FastAPI on HF Space** → ResNet-50 → PyTesseract → YOLOv8 → Siamese CNN → EfficientNet

---

## 1. Repo structure

```
advs-ml-api/
├── README.md              # HF Space metadata header (required)
├── Dockerfile
├── requirements.txt
├── main.py
├── auth.py
├── models/
│   ├── classifier.py       # ResNet-50 (document type)
│   ├── ocr.py               # PyTesseract
│   ├── detector.py           # YOLOv8 (signature/stamp regions)
│   ├── signature_verify.py   # Siamese CNN
│   └── stamp_verify.py       # EfficientNet
└── weights/                  # .pt / .pth files
```

## 2. HF Space README header

Every Space needs this YAML block at the top of README.md:

```yaml
---
title: ADVS ML API
emoji: 📄
colorFrom: blue
colorTo: gray
sdk: docker
app_port: 7860
pinned: false
---
```

## 3. Dockerfile

```dockerfile
FROM python:3.10-slim

RUN apt-get update && apt-get install -y \
    tesseract-ocr libtesseract-dev libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .
EXPOSE 7860
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "7860"]
```

## 4. requirements.txt

```
fastapi
uvicorn[standard]
torch --index-url https://download.pytorch.org/whl/cpu
torchvision --index-url https://download.pytorch.org/whl/cpu
ultralytics
pytesseract
pillow
python-multipart
numpy
python-dotenv
```

## 5. main.py — key design decisions

**Load all 5 models once at startup, not per request.** Cold-loading 5 models on every call will eat your free CPU quota fast.

```python
from fastapi import FastAPI, UploadFile, Depends, HTTPException
from contextlib import asynccontextmanager
from auth import verify_token
import torch

models = {}

@asynccontextmanager
async def lifespan(app: FastAPI):
    torch.set_num_threads(2)  # free tier is CPU-limited, don't oversubscribe
    models["classifier"] = load_resnet50()
    models["detector"] = load_yolov8()
    models["sig_verify"] = load_siamese()
    models["stamp_verify"] = load_efficientnet()
    yield
    models.clear()

app = FastAPI(lifespan=lifespan)

@app.post("/validate", dependencies=[Depends(verify_token)])
async def validate_document(file: UploadFile):
    # Run the full pipeline in one round trip instead of 5 separate
    # HTTP calls from Laravel — much cheaper over the network.
    image = await load_image(file)
    doc_type = classify(models["classifier"], image)
    text = extract_text(image)                    # pytesseract, no GPU/model load
    regions = detect_regions(models["detector"], image)
    sig_score = verify_signature(models["sig_verify"], regions)
    stamp_score = verify_stamp(models["stamp_verify"], regions)
    return {
        "document_type": doc_type,
        "extracted_text": text,
        "signature_match": sig_score,
        "stamp_match": stamp_score,
    }
```

`auth.py` — simple bearer token check:

```python
import os
from fastapi import Header, HTTPException

def verify_token(authorization: str = Header(...)):
    token = authorization.replace("Bearer ", "")
    if token != os.environ.get("API_TOKEN"):
        raise HTTPException(status_code=401, detail="Invalid token")
```

## 6. Memory/performance notes for your specific 5 models

- ResNet-50 (~100MB) + YOLOv8n/s (~10–40MB) + a Siamese CNN and EfficientNet-B0 (~20MB) all fit comfortably under 16GB. PyTesseract isn't a loaded model — it shells out to the tesseract binary, so it adds negligible RAM.
- Use `yolov8n` or `yolov8s`, not larger variants — no accuracy need to justify the extra load time on CPU.
- **Free Spaces sleep after inactivity.** First request after sleep costs 30–60s cold start. Plan for this in Laravel (see below) — don't assume instant response during your defense demo.

## 7. Secrets

In the Space: **Settings → Repository secrets → `API_TOKEN`**. Read it in Python via `os.environ["API_TOKEN"]`. Never hardcode it in the repo.

## 8. Push to HF

```bash
huggingface-cli login          # paste your HF token once
git clone https://huggingface.co/spaces/<your-username>/advs-ml-api
cd advs-ml-api
# copy in your files
git add .
git commit -m "Initial ADVS ML API"
git push
```

Space builds automatically on push; watch the build logs in the Space UI.

## 9. Laravel integration

`.env`:
```
ML_API_URL=https://<your-username>-advs-ml-api.hf.space
ML_API_TOKEN=your-token-here
```

`config/services.php`:
```php
'ml_pipeline' => [
    'url' => env('ML_API_URL'),
    'token' => env('ML_API_TOKEN'),
],
```

`app/Services/MLPipelineService.php`:
```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\UploadedFile;

class MLPipelineService
{
    public function validateDocument(UploadedFile $file): array
    {
        $response = Http::withToken(config('services.ml_pipeline.token'))
            ->timeout(90) // account for cold starts
            ->retry(2, 5000)
            ->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
            ->post(config('services.ml_pipeline.url') . '/validate');

        if ($response->failed()) {
            throw new \RuntimeException('ML pipeline error: ' . $response->body());
        }

        return $response->json();
    }
}
```

**Important:** because cold starts + 5-model inference can take 10–30s+, don't call this synchronously inside a web request. Dispatch it as a **queued Job**, write the result to the document's DB row when done, and have the frontend poll or use Livewire/broadcasting to reflect the status change. This also protects your app if the Space is asleep when a vendor uploads a document.

## 10. Testing checklist

- `curl` each endpoint directly against the Space URL with a sample document image
- Time a cold-start request vs. a warm one
- Test with actual BIR/vendor documents from your 7-document-type checklist, not just clean samples
- Free tier has limited concurrency — test what happens with 2–3 simultaneous uploads

## 11. Defense-day consideration

Free CPU Basic is fine for development and most demos. If your panel defense needs zero cold-start delay, either:
- Set up a scheduled ping (cron job hitting a `/health` endpoint every ~10 min) to keep it warm, or
- Temporarily upgrade to a paid persistent Space for defense week only, then downgrade after.

Given your cost-consciousness, the ping-keep-alive approach is free and usually sufficient for a live demo.


---

## File: docs/archive/SPRINT_GUIDE_LEGACY.md

# ADVS Sprint Guide for 3 Independent Members

This guide turns the current ADVS repository into a complete implementation plan for three developers working in parallel. It uses `AGENTS.md` as the ground truth for what is built today, and uses `ADVS_System_Reference.md` for target domain behavior.

Current baseline from `AGENTS.md`:

- Laravel 12, PHP 8.2, Fortify session auth, Livewire 4, Volt, Flux UI, Tailwind v4, MySQL.
- Auth, roles, signature enrollment, admin user management, system settings, audit trail, and theme system are real and tested.
- Officer/admin review dashboards and vendor portal are UI prototypes backed by `App\Support\DemoData`, `DemoStore`, and `VendorDemoData`.
- Pipeline tables exist as migrations, but pipeline models, jobs, services, actions, and Python inference scripts are not implemented yet.
- Python currently has training scaffolding only. Use `python/env/Scripts/python.exe`, not bare `python` or `py`.

The system is complete when a vendor can register, enroll a reference signature, submit real documents, trigger a queued pipeline, receive validation results, and have a compliance officer approve or reject the submission with an auditable trail and notifications.

---

## 1. Team Split

Each member owns a vertical workstream with explicit contracts so work can proceed independently.

| Member | Primary ownership | Main deliverables | Must not own |
|---|---|---|---|
| Member 1 | Laravel backend, schema, Eloquent, upload intake, queues, risk score, notifications, audit | Pipeline models, services, jobs, upload controller, risk/report persistence, officer decision writes | Python model training and UI visual polish |
| Member 2 | Python ML/inference, OCR, image processing, JSON CLI contracts, model training artifacts | `python/utils/*`, inference scripts, model loaders, training validation, fixture outputs | Laravel controllers and Livewire screens |
| Member 3 | Livewire/Volt/Flux UI, role-scoped user flows, dashboard wiring, frontend tests/build | Vendor portal, officer review pages, admin threshold/model/reference screens, replacement of demo data in views | Python inference internals and database migrations |

Shared responsibility:

- All members write tests for their own changes.
- All members preserve role boundaries: `vendor`, `compliance_officer`, `admin`.
- All members use named routes, not hardcoded URLs.
- All members keep the human-in-the-loop rule: the pipeline flags risk; officers make decisions.

---

## 2. Working Rules

### Branching

Base all feature branches on `staging`.

Suggested branches:

- `feature/member-1-backend-pipeline`
- `feature/member-2-python-inference`
- `feature/member-3-real-ui`

Merge order inside each sprint:

1. Contract-only changes.
2. Backend/Python service implementation behind tests.
3. UI wiring against stable read-model shapes.
4. Integration fixes.

### Definition of Done for Every PR

Each PR must include:

- Code or docs scoped to one workstream.
- A test proving the changed behavior.
- No unrelated formatting churn.
- No removal of existing tests.
- `vendor/bin/pint --dirty --format agent` for PHP edits.
- Affected test command in the PR notes.

Minimum verification commands:

```bash
php artisan test --compact tests/Feature/RelevantTest.php
vendor/bin/pint --dirty --format agent
npm run build
python/env/Scripts/python.exe -m pytest python/tests -q
```

Run only the commands relevant to the changed files.

### Contract-First Rule

Members can work independently by honoring these contracts:

- Member 1 can build Laravel pipeline orchestration against fake Python JSON outputs until Member 2 finishes real scripts.
- Member 2 can build Python scripts using JSON fixtures without waiting for Laravel.
- Member 3 can build UI against read-model arrays that match the final Eloquent data shape, then swap the provider from demo data to real services.

Do not let controllers call Python directly. Laravel invokes Python only inside queued jobs or services through the `Process` facade.

---

## 3. Cross-Team Technical Contracts

### Python CLI Contract

Every inference script must accept:

```bash
python/env/Scripts/python.exe python/scripts/script_name.py --input storage/app/python_payloads/input.json --output storage/app/python_payloads/output.json
```

Rules:

- Input and output are JSON files.
- Exit code `0` means success.
- Non-zero exit code means failure; write error details to stderr and output JSON when possible.
- Scripts must not print non-JSON business output to stdout.
- Paths returned to Laravel must be project-relative or storage-disk paths agreed with Member 1.

Required scripts:

| Script | Owner | Purpose |
|---|---|---|
| `python/scripts/preprocess.py` | Member 2 | Grayscale, binarize, morph-open, invert, save preprocessed image |
| `python/scripts/ocr_runner.py` | Member 2 | PyTesseract OCR plus extracted fields |
| `python/scripts/classify_document.py` | Member 2 | ResNet-50 document type classification |
| `python/scripts/signature_verify.py` | Member 2 | YOLO signature crop plus Siamese verification |
| `python/scripts/stamp_verify.py` | Member 2 | YOLO stamp crop plus EfficientNet issuer-logo comparison |
| `python/scripts/enroll_reference.py` | Member 2 | Generate/store issuer logo reference vector after officer approval |


### Core JSON Output Shapes

Preprocess:

```json
{
  "preprocessed_path": "storage/app/private/processed/1/2/page-1.png",
  "width": 2480,
  "height": 3508,
  "flags": []
}
```

OCR:

```json
{
  "text": "raw extracted text",
  "confidence": 0.82,
  "fields": {
    "business_name": "Sample Vendor Inc.",
    "document_number": "123-456-789",
    "issue_date": "2026-01-01",
    "expiry_date": "2026-12-31",
    "city": "Pasig"
  },
  "missing_fields": ["tin"],
  "flags": ["missing_tin"]
}
```

Classification:

```json
{
  "label": "bir_permit",
  "confidence": 0.91,
  "scores": {
    "bir_permit": 0.91,
    "business_registration": 0.05,
    "financial_statement": 0.03,
    "fake": 0.01
  },
  "flags": []
}
```

Signature verification:

```json
{
  "detected": true,
  "bbox": [120, 840, 460, 1010],
  "distance": 0.84,
  "threshold": 1.2,
  "similarity": 0.88,
  "match": true,
  "flags": []
}
```

Stamp verification:

```json
{
  "detected": true,
  "bbox": [690, 760, 940, 1010],
  "issuer_scope": "lgu",
  "issuer_key": {
    "document_type": "business_registration",
    "city": "Pasig"
  },
  "similarity": 0.89,
  "threshold": 0.85,
  "match": true,
  "flags": []
}
```

All scores use `0.0` to `1.0` internally. Laravel converts the composite risk score to `0` to `100`.

### UI Read-Model Contract

Member 3 should render pages from arrays or DTOs with stable keys. Member 1 later supplies these from Eloquent services.

Submission summary:

```php
[
    'id' => 1,
    'vendor_name' => 'Sample Vendor Inc.',
    'submitted_at' => '2026-07-03 09:15:00',
    'status' => 'pending_review',
    'risk_score' => 42,
    'risk_level' => 'medium',
    'flags_count' => 3,
    'documents_count' => 2,
]
```

Validation detail:

```php
[
    'submission' => [...],
    'documents' => [
        [
            'id' => 10,
            'filename' => 'bir-permit.pdf',
            'status' => 'completed',
            'document_type' => 'bir_permit',
            'ocr' => [...],
            'classification' => [...],
            'signature' => [...],
            'stamp' => [...],
            'risk' => [...],
            'flags' => [...],
        ],
    ],
    'decision' => [
        'reviewed_by' => null,
        'reviewed_at' => null,
        'decision' => null,
        'comments' => null,
    ],
]
```

---

## 4. Sprint Roadmap

Assume 8 sprints. If the team uses one-week sprints, keep each sprint narrow. If using two-week sprints, include hardening and review inside the sprint.

---

## Sprint 0: Baseline, Contracts, and Schema Recon

Goal: Freeze contracts and confirm the real database shape before building pipeline code.

### Member 1: Backend

Tasks:

- Inspect migrations for `vendors`, `document_types`, `submissions`, `documents`, `validation_results`, `vendor_embeddings`, `logo_references`, and `notifications`.
- Run schema reconnaissance:

```bash
php artisan migrate:fresh --seed
php artisan db:show
php artisan db:table submissions
php artisan db:table documents
php artisan db:table validation_results
```

- Draft model relationship map:
  - `User hasOne Vendor`
  - `Vendor belongsTo User`
  - `Vendor hasMany Submission`
  - `Submission belongsTo Vendor`
  - `Submission hasMany Document`
  - `Document belongsTo Submission`
  - `Document belongsTo DocumentType`
  - `Document hasOne ValidationResult`
  - `Vendor hasOne VendorEmbedding`
  - `LogoReference belongsTo DocumentType`
- Decide exact enum strings from the live migrations.
- Create backend contract tests using fake Python outputs.

Deliverables:

- Schema map in PR description.
- Test fixtures under `tests/Fixtures/Pipeline/`.
- No production behavior yet.

### Member 2: Python

Tasks:

- Create fixture input/output JSON examples for every CLI script.
- Confirm ML venv works:

```bash
python/env/Scripts/python.exe --version
python/env/Scripts/python.exe -m pip list
```

- Inventory existing training scripts and missing inference scripts.
- Create `python/utils/json_io.py` design:
  - read JSON input
  - validate required keys
  - atomic write output
  - consistent error response
- Create pytest contract tests that do not require trained weights.

Deliverables:

- `python/tests/test_contract_shapes.py`
- JSON fixtures under `python/tests/fixtures/`
- No Laravel dependency.

### Member 3: UI

Tasks:

- Inventory all demo-backed views:
  - `resources/views/livewire/vendor/*`
  - `resources/views/livewire/admin/pending.blade.php`
  - `resources/views/livewire/admin/submissions/show.blade.php`
  - `resources/views/livewire/admin/dashboard.blade.php`
  - `resources/views/livewire/admin/notifications.blade.php`
  - `resources/views/livewire/admin/vendors/*`
- Record the array keys currently consumed from `DemoData`, `DemoStore`, and `VendorDemoData`.
- Define read-model interfaces or service names that can replace demo data later.
- Confirm theme tests and Flux/Volt conventions.

Deliverables:

- UI data-shape map in PR description.
- No visual redesign yet.

Sprint 0 integration gate:

- All three members agree on JSON and UI read-model shapes.
- No code path depends on missing ML weights.
- No existing auth/admin/theme tests regress.

---

## Sprint 1: Pipeline Models and Python Utilities

Goal: Add the missing foundation without touching the demo UI yet.

### Member 1: Backend

Tasks:

- Create Eloquent models using Artisan:

```bash
php artisan make:model Vendor --factory --no-interaction
php artisan make:model DocumentType --factory --no-interaction
php artisan make:model Submission --factory --no-interaction
php artisan make:model Document --factory --no-interaction
php artisan make:model ValidationResult --factory --no-interaction
php artisan make:model VendorEmbedding --factory --no-interaction
php artisan make:model LogoReference --factory --no-interaction
php artisan make:model Notification --factory --no-interaction
```

- Add casts through `casts()` methods, matching live schema.
- Add relationships only after schema inspection.
- Add factories that produce valid rows.
- Add model tests for relationships and casts.

Tests:

```bash
php artisan make:test --phpunit PipelineModelTest --no-interaction
php artisan test --compact --filter=PipelineModelTest
```

Deliverables:

- Models, factories, relationship tests.

### Member 2: Python

Tasks:

- Implement:
  - `python/utils/json_io.py`
  - `python/utils/image_utils.py`
  - `python/utils/model_loader.py`
- Add deterministic dry-run mode for inference scripts so tests can pass without model weights.
- Implement `preprocess.py` with OpenCV if available and a clear failure when dependencies are missing.
- Use parameters from system settings/reference defaults:
  - `BINARIZATION_THRESHOLD = 150`
  - `MORPH_KERNEL_SIZE = 2`
  - `PDF_DPI = 300`
  - `MAX_PDF_PAGES = 2`

Tests:

```bash
python/env/Scripts/python.exe -m pytest python/tests -q
```

Deliverables:

- Utility modules.
- Preprocess script and tests.

### Member 3: UI

Tasks:

- Add read-model service placeholders that return the same shape as current demo data.
- Keep views rendering with current demo data while isolating calls behind one method per page.
- Confirm no raw color additions; use `cu-*` tokens.
- Add empty/error/loading states where missing.

Tests:

```bash
php artisan test --compact tests/Feature/Theme
npm run build
```

Deliverables:

- UI seam ready for real data swap.
- No behavior change visible to users.

Sprint 1 integration gate:

- Backend models compile and factories work.
- Python scripts can run fixture tests.
- UI can be switched page-by-page from demo arrays to service arrays.

---

## Sprint 2: Real Vendor Upload Intake

Goal: Replace the fake vendor submit flow with real persistence and queue dispatch.

### Member 1: Backend

Tasks:

- Create `DocumentSubmissionController` or a Volt-compatible action endpoint.
- Create a Form Request for uploads:
  - accepted MIME: PDF, PNG, JPG, JPEG
  - per-file max: `MAX_FILE_SIZE_MB`, default 10 MB
  - server-side MIME check via uploaded file MIME, not extension only
  - authenticated vendor only
- Store files privately under a stable pattern:

```text
storage/app/private/submissions/{vendor_id}/{submission_id}/originals/
storage/app/private/submissions/{vendor_id}/{submission_id}/processed/
```

- Create a `Submission` row and one `Document` row per file.
- Create `ProcessDocumentJob`.
- Create `ProcessDocumentAction` shell that can consume fake Python outputs.
- Dispatch the job after DB write.
- Audit the upload event.
- Notify vendor that submission was received.

Tests:

```bash
php artisan make:test --phpunit DocumentSubmissionTest --no-interaction
php artisan test --compact tests/Feature/DocumentSubmissionTest.php
```

Test cases:

- Vendor upload happy path creates submission and documents.
- Queue assertion: `Queue::assertPushed(ProcessDocumentJob::class)`.
- Invalid MIME rejected.
- Oversized file rejected.
- Officer/admin cannot use vendor upload endpoint unless intended.

Deliverables:

- Real server-side upload path.
- Queue job exists but can still use fake script outputs.

### Member 2: Python

Tasks:

- Add PDF conversion helper using `pdf2image` for first 2 pages at 300 DPI.
- Add image metadata extraction.
- Make `preprocess.py` accept both image paths and converted PDF page paths.
- Return structured flags for blank/unreadable images instead of crashing.

Tests:

```bash
python/env/Scripts/python.exe -m pytest python/tests/test_preprocess.py -q
```

Deliverables:

- Preprocess can handle the input types Member 1 stores.

### Member 3: UI

Tasks:

- Wire `resources/views/livewire/vendor/submit.blade.php` to the real upload endpoint/action.
- Keep client-side validation as a convenience only; server remains authoritative.
- Add upload progress, success, and error states using Flux components.
- After successful upload, route to `vendor.submissions` or the new submission detail.
- Remove only the submit page's dependency on `VendorDemoData`; leave other pages untouched for now.

Tests:

```bash
php artisan test --compact --filter=VendorPortal
npm run build
```

Deliverables:

- Vendor can upload real files from the UI.

Sprint 2 integration gate:

- Manual vendor upload creates DB records.
- Queue job is pushed.
- No file is stored in public web-accessible storage.

---

## Sprint 3: OCR, Classification, and Backend Stage Persistence

Goal: Save real stage outputs to `validation_results` using stable JSON contracts.

### Member 1: Backend

Tasks:

- Implement service wrappers around Python scripts:
  - `PreprocessDocumentService`
  - `OcrDocumentService`
  - `ClassifyDocumentService`
- Services use Laravel `Process` facade.
- Payload files are unique per document/job and deleted in `finally`.
- Persist stage status on `documents.processing_status`:
  - `queued`
  - `preprocessing`
  - `ocr`
  - `classifying`
  - `failed`
  - final statuses from live schema
- Persist OCR text, OCR fields, classification label/confidence into `validation_results`.
- Do not abort the whole pipeline for low confidence; record flags.

Tests:

```bash
php artisan make:test --phpunit ProcessDocumentActionTest --no-interaction
php artisan test --compact --filter=ProcessDocumentActionTest
```

Test cases:

- Fake successful script outputs persist into `validation_results`.
- Script failure marks document failed and records error.
- Low OCR/classification confidence produces flags, not an exception.

Deliverables:

- First half of pipeline persisted.

### Member 2: Python

Tasks:

- Implement `ocr_runner.py`:
  - PyTesseract `--psm 6`
  - raw text output
  - confidence when available
  - regex extraction for document number, business name, issue date, expiry date, city
- Implement `classify_document.py`:
  - load `resnet50_authenticity.h5` and label mapping when present
  - dry-run fallback for contract tests
  - return low-confidence output instead of crashing on unknown input
- Add `python/utils/model_loader.py` singleton cache.

Tests:

```bash
python/env/Scripts/python.exe -m pytest python/tests/test_ocr_runner.py -q
python/env/Scripts/python.exe -m pytest python/tests/test_classify_document.py -q
```

Deliverables:

- OCR and classification scripts ready for Laravel.

### Member 3: UI

Tasks:

- Wire `vendor/submissions` to real `Submission` and `Document` read models.
- Show per-document processing states.
- Add "processing", "failed", and "pending review" badges.
- Add a vendor submission detail page if the existing page lacks real document breakdown.
- Keep risk details hidden from vendors unless product rules explicitly allow a vendor-safe status summary.

Tests:

```bash
php artisan test --compact --filter=VendorPortal
npm run build
```

Deliverables:

- Vendor can see real upload and processing status.

Sprint 3 integration gate:

- Upload a document, run the queue synchronously, and see OCR/classification fields in the DB.
- Vendor UI reflects real status.

---

## Sprint 4: Signature, Stamp, Embeddings, and Logo References

Goal: Complete verification components and reference storage rules.

### Member 1: Backend

Tasks:

- Implement `VendorEmbedding` creation for registration signature enrollment.
- Replace or wrap the mock `SignatureAuthenticityService` so it delegates to Python verification when available.
- Implement service wrappers:
  - `VerifySignatureService`
  - `VerifyStampService`
  - `EnrollLogoReferenceService`
- Enforce reference rules:
  - Signatures are per vendor and enrolled during registration.
  - Stamps/logos are per issuer, not per vendor.
  - National issuers key by `document_type_id` and empty city sentinel when schema requires it.
  - LGU issuers key by `document_type_id` plus detected city.
- Persist signature/stamp scores and flags to `validation_results`.

Tests:

```bash
php artisan test --compact --filter=SignatureEnrollment
php artisan test --compact --filter=DocumentVerification
```

Test cases:

- Vendor without signature reference cannot complete registration or cannot submit, depending on existing middleware behavior.
- Signature mismatch becomes a flag.
- Missing stamp becomes a flag.
- Unknown issuer logo becomes a flag, not an exception.

Deliverables:

- Signature and stamp verification integrated.

### Member 2: Python

Tasks:

- Implement `signature_verify.py`:
  - YOLO signature detection
  - crop extraction
  - Siamese embedding
  - Euclidean distance against reference
  - dry-run fallback
- Implement `stamp_verify.py`:
  - YOLO stamp/logo detection
  - EfficientNet feature vector
  - cosine similarity against issuer reference
  - reason flags for no stamp, no issuer logo, no city, unreferenced logo
- Implement `enroll_reference.py`.
- Add tests for every no-detection and unreferenced path.

Tests:

```bash
python/env/Scripts/python.exe -m pytest python/tests/test_signature_verify.py -q
python/env/Scripts/python.exe -m pytest python/tests/test_stamp_verify.py -q
python/env/Scripts/python.exe -m pytest python/tests/test_enroll_reference.py -q
```

Deliverables:

- Verification scripts with stable output.

### Member 3: UI

Tasks:

- Wire officer pending queue to real submissions.
- Sort by highest risk when risk exists; otherwise sort newest/pending.
- Show columns:
  - vendor
  - submitted date
  - status
  - document count
  - risk score
  - risk level
  - flags count
- Add filters for status and risk level.
- Keep admin and officer access controlled with `role:compliance_officer,admin` or equivalent existing middleware pattern.

Tests:

```bash
php artisan test --compact --filter=OfficerReview
npm run build
```

Deliverables:

- Officer queue reads real submissions.

Sprint 4 integration gate:

- Pipeline stores signature/stamp outputs.
- Officer can see the real pending submission.

---

## Sprint 5: Risk Score and Validation Reports

Goal: Generate the final validation report and risk score used by officers.

### Member 1: Backend

Tasks:

- Implement `RiskScoreService`.
- Pull weights and thresholds from `SystemSettingsService`.
- Defaults from `AGENTS.md` target:
  - text weight `0.25`
  - classification weight `0.25`
  - signature weight `0.25`
  - stamp weight `0.25`
  - missing component penalty `15`
  - low risk `0-30`
  - medium risk `31-60`
  - high risk `61-100`
  - classification threshold `0.70`
  - YOLO threshold `0.50`
  - signature distance threshold `1.20`
  - stamp similarity threshold `0.85`
- Normalize component scores carefully:
  - OCR/text score contributes risk as `1 - text_score`.
  - Classification contributes risk as `1 - confidence`.
  - Signature contributes risk from distance/similarity according to the chosen persisted field.
  - Stamp contributes risk as `1 - similarity`.
  - Missing required component adds penalty.
- Update `submissions.composite_risk_score`, `submissions.risk_level`, and status to pending review when all documents complete.
- Create report query service for UI.

Tests:

```bash
php artisan make:test --phpunit RiskScoreServiceTest --unit --no-interaction
php artisan test --compact --filter=RiskScoreServiceTest
```

Test cases:

- All components pass gives low risk.
- One missing component adds penalty.
- Low classification confidence increases risk.
- High risk starts at 61.
- Medium risk starts at 31.

Deliverables:

- Deterministic risk scoring with tests.

### Member 2: Python

Tasks:

- Train or smoke-test available model scripts:

```bash
python/env/Scripts/python.exe python/scripts/train_classifier.py --dry-run
python/env/Scripts/python.exe python/scripts/train_detector.py --dry-run
python/env/Scripts/python.exe python/scripts/train_signature.py --dry-run
```

- Produce or document local model artifacts under `python/models/`:
  - `resnet50_authenticity.h5`
  - label mapping
  - YOLO weights
  - Siamese weights
  - EfficientNet/stamp artifacts
- Ensure model files remain gitignored.
- Add clear error messages when weights are missing.

Tests:

```bash
python/env/Scripts/python.exe -m pytest python/tests -q
```

Deliverables:

- Model artifact readiness notes and scripts that fail clearly if weights are missing.

### Member 3: UI

Tasks:

- Wire validation report page to real report query service.
- Show per-document breakdown:
  - OCR text and missing fields
  - classification label/confidence
  - signature distance/similarity
  - stamp similarity and issuer key
  - flags
  - component scores
  - composite risk score
- Use `flux:badge` colors for risk:
  - low: green
  - medium: yellow
  - high: red
- Add expandable sections for details.
- Do not use inline styles or raw colors.

Tests:

```bash
php artisan test --compact --filter=OfficerReview
php artisan test --compact tests/Feature/Theme
npm run build
```

Deliverables:

- Officer can inspect real validation report.

Sprint 5 integration gate:

- A processed submission reaches pending review with risk score and report details visible to officer/admin.

---

## Sprint 6: Decisions, Notifications, Audit, and Archive

Goal: Complete human review and traceability.

### Member 1: Backend

Tasks:

- Implement approve/reject actions through controller or Livewire action service.
- Enforce role access with policy or `role:` middleware.
- Record:
  - `reviewed_by`
  - `reviewed_at`
  - decision
  - comments/reason
- Update vendor accreditation status based on approved/rejected submission.
- Create notifications:
  - vendor submission received
  - vendor processing complete
  - officer high-risk submission
  - vendor approved
  - vendor rejected
- Write audit logs for:
  - upload
  - pipeline failure
  - risk report generated
  - approve/reject
  - settings changes, if touched
- Trigger logo reference enrollment after approval when a document has an unreferenced issuer logo and a valid stamp crop/vector exists.

Tests:

```bash
php artisan test --compact --filter=OfficerWorkflow
php artisan test --compact --filter=Notifications
php artisan test --compact --filter=Audit
```

Deliverables:

- End-to-end officer decision state changes.

### Member 2: Python

Tasks:

- Harden script failure paths:
  - corrupt image
  - missing file
  - missing weights
  - empty OCR
  - no signature
  - no stamp
  - invalid JSON input
- Ensure scripts return flags rather than crashing for expected poor-input cases.
- Add timing logs to stderr or structured debug file if needed.

Tests:

```bash
python/env/Scripts/python.exe -m pytest python/tests -q
```

Deliverables:

- Predictable error and flag behavior for Laravel.

### Member 3: UI

Tasks:

- Wire approve/reject buttons to real actions.
- Require comment for rejection.
- Show confirmation modal before final decision.
- Update archive page to real reviewed submissions.
- Wire notifications page to real `notifications` table.
- Add unread/read state if table supports it.
- Ensure vendor sees decision status and notification.

Tests:

```bash
php artisan test --compact --filter=OfficerWorkflow
php artisan test --compact --filter=VendorPortal
npm run build
```

Deliverables:

- Review workflow usable from UI.

Sprint 6 integration gate:

- Officer approves/rejects a real processed submission.
- Vendor sees updated status and notification.
- Audit log contains decision event.

---

## Sprint 7: Admin Controls and Production Hardening

Goal: Make the system configurable, observable, and safe to demo or deploy.

### Member 1: Backend

Tasks:

- Ensure every threshold used by pipeline comes from `system_settings`.
- Add validation for risk weight totals if settings UI allows editing all weights.
- Add secured download/preview controller for documents:
  - vendors can only access own documents
  - officers/admins can access review documents
  - no direct public storage access
- Add failed job handling:
  - document marked failed
  - admin/officer notification
  - error stored for review
- Add data retention command if required by scope.
- Confirm cache commands work:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Tests:

```bash
php artisan test --compact --filter=DocumentAccess
php artisan test --compact --filter=SystemSettings
```

Deliverables:

- Hardened backend boundaries and settings integration.

### Member 2: Python

Tasks:

- Optimize model loading so each script avoids repeated expensive setup where practical.
- Confirm memory and runtime on typical files.
- Record baseline processing time for:
  - JPG
  - PNG
  - 1-page PDF
  - 2-page PDF
- Document local model setup in existing allowed project docs only if requested; otherwise keep notes in PR.
- Verify no generated dataset or model weights are staged.

Tests:

```bash
python/env/Scripts/python.exe -m pytest python/tests -q
git status --short
```

Deliverables:

- Performance baseline and clean artifact handling.

### Member 3: UI

Tasks:

- Complete admin screens for:
  - system settings thresholds
  - audit trail review/export
  - vendor profiles
  - notification views
  - ML model status placeholders or real metadata if available
- Make officer/admin dashboards real-data backed.
- Remove remaining `Support\Demo*` dependencies from completed pages.
- Run responsive/accessibility pass.
- Run production build.

Tests:

```bash
php artisan test --compact tests/Feature/Theme
php artisan test --compact --filter=Dashboard
npm run build
```

Deliverables:

- Admin/officer UI no longer depends on demo data for core workflows.

Sprint 7 integration gate:

- Core pages are real-data backed.
- Settings affect pipeline behavior.
- Cache/build commands pass.

---

## Sprint 8: End-to-End Acceptance and Thesis Demo Readiness

Goal: Prove the system from registration to final decision.

### Member 1: Backend

Tasks:

- Run full suite or coordinate with team:

```bash
php artisan test --compact
```

- Verify queue behavior with `QUEUE=sync` and with a real worker.
- Verify DB state transitions:
  - vendor registered
  - signature enrolled
  - submission processing
  - documents completed/failed
  - validation results created
  - submission pending review
  - decision approved/rejected
- Verify audit log completeness.

Deliverables:

- Backend acceptance checklist complete.

### Member 2: Python

Tasks:

- Run all Python tests:

```bash
python/env/Scripts/python.exe -m pytest python/tests -v --tb=short
```

- Run each script manually against one fixture.
- Confirm missing model artifacts produce actionable errors.
- Confirm dry-run mode still works for demos without trained weights, if retained.

Deliverables:

- Python acceptance checklist complete.

### Member 3: UI

Tasks:

- Run final UI build:

```bash
npm run build
```

- Manual browser walkthrough:
  - vendor registration
  - signature enrollment
  - login
  - submit documents
  - view submission status
  - officer pending queue
  - validation report
  - approve/reject
  - vendor notification
  - admin settings/audit
- Check desktop-first layouts and mobile-safe breakpoints.
- Confirm no view uses hardcoded URLs for app routes.

Deliverables:

- UI acceptance checklist complete.

Sprint 8 integration gate:

- One clean end-to-end demo path works.
- All affected tests pass.
- No critical demo page depends on session-only demo decisions.

---

## 5. Member-Specific Backlogs

Use these as the detailed checklist under the sprint roadmap.

### Member 1 Backend Checklist

- [ ] Inspect live schema before every model/migration change.
- [ ] Create pipeline models and factories.
- [ ] Add model relationships and casts.
- [ ] Implement real upload validation.
- [ ] Store files privately.
- [ ] Create `Submission` and `Document` records.
- [ ] Dispatch `ProcessDocumentJob`.
- [ ] Implement `ProcessDocumentAction`.
- [ ] Implement Python service wrappers through `Process`.
- [ ] Persist `ValidationResult`.
- [ ] Implement risk scoring from `SystemSettingsService`.
- [ ] Implement officer approve/reject services.
- [ ] Implement notifications.
- [ ] Implement audit logging.
- [ ] Implement secure document preview/download.
- [ ] Add failed-job handling.
- [ ] Run Pint and affected PHP tests.

### Member 2 Python Checklist

- [ ] Use `python/env/Scripts/python.exe`.
- [ ] Create JSON input/output helpers.
- [ ] Create image/PDF helpers.
- [ ] Implement `preprocess.py`.
- [ ] Implement `ocr_runner.py`.
- [ ] Implement field extraction.
- [ ] Implement `classify_document.py`.
- [ ] Implement `signature_verify.py`.
- [ ] Implement `stamp_verify.py`.
- [ ] Implement `enroll_reference.py`.
- [ ] Add dry-run/fixture mode where useful.
- [ ] Add pytest coverage for every script.
- [ ] Validate training data layout.
- [ ] Train or smoke-test model scripts.
- [ ] Keep generated datasets and weights out of git.

### Member 3 UI Checklist

- [ ] Inventory demo data keys.
- [ ] Introduce read-model boundary.
- [ ] Wire vendor submit page to real upload.
- [ ] Wire vendor submissions to real data.
- [ ] Wire vendor notifications to real data.
- [ ] Wire officer dashboard to real data.
- [ ] Wire pending queue to real data.
- [ ] Wire validation report drill-down to real data.
- [ ] Implement approve/reject UI.
- [ ] Wire archived reports.
- [ ] Wire vendor profiles.
- [ ] Wire admin settings/audit/model-status pages.
- [ ] Remove demo dependencies from completed workflows.
- [ ] Run theme tests and `npm run build`.

---

## 6. System Acceptance Criteria

The system is functionally complete when all of these pass.

### Vendor Flow

- Vendor can register.
- Vendor must enroll a reference signature before verification email is sent.
- Vendor can log in after verification.
- Vendor can upload PDF, PNG, JPG, or JPEG documents.
- Invalid MIME and oversized files are rejected server-side.
- Vendor can see submission status.
- Vendor receives notifications for received, processed, approved, and rejected states.

### Pipeline Flow

- Upload creates persistent `submissions` and `documents`.
- Queue job processes each document.
- Python preprocessing, OCR, classification, signature verification, and stamp verification are called through services.
- Poor input records flags and continues when possible.
- Validation result stores all stage outputs.
- Composite risk score is computed from configurable settings.
- Submission becomes pending review when processing completes.

### Officer Flow

- Officer sees pending submissions sorted by risk.
- Officer opens validation report.
- Report shows OCR, classification, signature, stamp, flags, and risk breakdown.
- Officer approves or rejects.
- Rejection requires comments.
- Decision updates submission/vendor status.
- Decision creates audit log and vendor notification.

### Admin Flow

- Admin can manage users.
- Admin can edit system thresholds.
- Admin can view/export audit trail.
- Admin can view vendor profiles and review history.
- Admin-only pages are not accessible to vendors or officers.

### Security and Data Rules

- Fortify owns auth routes.
- `role:` middleware or policies protect role pages.
- No direct public document storage.
- Vendors cannot access other vendors' documents.
- Audit logs are append-only.
- No auto-approval or auto-rejection by ML.
- Signature reference is per vendor.
- Stamp/logo reference is per issuer, not per vendor.

---

## 7. Final Verification Matrix

| Area | Command or action | Owner |
|---|---|---|
| PHP formatting | `vendor/bin/pint --dirty --format agent` | Member 1 and Member 3 |
| Backend tests | `php artisan test --compact` | Member 1 |
| Python tests | `python/env/Scripts/python.exe -m pytest python/tests -v --tb=short` | Member 2 |
| Frontend build | `npm run build` | Member 3 |
| Route/cache sanity | `php artisan route:cache && php artisan view:cache` | Member 1 |
| Upload E2E | Browser walkthrough with seeded vendor | All |
| Officer review E2E | Browser walkthrough with seeded officer | All |
| Admin controls | Browser walkthrough with seeded admin | Member 3 |

Seeded demo accounts from `AGENTS.md`, password `password`:

- `vendor@advs.test`
- `officer@advs.test`
- `officer2@advs.test`
- `admin@advs.test`
- `admin2@advs.test`

---

## 8. Risk Register

| Risk | Impact | Mitigation | Owner |
|---|---|---|---|
| Python weights are not available | Pipeline cannot produce real ML outputs | Keep dry-run contract mode, make missing-weight errors explicit, train/smoke-test scripts independently | Member 2 |
| Demo UI data shape differs from final models | UI rewrites late in project | Freeze read-model arrays in Sprint 0 and keep keys stable | Member 3 |
| Schema guessed incorrectly | Model casts/relationships break | Run schema reconnaissance before model work | Member 1 |
| Queue failures are silent | Documents stay stuck in processing | Failed-job handler marks document failed and notifies admin/officer | Member 1 |
| File storage exposed publicly | Vendor documents leak | Use private disk and secured streaming controller | Member 1 |
| UI allows out-of-role actions | Security and thesis scope violation | Middleware, policies, route tests, hidden nav actions | Member 3 |
| Pipeline aborts on low-confidence input | Human review loses evidence | Fail-forward: record flags and compute risk | Member 1 and Member 2 |
| Generated datasets/weights committed | Repo bloat and privacy risk | Check `git status --short`; keep artifacts gitignored | Member 2 |

---

## 9. Recommended Integration Demo Script

Use this script for the final presentation.

1. Log in as `vendor@advs.test`.
2. Open Submit Documents.
3. Upload one valid PDF or image.
4. Confirm submission appears in My Submissions as processing.
5. Run the queue or wait for worker.
6. Confirm status changes to pending review.
7. Log in as `officer@advs.test`.
8. Open Pending Submissions.
9. Open the highest-risk submission.
10. Review OCR, classification, signature, stamp, and risk breakdown.
11. Reject with comment or approve.
12. Log back in as vendor.
13. Confirm notification and updated status.
14. Log in as `admin@advs.test`.
15. Review audit trail and system settings.

The demo is successful only if each visible state is backed by database records, not session-only demo data.


---

## File: docs/archive/TRAINING_SCRIPT_LEGACY.md

You are an expert machine learning engineer. I need you to produce a **single, well‑commented Python training script** (or a set of clearly named functions inside one script) that trains all the models for my Automated Document Validation System (ADVS). The script must be self‑contained, reading data from the directory structure described below, and saving trained models. Assume all imports are available.

> **This brief is operationalized in [`docs/phases/MODEL_TRAINING_PHASES.md`](docs/phases/MODEL_TRAINING_PHASES.md)** — the phased plan covering datasets (including the **Negofood** food-business document classes), training order, empirical thresholds, and the named inference contracts the Laravel pipeline calls. Keep the classifier's class list in lockstep with `DocumentTypeSeeder` (see [`docs/phases/PIPELINE_INTEGRATION_PHASES.md`](docs/phases/PIPELINE_INTEGRATION_PHASES.md) Phase P1).

### Project Overview
- **Goal**: Automate vendor document accreditation by verifying document type, authenticity, signature, and stamp.
- **Models to train**:
  1. ResNet‑50 for document type/authenticity classification (multi‑class).
  2. YOLOv8 for signature and stamp detection on full document images.
  3. Siamese CNN (using ResNet‑50 backbone) for signature verification.
  4. EfficientNet‑B0 feature extractor for stamp verification (with threshold optimisation).

### Directory Structure (read-only)
```
project_root/
├── data/
│   ├── classification/
│   │   ├── train/   # subfolders per class, e.g., 'bir_permit', 'financial_statement', ..., 'fake'
│   │   └── val/     # same structure
│   ├── detection/
│   │   ├── images/        # .jpg/.png full document pages
│   │   └── labels/        # YOLO format .txt files (class 0=signature, 1=stamp)
│   ├── signatures/
│   │   └── raw/           # subfolders per vendor ID, each containing genuine signature images
│   └── stamps/
│       ├── genuine/       # genuine stamp crops
│       └── forged/        # forged stamp crops
└── models/               # (script will create subfolders)
```

### Detailed Requirements for Each Model

#### 1. ResNet‑50 Classification
- Input: 512×512 RGB images.
- Use `tf.keras.preprocessing.image_dataset_from_directory` to load training/validation sets.
- Apply data augmentation within the training pipeline: random horizontal flip, rotation (±10°), zoom (±10%).
- Build model:
  - Base: `ResNet50(weights='imagenet', include_top=False, input_shape=(512,512,3))` – freeze initially.
  - Head: `GlobalAveragePooling2D → Dropout(0.5) → Dense(512, activation='relu', kernel_regularizer=l2(1e-4)) → Dropout(0.3) → Dense(num_classes, activation='softmax')`.
- Compile with `Adam(learning_rate=1e-4)`, categorical crossentropy, metric `accuracy`.
- Use callbacks:
  - `ModelCheckpoint` saving best weights to `models/resnet50_best.h5`
  - `EarlyStopping(patience=5, restore_best_weights=True)`
  - `ReduceLROnPlateau(factor=0.2, patience=3)`
- Train first phase (frozen base) for max 20 epochs.
- Then unfreeze the last 30 layers of ResNet‑50, recompile with `Adam(1e-5)`, fine-tune for max 10 epochs.
- Save the final model, and also save the class names mapping (e.g., as a JSON file `models/class_names.json`).

#### 2. YOLOv8 Detection
- Use Ultralytics YOLOv8.
- Create a `data/detection/data.yaml` dynamically in the script (or from provided paths).
- Train `yolov8n.pt` (nano) for 50 epochs, imgsz=640, batch=16, patience=10, device 0 if GPU, cache=True.
- After training, evaluate on the validation set (the split will be handled by the dataset.yaml). Print mAP@0.5.
- Export best model to ONNX: `models/yolov8_stamp_signature.onnx`.

#### 3. Siamese CNN for Signature Verification
- **Data preparation for Siamese**:
  - Because the dataset only contains genuine signatures per vendor, the script must:
    - Create synthetic forgeries by applying elastic deformation + rotation + noise to 30% of each vendor’s samples (use `cv2.remap` for elastic deformation, or use `scipy.ndimage`). Save these forged images temporarily (or create on-the-fly).
    - Build a pairwise dataset: for each vendor, generate genuine pairs (two different authentic signatures from the same vendor) and forged pairs (genuine vs synthetic forgery). Label 1 = genuine, 0 = forgery.
  - Use a custom data generator that yields random pairs each batch to avoid pre‑storing all pairs.
- **Model architecture**:
  - Shared backbone: `ResNet50(weights='imagenet', include_top=False, pooling='avg')`.
  - Add a `Dense(128)` embedding layer (no activation) after the backbone, with L2 normalisation.
  - Input two images (pair), run through the same backbone (Siamese twin) to obtain two 128‑D vectors.
  - Compute Euclidean distance (or cosine similarity) between the two embeddings.
  - Use a final `Dense(1, activation='sigmoid')` on the distance vector to output match probability. (Alternative: contrastive loss – choose whichever you prefer; mention which you used.)
- Compile with binary crossentropy and Adam(1e-4).
- Train for 20 epochs (or use early stopping on validation accuracy). Use a validation split (e.g., hold out 20% of vendors).
- After training, save the full Siamese model and the shared encoder separately as `models/siamese_signature.h5` and `models/siamese_encoder.h5`.
- Determine optimal threshold: compute Equal Error Rate (EER) on validation pairs, and store the threshold in `models/signature_threshold.txt`.

#### 4. EfficientNet for Stamp Verification
- Use pre‑trained `EfficientNetB0(weights='imagenet', include_top=False, pooling='avg')` as a feature extractor (1280‑D).
- **Data**: `data/stamps/genuine/` and `data/stamps/forged/`.
- Load all images (resize to 224×224), extract feature vectors, and store them in a pandas DataFrame with labels (genuine=1, forged=0).
- Train a simple logistic regression (or small MLP) on these features to distinguish genuine vs forged. Alternatively, use cosine similarity directly – pick the simpler method that yields a threshold.
- If using logistic regression: split data 80/20, train, evaluate accuracy, and save the model as `models/stamp_classifier.pkl`.
- If using pure feature extraction + threshold: compute optimal cosine similarity threshold on a validation set (genuine vs genuine stored reference should have high similarity, genuine vs forged low). Save threshold value in `models/stamp_threshold.txt`.
- Also save the EfficientNet feature extractor as `models/efficientnet_feature_extractor.h5`.

### Outputs (all in `models/`)
The script must produce these files:
- `resnet50_classifier.h5` and `class_names.json`
- `yolov8_stamp_signature.onnx`
- `siamese_signature.h5`, `siamese_encoder.h5`, `signature_threshold.txt`
- `efficientnet_feature_extractor.h5`, `stamp_classifier.pkl` (or `stamp_threshold.txt`)

### Additional Requirements
- The script must run from end to end without user intervention after setting the project root path.
- Print clear progress messages.
- Handle common errors (e.g., missing folders) gracefully with descriptive messages.
- Use a main function that allows easy configuration of hyperparameters at the top.
- Include a small test at the end: for each model, run a quick inference on a sample image from the validation set and print results.

Write the complete Python script now.

---

# Other

## File: advs_video/DEMO_SCRIPT.md

# ADVS Demo — Full Narration Script (5–6 min)

> **Setup before you start:** two browser windows side by side — **Left = Vendor**,
> **Right = Officer**. Queue worker running (`php artisan queue:work`). The vendor
> account has **zero** real submissions (so it's a fresh live upload, not the
> `VendorDemoData` fallback rows). Have a valid sample PDF and one deliberately-bad
> file (a `.txt` renamed to `.pdf`, or an 11 MB image) ready on the desktop.
>
> **Clips:** `out/advs-architecture.mp4` (Part 2) · `out/advs-roadmap.mp4` (Part 6).

---

## PART 1 — Problem & Scope · `0:00–0:45`
**On screen:** ADVS landing page (`/`), then a roles title slide.

> "Good morning. Vendor accreditation today is manual — a compliance officer opens
> each uploaded permit, eyeballs the stamp and signature, cross-checks the details,
> and files a decision by hand. It's slow, and it's easy to miss a forged document.
>
> **ADVS automates that review.** It ingests a vendor's accreditation documents,
> runs them through an image-processing and machine-learning pipeline, and hands the
> officer a risk score and a set of flags — so the human decision is faster and
> better-informed.
>
> There are three roles" *(switch to roles slide)* — "the **Vendor**, who submits
> documents; the **Compliance Officer**, who reviews the AI report and approves or
> rejects; and the **Admin**, who manages users, thresholds, and the models. Today
> we'll walk the full submission-and-review workflow that's already live, and then
> show exactly where the ML engine plugs in."

---

## PART 2 — Architecture · `0:45–1:15`
**On screen:** ▶️ **play `advs-architecture.mp4`**.

> "Quickly, the stack. The vendor portal and the officer dashboard are **Laravel 12
> with Livewire and Flux**. Submissions and validation reports live in **MySQL**.
> When a document comes in, we dispatch a queued job — `ProcessDocumentJob` — which
> calls the **Python ML pipeline** through Laravel's Process facade.
>
> That last box" *(clip highlights the standby badge)* — "the ML pipeline — is on
> **standby**. The important part: the application already calls it, the schema
> already stores its results. Four models sit behind it: **ResNet-50, YOLOv8, a
> Siamese CNN, and EfficientNet**. Keep that box in mind — I'll come back to it."

---

## PART 3 — Vendor Flow (LIVE) · `1:15–2:30`
**On screen (Left/Vendor window):** log in `vendor@advs.test` → **New submission** → `vendor/submit`.

> "Let's be a vendor. I log in and go to **Submit Documents**." *(gesture to the drop
> zone)* "PDF, PNG, JPG or JPEG, up to 10 MB each — the rules are right here on the side.
>
> First, watch validation reject a bad file." *(drag the bad file in)* "The lane turns
> red — **'Check File'** — and it tells me exactly why: wrong type / over the size
> limit. Nothing gets queued.
>
> Now a real document." *(drag the valid PDF)* "Each file gets its own **document
> type** — Business Permit, BIR Permit, or Financial Statement." *(pick one)* "The
> status moves **Waiting → Uploading → Ready**, and I hit **Submit**.
>
> And I'm redirected to **My Submissions** with 'Submission queued successfully.'
> Here's my new batch — status **Processing**, at 35%." *(click Details)* "I can even
> preview the file I just sent. That's the whole vendor side."

---

## PART 4 — Officer / Admin Flow (LIVE) · `2:30–4:00`
**On screen (Right/Officer window):** log in `officer@advs.test` → `admin/dashboard`.

> "Now the compliance officer. This is the workspace. Four KPIs up top — **pending
> review, flagged today, high-risk, and approval rate**.
>
> Down here, **Recent activity** — and notice: the submission I just made **already
> shows up**." *(open notifications / point at the feed)* "The officer is notified
> automatically the moment a vendor submits.
>
> Let me open a flagged submission." *(click into `admin/submissions/{id}`)* "This is
> the review screen. On the left, the **composite risk score** on a gauge, and the
> reason it's flagged. On the right, the **officer decision** — approve or reject.
>
> Here's the heart of it — the **risk-score breakdown**." *(point down the table)*
> "Every component the pipeline evaluates: **Text OCR, Document Classification,
> Signature Match, Stamp Match** — each with its score, its threshold, and pass/fail.
> I can expand a forensic check to compare the reference against the submitted crop.
> Below: the **flags raised**, the **original documents** — which stream from private
> storage, not a public URL — and the **OCR-extracted text**.
>
> I'll approve." *(click Approve → modal → add a comment → Confirm)* "I add a note for
> the audit trail, confirm — and the decision is recorded and cascades to the vendor's
> accreditation status.
>
> One more thing — access control." *(switch to Left/Vendor window, type
> `/admin/dashboard` in the URL)* "A vendor trying to reach the admin dashboard gets a
> hard **403**. Roles are enforced, not suggested."

---

## PART 5 — The ML Pipeline (narrate) · `4:00–5:00`
**On screen:** stay on the officer's **Risk score breakdown** table.

> "Back to that standby box. Look closely at the breakdown — the four model stages are
> **already wired into this table**. Where a model isn't live yet, the score reads
> **'Unavailable'** or **'Not detected'**" *(point at those cells)*. "That's the honest
> state of the project.
>
> Everything around the models is done: the upload, the queue, the job, the service
> wrappers, the database columns, this exact report UI, the officer decision. What
> remains is **training the four models and dropping their weights into the pipeline** —
> ResNet-50 for classification, YOLOv8 to locate the signature and stamp, the Siamese
> network to verify the signature against the vendor's enrolled reference, and
> EfficientNet to match the official stamp. When those land, these cells fill with real
> scores and the composite risk becomes live. **That's our second half** — and the
> system is built to receive it."

---

## PART 6 — Roadmap + Q&A · `5:00–6:00`
**On screen:** ▶️ **play `advs-roadmap.mp4`** (ends on a **"Questions?"** card).

> "To close — where we stand. **Shipped:** authentication and the three roles, batch
> upload with validation, submission tracking, notifications and the audit trail, the
> full officer review-and-decide workflow, the KPI dashboard, private file streaming,
> and role-based access.
>
> **Phase two:** train and wire the four ML models, compute the composite risk score
> from real signals, and add renewal and expiration monitoring for the compliance
> lifecycle.
>
> That's ADVS." *(clip lands on 'Questions?')* "We'd be glad to take your questions."


---

## File: advs_video/src/skills/3d.md

---
title: 3D Animation with ThreeCanvas
impact: MEDIUM
impactDescription: enables proper 3D scene setup and smooth animations
tags: 3d, three, threejs, webgl, spatial
---

## ThreeCanvas Setup

Always wrap 3D content in ThreeCanvas and include proper lighting.

**Incorrect (missing ThreeCanvas wrapper):**

```tsx
<mesh rotation={[0, frame * 0.02, 0]}>
  <boxGeometry args={[2, 2, 2]} />
  <meshStandardMaterial color="#4a9eff" />
</mesh>
```

**Correct (proper ThreeCanvas setup):**

```tsx
import { ThreeCanvas } from "@remotion/three";

<ThreeCanvas>
  <ambientLight intensity={0.5} />
  <pointLight position={[10, 10, 10]} />
  <mesh rotation={[0, frame * 0.02, 0]}>
    <boxGeometry args={[2, 2, 2]} />
    <meshStandardMaterial color="#4a9eff" />
  </mesh>
</ThreeCanvas>;
```

## Lighting Setup

Every 3D scene needs ambient + directional light for depth.

**Incorrect (no lighting - objects appear flat/black):**

```tsx
<ThreeCanvas>
  <mesh>
    <sphereGeometry args={[1, 32, 32]} />
    <meshStandardMaterial color="red" />
  </mesh>
</ThreeCanvas>
```

**Correct (proper lighting):**

```tsx
<ThreeCanvas>
  <ambientLight intensity={0.4} />
  <directionalLight position={[5, 5, 5]} intensity={0.8} />
  <mesh>
    <sphereGeometry args={[1, 32, 32]} />
    <meshStandardMaterial color="red" />
  </mesh>
</ThreeCanvas>
```

## Frame-Based Rotation

Use frame directly for smooth continuous rotation.

```tsx
const frame = useCurrentFrame();
const rotationY = frame * 0.02; // Adjust speed with multiplier

<mesh rotation={[0, rotationY, 0]}>
  <boxGeometry args={[2, 2, 2]} />
  <meshStandardMaterial color="#4a9eff" />
</mesh>;
```

## Floating/Hovering Animation

Use sine wave on Y position for organic floating effect.

```tsx
const frame = useCurrentFrame();
const floatY = Math.sin(frame * 0.1) * 0.3; // Amplitude 0.3, speed 0.1

<mesh position={[0, floatY, 0]}>{/* geometry and material */}</mesh>;
```

## Spring-Based Scale Entrance

Use spring() for bouncy 3D object entrances.

```tsx
const scaleProgress = spring({
  frame,
  fps,
  config: { damping: 12, stiffness: 100 },
});

<mesh scale={[scaleProgress, scaleProgress, scaleProgress]}>
  {/* geometry and material */}
</mesh>;
```

## Camera Positioning

Position camera at reasonable distance for scene visibility.

```tsx
<ThreeCanvas camera={{ position: [0, 0, 5], fov: 75 }}>
  {/* scene content */}
</ThreeCanvas>
```


---

## File: advs_video/src/skills/charts.md

---
title: Chart & Data Visualization
impact: HIGH
impactDescription: improves data viz quality and animation polish
tags: charts, data, visualization, bar-chart, pie-chart, graphs
---

## Bar Chart Animations

Stagger bar entrances with 3-5 frame delays and use spring() for organic motion.

**Incorrect (all bars animate together):**

```tsx
const bars = data.map((item, i) => {
  const height = spring({ frame, fps, config: { damping: 18 } });
  return <div style={{ height: height * item.value }} />;
});
```

**Correct (staggered entrances):**

```tsx
const STAGGER_DELAY = 5;

const bars = data.map((item, i) => {
  const delay = i * STAGGER_DELAY;
  const height = spring({
    frame: frame - delay,
    fps,
    config: { damping: 18, stiffness: 80 },
  });
  return <div style={{ height: height * item.value }} />;
});
```

## Always Include Y-Axis Labels

Charts without axis labels are hard to read. Always add labeled tick marks.

**Incorrect (no axis):**

```tsx
<div style={{ display: "flex", alignItems: "flex-end", gap: 8 }}>{bars}</div>
```

**Correct (with Y-axis):**

```tsx
const yAxisSteps = [0, 25, 50, 75, 100];

<div style={{ display: "flex" }}>
  <div
    style={{
      display: "flex",
      flexDirection: "column",
      justifyContent: "space-between",
    }}
  >
    {yAxisSteps.reverse().map((step) => (
      <span style={{ fontSize: 12, color: "#888" }}>{step}</span>
    ))}
  </div>
  <div
    style={{
      display: "flex",
      alignItems: "flex-end",
      gap: 8,
      borderLeft: "1px solid #333",
    }}
  >
    {bars}
  </div>
</div>;
```

## Value Labels Inside Bars

Position value labels inside bars when height is sufficient, fade in after bar animates.

```tsx
const barHeight = normalizedHeight * progress;

<div style={{ height: barHeight, backgroundColor: COLOR_BAR }}>
  {barHeight > 30 && (
    <span style={{ opacity: progress, fontSize: 11 }}>
      {item.value.toLocaleString()}
    </span>
  )}
</div>;
```

## Pie Chart Animation

Animate segments using stroke-dashoffset, starting from 12 o'clock.

```tsx
const circumference = 2 * Math.PI * radius;
const segmentLength = (value / total) * circumference;
const offset = interpolate(progress, [0, 1], [segmentLength, 0]);

<circle
  r={radius}
  cx={center}
  cy={center}
  fill="none"
  stroke={color}
  strokeWidth={strokeWidth}
  strokeDasharray={`${segmentLength} ${circumference}`}
  strokeDashoffset={offset}
  transform={`rotate(-90 ${center} ${center})`}
/>;
```


---

## File: advs_video/src/skills/messaging.md

---
title: Chat & Messaging UI
impact: HIGH
impactDescription: creates realistic chat interfaces with proper bubble styling and animations
tags: chat, messaging, whatsapp, imessage, bubbles, conversation
---

## Chat Bubble Layout

Use flexbox to align sent messages right, received messages left.

**Incorrect (all bubbles centered):**

```tsx
<div style={{ textAlign: "center" }}>
  {messages.map((msg) => (
    <div>{msg.text}</div>
  ))}
</div>
```

**Correct (proper chat alignment):**

```tsx
<div
  style={{
    display: "flex",
    flexDirection: "column",
    justifyContent: "flex-end",
    padding: 40,
  }}
>
  {messages.map((msg, i) => (
    <div
      style={{
        display: "flex",
        justifyContent: msg.sent ? "flex-end" : "flex-start",
        marginTop: 12,
      }}
    >
      <div
        style={{
          maxWidth: "70%",
          padding: "12px 16px",
          borderRadius: 16,
          backgroundColor: msg.sent ? "#1f8a70" : "#202c33",
          color: "#e9edef",
        }}
      >
        {msg.text}
      </div>
    </div>
  ))}
</div>
```

## Staggered Message Entrances

Messages should appear one by one with slide + fade animations.

**Incorrect (all messages appear at once):**

```tsx
{
  messages.map((msg) => <Bubble text={msg.text} />);
}
```

**Correct (staggered with delays):**

```tsx
const STAGGER_DELAY = 38;
const FADE_DURATION = 18;

{
  messages.map((msg, i) => {
    const startFrame = i * STAGGER_DELAY;
    const opacity = interpolate(
      frame - startFrame,
      [0, FADE_DURATION],
      [0, 1],
      { extrapolateLeft: "clamp", extrapolateRight: "clamp" },
    );
    const slideX = interpolate(opacity, [0, 1], [msg.sent ? 40 : -40, 0]);

    return (
      <div style={{ opacity, transform: `translateX(${slideX}px)` }}>
        {msg.text}
      </div>
    );
  });
}
```

## Spring Bounce on Bubble Entrance

Add spring physics for organic bubble pop-in effect.

```tsx
const bounce = spring({
  frame: frame - startFrame,
  fps,
  config: { damping: 12, stiffness: 170 }
});
const scaleValue = interpolate(bounce, [0, 1], [0.98, 1]);

<div style={{
  transform: `translateX(${slideX}px) scale(${scaleValue})`,
  transformOrigin: msg.sent ? "100% 100%" : "0% 100%"
}}>
```

## Dark Theme Colors (WhatsApp style)

```tsx
const COLOR_BACKGROUND = "#0b141a";
const COLOR_SENT = "#1f8a70"; // Green for sent
const COLOR_RECEIVED = "#202c33"; // Dark gray for received
const COLOR_TEXT = "#e9edef"; // Light text
```

## Light Theme Colors (iMessage style)

```tsx
const COLOR_BACKGROUND = "#ffffff";
const COLOR_SENT = "#007AFF"; // Blue for sent
const COLOR_RECEIVED = "#E9E9EB"; // Light gray for received
const COLOR_TEXT_SENT = "#ffffff";
const COLOR_TEXT_RECEIVED = "#000000";
```


---

## File: advs_video/src/skills/sequencing.md

---
title: Timing & Sequencing
impact: HIGH
impactDescription: controls when elements appear and enables complex choreography
tags: sequence, series, timing, delay, choreography
---

## Sequence for Delayed Elements

Use Sequence to delay when an element appears in the timeline.

**Incorrect (manual frame checks):**

```tsx
{
  frame >= 30 && <Title />;
}
{
  frame >= 60 && <Subtitle />;
}
```

**Correct (Sequence component):**

```tsx
import { Sequence } from "remotion";

<Sequence from={30} durationInFrames={90}>
  <Title />
</Sequence>
<Sequence from={60} durationInFrames={60}>
  <Subtitle />
</Sequence>
```

## Series for Sequential Playback

Use Series when elements should play one after another without overlap.

```tsx
import { Series } from "remotion";

<Series>
  <Series.Sequence durationInFrames={45}>
    <Intro />
  </Series.Sequence>
  <Series.Sequence durationInFrames={60}>
    <MainContent />
  </Series.Sequence>
  <Series.Sequence durationInFrames={30}>
    <Outro />
  </Series.Sequence>
</Series>;
```

## Series with Offset for Overlap

Use negative offset for overlapping sequences:

```tsx
<Series>
  <Series.Sequence durationInFrames={60}>
    <SceneA />
  </Series.Sequence>
  <Series.Sequence offset={-15} durationInFrames={60}>
    {/* Starts 15 frames before SceneA ends */}
    <SceneB />
  </Series.Sequence>
</Series>
```

## Staggered Element Entrances

For staggered animations of multiple items, calculate delays:

**Incorrect (hardcoded delays):**

```tsx
const items = data.map((item, i) => {
  const delay = i === 0 ? 0 : i === 1 ? 10 : i === 2 ? 20 : 30;
  // ...
});
```

**Correct (calculated stagger):**

```tsx
const STAGGER_DELAY = 8;
const BASE_DELAY = 15;

const items = data.map((item, i) => {
  const delay = BASE_DELAY + i * STAGGER_DELAY;
  const progress = spring({
    frame: frame - delay,
    fps,
    config: { damping: 15, stiffness: 120 },
  });
  return (
    <Item
      key={i}
      style={{
        opacity: progress,
        transform: `translateY(${(1 - progress) * 20}px)`,
      }}
    />
  );
});
```

## Nested Sequences

Sequences can be nested for complex timing:

```tsx
<Sequence from={0} durationInFrames={120}>
  <Background />
  <Sequence from={15} durationInFrames={90}>
    <Title />
  </Sequence>
  <Sequence from={45} durationInFrames={60}>
    <Subtitle />
  </Sequence>
</Sequence>
```

## Frame References Inside Sequences

Inside a Sequence, useCurrentFrame() returns the local frame (starting from 0):

```tsx
<Sequence from={60} durationInFrames={30}>
  <MyComponent />
  {/* Inside MyComponent, useCurrentFrame() returns 0-29, not 60-89 */}
</Sequence>
```


---

## File: advs_video/src/skills/social-media.md

---
title: Social Media Content
impact: MEDIUM
impactDescription: optimizes content for mobile viewing and engagement
tags: social, instagram, tiktok, reels, stories, mobile
---

## Safe Zone for UI Overlays

Keep key content in the center 80% to avoid platform UI elements.

**Incorrect (content at edges - gets covered by UI):**

```tsx
<AbsoluteFill style={{ padding: 10 }}>
  <div style={{ position: "absolute", top: 0 }}>Title</div>
  <div style={{ position: "absolute", bottom: 0 }}>CTA</div>
</AbsoluteFill>
```

**Correct (content in safe zone):**

```tsx
const SAFE_MARGIN_TOP = height * 0.12;
const SAFE_MARGIN_BOTTOM = height * 0.15;
const SAFE_MARGIN_SIDES = width * 0.05;

<AbsoluteFill
  style={{
    paddingTop: SAFE_MARGIN_TOP,
    paddingBottom: SAFE_MARGIN_BOTTOM,
    paddingLeft: SAFE_MARGIN_SIDES,
    paddingRight: SAFE_MARGIN_SIDES,
  }}
>
  {content}
</AbsoluteFill>;
```

## Mobile-First Text Sizing

Text must be readable on small screens. Minimum 48px for headlines.

**Incorrect (text too small for mobile):**

```tsx
const TITLE_SIZE = 24;
const BODY_SIZE = 14;
```

**Correct (mobile-readable sizes):**

```tsx
const TITLE_SIZE = Math.max(48, Math.round(width * 0.08));
const BODY_SIZE = Math.max(28, Math.round(width * 0.045));
```

## Hook in First Frames

Social content needs immediate visual interest. Add movement from frame 0.

**Incorrect (static start):**

```tsx
const entrance = spring({ frame: frame - 30, fps }); // Starts after 1 second
```

**Correct (immediate hook):**

```tsx
const entrance = spring({
  frame,
  fps,
  config: { damping: 12, stiffness: 200 },
});
const pulse = Math.sin(frame * 0.15) * 0.03 + 1; // Subtle constant motion

<div style={{ transform: `scale(${entrance * pulse})` }}>{content}</div>;
```

## High Contrast Colors

Use bold, saturated colors that pop on mobile screens.

```tsx
// Good for social
const COLOR_PRIMARY = "#FF3366";
const COLOR_ACCENT = "#00D4FF";
const COLOR_BG = "#0A0A0A";

// Avoid muted/pastel colors that look washed out
// const COLOR_PRIMARY = "#C4A4A4"; // Too muted
```

## Loop-Friendly Endings

Design animations that can seamlessly loop.

```tsx
const TOTAL_DURATION = durationInFrames;
const loopProgress = (frame % TOTAL_DURATION) / TOTAL_DURATION;

// Or fade to start state at the end
const fadeOut = interpolate(
  frame,
  [TOTAL_DURATION - 15, TOTAL_DURATION],
  [1, 0],
);
```


---

## File: advs_video/src/skills/spring-physics.md

---
title: Spring Physics Animation
impact: HIGH
impactDescription: creates natural, organic motion instead of mechanical animations
tags: spring, physics, bounce, easing, organic
---

## Prefer spring() Over interpolate()

Use spring() for natural motion, interpolate() only for linear progress.

**Incorrect (mechanical motion):**

```tsx
const scale = interpolate(frame, [0, 30], [0, 1], {
  extrapolateRight: "clamp",
});
```

**Correct (organic spring motion):**

```tsx
const scale = spring({
  frame,
  fps,
  config: { damping: 12, stiffness: 100 },
  durationInFrames: 30,
});
```

## Spring Config Parameters

```tsx
spring({
  frame,
  fps,
  config: {
    damping: 10, // Higher = less bounce (10-200)
    stiffness: 100, // Higher = faster snap (50-200)
    mass: 1, // Higher = more inertia (0.5-3)
  },
});
```

## Common Spring Presets

```tsx
// Snappy, minimal bounce (UI elements)
const snappy = { damping: 20, stiffness: 200 };

// Bouncy entrance (playful animations)
const bouncy = { damping: 8, stiffness: 100 };

// Smooth, no bounce (subtle reveals)
const smooth = { damping: 200, stiffness: 100 };

// Heavy, slow (large objects)
const heavy = { damping: 15, stiffness: 80, mass: 2 };
```

## Delayed Spring Start

Offset the frame for delayed spring animations:

**Incorrect (spring starts immediately):**

```tsx
const entrance = spring({ frame, fps, config: { damping: 12 } });
```

**Correct (spring starts after delay):**

```tsx
const ENTRANCE_DELAY = 20;
const entrance = spring({
  frame: frame - ENTRANCE_DELAY,
  fps,
  config: { damping: 12, stiffness: 100 },
});
// Returns 0 until frame 20, then animates to 1
```

## Spring for Scale with Overshoot

For bouncy scale animations that overshoot:

```tsx
const bounce = spring({
  frame,
  fps,
  config: { damping: 8, stiffness: 150 },
});
// Will overshoot past 1.0 before settling

<div style={{ transform: `scale(${bounce})` }}>{content}</div>;
```

## Combining Spring with Interpolate

Map spring output (0-1) to custom ranges:

```tsx
const springProgress = spring({ frame, fps, config: { damping: 15 } });

// Map to rotation
const rotation = interpolate(springProgress, [0, 1], [0, 360]);

// Map to position
const translateY = interpolate(springProgress, [0, 1], [50, 0]);

<div style={{ transform: `translateY(${translateY}px) rotate(${rotation}deg)` }}>
```

## Chained Springs for Sequential Motion

```tsx
const PHASE_1_END = 30;
const PHASE_2_START = 25; // Slight overlap

const phase1 = spring({ frame, fps, config: { damping: 15 } });
const phase2 = spring({
  frame: frame - PHASE_2_START,
  fps,
  config: { damping: 12 },
});

// phase1 controls entrance, phase2 controls secondary motion
```


---

## File: advs_video/src/skills/transitions.md

---
title: Scene Transitions
impact: HIGH
impactDescription: enables smooth scene changes and professional video flow
tags: transitions, fade, slide, wipe, scenes
---

## TransitionSeries for Scene Changes

Use TransitionSeries to animate between multiple scenes or clips.

**Incorrect (abrupt scene cuts):**

```tsx
<Sequence from={0} durationInFrames={60}>
  <SceneA />
</Sequence>
<Sequence from={60} durationInFrames={60}>
  <SceneB />
</Sequence>
```

**Correct (smooth transitions):**

```tsx
import { TransitionSeries, linearTiming } from "@remotion/transitions";
import { fade } from "@remotion/transitions/fade";

<TransitionSeries>
  <TransitionSeries.Sequence durationInFrames={60}>
    <SceneA />
  </TransitionSeries.Sequence>
  <TransitionSeries.Transition
    presentation={fade()}
    timing={linearTiming({ durationInFrames: 15 })}
  />
  <TransitionSeries.Sequence durationInFrames={60}>
    <SceneB />
  </TransitionSeries.Sequence>
</TransitionSeries>;
```

## Available Transition Types

Import transitions from their respective modules:

```tsx
import { fade } from "@remotion/transitions/fade";
import { slide } from "@remotion/transitions/slide";
import { wipe } from "@remotion/transitions/wipe";
import { flip } from "@remotion/transitions/flip";
import { clockWipe } from "@remotion/transitions/clock-wipe";
```

## Slide Transition with Direction

Specify slide direction for enter/exit animations.

```tsx
import { slide } from "@remotion/transitions/slide";

<TransitionSeries.Transition
  presentation={slide({ direction: "from-left" })}
  timing={linearTiming({ durationInFrames: 20 })}
/>;
```

Directions: `"from-left"`, `"from-right"`, `"from-top"`, `"from-bottom"`

## Custom Crossfade Without TransitionSeries

For simple opacity crossfades within a single component:

```tsx
const TRANSITION_START = 60;
const TRANSITION_DURATION = 15;

const scene1Opacity = interpolate(
  frame,
  [TRANSITION_START, TRANSITION_START + TRANSITION_DURATION],
  [1, 0],
  { extrapolateLeft: "clamp", extrapolateRight: "clamp" }
);

const scene2Opacity = interpolate(
  frame,
  [TRANSITION_START, TRANSITION_START + TRANSITION_DURATION],
  [0, 1],
  { extrapolateLeft: "clamp", extrapolateRight: "clamp" }
);

<AbsoluteFill style={{ opacity: scene1Opacity }}><SceneA /></AbsoluteFill>
<AbsoluteFill style={{ opacity: scene2Opacity }}><SceneB /></AbsoluteFill>
```

## Timing Options

```tsx
import { linearTiming, springTiming } from "@remotion/transitions";

// Linear timing - constant speed
linearTiming({ durationInFrames: 20 });

// Spring timing - organic motion
springTiming({ config: { damping: 200 }, durationInFrames: 25 });
```


---

## File: advs_video/src/skills/typography.md

---
title: Typography & Text Animation
impact: HIGH
impactDescription: fixes common text animation bugs and improves readability
tags: typography, text, typewriter, kinetic, animation
---

## Typewriter Effect - Use String Slicing

Always use string slicing for typewriter effects. Never use per-character opacity.

**Incorrect (per-character opacity - breaks cursor positioning):**

```tsx
{
  text
    .split("")
    .map((char, i) => (
      <span style={{ opacity: i < typedCount ? 1 : 0 }}>{char}</span>
    ));
}
<span>|</span>;
```

**Correct (string slicing - cursor follows text):**

```tsx
const typedText = FULL_TEXT.slice(0, typedChars);

<span>{typedText}</span>
<span style={{ opacity: caretOpacity }}>▌</span>
```

## Cursor Blink - Use Smooth Interpolation

Blinking cursors should fade smoothly, not flash on/off abruptly.

**Incorrect (abrupt blink):**

```tsx
const caretVisible = Math.floor(frame / 15) % 2 === 0;
<span style={{ opacity: caretVisible ? 1 : 0 }}>|</span>;
```

**Correct (smooth blink):**

```tsx
const CURSOR_BLINK_FRAMES = 16;
const caretOpacity = interpolate(
  frame % CURSOR_BLINK_FRAMES,
  [0, CURSOR_BLINK_FRAMES / 2, CURSOR_BLINK_FRAMES],
  [1, 0, 1],
  { extrapolateLeft: "clamp", extrapolateRight: "clamp" },
);

<span style={{ opacity: caretOpacity }}>▌</span>;
```

## Word Carousel - Stable Width Container

Prevent layout shifts by using the longest word to set container width.

**Incorrect (width jumps between words):**

```tsx
<div style={{ position: "relative" }}>
  <span>{WORDS[currentIndex]}</span>
</div>
```

**Correct (stable width from longest word):**

```tsx
const longestWord = WORDS.reduce(
  (a, b) => (a.length >= b.length ? a : b),
  WORDS[0],
);

<div style={{ position: "relative" }}>
  <div style={{ visibility: "hidden" }}>{longestWord}</div>
  <div style={{ position: "absolute", left: 0, top: 0 }}>
    {WORDS[currentIndex]}
  </div>
</div>;
```

## Text Highlight - Two Layer Crossfade

Use overlapping layers for smooth highlight transitions.

```tsx
const typedOpacity = interpolate(
  frame,
  [highlightStart - 8, highlightStart + 8],
  [1, 0],
  { extrapolateLeft: "clamp", extrapolateRight: "clamp" },
);
const finalOpacity = interpolate(
  frame,
  [highlightStart, highlightStart + 8],
  [0, 1],
  { extrapolateLeft: "clamp", extrapolateRight: "clamp" },
);

{
  /* Typing layer */
}
<div style={{ opacity: typedOpacity }}>{typedText}</div>;

{
  /* Final layer with highlight */
}
<div style={{ position: "absolute", inset: 0, opacity: finalOpacity }}>
  <span>{preText}</span>
  <span style={{ backgroundColor: COLOR_HIGHLIGHT }}>{HIGHLIGHT_WORD}</span>
  <span>{postText}</span>
</div>;
```


---

## File: python/M4_training_run_record.md

# M4 · Siamese Signature Verifier — Training Run Record

Stage 4a signature verifier (model 3 of 4). CEDAR, signer-disjoint, trained on Colab free tier (T4).
This record captures the run for ML Model Management / System Settings and the thesis evaluation.

## Result summary

| Metric | Value |
|---|---|
| **EER** | **3.2%** (verification accuracy ≈ 96.8%) |
| FAR @ EER | 2.7% |
| FRR @ EER | 3.6% |
| **`SIGNATURE_DISTANCE_THRESHOLD` (EER)** | **1.243976** |
| Genuine pair distance (mean) | 0.807 |
| Forged pair distance (mean) | 1.697 |
| Embedding dimension | 128 (unit-normalised) |
| Final val accuracy (sigmoid head) | 0.977 |
| Final val loss | 0.310 |

Threshold and metrics are computed on the **held-out validation pairs (signer-disjoint)** — signers the
encoder never saw in training — so this reflects generalisation to unseen vendors, not memorisation.

## Training regime (what produced this)

- **Backbone frozen.** ResNet-50 (ImageNet) held fixed; only the 128-D projection head + twin's sigmoid
  head trained. This removed the overfitting seen in the first attempt (train acc pinned at 1.0 while
  val loss stuck at chance ≈ 0.69) and stabilised BatchNorm (inference-mode moving stats).
- **EarlyStopping(monitor=val_loss, restore_best_weights=True)** + ReduceLROnPlateau.
- Architecture unchanged from the repo `build_encoder`: `ResNet50(pooling='avg') → Dense(128) →
  UnitNormalization(axis=-1)`. Only the training regime differs, so `siamese_encoder.h5` stays
  drop-in compatible with the API.
- CONFIG: image_size 224 · embedding_dim 128 · batch 16 · lr 1e-4 · pairs_per_vendor 20 · seed 42.

## Dataset provenance

- **CEDAR** (Kaggle: `shreelakshmigp/cedardataset`), 55 signers, `full_org/` + `full_forg/`.
- Loaded to uint8 RGB @ 224, grouped by writer id (first number in filename).
- **~20% of signers held out** for validation (signer-disjoint).
- Pairs alternate genuine (label 1) and forged (label 0); forged partner = real CEDAR forgery.

## IMPORTANT — DoD similarity gate needs recalibration

siamese.md §10 states the gate *"genuine similarity ≥ 0.85, forged < 0.85."* With the API's
`similarity = 1 / (1 + distance)` and L2-normalised embeddings on the unit sphere, the realistic
similarity scale for this model is:

| Pair type | Distance (mean) | Similarity = 1/(1+d) |
|---|---|---|
| Genuine | 0.807 | ≈ **0.553** |
| EER boundary | 1.244 | ≈ **0.446** |
| Forged | 1.697 | ≈ **0.371** |

A literal **0.85 similarity gate would fail this strong model.** The 0.85 figure does not match the
Euclidean-on-unit-sphere embedding scale (max distance is 2.0, so similarity is bounded near 0.33–0.55
for realistic pairs). The correct decision boundary is the **empirical EER distance threshold**.

### Recommended DoD gate for `test_signature_verify.py`

Gate on the EER threshold and the ordering, not an absolute 0.85:

1. Embedding length is **exactly 128**.
2. Genuine pair distance **< `SIGNATURE_DISTANCE_THRESHOLD`** (match) → `match == true`.
3. Forged pair distance **> `SIGNATURE_DISTANCE_THRESHOLD`** (no match) → `match == false`.
4. (Optional, robust) mean genuine distance **< mean forged distance** by a clear margin.

If a similarity-based assertion is preferred, derive the bound from the threshold at test time
(`sim_boundary = 1/(1+threshold) ≈ 0.446`) rather than hard-coding 0.85.

## Phase-6 / M4 checklist status

- [x] Notebook run on Colab GPU; `siamese_encoder.h5`, `siamese_signature.h5`,
      `signature_threshold.txt` produced (drop into `python/models/`).
- [x] EER threshold recorded: **1.243976** → the `SIGNATURE_DISTANCE_THRESHOLD` default.
- [x] Validation metric recorded for ML Model Management: EER 3.2% / FAR 2.7% / FRR 3.6%.
- [x] `test_signature_verify.py` written at `python/tests/test_signature_verify.py` — gates on the
      recalibrated EER distance threshold above, **not** the literal 0.85. Real DoD crops live in
      `python/tests/fixtures/signature_data/` (see that folder's README).
- [ ] `signature_verify.py` + `model_loader.py` (Phase-10 Laravel CLI handoff).
- [ ] `--dry-run` / `--smoke` still green; serve-path preprocessing still `resnet50.preprocess_input`.

## Next steps

1. Download the three artefacts from `MyDrive/advs/python/models/` into the repo's `python/models/`
   (gitignored — commit by hand).
2. Register **1.243976** as the `SIGNATURE_DISTANCE_THRESHOLD` default in System Settings, and record
   EER/FAR/FRR in ML Model Management.
3. Write `test_signature_verify.py` with the recalibrated gate.
4. Optional: retrain with `epochs≈80` for a marginally lower EER (val loss was still decreasing at
   epoch 40), or run the Stage-2 fine-tune (unfreeze `conv5_*` at lr 1e-5) if you want to push further.


---

## File: python/data/CLASSIFIER_DATASET_SPEC.md

# ADVS — ResNet-50 Classifier Dataset Spec & Taxonomy

> The **current class list** the document-classification model (Stage 3, ResNet-50) is trained on, plus
> the **balanced per-label dataset targets**. This is the source of truth for what lives under
> `python/data/training/classifier_data/<class>/` and `python/data/validation/classifier_data/<class>/`.
>
> Context: [`../DEVELOPMENT_PHASES.md`](../DEVELOPMENT_PHASES.md) (Phase 1 taxonomy lock-in, Phase 2
> dataset generation) · [`../../docs/CLIENT_INTERVIEW_GAP_PLAN.md`](../../docs/CLIENT_INTERVIEW_GAP_PLAN.md)
> (Negofood food-business / vendor scope) · [`../../docs/ADVS_REFERENCE.md`](../../docs/ADVS_REFERENCE.md)
> (Stage 3 + §9 `CLASSIFICATION_CONFIDENCE_THRESHOLD = 0.70`).

---

## TL;DR — average dataset size per label

| Split | **Target per class (uniform)** | Notes |
|---|---:|---|
| **Training** | **1,000 images** | ~500 clean + ~500 scan-degraded; include real samples where available |
| **Validation** | **200 images** | ≈ 20 % of train; stratified, no leakage from train |
| Test (optional) | 100 images | held-out, untouched until final eval |
| Absolute floor | 300 train / 60 val | only if a type is data-scarce; pad toward uniform |

**Uniform ~1,000 train / ~200 val per label.** The current dataset already exposes the live label set below,
so 1,000 per label remains a *proven, reachable* balance point, not an arbitrary one.

Balance is enforced at **two layers**: (1) **data-level** — generate to a uniform per-class count
(primary); (2) **loss-level** — `class_weight` via scikit-learn `compute_class_weight`, already wired in
[`../scripts/train_classifier.py`](../scripts/train_classifier.py), corrects residual skew. Keep splits
**stratified** (same per-class proportion in train/val/test); the generators' content-hash manifest
prevents duplicate leakage across splits.

> `fake` should be **1,000–1,500 and diverse** (forgeries/edits/wrong-templates spanning many real types)
> so the fraud class isn't one visual mode. `other` (optional reject bucket) ~500–1,000 if adopted;
> otherwise rely on the 0.70 confidence threshold to surface "Unknown document type".

---

## Current live labels

`issuer_scope` drives **Stage 4b** logo/seal verification (`national` = one agency logo by type; `lgu` =
per-city seal by type+city; `null` = no issuer logo). `requires_expiry` is kept as dataset metadata for
future pipeline work; the labels below are the exact folder names currently present in both
`training/classifier_data/` and `validation/classifier_data/`.

| Class folder | Issuer / note | `issuer_scope` | `requires_expiry` |
|---|---|---|---|
| `bir_certificate` | BIR | national | no |
| `business_permit` | LGU / City | lgu | yes |
| `drivers_license` | LTO | national | yes |
| `dti_registration` | DTI | national | yes |
| `employment_contract` | employer | null | contextual |
| `fake` | forged / tampered / wrong-template negatives | null | contextual |
| `fda_registration` | FDA | national | yes |
| `financial_statement` | auditor | null | no |
| `food_handler_certificate` | LGU City Health | lgu | yes |
| `health_medical_certificate` | clinic / physician | null | contextual |
| `national_id` | PSA / PhilSys | national | no |
| `nbi_clearance` | NBI | national | yes |
| `other` | out-of-taxonomy / unsupported docs | null | contextual |
| `passport` | DFA | national | yes |
| `philhealth_id` | PhilHealth | national | no |
| `police_clearance` | PNP / LGU | lgu | yes |
| `postal_id` | PHLPost | national | yes |
| `prc_id` | PRC | national | yes |
| `sanitary_permit` | LGU City Health | lgu | yes |
| `sec_gis` | SEC | national | no |
| `sec_registration` | SEC | national | no |
| `signed_contract` | counterparty | null | contextual |
| `sss_id` | SSS | national | no |
| `tin_id` | BIR | national | no |
| `umid` | SSS/GSIS | national | no |
| `voters_id` | COMELEC | national | no |

---

## Generation plan (how to hit the targets)

1. **Reuse the generator machinery.** New types reuse [`bir_dataset_generator`](../scripts/bir_dataset_generator.py)'s
   helpers — text-fit, white-keyed asset compositing, Augraphy scan degrade, atomic dedup manifest. **Do
   not reimplement.** Each new type needs: a blank template under `data/template/`, the issuer seal/logo
   under `data/seal/` or `logo/`, calibrated field boxes (`annotate_boxes.py`), and field values that
   satisfy the OCR regexes — **including a printed expiry date** for every `requires_expiry = yes` type.
2. **Generate clean + scan per base doc** (≈50/50) so the model sees both digital and photocopy-quality inputs.
3. **Populate a balanced validation split** (target 200/class) using the same label set as training.
4. **`fake`:** synthesize from the real classes (field edits, pasted stamps/signatures, recompression,
   wrong-template mixes) spanning many types — not a single forgery style.
5. **QA 10 samples per new type first**, then batch; verify the dedup manifest (`next_index`, unique hashes).

---

## Storage & git

- Folders are scaffolded with a tracked `.gitkeep` each (train + val), per `.gitignore` lines 40–45
  (directory scaffold + `.gitkeep` are the committable exception).
- **Images and `_synthetic_manifest.json` are gitignored** — local artifacts only; **never `git add`** them.
- The classifier reads the class label from the **immediate subfolder name** of `classifier_data/`
  (`image_dataset_from_directory`), so every class is a **flat top-level folder** — IDs are individual
  classes (`national_id`, `philhealth_id`, …), not nested under a `government_id/` parent.


---

## File: python/kaggle_nginx_deployment.md

# Migration Spec (Final): FastAPI ML API → own GitHub repo → Kaggle (free GPU) + ngrok

## How this document is used

This file is an execution spec for an agentic coding tool. Tasks are ordered; do them in sequence. Sections marked **HUMAN** are manual and must NOT be attempted by the agent.

## Context

- The FastAPI app lives in `<LARAVEL_ROOT>/python` (e.g. `C:\xampp\htdocs\projects\advs\python`), a subdirectory of a Laravel project. It has **no git repo of its own yet**; the parent Laravel dir may or may not be a git repo.
- Entrypoint: `api.main:app` via uvicorn, port **7860**.
- Current layout inside `python/`: `api/`, `models/` (trained TF/HF models, LARGE), `backup_models/`, `data/`, `env/` (Windows venv), `tmp/`, `augraphy_cache/`, `scripts/`, `tests/`, `notebooks/`, `Dockerfile`, `requirements.txt`, `requirements-api.txt`, `.env.api` (secrets), `.env.api.example`.
- Target architecture:
  - **Code** → new private GitHub repo `advs-api` (repo root == current `python/` dir).
  - **Models** → private Kaggle Dataset `advs-models` (mounted read-only at `/kaggle/input/advs-models`).
  - **Secrets** → Kaggle Secrets; `.env.api` is materialized at runtime from them.
  - **Hosting** → Kaggle notebook (GPU T4, ~30 GB RAM) runs uvicorn + ngrok; public URL printed each session.
- Hugging Face Spaces is no longer free for Docker/Gradio — do not use it for compute.
- After extraction, repo root == `python/`, so all paths below (`api/`, `deploy/kaggle/`, …) are relative to the new repo root.

---

## HUMAN — one-time manual steps (agent must wait for these)

1. Create an empty **private** GitHub repo (no README): `advs-api`.
   (Optional: `gh repo create advs-api --private`.)
2. Create a free ngrok account → copy the authtoken.
3. Upload the `python/models/` folder as a **private Kaggle Dataset** named `advs-models`
   (web UI drag-drop or `kaggle datasets create`). Current Kaggle limit ≈ 20 GB per dataset — confirm total size first.
4. In Kaggle (notebook → Secrets), create one secret per key listed in `.env.api.example`, plus:
   `NGROK_AUTHTOKEN`, and `GITHUB_TOKEN` (personal access token, needed because the repo is private).
5. Note the GitHub username: `<GITHUB_USER>`.

---

## Task 1 — Git hygiene (BEFORE any commit)

Create/update `python/.gitignore` with exactly:

```text
env/
tmp/
augraphy_cache/
models/
backup_models/
*.log
.env.api
__pycache__/
*.pyc
```

No git commands yet. This file must exist before Task 2's first commit.

## Task 2 — Extract `python/` into its own GitHub repo

Do NOT physically move the folder; Laravel integration must keep working.

1. Init and push:

   ```bash
   cd <LARAVEL_ROOT>/python
   git init -b main
   git add .
   git commit -m "Initial commit: FastAPI backend (code only)"
   git remote add origin https://github.com/<GITHUB_USER>/advs-api.git
   git push -u origin main
   ```

2. If `<LARAVEL_ROOT>` is a git repo, stop tracking `python/` there:

   ```bash
   cd <LARAVEL_ROOT>
   git rm -r --cached python
   # add a line `python/` to the parent .gitignore
   git commit -m "Extract python API into its own repository"
   git push
   ```

   If the parent is not a git repo, skip this step.

3. Verify:
   - `git ls-files` in the new repo contains nothing under `models/`, `backup_models/`, `env/`, `tmp/`, and no `.env.api` or `*.log`.
   - No tracked file exceeds 50 MB.
   - Parent repo (if any) no longer lists python files in `git status`.

## Task 3 — Env-driven paths

Create `api/config.py`:

```python
import os
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent
MODELS_DIR = Path(os.environ.get("MODELS_DIR", BASE_DIR / "models"))
DATA_DIR = Path(os.environ.get("DATA_DIR", BASE_DIR / "data"))
```

Replace every hardcoded `models/`, `backup_models/`, or absolute Windows path inside `api/` with these constants. Do NOT change model/inference logic. Local Windows run with no env vars must behave exactly as before.

## Task 4 — Health endpoint

If missing, add to `api/main.py`:

```python
@app.get("/health")
def health():
    return {"status": "ok"}
```

## Task 5 — Kaggle start script

Create `deploy/kaggle/kaggle_start.py`:

```python
"""Kaggle entrypoint: start FastAPI + ngrok, keep the notebook session alive."""
import argparse, os, subprocess, sys, time
from pathlib import Path

import requests

REPO_ROOT = Path(__file__).resolve().parents[2]
PORT = 7860

def wait_for_server(timeout_s=900):
    start = time.time()
    while time.time() - start < timeout_s:
        try:
            if requests.get(f"http://127.0.0.1:{PORT}/health", timeout=5).status_code == 200:
                return True
        except Exception:
            pass
        print(f"[boot] waiting for server (model load can take minutes)… {int(time.time()-start)}s", flush=True)
        time.sleep(5)
    return False

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--smoke", action="store_true", help="local check: boot server, hit /health, exit (no ngrok)")
    args = ap.parse_args()

    ds = os.environ.get("KAGGLE_MODELS_DATASET", "advs-models")
    kpath = Path("/kaggle/input") / ds
    if kpath.is_dir():
        os.environ["MODELS_DIR"] = str(kpath)

    server = subprocess.Popen(
        [sys.executable, "-m", "uvicorn", "api.main:app", "--host", "0.0.0.0", "--port", str(PORT)],
        cwd=REPO_ROOT,
    )
    try:
        if not wait_for_server():
            raise SystemExit("server never became healthy")
        print("[boot] server healthy", flush=True)
        if args.smoke:
            return

        from pyngrok import ngrok
        url = ngrok.connect(PORT).public_url
        print("\n" + "=" * 20 + f" PUBLIC URL: {url} " + "=" * 20 + "\n", flush=True)

        while True:  # heartbeat keeps the notebook cell busy → prevents idle kill
            time.sleep(600)
            try:
                r = requests.get(f"http://127.0.0.1:{PORT}/health", timeout=10)
                print(f"[heartbeat] {time.strftime('%H:%M:%S')} status={r.status_code} url={url}", flush=True)
            except Exception as e:
                print(f"[heartbeat] error: {e}", flush=True)
    finally:
        server.terminate()

if __name__ == "__main__":
    main()
```

Agent may refine (logging to file, cleaner shutdown) but must keep: MODELS_DIR override, long boot tolerance, ngrok banner, infinite heartbeat, `--smoke` mode.

## Task 6 — Light requirements

Create `deploy/kaggle/requirements-kaggle.txt`. The Kaggle base image ALREADY ships tensorflow, torch, transformers, opencv-python, numpy, pandas, scikit-learn, Pillow. Include ONLY what's missing, e.g.:

```text
fastapi
uvicorn[standard]
pyngrok
python-dotenv
requests
# + app-specific light deps diffed from requirements-api.txt
# DO NOT include tensorflow / torch / transformers / opencv here
```

Agent: diff `requirements-api.txt` against the preinstalled list; include remaining light deps; comment out heavy ones with a note.

## Task 7 — Bootstrap notebook

Create `deploy/kaggle/bootstrap.ipynb` (valid nbformat 4 JSON, python3 kernel) with these cells:

1. **Markdown** — preflight checklist: Accelerator = GPU (T4); Internet = ON; attach dataset `advs-models`; attach all secrets.
2. **Code** — clone (private-aware):

   ```python
   import os
   token = os.environ.get("GITHUB_TOKEN", "")
   repo = "<GITHUB_USER>/advs-api"
   url = f"https://{token}@github.com/{repo}" if token else f"https://github.com/{repo}"
   !git clone --depth 1 {url} advs
   %cd advs
   ```

3. **Code** — deps:

   ```python
   !pip install -q -r deploy/kaggle/requirements-kaggle.txt
   ```

4. **Code** — secrets → runtime files/env:

   ```python
   import os
   from pathlib import Path
   from kaggle_secrets import UserSecretsClient
   sec = UserSecretsClient()

   os.environ["NGROK_AUTHTOKEN"] = sec.get_secret("NGROK_AUTHTOKEN")

   keys = [l.split("=")[0].strip() for l in Path(".env.api.example").read_text().splitlines()
           if "=" in l and not l.startswith("#")]
   Path(".env.api").write_text("\n".join(f"{k}={sec.get_secret(k)}" for k in keys) + "\n")
   print("wrote .env.api with:", keys)
   ```

5. **Code** — run (blocking; this cell IS the keep-alive):

   ```python
   !python deploy/kaggle/kaggle_start.py
   ```

## Task 8 — Docs

Create `deploy/kaggle/README.md` covering:

1. The HUMAN one-time steps above (repo, dataset, secrets, ngrok).
2. Session workflow: new notebook → GPU + Internet ON → attach dataset + secrets → paste/run bootstrap cells → copy printed ngrok URL.
3. Restart procedure: Kaggle kills sessions after ~9–12 h → Run All again; free-ngrok URL changes every session, re-share it.
4. Troubleshooting: dataset not mounted (`ls /kaggle/input`), ngrok auth error, clone auth error, OOM (never run 2 workers; restart kernel), long model load (watch `[boot]` prints).

## Task 9 — Commit and push

Commit all changes to `advs-api` (`main`) with clear messages and push.

---

## Constraints (DO NOT)

- Do not change inference/model logic.
- Do not commit `models/`, `backup_models/`, `.env.api`, `env/`, `tmp/`, logs.
- Do not delete `Dockerfile` / `requirements.txt` (used elsewhere).
- Do not add heavy ML packages to `requirements-kaggle.txt`.
- Do not physically move `python/` out of the Laravel root.
- Windows local run with zero env vars must behave exactly as before.

## Acceptance criteria

- [ ] `python/` is its own repo pushed to `https://github.com/<GITHUB_USER>/advs-api`; parent repo (if any) no longer tracks it.
- [ ] First commit contains code only (no models, venv, secrets, logs); no tracked file >50 MB.
- [ ] No hardcoded `models/` literals in `api/` except `api/config.py`.
- [ ] `/health` endpoint exists and responds.
- [ ] `deploy/kaggle/` contains: `kaggle_start.py`, `requirements-kaggle.txt`, `bootstrap.ipynb` (valid JSON), `README.md`.
- [ ] `python deploy/kaggle/kaggle_start.py --smoke` passes locally (server boots, `/health` 200, clean exit, no ngrok).
- [ ] `uvicorn api.main:app` still boots locally with defaults.
- [ ] README covers dataset upload, secrets, GPU/Internet settings, daily restart.

## Open inputs (resolve before starting)

- `<GITHUB_USER>` — from the human.
- Whether `<LARAVEL_ROOT>` is a git repo — agent must detect and branch in Task 2.
- Exact secret key names — agent reads `.env.api.example` itself.
- Total size of `models/` — human confirms it fits the Kaggle dataset limit.

---

## File: python/siamese.md

# ADVS — Siamese Signature Validator · Development Context

> **Scope:** the standalone development context for **model 3 of 4** — the Siamese CNN signature
> verifier (Stage 4a). Everything you need to build, train, threshold, serve, and finish this one
> model. This is the **M4** track in the phase plans.
>
> Read alongside:
> - **What Stage 4a does** — [`../docs/ADVS_REFERENCE.md`](../docs/ADVS_REFERENCE.md) §5 Stage 4a, §8 (storage), §9 (`SIGNATURE_DISTANCE_THRESHOLD`, `RISK_WEIGHT_SIGNATURE`).
> - **The Python lifecycle** — [`DEVELOPMENT_PHASES.md`](DEVELOPMENT_PHASES.md) Phase 6 (Siamese) — this doc expands it.
> - **How we build** — [`../README.md`](../README.md) and [`README.md`](README.md).
> - **M-phase tracking** — [`../docs/phases/MODEL_TRAINING_PHASES.md`](../docs/phases/MODEL_TRAINING_PHASES.md) (M4).

**Legend:** ✅ done · 🟡 partial · ⚠️ present but inadequate · ❌ not started · ⏸ deferred.

**Status headline:** 🟡 **awaiting the Colab GPU run of notebook 03.** Trainer + notebook are
CEDAR-ready, preprocessing is aligned with the live API serve path, `--dry-run` / `--smoke` are green,
and smoke artefacts load through the API registry. The one remaining step is to run
[`notebooks/03_siamese_signature.ipynb`](notebooks/03_siamese_signature.ipynb) on a Colab GPU and drop
the three artefacts into `python/models/`.

---

## 0. Global constraints (obey always)

- **Interpreter:** the repo ML venv **`python/env/Scripts/python.exe`** (python.org 3.12.10, full TF
  stack). **Never** bare `python` (MSYS2, no wheels); **do not** rebuild on `py` (3.14, too new for TF).
- **Keras 3 caveat:** the venv runs **TF 2.16 → Keras 3**. This dictates two design choices below
  (UnitNormalization layer, module-level `l1_distance`) — do **not** revert them to Lambdas/closures.
- **Run from the project root** `c:\xampp\htdocs\projects\advs`.
- **Weights are gitignored** (`python/models/`). Never `git add` `.h5` / `.txt` weight artefacts.
  Document how to reproduce them instead.
- **Generated signature data is gitignored** (`data/training/signature_data/**`, `data/validation/**`).
- **ASCII-only `print()`** (Windows cp1252 console); keep the `[signature]` log prefix.
- **Tests** use `pytest`, load modules by path via `importlib.util`, and must **never** write into the
  real data folders — use `tmp_path`.

---

## 1. What this model is (and is not)

**Purpose:** given the cropped signature from a submitted document, decide whether it belongs to the
**same vendor** whose reference signature was captured at registration — i.e. forgery / impersonation
detection, not signature *recognition*.

**Pipeline placement — Stage 4a** ([reference §5 Stage 4a](../docs/ADVS_REFERENCE.md)):

```
Stage 4 (YOLOv8) detects a `signature` crop
        │
        ▼
Stage 4a: crop → Siamese encoder → 128-D query embedding
        │        Euclidean distance vs the vendor's REGISTRATION reference embedding
        ▼        distance ≤ SIGNATURE_DISTANCE_THRESHOLD ?  → match / flag
Stage 5 risk score consumes (1 − signature_similarity) · RISK_WEIGHT_SIGNATURE (w3 ≈ 0.20)
```

**Verify-only in the pipeline.** The per-vendor 128-D reference is enrolled **at vendor registration**
(the API `/v1/signature/embed` route) and stored by Laravel in `vendor_embeddings.signature_embedding`.
By submission time the reference **always exists**, so `ProcessDocumentAction` only ever *computes a
distance* — there is **no** "first submission auto-enrolls the signature" branch. (Contrast Stage 4b
logos, which are issuer-keyed and seeded on officer approval.)

**Fail-forward.** No signature detected by YOLOv8 → Stage 4a is **skipped**, recorded as a
`no_signature_detected` risk factor; the pipeline does **not** abort.

---

## 2. Architecture

Twin-tower Siamese network with a shared ResNet-50 encoder. Defined in
[`scripts/train_signature.py`](scripts/train_signature.py) (`build_encoder`, `train`).

```
                 shared encoder (siamese_encoder.h5)
  crop ──► ResNet50(weights=imagenet, include_top=False, pooling='avg')  # 2048-D
       ──► Dense(128, activation=None)
       ──► UnitNormalization(axis=-1)      # L2-normalise → 128-D embedding on the unit sphere

  in_a ─► encoder ─┐
                   ├─► Lambda(l1_distance) = |emb_a − emb_b|  ─► Dense(1, sigmoid) = P(same vendor)
  in_b ─► encoder ─┘
                 (full twin = siamese_signature.h5)
```

| Config (`CONFIG` in `train_signature.py`) | Value |
|---|---|
| `image_size` | 224 |
| `embedding_dim` | **128** (contract-fixed; reference §5 4a) |
| `batch_size` | 16 |
| `epochs` | 20 |
| `lr` (Adam) | 1e-4 |
| `pairs_per_vendor` | 20 (alternating genuine / forged) |
| loss / metric | binary_crossentropy / accuracy |
| `seed` | 42 |

**Two Keras-3 design constraints — do not "simplify" away:**
1. **`UnitNormalization` layer, not a `Lambda`.** Keras 3 cannot pickle a lambda that closes over the
   lazily-imported `tf` module, and the API's plain `load_model()` refuses Lambdas under default
   `safe_mode`. Use the real layer.
2. **`l1_distance` is a module-level named function** (no closure) so Keras can serialise the twin
   model's Lambda into `siamese_signature.h5`.

---

## 3. Preprocessing contract — MUST match the serve path

The embedding space and the EER threshold only transfer if training and serving preprocess **identically**.

| | Training ([`train_signature.py`](scripts/train_signature.py)) | Serving ([`api/routers/signature.py`](api/routers/signature.py) → [`api/embedding.py`](api/embedding.py)) |
|---|---|---|
| Colour | **RGB** (`cv2.cvtColor(..., BGR2RGB)`) | **RGB** (`image.convert("RGB")`) |
| Resize | `cv2.resize(..., (224, 224))` | `resize(keras_input_size(model))` — read from `model.input_shape` |
| Normalise | `resnet50.preprocess_input` | `resnet50.preprocess_input` (`_resnet_preprocess`) |
| dtype | float32 | float32 |

**Rule:** if you change preprocessing on one side, change it on the other in the same commit, then
recompute the threshold. The serve size is read from the model's own `input_shape`, so a re-export at a
different `image_size` stays consistent automatically — but the **normalisation must stay
`resnet50.preprocess_input`**.

---

## 4. Data

**Layout (read-only to the trainer, gitignored):**

```
data/training/signature_data/<vendor>/*.png            # genuine signatures (≥ 2 per vendor)
data/training/signature_data/<vendor>/forged/*.png     # OPTIONAL real skilled forgeries (CEDAR)
data/validation/signature_data/<vendor>/*.png          # same shape, held-out signers
```

**Pairing** (`make_pairs`): per vendor, alternate genuine pairs (label **1**) and forged pairs
(label **0**). Forged partner = a **real skilled forgery** from `<vendor>/forged/` when present,
otherwise a **synthetic forgery** fabricated from a genuine sample via
`make_synthetic_forgery` (elastic `cv2.remap` warp + ±12° rotation + gaussian noise).

**Corpus:** the primary source is **CEDAR** — notebook 03 downloads it and reshapes it into the
`<signer>/` + `<signer>/forged/` layout. Hold out **~20% of signers** for validation (signer-disjoint,
so the model is tested on identities it never trained on).

**Current state:** ⚠️ `data/training/signature_data` holds only **smoke-test fixtures** (~4 vendor
subdirs, ~16 train / 8 val files) — enough for `--smoke` and `--dry-run`, **not** enough to train a
usable model. Real training data comes from the CEDAR run in notebook 03.

---

## 5. Training

Two entry points, same logic:

- **[`scripts/train_signature.py`](scripts/train_signature.py)** — the CLI trainer (local / smoke).
- **[`notebooks/03_siamese_signature.ipynb`](notebooks/03_siamese_signature.ipynb)** — the CEDAR
  download + Colab-GPU training path. **This is the one that still needs to be run.**

```bash
# from project root, using the repo ML venv
python/env/Scripts/python.exe python/scripts/train_signature.py --dry-run   # layout check only, stdlib only
python/env/Scripts/python.exe python/scripts/train_signature.py --smoke     # 1-epoch tiny CPU run (needs fixtures)
python/env/Scripts/python.exe python/scripts/train_signature.py             # full training (needs ML stack + real data)
```

Flags: `--data-root` (default `python/data`), `--models-out` (default `python/models`), `--dry-run`
(no heavy imports), `--smoke` (reduced sizes, `CUDA_VISIBLE_DEVICES=-1`). Heavy imports (`tensorflow`,
`cv2`) are lazy so `--dry-run` works with stdlib only. Exit codes: `0` ok, `2` structure error.

**Recommended path to real weights:** open notebook 03 on a **Colab GPU**, run the CEDAR cells, then
download the three artefacts into `python/models/`. Full local CPU training is slow; smoke/dry-run
locally, train on Colab.

---

## 6. Outputs & the EER threshold

Written to `--models-out` (default `python/models/`, gitignored):

| Artefact | What | Consumed by |
|---|---|---|
| `siamese_encoder.h5` | the shared 128-D encoder tower | **the API** (`SIAMESE_MODEL_PATH` defaults here) — embed + verify |
| `siamese_signature.h5` | the full twin (encoder + L1 + sigmoid) | analysis / re-training; not the serve path |
| `signature_threshold.txt` | the **EER** Euclidean-distance threshold | `SIGNATURE_DISTANCE_THRESHOLD` default |

**Threshold = Equal Error Rate.** `equal_error_rate_threshold` scans 200 candidate distances and picks
the one where **FAR ≈ FRR** (label 1 = genuine = small distance). Computed on the validation pairs
(falls back to training pairs if no val vendors). This is the empirically-determined
`SIGNATURE_DISTANCE_THRESHOLD` the reference §9 leaves as "Empirical" — **never invent a value**; the
training run produces it.

> Note the API serves from `siamese_encoder.h5` (the encoder alone) and does the distance/threshold
> comparison itself — it does **not** use the sigmoid head. The twin's sigmoid is a training convenience.

---

## 7. Serving (Stage 4a runtime)

FastAPI router: [`api/routers/signature.py`](api/routers/signature.py). Stateless — the reference
embedding is passed in per request (Laravel stores it).

- **`POST /v1/signature/embed`** — one crop → `{ "embedding": [128 floats] }`. Used at **registration**
  to enroll the vendor's reference.
- **`POST /v1/signature/verify`** — `file` + `reference_embedding` (JSON array) →
  ```json
  { "match": true|false|null, "distance": 1.24, "similarity": 0.446,
    "threshold": 1.20, "embedding": [ ... ] }
  ```
  `similarity = 1 / (1 + distance)`; `match = distance ≤ threshold` (or **`null`** if no threshold file
  is present yet — the API never fabricates one). `require_same_length` guards reference/query
  dimension mismatch (422).

Both routes return **503 `model_not_loaded`** until the weights exist. Preprocessing inside the router
(`_resnet_preprocess`) is `resnet50.preprocess_input` — the same call the trainer uses (§3).

---

## 8. Named inference contract (Laravel handoff) — ❌ not built yet

The FastAPI signature endpoints must preserve the serving contract documented in [`README.md`](README.md)
`--input <json>` / `--output <json>` CLI contract (the pipeline calls the CLI, not the HTTP API,
per the current design):

```
signature_verify.py  --input <payload.json> --output <result.json>
  payload:  { image_path, reference_embedding, mode? }
  result :  { "match": true, "similarity": 0.91, "embedding": [ ... ] }
  no crop:  { "match": false, "reason": "no_signature_detected" }
```

It should load the encoder **once per process** via `utils/model_loader.py` (also unbuilt) and reuse
the exact preprocessing above. Exit 0 on success, non-zero + stderr on failure.

---

## 9. Thresholds & risk (reference §9)

| Parameter | Default | Role |
|---|---|---|
| `SIGNATURE_DISTANCE_THRESHOLD` | **Empirical (EER)** | max Euclidean distance for a match — produced by training, written to `signature_threshold.txt`, configurable in System Settings |
| `RISK_WEIGHT_SIGNATURE` (w3) | 0.20 | weight of `(1 − signature_similarity)` in the composite risk score |
| `MISSING_COMPONENT_PENALTY` | 15 | added when no signature is detected |

Thresholds are **configurable**, not hard-coded. Training fixes the empirical one; operators can tune
it in the ML Model Management / System Settings UI.

---

## 10. Definition of done (Phase 6 / M4)

- [x] Notebook 03 run on Colab GPU; `siamese_encoder.h5`, `siamese_signature.h5`,
      `signature_threshold.txt` dropped into `python/models/` (EER threshold **1.243976**).
- [x] `pytest python/tests/test_signature_verify.py` written, gating on the **empirical EER distance
      threshold** in `signature_threshold.txt` (NOT the literal 0.85): genuine pair distance ≤ threshold
      (match), forged pair distance > threshold (no match), mean genuine below mean forged by ≥ 0.30, and
      embedding length **exactly 128**. The 0.85 similarity figure is miscalibrated to the unit-sphere
      embedding scale (genuine similarity ≈ 0.55 for this model) — see `M4_training_run_record.md`.
- [x] EER threshold recorded and surfaced as the reference §9 `SIGNATURE_DISTANCE_THRESHOLD` default.
- [ ] Validation metric (EER / FAR-FRR) recorded for the ML Model Management UI.
- [ ] `--dry-run` and `--smoke` stay green; serve-path preprocessing (§3) still matches the trainer.

---

## 11. Key files

| File | Role |
|---|---|
| [`scripts/train_signature.py`](scripts/train_signature.py) | trainer, pairing, synthetic forgery, EER threshold |
| [`notebooks/03_siamese_signature.ipynb`](notebooks/03_siamese_signature.ipynb) | CEDAR download + Colab-GPU training (**run this**) |
| [`api/routers/signature.py`](api/routers/signature.py) | `/v1/signature/{embed,verify}` serve path |
| [`api/embedding.py`](api/embedding.py) | shared embed/euclidean helpers (serve preprocessing) |
| `scripts/signature_verify.py` | ❌ Phase-10 CLI contract for the Laravel pipeline |
| `utils/model_loader.py` | ❌ singleton encoder loader (Phase 10) |
| `python/models/siamese_encoder.h5` | ❌ produced by the Colab run (gitignored) |
| `python/tests/test_signature_verify.py` | ❌ contract tests (DoD) |

---

## 12. Open items

1. ~~**Run notebook 03 on Colab** → produce and commit-by-hand the three artefacts to `python/models/`.~~
   ✅ done (EER threshold `1.243976`).
2. ~~**Write `test_signature_verify.py`**~~ ✅ done — `python/tests/test_signature_verify.py` gates on the
   **empirical EER distance threshold** (not the miscalibrated 0.85 similarity); dim 128 / genuine ≤
   threshold / forged > threshold. Real DoD crops live in `python/tests/fixtures/signature_data/`.
3. **Build the Phase-10 CLI** `signature_verify.py` + `model_loader.py` for the Laravel handoff.
4. **Record the EER** and register it in ML Model Management + System Settings as the configurable
   default.

*Current-state figures are a snapshot — re-count `data/training/signature_data` and check
`python/models/` before acting.*


---

