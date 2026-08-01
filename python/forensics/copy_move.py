"""T3 — Copy-move / clone detection.

Fraudsters often lift a genuine element — a valid stamp, a signature, a security
seal — and clone it within the same document (or duplicate a region to cover an
edit). A copied region is pixel-near-identical to its source, so it produces
keypoint descriptors that match *another location in the same image* with a
consistent translation offset. We detect that: ORB keypoints matched against
themselves, self-matches discarded, and the surviving matches binned by their
translation vector — a dominant offset shared by many matches is a clone.

Operates on a single raster page image. cv2/numpy imported lazily.
"""

from __future__ import annotations

from collections import Counter
from pathlib import Path
from typing import Any

from . import technique_result, skipped_result

MAX_FEATURES = 2000
MIN_SPATIAL_OFFSET = 16    # px; matches closer than this are the same physical point
OFFSET_BIN = 8            # px; translation vectors rounded to this grid before voting
MIN_CLONE_MATCHES = 12    # this many matches sharing one offset => a clone
HAMMING_MAX = 40          # ORB descriptor Hamming distance cap for a "match"

# ── Periodic-texture rejection ────────────────────────────────────────────────
# Government documents carry tiled security backgrounds (guilloche, microtext,
# watermark lattices). A tiled pattern is self-similar at every multiple of its
# pitch, so it votes for MANY offsets at once; a genuine clone has exactly ONE
# true translation. Measured on a real BIR 2303's orange guilloche: offsets
# (32,0)=25, (0,96)=22, (24,0)=15 — 3 competing offsets, a 1.14 dominance ratio —
# which scored this technique to 0.21 and landed 0.01 short of hard-flagging a
# genuine certificate. A synthetic 6x6 tiling shows the same shape far more
# strongly (35 competing offsets, 1.10 ratio), while a real cloned region shows
# 1 offset and no runner-up at all.
#
# NOTE: spatial spread deliberately plays no part here. It does not separate the
# two cases — the tiled sample spans 0.81 of the page and the cloned sample 0.74,
# and the BIR's false match was actually compact. Periodicity and dominance are
# the load-bearing tests.
PERIODIC_MIN_OFFSETS = 3   # this many competing offsets => a tiled texture
DOMINANCE_RATIO = 2.0      # the top offset must beat the runner-up by this factor


def analyze(path: str | None) -> dict[str, Any]:
    """Detect duplicated regions within one image via offset-consistent ORB matches."""
    if not path:
        return skipped_result("no image path supplied")
    p = Path(path)
    if not p.is_file():
        return skipped_result(f"file not found: {p}")

    try:
        import cv2
        import numpy as np

        img = cv2.imread(str(p), cv2.IMREAD_GRAYSCALE)
        if img is None:
            return skipped_result(f"unreadable image: {p}")

        orb = cv2.ORB_create(nfeatures=MAX_FEATURES)
        keypoints, descriptors = orb.detectAndCompute(img, None)
        if descriptors is None or len(keypoints) < MIN_CLONE_MATCHES * 2:
            return technique_result(score=1.0, threshold=0.85, detail="Too few keypoints to assess cloning.")

        # Self-match: each descriptor against all others (k=3 so we can skip the
        # trivial self-hit and inspect the next-best, genuinely different point).
        matcher = cv2.BFMatcher(cv2.NORM_HAMMING)
        knn = matcher.knnMatch(descriptors, descriptors, k=3)

        offsets: Counter = Counter()
        pair_points: dict[tuple[int, int], tuple] = {}
        for matches in knn:
            for m in matches:
                if m.queryIdx == m.trainIdx or m.distance > HAMMING_MAX:
                    continue
                p1 = keypoints[m.queryIdx].pt
                p2 = keypoints[m.trainIdx].pt
                dx, dy = p2[0] - p1[0], p2[1] - p1[1]
                if (dx * dx + dy * dy) ** 0.5 < MIN_SPATIAL_OFFSET:
                    continue
                # Canonicalise the vector direction so A->B and B->A vote together.
                if (dx, dy) < (-dx, -dy):
                    dx, dy = -dx, -dy
                key = (int(round(dx / OFFSET_BIN)) * OFFSET_BIN, int(round(dy / OFFSET_BIN)) * OFFSET_BIN)
                offsets[key] += 1
                pair_points.setdefault(key, (p1, p2))

        if not offsets:
            return technique_result(score=1.0, threshold=0.85, detail="No duplicated regions detected.")

        ranked = offsets.most_common()
        offset, votes = ranked[0]
        runner_up = int(ranked[1][1]) if len(ranked) > 1 else 0
        if votes < MIN_CLONE_MATCHES:
            return technique_result(score=1.0, threshold=0.85, detail="No offset-consistent duplication found.",
                                    top_offset=list(offset), top_votes=int(votes))

        # A tiled security background votes for many offsets at once, and no single
        # one dominates. Either shape means "periodic texture", not "cloned region".
        competing = sum(1 for _, count in ranked if count >= MIN_CLONE_MATCHES)
        if competing >= PERIODIC_MIN_OFFSETS or votes < DOMINANCE_RATIO * runner_up:
            return technique_result(
                score=1.0, threshold=0.85,
                detail=("Self-similarity is periodic, not localized — consistent with a tiled "
                        "security background (guilloche / microtext), so no clone is reported."),
                notes=[f"Periodic background: {competing} competing offset(s), top {int(votes)} vs "
                       f"runner-up {runner_up} votes — below the {DOMINANCE_RATIO}x dominance a clone shows."],
                top_offset=list(offset), top_votes=int(votes),
                competing_offsets=competing, runner_up_votes=runner_up,
            )

        # Strength scales with how far past the trigger the vote count is.
        penalty = min(0.85, 0.4 + 0.03 * (votes - MIN_CLONE_MATCHES))
        score = 1.0 - penalty
        detail = (f"{votes} offset-consistent duplicate matches (translation {offset}) — "
                  f"a region appears cloned within the document.")
        return technique_result(score=score, threshold=0.85,
                                flags=[f"Copy-move: {votes} cloned keypoints at offset {list(offset)}"],
                                detail=detail, top_offset=list(offset), top_votes=int(votes))
    except Exception as exc:  # noqa: BLE001 — fail-forward
        return skipped_result(f"copy-move analysis failed: {exc}")
