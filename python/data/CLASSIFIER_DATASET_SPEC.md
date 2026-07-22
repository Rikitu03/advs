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
