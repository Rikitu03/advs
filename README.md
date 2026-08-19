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

- Fortify session authentication, email verification, password reset, and
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
   retained as flags instead of aborting processing.
4. **Classification** predicts the document class and exposes authenticity as
   `1 - P(fake)` so a confident fake prediction increases risk.
5. **Detection** locates signature, stamp, and logo regions with YOLOv8.
6. **Signature verification** compares a detected crop with the vendor's
   registration-time 128-D reference embedding.
7. **Stamp/logo verification** runs a texture tamper check and compares the
   feature vector with an issuer reference. National references use document
   type; LGU references use document type plus OCR-detected city.
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

