# ADVS — ResNet-50 Classifier Dataset Spec & Taxonomy

> The **current class list** the document-classification model (Stage 3, ResNet-50) is trained on, plus
> the **balanced per-label dataset targets**. This is the source of truth for what lives under
> `python/data/training/classifier_data/<class>/` and `python/data/validation/classifier_data/<class>/`.
>
> Context: [`../DEVELOPMENT_PHASES.md`](../DEVELOPMENT_PHASES.md) (Phase 1 taxonomy lock-in, Phase 2
> dataset generation) · [`../../docs/CLIENT_INTERVIEW_GAP_PLAN.md`](../../docs/CLIENT_INTERVIEW_GAP_PLAN.md)
> (Negofood food-business / vendor scope) · [`../../ADVS_System_Reference.md`](../../ADVS_System_Reference.md)
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
(primary); (2) **loss-level** — `class_weight` via scikit-learn `compute_class_weight` (**planned but
not yet wired** in [`../scripts/train_classifier.py`](../scripts/train_classifier.py), see
[Complications](#complications--blockers) below), corrects residual skew. Keep splits **stratified**
(same per-class proportion in train/val/test); the generators' content-hash manifest prevents duplicate
leakage across splits.

> `fake` should be **1,000–1,500 and diverse** (forgeries/edits/wrong-templates spanning many real types)
> so the fraud class isn't one visual mode. `other` (optional reject bucket) ~500–1,000 if adopted;
> otherwise rely on the 0.70 confidence threshold to surface "Unknown document type".

---

## Current live labels (actual filesystem state)

The folders below are the **exact labels currently present** in **both** `training/classifier_data/` and
`validation/classifier_data/`. Last verified: **2026-07-08**.

### ✅ Populated classes (images present)

| Class folder | Train images | Val images | Generator | Notes |
|---|---:|---:|---|---|
| `bir_certificate` | **1,514** | **202** | ✅ `bir_dataset_generator.py` | Meets target; well-populated |
| `business_permit` | **1,000** | **202** | ✅ `business_permit_dataset_generator.py` | Meets target |
| `dti_registration` | **2,000** | **1,000** | ✅ `dti_registration_dataset_generator.py` | ⚠️ Over-represented; val oversized (5× target) |
| `fake` | **2,007** | **1,002** | ✅ `fake_dataset_generator.py` | ⚠️ Over-represented; val oversized (5× target) |

**Total classes: 4** (training and validation are symmetric — no mismatches).

`train_classifier.py --dry-run` exits 0 with these 4 classes.

### ❌ Planned classes (folders do NOT exist on disk)

The following 22 classes were **previously listed in this spec** but have **never been generated**. No
class folders, no `.gitkeep`, no images exist for them in either training or validation `classifier_data/`.
They remain the intended taxonomy once generators are built for each.

| Planned class | `DocumentTypeSeeder` code | Generator | Template | Seal/Logo |
|---|---|---|---|---|
| `sec_registration` | ✅ `sec_registration` | ❌ | ❌ | ❌ |
| `sec_gis` | ✅ `sec_gis` | ❌ | ❌ | ❌ |
| `sanitary_permit` | ✅ `sanitary_permit` | ❌ | ❌ | ❌ |
| `fda_registration` | ✅ `fda_registration` | ❌ | ❌ | ❌ |
| `food_handler_certificate` | ✅ `food_handler_certificate` | ❌ | ❌ | ❌ |
| `financial_statement` | ✅ `financial_statement` | ✅ `financial_statement_generator.py` | ? | ❌ |
| `signed_contract` | ✅ `signed_contract` | ❌ | ❌ | ❌ |
| `national_id` | ✅ `national_id` | ❌ | ❌ | ❌ |
| `philhealth_id` | ✅ `philhealth_id` | ❌ | ❌ | ❌ |
| `sss_id` | ✅ `sss_id` | ❌ | ❌ | ❌ |
| `umid` | ✅ `umid` | ❌ | ❌ | ❌ |
| `postal_id` | ✅ `postal_id` | ❌ | ❌ | ❌ |
| `drivers_license` | ✅ `drivers_license` | ❌ | ❌ | ❌ |
| `passport` | ✅ `passport` | ❌ | ❌ | ❌ |
| `prc_id` | ✅ `prc_id` | ❌ | ❌ | ❌ |
| `voters_id` | ✅ `voters_id` | ❌ | ❌ | ❌ |
| `tin_id` | ✅ `tin_id` | ❌ | ❌ | ❌ |
| `employment_contract` | ❌ not in seeder | ❌ | ❌ | ❌ |
| `health_medical_certificate` | ❌ not in seeder | ❌ | ❌ | ❌ |
| `nbi_clearance` | ❌ not in seeder | ❌ | ❌ | ❌ |
| `police_clearance` | ❌ not in seeder | ❌ | ❌ | ❌ |
| `other` | ❌ (reject bucket, not a doc type) | ❌ | — | — |

> **`financial_statement`** is the notable gap: the generator exists and was previously populated (~1,005
> train images in older runs), but the class folder was **removed or not regenerated** in the current
> dataset. Re-running the generator would restore it.

---

## Class balance analysis (current 4-class dataset)

| Class | Train | Val | Train % | Val % | vs Target |
|---|---:|---:|---:|---:|---|
| `bir_certificate` | 1,514 | 202 | 23.2% | 8.4% | ✅ at target |
| `business_permit` | 1,000 | 202 | 15.3% | 8.4% | ✅ at target |
| `dti_registration` | 2,000 | 1,000 | 30.7% | 41.6% | ⚠️ 2× train, 5× val |
| `fake` | 2,007 | 1,002 | 30.8% | 41.6% | ⚠️ 2× train, 5× val |
| **Totals** | **6,521** | **2,406** | 100% | 100% | |

**Imbalance:** `dti_registration` and `fake` each hold ~31% of training data while `business_permit`
holds only 15%. The validation split is even more skewed — `dti_registration` and `fake` each have 5×
the target (1,000 vs 200). This will bias the model unless corrected by `class_weight` or resampling.

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

### Existing generators (ready to run)

| Generator script | Output class | Status |
|---|---|---|
| `bir_dataset_generator.py` | `bir_certificate` | ✅ populated |
| `business_permit_dataset_generator.py` | `business_permit` | ✅ populated |
| `dti_registration_dataset_generator.py` | `dti_registration` | ✅ populated |
| `fake_dataset_generator.py` | `fake` | ✅ populated |
| `financial_statement_generator.py` | `financial_statement` | ✅ exists but **class folder empty/absent** |

### Templates & assets available

| Template directory | Seal/Logo asset |
|---|---|
| `data/template/bir_permit/` | `data/seal/BIR_SEAL.png` |
| `data/template/business_permits/` | `data/logo/logo_digos.png` (LGU) |
| `data/template/dti_registration/` | `data/logo/dti_logo.png` |

---

## Complications & blockers

> See the [Training Readiness Assessment](#training-readiness-assessment) below.

### 1. `class_weight` not wired in `train_classifier.py`

The spec (this document) previously claimed `class_weight` via scikit-learn's `compute_class_weight` was
"already wired in" the training script. **This is incorrect.** Neither `class_weight` nor
`compute_class_weight` appear anywhere in
[`train_classifier.py`](../scripts/train_classifier.py). With the current 2:1 imbalance between the
largest and smallest classes, this is a **moderate risk** — the model will be biased toward
`dti_registration` and `fake`.

### 2. Validation split is heavily oversized for 2 classes

`dti_registration` and `fake` each have ~1,000 validation images (5× the 200 target). The other two
classes have ~200 each. This distorts validation metrics — accuracy will be dominated by the two large
classes, masking poor per-class recall on the smaller ones.

### 3. Only 4 of 22+ intended classes exist

The final taxonomy calls for 20+ document types (per `DocumentTypeSeeder`) plus `fake` and optionally
`other`. Only **3 real document types + `fake`** are populated. Training now would produce a model that
can only distinguish between BIR certificates, business permits, DTI registrations, and fakes — it would
**misclassify every other document type**.

### 4. `financial_statement` regression

The `financial_statement` generator exists and has been run before (DEVELOPMENT_PHASES.md recorded 1,005
train images), but the class folder is **absent from the current filesystem**. It needs to be regenerated.

---

## Training readiness assessment

### Can we run `train_classifier.py` right now?

**Technically yes** — `--dry-run` exits 0 and a `--smoke` (or full) run would train a 4-class model.
**Practically, it is premature** for the following reasons:

| Factor | Status | Severity | Impact |
|---|---|---|---|
| Script runs | ✅ `--dry-run` exits 0 | — | — |
| Minimum 2 classes | ✅ 4 classes present | — | — |
| Class balance (data-level) | ⚠️ 2:1 skew | **Medium** | Model biased toward over-represented classes |
| Class balance (loss-level) | ❌ `class_weight` missing from script | **Medium** | No loss-level correction for skew |
| Validation split sizing | ⚠️ 2 classes 5× oversized | **Medium** | Val metrics unreliable |
| Taxonomy completeness | ❌ 4 of ~22 classes | **🔴 High** | Model useless for the 18+ missing document types |
| `financial_statement` | ❌ generator exists, data missing | **Low** | Quick to fix (re-run generator) |

### Verdict: **NOT a go** for production training

A 4-class model would be a valid **proof-of-concept / smoke run** to validate the training pipeline end
to end (`--smoke` mode already supports this). But it is **not production-ready** because:

1. **~82% of the target taxonomy is missing.** The model would reject or misclassify most real documents.
2. **Balance issues** need fixing even for the 4 existing classes before a real training run.
3. The **`class_weight` gap** in the training script should be fixed regardless.

### Recommended next steps (priority order)

1. **Re-run `financial_statement_generator.py`** to restore the 5th class (low effort, generator exists).
2. **Wire `class_weight`** into `train_classifier.py` `model.fit()` calls.
3. **Trim `dti_registration` and `fake` validation splits** to ~200 each (or generate more val images for
   `bir_certificate` and `business_permit` to match).
4. **Run `--smoke`** with the corrected 5-class dataset to validate the full pipeline end-to-end.
5. **Build generators for the remaining document types** (Phase 2 in DEVELOPMENT_PHASES.md) — this is the
   long pole before production training.

---

## Storage & git

- Folders are scaffolded with a tracked `.gitkeep` each (train + val), per `.gitignore` lines 40–45
  (directory scaffold + `.gitkeep` are the committable exception).
- **Images and `_synthetic_manifest.json` are gitignored** — local artifacts only; **never `git add`** them.
- The classifier reads the class label from the **immediate subfolder name** of `classifier_data/`
  (`image_dataset_from_directory`), so every class is a **flat top-level folder** — IDs are individual
  classes (`national_id`, `philhealth_id`, …), not nested under a `government_id/` parent.
