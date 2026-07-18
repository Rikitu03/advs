# ADVS — Python ML Development Phases (top to end)

> **Scope:** the **complete, standalone development lifecycle of the `python/` track** — environment →
> data taxonomy → dataset preparation → model training → OCR/field extraction → forensics → the named
> inference contracts Laravel calls → evaluation/packaging → retraining. It is the authoritative
> reference for work done *inside this directory*. The Laravel pipeline wiring and the UI are covered by
> their own plans (linked below); this doc owns everything Python.
>
> Read alongside:
> - **What the system does** — [`../ADVS_System_Reference.md`](../ADVS_System_Reference.md) (Stages 1–T, §9 thresholds, §8 model files).
> - **How we build** — [`../CLAUDE.md`](../CLAUDE.md) (§6 Python integration & I/O contract).
> - **Training brief** — [`../training_script.md`](../training_script.md).
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

**Goal:** Train the multi-class type/authenticity classifier ([scripts/train_classifier.py](scripts/train_classifier.py), [training_script.md §1](../training_script.md)).
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

**Goal:** Deliver the exact CLI scripts the orchestrator calls, matching the [CLAUDE.md §6](../CLAUDE.md)
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
**DoD:** `pytest python/tests/ -v` green across all six; manual runs match the [CLAUDE.md §6](../CLAUDE.md)
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
