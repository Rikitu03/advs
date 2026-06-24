"""Generate a tiny synthetic ADVS dataset matching the per-model data layout.

Writes small random PNGs (+ YOLO labels) into:
    <data-root>/training/<model>_data/...
    <data-root>/validation/<model>_data/...
so the per-model training scripts can be smoke-tested with no real data. NOT for
producing real models.

    # default data-root is python/data (resolved relative to this file)
    python python/.claude/skills/run-advs-training/make_fixtures.py
    python scripts/train_classifier.py --dry-run    # validate scaffold (stdlib only)
    python scripts/train_classifier.py --smoke      # tiny real run (needs ML stack)

Pure standard library - no numpy / Pillow / tensorflow needed (writes PNGs by
hand with zlib + struct), so it runs in any Python including the wheel-less
MSYS venv.
"""

from __future__ import annotations

import argparse
import random
import struct
import zlib
from pathlib import Path

# python/ is three levels up: .../python/.claude/skills/run-advs-training/make_fixtures.py
PY_ROOT = Path(__file__).resolve().parents[3]

CLASSES = ["bir_permit", "financial_statement", "business_registration", "fake"]
VENDORS = ["vendor_001", "vendor_002", "vendor_003", "vendor_004"]


def write_png(path: Path, size: int, seed: int) -> None:
    """Write a size x size RGB PNG of random pixels using only the stdlib."""
    rng = random.Random(seed)
    raw = bytearray()
    for _ in range(size):
        raw.append(0)                      # filter type 0 (None) per scanline
        raw += rng.randbytes(size * 3)     # RGB pixels

    def chunk(typ: bytes, data: bytes) -> bytes:
        body = typ + data
        return struct.pack(">I", len(data)) + body + struct.pack(">I", zlib.crc32(body) & 0xFFFFFFFF)

    ihdr = struct.pack(">IIBBBBB", size, size, 8, 2, 0, 0, 0)  # 8-bit, colour type 2 = RGB
    png = (
        b"\x89PNG\r\n\x1a\n"
        + chunk(b"IHDR", ihdr)
        + chunk(b"IDAT", zlib.compress(bytes(raw)))
        + chunk(b"IEND", b"")
    )
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(png)


def build_split(root: Path, split: str, n: int, size: int, seed0: int) -> int:
    seed = seed0
    base = root / split

    # 1. classifier - one subfolder per class
    for cls in CLASSES:
        for i in range(n):
            seed += 1
            write_png(base / "classifier_data" / cls / f"{i}.png", size, seed)

    # 2. detector - images/ + matching YOLO labels/
    for i in range(n * 2):
        seed += 1
        write_png(base / "detector_data" / "images" / f"page_{i}.png", size, seed)
        lbl = base / "detector_data" / "labels" / f"page_{i}.txt"
        lbl.parent.mkdir(parents=True, exist_ok=True)
        lbl.write_text("0 0.3 0.3 0.2 0.1\n1 0.7 0.7 0.2 0.2\n")

    # 3. signature - one subfolder per vendor of genuine signatures
    for v in VENDORS:
        for i in range(n):
            seed += 1
            write_png(base / "signature_data" / v / f"{i}.png", size, seed)

    # 4. stamp - genuine/ + forged/ crops
    for kind in ("genuine", "forged"):
        for i in range(n * 2):
            seed += 1
            write_png(base / "stamp_data" / kind / f"{i}.png", size, seed)

    return seed


def main() -> int:
    ap = argparse.ArgumentParser(description="Generate synthetic ADVS smoke dataset.")
    ap.add_argument("--data-root", default=str(PY_ROOT / "data"))
    ap.add_argument("--count", type=int, default=4, help="Images per class/vendor (train).")
    ap.add_argument("--size", type=int, default=96)
    args = ap.parse_args()

    root = Path(args.data_root)
    seed = build_split(root, "training", args.count, args.size, seed0=1000)
    build_split(root, "validation", max(2, args.count // 2), args.size, seed0=seed + 1)
    print(f"[make-fixtures] wrote synthetic train+val dataset under {root.resolve()}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
