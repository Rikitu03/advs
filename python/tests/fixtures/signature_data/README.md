# Signature DoD fixtures — Stage 4a (M4)

Real signature crops that gate the trained Siamese verifier in
[`../../test_signature_verify.py`](../../test_signature_verify.py). Distinct from the random-noise
**smoke** fixtures under `python/data/training/signature_data/` (which are gitignored and only exercise
`--smoke`/`--dry-run`). These are **committed** — small, deterministic, and travel with the repo so the
DoD gate is reproducible in CI, the same way `python/tests/fixtures/bir1_words.json` is committed.

## Layout

```
signature_data/
  <signer>/            genuine signatures for one writer (>= 2 .png)
    0.png
    1.png
    ...
    forged/            OPTIONAL skilled forgeries of the SAME writer
      0.png
      ...
```

The test picks the **first** signer directory with >= 2 genuine `.png`, uses `genuine[0]`/`genuine[1]`
for the genuine pair, and `forged/0.png` for the forged pair. If a signer has no `forged/`, the test
falls back to a synthetic elastic-warp forgery of `genuine[0]`. Include a real `forged/` set — a real
skilled forgery is a stronger, more honest test than the synthetic fallback.

## Provenance

- **CEDAR** signature dataset (Kaggle: `shreelakshmigp/cedardataset`), `full_org/` (genuine) +
  `full_forg/` (forged), grouped by writer id (first number in the filename).
- **These fixtures are held-out (signer-disjoint) writers** — the encoder never trained on them, so the
  gate measures generalisation, not memorisation:
  - `signer_08` = CEDAR writer 8 · `signer_18` = CEDAR writer 18 (4 genuine + 3 forged each).
  - Both are in the notebook-03 seed-42 validation split (held out = `8, 18, 19, 25, 28, 29, 40, 42, 46,
    51, 55`) — see [`../../../M4_training_run_record.md`](../../../M4_training_run_record.md) and
    notebook `03_siamese_signature.ipynb` Step 5.
  - Observed distances (EER threshold `1.243976`): signer_08 genuine 0.898 / forged 1.683 ·
    signer_18 genuine 0.777 / forged 1.772 — bracketing the run's genuine 0.807 / forged 1.697 means.
- Keep it minimal: ~2 signers, a few genuine + a few forged each, is enough for the four DoD checks.
  To refresh, re-fetch any held-out writer via the Kaggle API (`dataset_download_file`) and rename to the
  `<signer>/N.png` + `<signer>/forged/N.png` layout.

## What the gate asserts (recalibrated — NOT the literal 0.85)

Against the empirical EER threshold in `python/models/signature_threshold.txt` (**1.243976**):

1. embedding length is exactly **128**
2. genuine pair distance **<= threshold** → match
3. forged pair distance **> threshold** → no match
4. mean genuine distance below mean forged distance by a clear margin (>= 0.30)

Override this location in CI with the `ADVS_SIG_DATA` environment variable.
