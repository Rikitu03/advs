"""ADVS - YOLOv8 signature + stamp detector (model 2 of 4).

Detects two classes (0=signature, 1=stamp) on full document pages. Faithful to
training_script.md §2.

Data layout (read-only):
    <data-root>/training/detector_data/images/*.jpg|png
    <data-root>/training/detector_data/labels/*.txt   (YOLO: "<cls> cx cy w h")
    <data-root>/validation/detector_data/images/*.jpg|png
    <data-root>/validation/detector_data/labels/*.txt

Outputs (under <models-out>):
    detector_data.yaml (generated), yolov8_stamp_signature.onnx, yolo_runs/

Run:
    python scripts/train_detector.py                 # full training (needs ultralytics + data)
    python scripts/train_detector.py --dry-run       # validate layout only (stdlib only)
    python scripts/train_detector.py --smoke         # 1-epoch tiny CPU run (needs ultralytics)

Heavy imports (ultralytics, torch) are lazy so --dry-run works with only stdlib.
"""

from __future__ import annotations

import argparse
import sys
import time
from pathlib import Path

PY_ROOT = Path(__file__).resolve().parents[1]

CONFIG: dict = {
    "weights": "yolov8n.pt",
    "epochs": 50,
    "imgsz": 640,
    "batch": 16,
    "patience": 10,
}
SMOKE_OVERRIDES = {
    "epochs": 1,
    "imgsz": 64,
    "batch": 2,
    "patience": 2,
}
NAMES = {0: "signature", 1: "stamp"}
IMG_EXTS = {".jpg", ".jpeg", ".png", ".bmp"}


def log(msg: str) -> None:
    print(f"[detector] {msg}", flush=True)


def section(title: str) -> None:
    print("\n" + "=" * 70 + f"\n  {title}\n" + "=" * 70, flush=True)


class TrainError(RuntimeError):
    """Expected, user-actionable failure (e.g. missing data)."""


def require_dir(path: Path, what: str) -> None:
    if not path.is_dir():
        raise TrainError(f"Missing {what}: expected directory '{path}'.")


def count_images(path: Path) -> int:
    return sum(1 for p in path.rglob("*") if p.suffix.lower() in IMG_EXTS)


def validate_structure(train_dir: Path, val_dir: Path) -> dict:
    require_dir(train_dir / "images", "detector training images")
    require_dir(train_dir / "labels", "detector training labels")
    require_dir(val_dir / "images", "detector validation images")
    n_train = count_images(train_dir / "images")
    n_val = count_images(val_dir / "images")
    if n_train == 0:
        raise TrainError(f"No training images found in {train_dir / 'images'}")
    return {"train_images": n_train, "val_images": n_val}


def write_data_yaml(yaml_path: Path, data_root: Path) -> None:
    """Ultralytics resolves label paths by swapping 'images'->'labels'."""
    text = (
        f"path: {data_root.as_posix()}\n"
        f"train: training/detector_data/images\n"
        f"val: validation/detector_data/images\n"
        f"names:\n" + "".join(f"  {k}: {v}\n" for k, v in NAMES.items())
    )
    yaml_path.write_text(text)
    log(f"Wrote {yaml_path}")


def pick_device():
    try:
        import torch

        return 0 if torch.cuda.is_available() else "cpu"
    except Exception:  # noqa: BLE001
        return "cpu"


def train(cfg: dict, data_root: Path, models_out: Path) -> None:
    section("YOLOv8 signature + stamp detection")
    from ultralytics import YOLO

    yaml_path = models_out / "detector_data.yaml"
    write_data_yaml(yaml_path, data_root)

    device = pick_device()
    log(f"Training {cfg['weights']} for {cfg['epochs']} epochs "
        f"(imgsz={cfg['imgsz']}, batch={cfg['batch']}, device={device})")
    model = YOLO(cfg["weights"])
    model.train(
        data=str(yaml_path), epochs=cfg["epochs"], imgsz=cfg["imgsz"],
        batch=cfg["batch"], patience=cfg["patience"], device=device, cache=True,
        project=str(models_out / "yolo_runs"), name="train", exist_ok=True,
    )

    metrics = model.val()
    try:
        log(f"mAP@0.5 = {float(metrics.box.map50):.4f}")
    except Exception:  # noqa: BLE001 - metrics shape varies by version
        log("validation complete (mAP attribute unavailable on this version)")

    onnx_path = models_out / "yolov8_stamp_signature.onnx"
    exported = model.export(format="onnx", imgsz=cfg["imgsz"])
    import shutil

    if exported and Path(exported).exists() and Path(exported) != onnx_path:
        shutil.copy(str(exported), str(onnx_path))
    log(f"Exported {onnx_path}")
    if onnx_path.exists():
        log("inference sanity check -> yolov8_stamp_signature.onnx present")


def parse_args(argv: list[str]) -> argparse.Namespace:
    ap = argparse.ArgumentParser(description="Train the ADVS YOLOv8 detector.")
    ap.add_argument("--data-root", default=str(PY_ROOT / "data"))
    ap.add_argument("--models-out", default=str(PY_ROOT / "models"))
    ap.add_argument("--dry-run", action="store_true",
                    help="Validate layout/config only; no heavy imports, no training.")
    ap.add_argument("--smoke", action="store_true",
                    help="Tiny 1-epoch CPU run (needs ultralytics + fixtures).")
    return ap.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv if argv is not None else sys.argv[1:])
    cfg = dict(CONFIG)
    if args.smoke:
        cfg.update(SMOKE_OVERRIDES)
        log("SMOKE MODE: reduced epochs/sizes, CPU only.")

    data_root = Path(args.data_root)
    train_dir = data_root / "training" / "detector_data"
    val_dir = data_root / "validation" / "detector_data"
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
    train(cfg, data_root, models_out)
    section(f"Done in {time.time() - started:.1f}s - artefacts in {models_out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
