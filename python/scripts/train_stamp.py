"""ADVS - EfficientNet-B0 stamp verifier (model 4 of 4).

Extracts 1280-D EfficientNet-B0 features from stamp crops and trains a logistic
regression to separate genuine from forged. Faithful to training_script.md §4.
Also calibrates the Stage 4b cosine-similarity threshold (each crop's vector vs
its asset's mean genuine vector — the stand-in for the issuer's enrolled
``logo_references`` vector) and writes it to ``stamp_threshold.txt``, which the
ADVS API reads in preference to the §9 default 0.85.

Data layout (read-only):
    <data-root>/training/stamp_data/genuine/*.png
    <data-root>/training/stamp_data/forged/*.png
    <data-root>/validation/stamp_data/genuine/*.png
    <data-root>/validation/stamp_data/forged/*.png

Outputs (under <models-out>):
    efficientnet_feature_extractor.h5, stamp_classifier.pkl, stamp_threshold.txt

Run:
    python scripts/train_stamp.py                 # full training (needs ML stack + data)
    python scripts/train_stamp.py --dry-run       # validate layout only (stdlib only)
    python scripts/train_stamp.py --smoke         # tiny CPU run (needs ML stack)

Heavy imports (tensorflow, sklearn, cv2) are lazy so --dry-run works with stdlib.
"""

from __future__ import annotations

import argparse
import os
import re
import sys
import time
from pathlib import Path

PY_ROOT = Path(__file__).resolve().parents[1]

CONFIG: dict = {
    "image_size": 224,
    "test_split": 0.20,   # used only if a validation set is absent
    "seed": 42,
}
SMOKE_OVERRIDES = {
    "image_size": 64,
}
IMG_EXTS = {".jpg", ".jpeg", ".png", ".bmp"}


def log(msg: str) -> None:
    print(f"[stamp] {msg}", flush=True)


def section(title: str) -> None:
    print("\n" + "=" * 70 + f"\n  {title}\n" + "=" * 70, flush=True)


class TrainError(RuntimeError):
    """Expected, user-actionable failure (e.g. missing data)."""


def require_dir(path: Path, what: str) -> None:
    if not path.is_dir():
        raise TrainError(f"Missing {what}: expected directory '{path}'.")


def count_images(path: Path) -> int:
    if not path.is_dir():
        return 0
    return sum(1 for p in path.iterdir() if p.suffix.lower() in IMG_EXTS)


def validate_structure(train_dir: Path, val_dir: Path) -> dict:
    require_dir(train_dir / "genuine", "genuine training stamps")
    require_dir(train_dir / "forged", "forged training stamps")
    n_gen = count_images(train_dir / "genuine")
    n_forg = count_images(train_dir / "forged")
    if n_gen == 0 or n_forg == 0:
        raise TrainError(f"Need non-empty genuine ({n_gen}) and forged ({n_forg}) training stamps.")
    return {
        "train_genuine": n_gen, "train_forged": n_forg,
        "val_genuine": count_images(val_dir / "genuine"),
        "val_forged": count_images(val_dir / "forged"),
    }


def asset_group(stem: str) -> str:
    """Asset key from a generated filename (``bir_seal_0007`` -> ``bir_seal``);
    datasets without that naming all fall into one shared group."""
    m = re.match(r"(.+?)_\d{2,}", stem)
    return m.group(1) if m else "all"


def calibrate_stamp_threshold(train_rows: list, eval_rows: list):
    """Cosine-similarity threshold where FAR ~= FRR (genuine scores HIGH).

    Each crop's vector is compared to its asset's mean GENUINE training vector
    — the stand-in for the issuer's enrolled reference in ``logo_references``.
    Returns None when the eval rows can't support a threshold (one class only).
    """
    import numpy as np

    refs = {}
    for grp in {r[2] for r in train_rows}:
        feats = [r[0] for r in train_rows if r[2] == grp and r[1] == 1]
        if feats:
            vec = np.mean(feats, axis=0)
            refs[grp] = vec / (np.linalg.norm(vec) or 1.0)

    sims, labels = [], []
    for feat, label, grp in eval_rows:
        ref = refs.get(grp)
        if ref is None:
            continue
        unit = feat / (np.linalg.norm(feat) or 1.0)
        sims.append(float(np.dot(unit, ref)))
        labels.append(label)
    sims = np.asarray(sims)
    labels = np.asarray(labels)
    if len(sims) == 0 or labels.min() == labels.max():
        return None

    lo, hi = float(sims.min()), float(sims.max())
    if hi <= lo:
        return float(lo)
    best_t, best_gap = lo, 1e9
    for t in np.linspace(lo, hi, 200):
        pred_genuine = sims >= t
        far = float(np.mean(pred_genuine[labels == 0]))
        frr = float(np.mean(~pred_genuine[labels == 1]))
        gap = abs(far - frr)
        if gap < best_gap:
            best_gap, best_t = gap, float(t)
    return best_t


def train(cfg: dict, train_dir: Path, val_dir: Path, models_out: Path) -> None:
    section("EfficientNet-B0 stamp verification")
    import cv2
    import numpy as np
    import pandas as pd
    from tensorflow.keras.applications import EfficientNetB0
    from tensorflow.keras.applications.efficientnet import preprocess_input

    size = cfg["image_size"]
    extractor = EfficientNetB0(weights="imagenet", include_top=False, pooling="avg",
                               input_shape=(size, size, 3))

    def features_for(folder: Path, label: int):
        rows = []
        if not folder.is_dir():
            return rows
        for ip in sorted(folder.iterdir()):
            if ip.suffix.lower() not in IMG_EXTS:
                continue
            arr = cv2.imread(str(ip))
            if arr is None:
                continue
            arr = cv2.resize(cv2.cvtColor(arr, cv2.COLOR_BGR2RGB), (size, size)).astype("float32")
            feat = extractor.predict(preprocess_input(np.expand_dims(arr, 0)), verbose=0)[0]
            rows.append((feat, label, asset_group(ip.stem)))
        return rows

    log("Extracting features (training set)")
    train_rows = features_for(train_dir / "genuine", 1) + features_for(train_dir / "forged", 0)
    val_rows = features_for(val_dir / "genuine", 1) + features_for(val_dir / "forged", 0)
    if not train_rows:
        raise TrainError("No training stamp images could be read.")

    from sklearn.linear_model import LogisticRegression
    from sklearn.metrics import accuracy_score
    from sklearn.model_selection import train_test_split

    x_tr = np.vstack([r[0] for r in train_rows])
    y_tr = np.asarray([r[1] for r in train_rows])

    if val_rows:
        x_te = np.vstack([r[0] for r in val_rows])
        y_te = np.asarray([r[1] for r in val_rows])
    else:  # no validation set -> split the training features
        log("No validation set found - splitting training features 80/20")
        strat = y_tr if np.bincount(y_tr).min() >= 2 else None
        x_tr, x_te, y_tr, y_te = train_test_split(
            x_tr, y_tr, test_size=cfg["test_split"], random_state=cfg["seed"], stratify=strat
        )

    df = pd.DataFrame(x_tr)
    df["label"] = y_tr
    log(f"feature matrix {x_tr.shape}, positives={int(y_tr.sum())}")

    clf = LogisticRegression(max_iter=1000)
    clf.fit(x_tr, y_tr)
    acc = accuracy_score(y_te, clf.predict(x_te)) if len(y_te) else float("nan")
    log(f"stamp classifier accuracy = {acc:.4f}")

    # Trusted artefact: this pickle is produced and consumed only by ADVS's own
    # code (training_script.md requires stamp_classifier.pkl). Never unpickle a
    # stamp_classifier.pkl received from an untrusted source.
    import pickle

    with open(models_out / "stamp_classifier.pkl", "wb") as fh:
        pickle.dump(clf, fh)
    extractor.save(str(models_out / "efficientnet_feature_extractor.h5"))
    log("Saved efficientnet_feature_extractor.h5 + stamp_classifier.pkl")

    section("Cosine-threshold calibration (Stage 4b issuer comparison)")
    if not val_rows:
        log("No validation set - calibrating on training features")
    threshold = calibrate_stamp_threshold(train_rows, val_rows or train_rows)
    if threshold is None:
        log("Calibration skipped: need both genuine and forged eval samples.")
    else:
        (models_out / "stamp_threshold.txt").write_text(f"{threshold:.6f}\n")
        log(f"Saved stamp_threshold.txt ({threshold:.4f})")

    section("Inference sanity check")
    log(f"classifier -> classes {list(clf.classes_)}")


def parse_args(argv: list[str]) -> argparse.Namespace:
    ap = argparse.ArgumentParser(description="Train the ADVS EfficientNet stamp verifier.")
    ap.add_argument("--data-root", default=str(PY_ROOT / "data"))
    ap.add_argument("--models-out", default=str(PY_ROOT / "models"))
    ap.add_argument("--dry-run", action="store_true",
                    help="Validate layout/config only; no heavy imports, no training.")
    ap.add_argument("--smoke", action="store_true",
                    help="Tiny CPU run (needs ML stack + fixtures).")
    return ap.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv if argv is not None else sys.argv[1:])
    cfg = dict(CONFIG)
    if args.smoke:
        cfg.update(SMOKE_OVERRIDES)
        os.environ.setdefault("CUDA_VISIBLE_DEVICES", "-1")
        log("SMOKE MODE: reduced sizes, CPU only.")

    data_root = Path(args.data_root)
    train_dir = data_root / "training" / "stamp_data"
    val_dir = data_root / "validation" / "stamp_data"
    models_out = Path(args.models_out)
    log(f"data-root={data_root}  models-out={models_out}")

    try:
        summary = validate_structure(train_dir, val_dir)
    except TrainError as exc:
        log(f"STRUCTURE ERROR: {exc}")
        return 2
    log(f"ok: {summary}")

    if args.dry_run:
        section("DRY RUN - structure valid, skipping training")
        return 0

    models_out.mkdir(parents=True, exist_ok=True)
    started = time.time()
    train(cfg, train_dir, val_dir, models_out)
    section(f"Done in {time.time() - started:.1f}s - artefacts in {models_out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
