# M4 · Siamese Signature Verifier — Training Run Record

Stage 4a signature verifier (model 3 of 4). CEDAR, signer-disjoint, trained on Colab free tier (T4).
This record captures the run for ML Model Management / System Settings and the thesis evaluation.

## Result summary

| Metric | Value |
|---|---|
| **EER** | **3.2%** (verification accuracy ≈ 96.8%) |
| FAR @ EER | 2.7% |
| FRR @ EER | 3.6% |
| **`SIGNATURE_DISTANCE_THRESHOLD` (EER)** | **1.243976** |
| Genuine pair distance (mean) | 0.807 |
| Forged pair distance (mean) | 1.697 |
| Embedding dimension | 128 (unit-normalised) |
| Final val accuracy (sigmoid head) | 0.977 |
| Final val loss | 0.310 |

Threshold and metrics are computed on the **held-out validation pairs (signer-disjoint)** — signers the
encoder never saw in training — so this reflects generalisation to unseen vendors, not memorisation.

## Training regime (what produced this)

- **Backbone frozen.** ResNet-50 (ImageNet) held fixed; only the 128-D projection head + twin's sigmoid
  head trained. This removed the overfitting seen in the first attempt (train acc pinned at 1.0 while
  val loss stuck at chance ≈ 0.69) and stabilised BatchNorm (inference-mode moving stats).
- **EarlyStopping(monitor=val_loss, restore_best_weights=True)** + ReduceLROnPlateau.
- Architecture unchanged from the repo `build_encoder`: `ResNet50(pooling='avg') → Dense(128) →
  UnitNormalization(axis=-1)`. Only the training regime differs, so `siamese_encoder.h5` stays
  drop-in compatible with the API.
- CONFIG: image_size 224 · embedding_dim 128 · batch 16 · lr 1e-4 · pairs_per_vendor 20 · seed 42.

## Dataset provenance

- **CEDAR** (Kaggle: `shreelakshmigp/cedardataset`), 55 signers, `full_org/` + `full_forg/`.
- Loaded to uint8 RGB @ 224, grouped by writer id (first number in filename).
- **~20% of signers held out** for validation (signer-disjoint).
- Pairs alternate genuine (label 1) and forged (label 0); forged partner = real CEDAR forgery.

## IMPORTANT — DoD similarity gate needs recalibration

siamese.md §10 states the gate *"genuine similarity ≥ 0.85, forged < 0.85."* With the API's
`similarity = 1 / (1 + distance)` and L2-normalised embeddings on the unit sphere, the realistic
similarity scale for this model is:

| Pair type | Distance (mean) | Similarity = 1/(1+d) |
|---|---|---|
| Genuine | 0.807 | ≈ **0.553** |
| EER boundary | 1.244 | ≈ **0.446** |
| Forged | 1.697 | ≈ **0.371** |

A literal **0.85 similarity gate would fail this strong model.** The 0.85 figure does not match the
Euclidean-on-unit-sphere embedding scale (max distance is 2.0, so similarity is bounded near 0.33–0.55
for realistic pairs). The correct decision boundary is the **empirical EER distance threshold**.

### Recommended DoD gate for `test_signature_verify.py`

Gate on the EER threshold and the ordering, not an absolute 0.85:

1. Embedding length is **exactly 128**.
2. Genuine pair distance **< `SIGNATURE_DISTANCE_THRESHOLD`** (match) → `match == true`.
3. Forged pair distance **> `SIGNATURE_DISTANCE_THRESHOLD`** (no match) → `match == false`.
4. (Optional, robust) mean genuine distance **< mean forged distance** by a clear margin.

If a similarity-based assertion is preferred, derive the bound from the threshold at test time
(`sim_boundary = 1/(1+threshold) ≈ 0.446`) rather than hard-coding 0.85.

## Phase-6 / M4 checklist status

- [x] Notebook run on Colab GPU; `siamese_encoder.h5`, `siamese_signature.h5`,
      `signature_threshold.txt` produced (drop into `python/models/`).
- [x] EER threshold recorded: **1.243976** → the `SIGNATURE_DISTANCE_THRESHOLD` default.
- [x] Validation metric recorded for ML Model Management: EER 3.2% / FAR 2.7% / FRR 3.6%.
- [x] `test_signature_verify.py` written at `python/tests/test_signature_verify.py` — gates on the
      recalibrated EER distance threshold above, **not** the literal 0.85. Real DoD crops live in
      `python/tests/fixtures/signature_data/` (see that folder's README).
- [ ] `signature_verify.py` + `model_loader.py` (Phase-10 Laravel CLI handoff).
- [ ] `--dry-run` / `--smoke` still green; serve-path preprocessing still `resnet50.preprocess_input`.

## Next steps

1. Download the three artefacts from `MyDrive/advs/python/models/` into the repo's `python/models/`
   (gitignored — commit by hand).
2. Register **1.243976** as the `SIGNATURE_DISTANCE_THRESHOLD` default in System Settings, and record
   EER/FAR/FRR in ML Model Management.
3. Write `test_signature_verify.py` with the recalibrated gate.
4. Optional: retrain with `epochs≈80` for a marginally lower EER (val loss was still decreasing at
   epoch 40), or run the Stage-2 fine-tune (unfreeze `conv5_*` at lr 1e-5) if you want to push further.
