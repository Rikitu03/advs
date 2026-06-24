---
name: run-advs-training
description: Build, scaffold, train, smoke-test, and dry-run the ADVS model-training pipeline (ResNet-50 classifier, YOLOv8 detector, Siamese signature verifier, EfficientNet stamp verifier). Use when asked to train an ADVS model, run/validate the training scripts, generate training fixtures, set up the python/data layout, or drive python/scripts/train_*.py.
---

ADVS trains four models (`training_script.md`): a ResNet-50 document classifier,
a YOLOv8 signature/stamp detector, a Siamese signature verifier, and an
EfficientNet stamp verifier. Each model is **one self-contained script** in
`python/scripts/` plus a matching self-contained notebook in `python/notebooks/`.
Drive them with **`python/.claude/skills/run-advs-training/driver.py`**, which
runs every script in `--dry-run` (validate the data layout, no heavy imports, no
training) or `--smoke` (tiny 1-epoch CPU run) mode and reports PASS/FAIL.

All paths below are relative to the **repo root** (`advs/`). The venv interpreter
is `python/env/Scripts/python.exe` — this machine's venv is a **python.org CPython
3.12** build (Windows `Scripts/` layout). On Linux/macOS it would be
`python/env/bin/python`. (Earlier this venv was an MSYS2/UCRT64 build with a
Unix-style `bin/` layout; it was rebuilt on python.org 3.12 so the ML stack installs.)

## Prerequisites

- Python venv at `python/env/` (already created with `python -m venv python/env`).
- **For `--dry-run` and fixtures: nothing else.** Both run on the bare venv with
  only the standard library — the scripts import `tensorflow`/`ultralytics`/`cv2`
  lazily, and `make_fixtures.py` writes PNGs by hand with `zlib`.
- **For `--smoke` / real training:** the full ML stack:

```bash
python/env/Scripts/python.exe -m pip install -r python/requirements.txt
```

> This install works on the current python.org 3.12 venv. `requirements.txt` pins
> `tensorflow==2.16.*`, which needs a wheel-capable CPython 3.10–3.12, and
> `onnx>=1.16,<1.17` (1.17+ pulls an `ml_dtypes.float4` that conflicts with TF 2.16).
> If Windows Defender locks a freshly-extracted file mid-install (`WinError 32`),
> just re-run `pip install` — it resumes from the cached wheels.

## Build / scaffold the data

The four models read from `python/data/{training,validation}/<model>_data/`
(layout documented in `python/data/README.md`). To populate it with a tiny
synthetic dataset for dry-run/smoke (pure stdlib, no deps):

```bash
python/env/Scripts/python.exe python/.claude/skills/run-advs-training/make_fixtures.py
```

→ `[make-fixtures] wrote synthetic train+val dataset under .../python/data`

## Run (agent path)

Drive all four scripts. Default is `--dry-run` (safe anywhere, no training):

```bash
python/env/Scripts/python.exe python/.claude/skills/run-advs-training/driver.py
```

Verified output ends with:

```
======================================================================
  SUMMARY (--dry-run)
    classifier  PASS
    detector    PASS
    signature   PASS
    stamp       PASS
  4/4 passed
======================================================================
```

Driver flags:

| flag | what it does |
|---|---|
| (none) | `--dry-run` each script: validate `python/data` layout, no heavy imports, no training |
| `--smoke` | tiny 1-epoch CPU run per script (needs the ML stack + fixtures) |
| `--only classifier,stamp` | run a subset (names: `classifier`, `detector`, `signature`, `stamp`) |

### Run one model directly

Each script is standalone and takes `--data-root` / `--models-out` / `--dry-run`
/ `--smoke`:

```bash
python/env/Scripts/python.exe python/scripts/train_classifier.py --dry-run   # exit 0
```

| model | script | key outputs (→ `python/models/`) |
|---|---|---|
| ResNet-50 classifier | `scripts/train_classifier.py` | `resnet50_classifier.h5`, `class_names.json` |
| YOLOv8 detector | `scripts/train_detector.py` | `yolov8_stamp_signature.onnx` |
| Siamese signature | `scripts/train_signature.py` | `siamese_signature.h5`, `siamese_encoder.h5`, `signature_threshold.txt` |
| EfficientNet stamp | `scripts/train_stamp.py` | `efficientnet_feature_extractor.h5`, `stamp_classifier.pkl` |

### Full training

Drop `--dry-run`/`--smoke` and point `--data-root` at real data:

```bash
python/env/Scripts/python.exe python/scripts/train_classifier.py --data-root python/data
```

Needs the ML stack installed (it is — see Prerequisites) and ideally a GPU
(YOLO/Siamese fine-tuning are slow on the CPU-only `torch` wheel here).

## Run (human path — notebooks)

Each `python/notebooks/NN_*.ipynb` is self-contained: open in Jupyter/Colab and
run top-to-bottom. Cell 1 (commented) pip-installs the stack; the big code cell
defines the training functions; the last cells dry-run then train. Edit
`DATA_ROOT` in the config cell to point at your data.

## Test

```bash
python/env/Scripts/python.exe -m py_compile python/scripts/*.py \
  python/.claude/skills/run-advs-training/driver.py \
  python/.claude/skills/run-advs-training/make_fixtures.py   # → COMPILE OK
python/env/Scripts/python.exe python/.claude/skills/run-advs-training/driver.py  # → 4/4 passed
```

## Gotchas

- **The venv is python.org CPython 3.12 → `python/env/Scripts/python.exe`, not
  `bin/`.** Built with `py -3.12 -m venv python/env` (Python 3.12.10 lives at
  `%LOCALAPPDATA%\Programs\Python\Python312`). Don't invoke the bare `python` on
  PATH — `which python` resolves to MSYS2 (`C:\msys64\ucrt64\bin\python`), a
  different interpreter with no ML stack. Always use `python/env/Scripts/python.exe`.
- **TensorFlow 2.16 has no wheels for CPython 3.13/3.14.** The box also has 3.13
  (Store) and 3.14 installed — both too new for TF, so the venv must stay on
  3.10–3.12. Don't rebuild it on whatever `py` defaults to (3.14).
- **`onnx` must stay `<1.17`.** onnx 1.17+ imports `ml_dtypes.float4_e2m1fn`, but
  TF 2.16 pins `ml-dtypes~=0.3.1` (no `float4`) → `AttributeError` on `import onnx`.
  `requirements.txt` pins `onnx>=1.16,<1.17` (resolves to 1.16.2).
- **`--dry-run` and `make_fixtures.py` sidestep all of that** — they're stdlib-only
  by design, which is why the harness is verifiable without the ML stack.
- **`make_fixtures.py` writes valid PNGs with `zlib`+`struct`, not Pillow** — so it
  runs even on a bare/wheel-less venv. The images are random noise; they exercise
  the pipeline plumbing, not model quality.
- **This skill is git-tracked because it lives under `python/.claude/`.** The repo
  root `.gitignore` has `/.claude` (root-only), so a skill at the repo-root
  `.claude/skills/` would be ignored — `python/.claude/skills/` is not.
- **Non-ASCII in `print()` mojibakes on the Windows console** (cp1252). The scripts
  use ASCII (`-`, `->`) in runtime output for this reason.

## Troubleshooting

- **`STRUCTURE ERROR: Missing ... expected directory 'python/data/...'`** — the data
  scaffold isn't populated. Run `make_fixtures.py` (above), or point `--data-root`
  at a real dataset laid out per `python/data/README.md`.
- **`ModuleNotFoundError: No module named 'tensorflow'` (or `ultralytics`/`cv2`)** on
  `--smoke`/full run — you're invoking the wrong interpreter (likely bare `python`
  = MSYS, not the venv). Use `python/env/Scripts/python.exe`; if the stack really
  is missing, `pip install -r requirements.txt` into it.
- **`AttributeError: module 'ml_dtypes' has no attribute 'float4_e2m1fn'`** on
  `import onnx` — onnx drifted to ≥1.17. Reinstall the pin: `pip install
  "onnx>=1.16,<1.17"`.
- **`OSError: [WinError 32] ... used by another process`** during `pip install` —
  Windows Defender is scanning a freshly-extracted file. Re-run `pip install`; it
  resumes from cached wheels and usually finishes within a couple of retries.
