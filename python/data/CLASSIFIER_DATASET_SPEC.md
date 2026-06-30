# ADVS — ResNet-50 Classifier Dataset Spec & Taxonomy

> The **canonical class list** the document-classification model (Stage 3, ResNet-50) is trained on, plus
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

**Uniform ~1,000 train / ~200 val per class.** With **22 classes** that's **≈ 22,000 train + ≈ 4,400 val**
images (+ optional ≈ 2,200 test). Two existing classes (`bir_certificate` ≈ 1,014, `financial_statement`
≈ 1,005) already sit at this target — so 1,000 is a *proven, reachable* balance point, not an arbitrary one.

Balance is enforced at **two layers**: (1) **data-level** — generate to a uniform per-class count
(primary); (2) **loss-level** — `class_weight` via scikit-learn `compute_class_weight`, already wired in
[`../scripts/train_classifier.py`](../scripts/train_classifier.py), corrects residual skew. Keep splits
**stratified** (same per-class proportion in train/val/test); the generators' content-hash manifest
prevents duplicate leakage across splits.

> `fake` should be **1,000–1,500 and diverse** (forgeries/edits/wrong-templates spanning many real types)
> so the fraud class isn't one visual mode. `other` (optional reject bucket) ~500–1,000 if adopted;
> otherwise rely on the 0.70 confidence threshold to surface "Unknown document type".

---

## Canonical taxonomy (22 classes)

`issuer_scope` drives **Stage 4b** logo/seal verification (`national` = one agency logo by type; `lgu` =
per-city seal by type+city; `null` = no issuer logo). `requires_expiry` drives **Phase 8** field
extraction + the compliance expiry/renewal layer. Train/val targets are uniform per the table above.

### A. Business registration & permits
| Class folder | Issuer | `issuer_scope` | `requires_expiry` | Generator | Seeder code today |
|---|---|---|---|---|---|
| `bir_certificate` | BIR | national | no | ✅ [bir_dataset_generator](../scripts/bir_dataset_generator.py) | `bir_permit` ⚠️ rename |
| `sec_registration` | SEC | national | no | ❌ new | — new |
| `sec_gis` | SEC | national | no (annual filing) | ❌ new | `gis` ⚠️ rename |
| `dti_registration` | DTI | national | **yes** (≈5 yr) | ❌ new | — new |
| `business_permit` | LGU / City | lgu | **yes** (annual) | ✅ [business_permit_dataset_generator](../scripts/business_permit_dataset_generator.py) | `business_permit` ✓ |

### B. Food-safety / operations
| Class folder | Issuer | `issuer_scope` | `requires_expiry` | Generator |
|---|---|---|---|---|
| `sanitary_permit` | LGU City Health | lgu | **yes** (annual) | ❌ new |
| `fda_registration` | FDA | national | **yes** (LTO/CPR validity) | ❌ new |
| `food_handler_certificate` | LGU City Health | lgu | **yes** (annual health card) | ❌ new |

### C. Financial / contractual
| Class folder | Issuer | `issuer_scope` | `requires_expiry` | Generator | Seeder code today |
|---|---|---|---|---|---|
| `financial_statement` | auditor | null | no | ✅ [financial_statement_generator](../scripts/financial_statement_generator.py) | `financial_stmt` ⚠️ rename |
| `signed_contract` | counterparty | null | contextual | ❌ new | `signed_contract` ✓ |

### D. Government IDs (one class per ID type)
All issued by a single national agency → `issuer_scope = national` (the agency logo/seal is the same
nationwide). `requires_expiry` varies by ID.
| Class folder | Issuing agency | `requires_expiry` |
|---|---|---|
| `national_id` (PhilSys / PhilID) | PSA | no (lifetime) |
| `philhealth_id` | PhilHealth | no |
| `sss_id` | SSS | no |
| `umid` (Unified Multi-Purpose ID) | SSS/GSIS | no |
| `postal_id` | PHLPost | **yes** (≈3 yr) |
| `drivers_license` | LTO | **yes** |
| `passport` | DFA | **yes** (10 yr) |
| `prc_id` | PRC | **yes** (3 yr) |
| `voters_id` | COMELEC | no |
| `tin_id` | BIR | no |

### E. Negative / reject
| Class folder | Purpose | Target |
|---|---|---|
| `fake` | forged / tampered / wrong-template negatives | 1,000–1,500, **diverse** |
| `other` *(optional)* | out-of-taxonomy / unsupported docs (explicit reject class) | 500–1,000, or omit and use the 0.70 threshold |

---

## Generation plan (how to hit the targets)

1. **Reuse the generator machinery.** New types reuse [`bir_dataset_generator`](../scripts/bir_dataset_generator.py)'s
   helpers — text-fit, white-keyed asset compositing, Augraphy scan degrade, atomic dedup manifest. **Do
   not reimplement.** Each new type needs: a blank template under `data/template/`, the issuer seal/logo
   under `data/seal/` or `logo/`, calibrated field boxes (`annotate_boxes.py`), and field values that
   satisfy the OCR regexes — **including a printed expiry date** for every `requires_expiry = yes` type.
2. **Generate clean + scan per base doc** (≈50/50) so the model sees both digital and photocopy-quality inputs.
3. **Populate a real validation split** (200/class) — current val folders hold only smoke-test placeholders.
4. **`fake`:** synthesize from the real classes (field edits, pasted stamps/signatures, recompression,
   wrong-template mixes) spanning many types — not a single forgery style.
5. **QA 10 samples per new type first**, then batch; verify the dedup manifest (`next_index`, unique hashes).

---

## Reconciliation needed (Phase 1 lock-in)

The classifier **folder names** and the DB **`DocumentTypeSeeder` codes** drift on three existing types
(flagged ⚠️ above): `bir_certificate`↔`bir_permit`, `financial_statement`↔`financial_stmt`, `sec_gis`↔`gis`.
Before training, pick **one** canonical code per type and make folder == seeder code, set every new type's
`issuer_scope` + `requires_expiry`, and remove the phantom `business_registration` reference in
[`../scripts/train_classifier.py`](../scripts/train_classifier.py). This is a coordinated change with the
Laravel side (pipeline Phase P1) — **do not rename existing folders unilaterally** (it moves local data).

---

## Storage & git

- Folders are scaffolded with a tracked `.gitkeep` each (train + val), per `.gitignore` lines 40–45
  (directory scaffold + `.gitkeep` are the committable exception).
- **Images and `_synthetic_manifest.json` are gitignored** — local artifacts only; **never `git add`** them.
- The classifier reads the class label from the **immediate subfolder name** of `classifier_data/`
  (`image_dataset_from_directory`), so every class is a **flat top-level folder** — IDs are individual
  classes (`national_id`, `philhealth_id`, …), not nested under a `government_id/` parent.
