"""Diagnostic script for signature enrollment detection issues.

Loads the same model and settings the API uses, runs detection on a provided
enrollment image, and prints detailed diagnostics: model path, image size,
raw detection count, per-detection label/confidence/bbox, and saves a
visualization overlay.

Usage:
    python scripts/debug_signature_enroll.py path/to/enrollment_photo.jpg
    python scripts/debug_signature_enroll.py path/to/photo.jpg --save-overlay
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

PY_ROOT = Path(__file__).resolve().parents[1]
if str(PY_ROOT) not in sys.path:
    sys.path.insert(0, str(PY_ROOT))

from PIL import Image

from api.config import Settings
from api.registry import ModelRegistry
from api.routers.detect import run_detection


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description="Debug signature enrollment detection.")
    ap.add_argument("image", help="Path to enrollment photo (JPG/PNG)")
    ap.add_argument("--save-overlay", action="store_true", help="Save detection overlay")
    ap.add_argument("--confidence", type=float, default=0.20, help="Detection confidence threshold")
    ap.add_argument("--imgsz", type=int, default=1280, help="Inference image size")
    args = ap.parse_args(argv if argv is not None else sys.argv[1:])

    image_path = Path(args.image)
    if not image_path.exists():
        print(f"[error] Image not found: {image_path}")
        return 1

    settings = Settings()
    registry = ModelRegistry(settings)
    registry.load_all()

    # Check which detector is loaded
    detector = registry.get("signature_enroll_detector") or registry.get("detector")
    if detector is None:
        print("[error] No detector loaded. Check /ready endpoint and model paths.")
        print(f"  detector_path: {settings.detector_path}")
        print(f"  signature_enroll_detector_model_path: {settings.signature_enroll_detector_model_path}")
        return 1

    detector_name = "signature_enroll_detector" if registry.get("signature_enroll_detector") else "detector"
    print(f"[info] Using detector: {detector_name}")
    print(f"[info] Model path: {getattr(settings, detector_name + '_path' if detector_name == 'detector' else detector_name + '_model_path', 'unknown')}")

    image = Image.open(image_path)
    print(f"[info] Image size: {image.size} (width x height)")
    print(f"[info] Detection settings: confidence={args.confidence}, imgsz={args.imgsz}")

    # Run detection
    detection = run_detection(
        detector,
        image,
        settings,
        confidence=args.confidence,
        imgsz=args.imgsz,
    )

    detections = detection["detections"]
    flags = detection["flags"]

    print(f"\n[results] Total detections: {len(detections)}")
    if not detections:
        print("[results] ❌ No detections found at confidence threshold.")
        print("[hint] Try lowering --confidence (e.g., 0.05) or the model may be out-of-distribution.")
    else:
        for i, det in enumerate(detections, 1):
            print(f"  [{i}] label={det['label']}, conf={det['confidence']:.3f}, box={det['box']}")

    print(f"\n[flags] {flags}")

    signature_dets = [d for d in detections if d["label"] == "signature"]
    print(f"[signature] Found {len(signature_dets)} signature(s)")

    if args.save_overlay and detections:
        try:
            from PIL import ImageDraw

            overlay = image.copy().convert("RGB")
            draw = ImageDraw.Draw(overlay)
            for det in detections:
                box = [int(v) for v in det["box"]]
                label = det["label"]
                conf = det["confidence"]
                color = "red" if label == "signature" else "blue"
                draw.rectangle(box, outline=color, width=3)
                draw.text((box[0], box[1] - 15), f"{label}:{conf:.2f}", fill=color)

            overlay_path = image_path.with_suffix(".overlay.png")
            overlay.save(overlay_path)
            print(f"\n[saved] Overlay saved to {overlay_path}")
        except Exception as e:
            print(f"[warn] Could not save overlay: {e}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
