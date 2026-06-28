"""T2 — Error Level Analysis (ELA).

A JPEG re-compresses every region at a similar error level. When a region is
pasted in and the image is resaved, that region carries a *different* compression
history, so it "lights up" at a different error level than its surroundings even
though the edit is invisible to the eye. ELA recompresses the image at a known
quality, measures the per-pixel error, and looks for spatially-concentrated
clusters of anomalously high error — the tell-tale of a pasted/edited region.

Operates on a single raster page image (the orchestrator passes the full-DPI
rendered page for PDFs — never the binarized Stage-1 output, which has no
compression history left to analyse). cv2/numpy imported lazily.
"""

from __future__ import annotations

from pathlib import Path
from typing import Any

from . import technique_result, skipped_result

JPEG_QUALITY = 90        # recompression quality for the error probe
BLOCK = 16               # error map is summarised into BLOCK x BLOCK cells
MAD_K = 6.0              # a cell must exceed median + MAD_K*MAD to be anomalous
ABS_FLOOR = 10.0         # ...and clear this absolute error floor (0-255 scale)
MIN_CLUSTER_BLOCKS = 4   # ignore clusters smaller than this many cells (noise)


def _load_bgr(path: Path):
    import cv2  # lazy
    img = cv2.imread(str(path), cv2.IMREAD_COLOR)
    if img is None:
        raise ValueError(f"unreadable image: {path}")
    return img


def _error_map(img):
    import cv2
    import numpy as np

    ok, buf = cv2.imencode(".jpg", img, [int(cv2.IMWRITE_JPEG_QUALITY), JPEG_QUALITY])
    if not ok:
        raise ValueError("JPEG recompression failed")
    recompressed = cv2.imdecode(buf, cv2.IMREAD_COLOR)
    diff = np.abs(img.astype(np.int16) - recompressed.astype(np.int16)).max(axis=2)
    return diff.astype(np.float32)


def analyze(path: str | None) -> dict[str, Any]:
    """Run ELA on one page image; report authenticity + suspect regions."""
    if not path:
        return skipped_result("no image path supplied")
    p = Path(path)
    if not p.is_file():
        return skipped_result(f"file not found: {p}")

    try:
        import cv2
        import numpy as np

        img = _load_bgr(p)
        h, w = img.shape[:2]
        err = _error_map(img)

        # Summarise the error map into BLOCK x BLOCK cells (mean error per cell).
        bh, bw = h // BLOCK, w // BLOCK
        if bh < 2 or bw < 2:
            return skipped_result("image too small for block-level ELA")
        cells = err[: bh * BLOCK, : bw * BLOCK].reshape(bh, BLOCK, bw, BLOCK).mean(axis=(1, 3))

        med = float(np.median(cells))
        mad = float(np.median(np.abs(cells - med))) * 1.4826
        threshold = max(med + MAD_K * mad, ABS_FLOOR)
        mask = (cells > threshold).astype(np.uint8)

        num, labels, stats, _ = cv2.connectedComponentsWithStats(mask, connectivity=8)
        regions = []
        anomalous_blocks = 0
        for i in range(1, num):
            area = int(stats[i, cv2.CC_STAT_AREA])
            if area < MIN_CLUSTER_BLOCKS:
                continue
            anomalous_blocks += area
            x, y = int(stats[i, cv2.CC_STAT_LEFT]), int(stats[i, cv2.CC_STAT_TOP])
            cw, ch = int(stats[i, cv2.CC_STAT_WIDTH]), int(stats[i, cv2.CC_STAT_HEIGHT])
            regions.append({"bbox": [x * BLOCK, y * BLOCK, cw * BLOCK, ch * BLOCK], "blocks": area})

        coverage = anomalous_blocks / float(bh * bw)
        penalty = min(0.8, 0.25 * len(regions) + 8.0 * coverage)
        score = 1.0 - penalty

        if regions:
            detail = (f"{len(regions)} region(s) with anomalous compression error "
                      f"(~{coverage * 100:.1f}% of the page) — possible paste/edit.")
            flags = [f"ELA: anomalous compression region at {r['bbox']}" for r in regions[:4]]
        else:
            detail = "Compression error is uniform across the page; no paste/edit signature."
            flags = []

        return technique_result(score=score, threshold=0.85, flags=flags, detail=detail,
                                regions=regions, coverage=round(coverage, 4),
                                is_jpeg=p.suffix.lower() in (".jpg", ".jpeg"))
    except Exception as exc:  # noqa: BLE001 — fail-forward
        return skipped_result(f"ELA failed: {exc}")
