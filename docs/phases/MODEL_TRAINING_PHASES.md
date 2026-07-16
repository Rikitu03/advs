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
> - **Model architectures & I/O contracts** — [ADVS_System_Reference.md](../../ADVS_System_Reference.md) (Stages 3, 4, 4a, 4b, T; §8 model files; §9 thresholds).
> - **Training spec** — [training_script.md](../../training_script.md) (the single end-to-end training script brief).
> - **How we build (Python)** — [CLAUDE.md §6](../../CLAUDE.md) (script I/O contract, logging) and §5–§6 phases.
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
| 2 | **YOLOv8** | Detect `signature` + `stamp_seal` + `logo` regions | Stage 4 — `signature_verify.py` / `stamp_verify.py` | `yolov8_document.pt` (and/or ONNX export) |
| 3 | **Siamese CNN** (ResNet-50 backbone) | Signature verification (128-D embedding) | Stage 4a — `signature_verify.py` | `siamese_signature.h5` + `siamese_encoder.h5` + `signature_threshold.txt` |
| 4 | **EfficientNet-B0** | Stamp/logo feature extraction + tamper texture | Stage 4b — `stamp_verify.py` | `efficientnet_stamp.h5` + `stamp_classifier.pkl` / `stamp_threshold.txt` |
| 5 | **Tamper fusion** *(planned)* | Fuse the 5 Stage-T forensic signals into one authenticity score | Stage T — `tamper_analyze.py` | *(deterministic blend today; ML model is a future phase)* |

> Thresholds live in [ADVS_System_Reference.md §9](../../ADVS_System_Reference.md) and are **configurable**,
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
| Training scripts | 🟡 Partial | `train_classifier.py` present; full multi-model training per [training_script.md](../../training_script.md) not consolidated. |
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
- Confirm the detection/signature/stamp layouts from [training_script.md](../../training_script.md):
  `data/detection/{images,labels}` (YOLO txt, class 0=signature, 1=stamp_seal, 2=logo), `data/signatures/raw/<vendor>`,
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

**Goal:** Train the multi-class authenticity/type classifier per the [training_script.md](../../training_script.md) spec.

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

## Phase M3 — YOLOv8 signature/stamp/logo detector

**Goal:** Detect `signature`, `stamp_seal`, and `logo` regions anywhere on a document (no ROI/homography needed).

**Tasks:**
- Dynamically write `data/detection/data.yaml`; train `yolov8n.pt`, 50 epochs, `imgsz=640`, `batch=16`,
  `patience=10`, GPU device 0 if available, `cache=True`.
- Evaluate mAP@0.5 on the val split; export best to `yolov8_document.pt` (and ONNX
  `yolov8_signature_stamp_logo.onnx`, honoring the `onnx < 1.17` pin).

**Definition of Done:** mAP@0.5 printed and recorded; crops route correctly (signature → Stage 4a,
stamp_seal/logo → Stage 4b); the no-detection path returns a graceful JSON flag
(`{"reason": "no_signature_detected"}` / `no_stamp_detected` / `no_logo_detected`) consumed by the
pipeline track.

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
[CLAUDE.md §6](../../CLAUDE.md) I/O contract — this is the seam the pipeline track (P0/P3) consumes.

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
contract examples in [CLAUDE.md §6](../../CLAUDE.md); the pipeline track can drive a live document through
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
