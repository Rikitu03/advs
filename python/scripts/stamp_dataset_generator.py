"""ADVS - genuine/forged stamp-crop dataset generator (Phase M5 data).

Builds the EfficientNet stamp verifier's training data from the repo's real
issuer artwork (BIR officer stamp, BIR seal, DTI logo, LGU seals). Each asset
yields N GENUINE variants (benign scan variance: small rotation, brightness/
contrast jitter, mild blur, sensor noise, JPEG round-trip) and N FORGED
variants (a benign variant plus one forgery perturbation: photocopy, hue
shift, elastic warp, downscale-upscale reproduction, partial erase), written
into the layout train_stamp.py reads:

    <out-root>/training/stamp_data/{genuine,forged}/*.png
    <out-root>/validation/stamp_data/{genuine,forged}/*.png

Run:
    python scripts/stamp_dataset_generator.py                  # defaults below
    python scripts/stamp_dataset_generator.py --per-asset 60 --seed 7
"""

from __future__ import annotations

import argparse
import shutil
import sys
from pathlib import Path

import cv2
import numpy as np

PY_ROOT = Path(__file__).resolve().parents[1]

ASSET_DIRS = ("stamps", "seal", "logo", "template/reference")
FORGERY_KINDS = ("photocopy", "hue_shift", "elastic_warp", "rescale", "erase")
CANVAS = 256  # output crops are CANVAS x CANVAS; training resizes to 224 anyway


def log(msg: str) -> None:
    print(f"[stamp-data] {msg}", flush=True)


def discover_assets(data_root: Path) -> dict[str, Path]:
    """asset name (lowercased stem) -> source PNG under the repo art folders."""
    out: dict[str, Path] = {}
    for rel in ASSET_DIRS:
        folder = data_root / rel
        if not folder.is_dir():
            continue
        for p in sorted(folder.glob("*.png")):
            out[p.stem.lower()] = p
    return out


def load_asset(path: Path) -> np.ndarray:
    """RGB uint8, alpha composited onto white, fitted onto a square canvas."""
    raw = cv2.imread(str(path), cv2.IMREAD_UNCHANGED)
    if raw is None:
        raise ValueError(f"Unreadable asset: {path}")
    if raw.ndim == 2:
        rgb = cv2.cvtColor(raw, cv2.COLOR_GRAY2RGB)
    elif raw.shape[2] == 4:
        bgr = raw[:, :, :3].astype("float32")
        alpha = (raw[:, :, 3:4].astype("float32")) / 255.0
        bgr = bgr * alpha + 255.0 * (1.0 - alpha)
        rgb = cv2.cvtColor(bgr.astype("uint8"), cv2.COLOR_BGR2RGB)
    else:
        rgb = cv2.cvtColor(raw, cv2.COLOR_BGR2RGB)

    h, w = rgb.shape[:2]
    scale = (CANVAS - 16) / max(h, w)  # small margin so rotations don't clip
    resized = cv2.resize(rgb, (max(1, int(w * scale)), max(1, int(h * scale))))
    canvas = np.full((CANVAS, CANVAS, 3), 255, dtype="uint8")
    y0 = (CANVAS - resized.shape[0]) // 2
    x0 = (CANVAS - resized.shape[1]) // 2
    canvas[y0:y0 + resized.shape[0], x0:x0 + resized.shape[1]] = resized
    return canvas


def genuine_variant(img: np.ndarray, rng: np.random.Generator) -> np.ndarray:
    """Benign scan variance — the same artwork as it appears across real scans."""
    h, w = img.shape[:2]
    angle = float(rng.uniform(-4, 4))
    scale = float(rng.uniform(0.94, 1.02))
    rot = cv2.getRotationMatrix2D((w / 2, h / 2), angle, scale)
    rot[:, 2] += rng.uniform(-4, 4, size=2)
    out = cv2.warpAffine(img, rot, (w, h), borderValue=(255, 255, 255))

    out = out.astype("float32")
    out = out * float(rng.uniform(0.92, 1.08)) + float(rng.uniform(-12, 12))
    out = np.clip(out, 0, 255).astype("uint8")

    if rng.random() < 0.6:
        out = cv2.GaussianBlur(out, (3, 3), sigmaX=float(rng.uniform(0.3, 0.9)))
    noise = rng.normal(0, rng.uniform(2, 6), out.shape).astype("float32")
    out = np.clip(out.astype("float32") + noise, 0, 255).astype("uint8")

    quality = int(rng.integers(70, 96))
    ok, buf = cv2.imencode(".jpg", out, [int(cv2.IMWRITE_JPEG_QUALITY), quality])
    return cv2.imdecode(buf, cv2.IMREAD_COLOR) if ok else out


def forge(img: np.ndarray, rng: np.random.Generator, kind: str) -> np.ndarray:
    """One forgery-style perturbation on top of a (benign) variant."""
    h, w = img.shape[:2]
    if kind == "photocopy":  # toner reproduction: no ink colour, crushed tones
        gray = cv2.cvtColor(img, cv2.COLOR_RGB2GRAY).astype("float32")
        gray = np.clip((gray - 96) * float(rng.uniform(1.6, 2.2)) + 128, 0, 255)
        gray += rng.normal(0, 10, gray.shape).astype("float32")
        return cv2.cvtColor(np.clip(gray, 0, 255).astype("uint8"), cv2.COLOR_GRAY2RGB)
    if kind == "hue_shift":  # wrong ink colour
        hsv = cv2.cvtColor(img, cv2.COLOR_RGB2HSV)
        hsv[:, :, 0] = (hsv[:, :, 0].astype(int) + int(rng.integers(40, 140))) % 180
        return cv2.cvtColor(hsv, cv2.COLOR_HSV2RGB)
    if kind == "elastic_warp":  # hand-redrawn geometry
        dx = cv2.GaussianBlur(rng.uniform(-1, 1, (h, w)).astype("float32"), (0, 0), sigmaX=6) * 10
        dy = cv2.GaussianBlur(rng.uniform(-1, 1, (h, w)).astype("float32"), (0, 0), sigmaX=6) * 10
        xx, yy = np.meshgrid(np.arange(w), np.arange(h))
        return cv2.remap(img, (xx + dx).astype("float32"), (yy + dy).astype("float32"),
                         interpolation=cv2.INTER_LINEAR, borderValue=(255, 255, 255),
                         borderMode=cv2.BORDER_CONSTANT)
    if kind == "rescale":  # low-quality reproduction of a reproduction
        f = float(rng.uniform(0.18, 0.35))
        small = cv2.resize(img, (max(8, int(w * f)), max(8, int(h * f))))
        return cv2.resize(small, (w, h), interpolation=cv2.INTER_LINEAR)
    if kind == "erase":  # tampered/incomplete artwork
        out = img.copy()
        for _ in range(int(rng.integers(1, 3))):
            bw, bh = int(rng.integers(w // 6, w // 3)), int(rng.integers(h // 6, h // 3))
            x0, y0 = int(rng.integers(0, w - bw)), int(rng.integers(0, h - bh))
            out[y0:y0 + bh, x0:x0 + bw] = 255
        return out
    raise ValueError(f"Unknown forgery kind: {kind}")


def split_counts(n: int, val_fraction: float) -> tuple[int, int]:
    n_val = max(1, round(n * val_fraction)) if n > 1 else 0
    return n - n_val, n_val


def generate(data_root: Path, out_root: Path, per_asset: int,
             val_fraction: float, seed: int) -> dict[str, int]:
    assets = discover_assets(data_root)
    if not assets:
        raise ValueError(f"No PNG assets found under {data_root} ({', '.join(ASSET_DIRS)})")
    log(f"assets: {', '.join(sorted(assets))}")

    for split in ("training", "validation"):
        target = out_root / split / "stamp_data"
        if target.exists():  # regenerable output — replace fixtures / older runs
            shutil.rmtree(target)

    dirs = {(split, kind): out_root / split / "stamp_data" / kind
            for split in ("training", "validation") for kind in ("genuine", "forged")}
    for d in dirs.values():
        d.mkdir(parents=True, exist_ok=True)

    rng = np.random.default_rng(seed)
    n_train, n_val = split_counts(per_asset, val_fraction)
    counts = {"train_genuine": 0, "train_forged": 0, "val_genuine": 0, "val_forged": 0}

    for name, path in sorted(assets.items()):
        base = load_asset(path)
        for i in range(per_asset):
            split = "training" if i < n_train else "validation"
            prefix = "train" if split == "training" else "val"

            variant = genuine_variant(base, rng)
            cv2.imwrite(str(dirs[(split, "genuine")] / f"{name}_{i:04d}.png"),
                        cv2.cvtColor(variant, cv2.COLOR_RGB2BGR))
            counts[f"{prefix}_genuine"] += 1

            kind = FORGERY_KINDS[int(rng.integers(len(FORGERY_KINDS)))]
            forged = forge(genuine_variant(base, rng), rng, kind)
            cv2.imwrite(str(dirs[(split, "forged")] / f"{name}_{i:04d}_{kind}.png"),
                        cv2.cvtColor(forged, cv2.COLOR_RGB2BGR))
            counts[f"{prefix}_forged"] += 1

    return counts


def parse_args(argv: list[str]) -> argparse.Namespace:
    ap = argparse.ArgumentParser(description="Generate the ADVS genuine/forged stamp dataset.")
    ap.add_argument("--data-root", default=str(PY_ROOT / "data"),
                    help="Repo data folder holding stamps/, seal/, logo/, template/reference/.")
    ap.add_argument("--out-root", default=str(PY_ROOT / "data"),
                    help="Where training/ and validation/ stamp_data are written.")
    ap.add_argument("--per-asset", type=int, default=40,
                    help="Genuine (and forged) variants generated per source asset.")
    ap.add_argument("--val-fraction", type=float, default=0.2)
    ap.add_argument("--seed", type=int, default=42)
    return ap.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv if argv is not None else sys.argv[1:])
    counts = generate(Path(args.data_root), Path(args.out_root),
                      args.per_asset, args.val_fraction, args.seed)
    log(f"done: {counts}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
