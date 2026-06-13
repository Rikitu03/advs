"""ADVS - Siamese CNN signature verifier (model 3 of 4).

Verifies whether two signatures belong to the same vendor. ResNet-50 backbone ->
128-D L2-normalised embedding (twin) -> L1 distance -> sigmoid match probability.
Synthetic forgeries are fabricated from genuine samples (elastic + rotation +
noise) because the dataset only ships genuine signatures. Faithful to
training_script.md §3.

Data layout (read-only):
    <data-root>/training/signature_data/<vendor>/*.png    (genuine signatures)
    <data-root>/validation/signature_data/<vendor>/*.png

Outputs (under <models-out>):
    siamese_signature.h5, siamese_encoder.h5, signature_threshold.txt (EER)

Run:
    python scripts/train_signature.py                 # full training (needs ML stack + data)
    python scripts/train_signature.py --dry-run       # validate layout only (stdlib only)
    python scripts/train_signature.py --smoke         # 1-epoch tiny CPU run (needs ML stack)

Heavy imports (tensorflow, cv2) are lazy so --dry-run works with only stdlib.
"""

from __future__ import annotations

import argparse
import os
import sys
import time
from pathlib import Path

PY_ROOT = Path(__file__).resolve().parents[1]

CONFIG: dict = {
    "image_size": 224,
    "embedding_dim": 128,
    "batch_size": 16,
    "epochs": 20,
    "lr": 1e-4,
    "pairs_per_vendor": 20,    # genuine+forged pairs generated per vendor
    "seed": 42,
}
SMOKE_OVERRIDES = {
    "image_size": 64,
    "batch_size": 2,
    "epochs": 1,
    "pairs_per_vendor": 4,
}
IMG_EXTS = {".jpg", ".jpeg", ".png", ".bmp"}


def log(msg: str) -> None:
    print(f"[signature] {msg}", flush=True)


def section(title: str) -> None:
    print("\n" + "=" * 70 + f"\n  {title}\n" + "=" * 70, flush=True)


class TrainError(RuntimeError):
    """Expected, user-actionable failure (e.g. missing data)."""


def require_dir(path: Path, what: str) -> None:
    if not path.is_dir():
        raise TrainError(f"Missing {what}: expected directory '{path}'.")


def vendor_dirs(path: Path) -> list[str]:
    return sorted(d.name for d in path.iterdir() if d.is_dir())


def validate_structure(train_dir: Path, val_dir: Path) -> dict:
    require_dir(train_dir, "signature training vendors")
    require_dir(val_dir, "signature validation vendors")
    tv = vendor_dirs(train_dir)
    vv = vendor_dirs(val_dir)
    if not tv:
        raise TrainError(f"No vendor subfolders under {train_dir}.")
    return {"train_vendors": len(tv), "val_vendors": len(vv)}


def make_synthetic_forgery(img, seed: int):
    """Elastic deformation (cv2.remap) + rotation + gaussian noise -> forgery."""
    import cv2
    import numpy as np

    rng = np.random.default_rng(seed)
    h, w = img.shape[:2]
    dx = cv2.GaussianBlur(rng.uniform(-1, 1, (h, w)).astype("float32"), (0, 0), sigmaX=4) * 6
    dy = cv2.GaussianBlur(rng.uniform(-1, 1, (h, w)).astype("float32"), (0, 0), sigmaX=4) * 6
    xx, yy = np.meshgrid(np.arange(w), np.arange(h))
    warped = cv2.remap(img, (xx + dx).astype("float32"), (yy + dy).astype("float32"),
                       interpolation=cv2.INTER_LINEAR, borderMode=cv2.BORDER_REFLECT)
    angle = float(rng.uniform(-12, 12))
    rot = cv2.getRotationMatrix2D((w / 2, h / 2), angle, 1.0)
    warped = cv2.warpAffine(warped, rot, (w, h), borderMode=cv2.BORDER_REFLECT)
    noise = rng.normal(0, 12, warped.shape).astype("float32")
    return np.clip(warped.astype("float32") + noise, 0, 255).astype("uint8")


def load_vendors(root: Path, size: int) -> dict:
    import cv2

    out: dict[str, list] = {}
    for vdir in sorted(d for d in root.iterdir() if d.is_dir()):
        imgs = []
        for ip in sorted(vdir.iterdir()):
            if ip.suffix.lower() not in IMG_EXTS:
                continue
            arr = cv2.imread(str(ip))
            if arr is not None:
                imgs.append(cv2.resize(arr, (size, size)))
        if len(imgs) >= 2:
            out[vdir.name] = imgs
    return out


def build_encoder(cfg: dict):
    import tensorflow as tf
    from tensorflow.keras import layers, models
    from tensorflow.keras.applications import ResNet50

    size = cfg["image_size"]
    backbone = ResNet50(weights="imagenet", include_top=False, pooling="avg",
                        input_shape=(size, size, 3))
    inp = layers.Input(shape=(size, size, 3))
    x = layers.Dense(cfg["embedding_dim"], activation=None)(backbone(inp))
    x = layers.Lambda(lambda t: tf.math.l2_normalize(t, axis=1), name="l2norm")(x)
    return models.Model(inp, x, name="siamese_encoder")


def equal_error_rate_threshold(distances, labels) -> float:
    """Distance threshold where FAR ~= FRR. label 1 = genuine (small distance)."""
    import numpy as np

    labels = np.asarray(labels)
    lo, hi = float(distances.min()), float(distances.max())
    if hi <= lo:
        return float(lo)
    best_t, best_gap = lo, 1e9
    for t in np.linspace(lo, hi, 200):
        pred_genuine = distances <= t
        far = float(np.mean(pred_genuine[labels == 0])) if np.any(labels == 0) else 0.0
        frr = float(np.mean(~pred_genuine[labels == 1])) if np.any(labels == 1) else 0.0
        gap = abs(far - frr)
        if gap < best_gap:
            best_gap, best_t = gap, float(t)
    return best_t


def train(cfg: dict, train_dir: Path, val_dir: Path, models_out: Path) -> None:
    section("Siamese CNN signature verification")
    import numpy as np
    import tensorflow as tf
    from tensorflow.keras import layers, models

    size = cfg["image_size"]
    rng = np.random.default_rng(cfg["seed"])

    train_vendors = load_vendors(train_dir, size)
    val_vendors = load_vendors(val_dir, size)
    if len(train_vendors) < 1:
        raise TrainError("Need >= 1 training vendor with >= 2 genuine signatures.")
    log(f"vendors -> {len(train_vendors)} train / {len(val_vendors)} val")

    def make_pairs(vendors: dict, n_per_vendor: int):
        pa, pb, labels = [], [], []
        for imgs in vendors.values():
            for k in range(n_per_vendor):
                if k % 2 == 0:  # genuine pair
                    i, j = rng.choice(len(imgs), size=2, replace=len(imgs) < 2)
                    pa.append(imgs[i]); pb.append(imgs[j]); labels.append(1)
                else:           # forged pair
                    i = int(rng.integers(len(imgs)))
                    forged = make_synthetic_forgery(imgs[i], seed=int(rng.integers(1 << 30)))
                    pa.append(imgs[i]); pb.append(forged); labels.append(0)
        a = np.asarray(pa, dtype="float32") / 255.0
        b = np.asarray(pb, dtype="float32") / 255.0
        return a, b, np.asarray(labels, dtype="float32")

    encoder = build_encoder(cfg)
    in_a = layers.Input(shape=(size, size, 3))
    in_b = layers.Input(shape=(size, size, 3))
    distance = layers.Lambda(lambda t: tf.math.abs(t[0] - t[1]), name="l1_distance")(
        [encoder(in_a), encoder(in_b)]
    )
    out = layers.Dense(1, activation="sigmoid")(distance)
    siamese = models.Model([in_a, in_b], out, name="siamese_signature")
    siamese.compile(optimizer=tf.keras.optimizers.Adam(cfg["lr"]),
                    loss="binary_crossentropy", metrics=["accuracy"])

    train_a, train_b, train_y = make_pairs(train_vendors, cfg["pairs_per_vendor"])
    fit_kwargs = {"epochs": cfg["epochs"], "batch_size": cfg["batch_size"]}
    if val_vendors:
        val_a, val_b, val_y = make_pairs(val_vendors, cfg["pairs_per_vendor"])
        fit_kwargs["validation_data"] = ([val_a, val_b], val_y)
    else:
        val_a = val_b = val_y = None
    log(f"training pairs: {len(train_y)}")

    siamese.fit([train_a, train_b], train_y, **fit_kwargs)

    siamese.save(str(models_out / "siamese_signature.h5"))
    encoder.save(str(models_out / "siamese_encoder.h5"))

    # EER threshold on validation (fall back to training pairs if no val vendors).
    ea_src, eb_src, y_src = (val_a, val_b, val_y) if val_vendors else (train_a, train_b, train_y)
    dists = np.linalg.norm(encoder.predict(ea_src, verbose=0) - encoder.predict(eb_src, verbose=0), axis=1)
    threshold = equal_error_rate_threshold(dists, y_src)
    (models_out / "signature_threshold.txt").write_text(f"{threshold:.6f}\n")
    log(f"Saved siamese_signature.h5, siamese_encoder.h5, signature_threshold.txt (EER={threshold:.4f})")

    section("Inference sanity check")
    emb = encoder.predict(np.random.rand(1, size, size, 3).astype("float32"), verbose=0)
    log(f"encoder -> embedding dim {emb.shape[1]}")


def parse_args(argv: list[str]) -> argparse.Namespace:
    ap = argparse.ArgumentParser(description="Train the ADVS Siamese signature verifier.")
    ap.add_argument("--data-root", default=str(PY_ROOT / "data"))
    ap.add_argument("--models-out", default=str(PY_ROOT / "models"))
    ap.add_argument("--dry-run", action="store_true",
                    help="Validate layout/config only; no heavy imports, no training.")
    ap.add_argument("--smoke", action="store_true",
                    help="Tiny 1-epoch CPU run (needs ML stack + fixtures).")
    return ap.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv if argv is not None else sys.argv[1:])
    cfg = dict(CONFIG)
    if args.smoke:
        cfg.update(SMOKE_OVERRIDES)
        os.environ.setdefault("CUDA_VISIBLE_DEVICES", "-1")
        log("SMOKE MODE: reduced epochs/sizes, CPU only.")

    data_root = Path(args.data_root)
    train_dir = data_root / "training" / "signature_data"
    val_dir = data_root / "validation" / "signature_data"
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
