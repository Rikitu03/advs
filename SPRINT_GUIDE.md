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
