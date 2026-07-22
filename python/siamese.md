# ADVS — Siamese Signature Validator · Development Context

> **Scope:** the standalone development context for **model 3 of 4** — the Siamese CNN signature
> verifier (Stage 4a). Everything you need to build, train, threshold, serve, and finish this one
> model. This is the **M4** track in the phase plans.
>
> Read alongside:
> - **What Stage 4a does** — [`../ADVS_System_Reference.md`](../ADVS_System_Reference.md) §5 Stage 4a, §8 (storage), §9 (`SIGNATURE_DISTANCE_THRESHOLD`, `RISK_WEIGHT_SIGNATURE`).
> - **The Python lifecycle** — [`DEVELOPMENT_PHASES.md`](DEVELOPMENT_PHASES.md) Phase 6 (Siamese) — this doc expands it.
> - **How we build** — [`../CLAUDE.md`](../CLAUDE.md) §6 (Python I/O contract) · Phase 6.
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

**Pipeline placement — Stage 4a** ([reference §5 Stage 4a](../ADVS_System_Reference.md)):

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

Phase 10 must deliver [`signature_verify.py`](scripts/) matching the [CLAUDE.md §6](../CLAUDE.md)
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
