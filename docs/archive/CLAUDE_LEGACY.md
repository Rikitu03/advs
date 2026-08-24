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
