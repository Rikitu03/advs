"""T4 — Font-consistency analysis.

A genuine government-issued document is printed by one system in one pass, so
its body text is typographically uniform. When a field is re-typed to change a
value (a name, a date, a TIN), the replacement rarely matches the surrounding
font's height, weight, or character spacing. This technique consumes the
word-level boxes Stage 2 OCR already produces (``image_to_data``) and flags
words whose glyph height or average character width deviates from the document
baseline using a robust (median / MAD) outlier test — robust because a handful
of tampered words must not move the baseline they are compared against.

Input ``words``: list of ``{"text": str, "conf": float, "bbox": [x, y, w, h]}``
(the shape ``ocr_dryrun``/``image_to_data`` yields). No image is needed, so this
module is pure-Python and importable on a bare interpreter.
"""

from __future__ import annotations

import statistics
from typing import Any

from . import technique_result, skipped_result

# A word must be at least this many MADs from the document median (height or
# char-width) to count as a typographic outlier.
DEFAULT_MAD_Z = 3.5
# Floor the MAD at this fraction of the median so a perfectly uniform baseline
# (MAD≈0) still flags a genuinely different field instead of dividing by zero.
MAD_FLOOR_FRAC = 0.05
# Ignore very short tokens — single glyphs / punctuation have unreliable metrics.
MIN_TEXT_LEN = 2
# Words must clear this OCR confidence to be trusted as baseline samples.
MIN_CONF = 40.0
# Each outlier field costs this much authenticity.
PER_OUTLIER_PENALTY = 0.30


def _median_abs_deviation(values: list[float], median: float) -> float:
    """MAD scaled to be a consistent estimator of stddev for normal data."""
    if not values:
        return 0.0
    mad = statistics.median([abs(v - median) for v in values])
    return mad * 1.4826


def _robust_z(value: float, median: float, mad: float) -> float:
    if mad <= 1e-9:
        return 0.0
    return abs(value - median) / mad


def analyze(words: list[dict[str, Any]] | None, mad_z: float = DEFAULT_MAD_Z) -> dict[str, Any]:
    """Flag words whose font metrics are outliers vs. the document baseline."""
    if not words:
        return skipped_result("no OCR word boxes supplied")

    samples = []
    for w in words:
        text = (w.get("text") or "").strip()
        bbox = w.get("bbox") or []
        conf = float(w.get("conf", 0) or 0)
        if len(text) < MIN_TEXT_LEN or len(bbox) != 4 or conf < MIN_CONF:
            continue
        _, _, bw, bh = bbox
        if bh <= 0 or bw <= 0:
            continue
        samples.append({"text": text, "height": float(bh), "char_w": float(bw) / len(text), "conf": conf})

    if len(samples) < 6:
        return skipped_result(f"too few reliable words ({len(samples)}) for a baseline")

    heights = [s["height"] for s in samples]
    char_ws = [s["char_w"] for s in samples]
    h_med, w_med = statistics.median(heights), statistics.median(char_ws)
    h_mad = max(_median_abs_deviation(heights, h_med), MAD_FLOOR_FRAC * h_med)
    w_mad = max(_median_abs_deviation(char_ws, w_med), MAD_FLOOR_FRAC * w_med)

    outliers = []
    for s in samples:
        hz = _robust_z(s["height"], h_med, h_mad)
        wz = _robust_z(s["char_w"], w_med, w_mad)
        if hz >= mad_z or wz >= mad_z:
            metric = "height" if hz >= wz else "spacing"
            outliers.append({"text": s["text"], "metric": metric, "height_z": round(hz, 2), "char_w_z": round(wz, 2)})

    score = max(0.0, 1.0 - PER_OUTLIER_PENALTY * len(outliers))
    if outliers:
        names = ", ".join(f"'{o['text']}' ({o['metric']})" for o in outliers[:4])
        detail = f"{len(outliers)} field(s) deviate from the document font baseline: {names}."
        flags = [f"Font mismatch on field {o['text']!r} ({o['metric']})" for o in outliers]
    else:
        detail = f"All {len(samples)} sampled words are typographically consistent."
        flags = []

    return technique_result(
        score=score,
        threshold=0.999,  # any outlier fails this technique's local gate
        flags=flags,
        detail=detail,
        outliers=outliers,
        baseline={"height_median": round(h_med, 2), "char_width_median": round(w_med, 2)},
        sampled_words=len(samples),
    )
