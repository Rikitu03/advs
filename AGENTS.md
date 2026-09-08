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

- Never run `php artisan migrate:fresh`, `migrate:refresh`, or any command that
  drops application tables unless the user explicitly requests a destructive
  database reset. Preserve the existing local database by default.
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
- laravel/telescope (TELESCOPE) - v5
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
