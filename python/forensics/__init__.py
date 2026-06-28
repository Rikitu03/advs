"""ADVS document-tampering forensics (pipeline Stage T).

A document-wide forensic layer that runs alongside the ML validation stages and
looks for *tampering* traces the per-component models miss: doctored fields,
pasted stamps/signatures, digitally edited regions, mismatched fonts, and
malformed identifiers.

Five deterministic, rule-based techniques live here, one module each:

    metadata          (T1)  file/EXIF/PDF metadata anomalies
    ela               (T2)  Error Level Analysis — recompression error hotspots
    copy_move         (T3)  cloned/duplicated regions within one image
    font_consistency  (T4)  per-field font/size/spacing outliers (from OCR boxes)
    cross_reference   (T5)  identifier format/checksum validation (TIN, reg. no.)

Score convention (IMPORTANT, shared by every technique):
    Each technique returns ``score`` = an AUTHENTICITY value in [0, 1], where
    1.0 = clean / no tampering evidence and 0.0 = strong tampering evidence.
    ``pass`` is ``score >= threshold``. The aggregate ``tamper_score`` produced
    by ``tamper_analyze`` is the INVERSE (0 = clean, 1 = heavily tampered), so it
    can feed the §6 risk formula directly as ``w5 * (1 - tamper_authenticity)``.

The heavy image dependencies (cv2/numpy/PIL/pikepdf/piexif) are imported lazily
inside the functions that need them, mirroring ``scripts/ocr_dryrun.py`` — so the
pure-Python techniques (font, cross-reference) import on a bare interpreter.
"""

from __future__ import annotations

from typing import Any

# Default per-technique blend weights for the aggregate tamper score. These are
# overridable from the JSON payload / SystemSetting rows on the Laravel side.
DEFAULT_WEIGHTS: dict[str, float] = {
    "metadata": 0.20,
    "ela": 0.25,
    "copy_move": 0.25,
    "font": 0.15,
    "cross_reference": 0.15,
}

# Aggregate authenticity below this => the document fails the forensic gate.
DEFAULT_TAMPER_THRESHOLD = 0.50

# A single technique reporting tamper evidence at/above this confidence is strong
# enough to hard-flag the document (the §6 risk hard-override mirrors this).
DEFAULT_HARD_CONFIDENCE = 0.80


def technique_result(
    score: float,
    threshold: float,
    flags: list[str] | None = None,
    detail: str = "",
    **extra: Any,
) -> dict[str, Any]:
    """Build the canonical per-technique result dict (clamped, pass computed)."""
    score = max(0.0, min(1.0, float(score)))
    result: dict[str, Any] = {
        "score": round(score, 4),
        "threshold": round(float(threshold), 4),
        "pass": score >= threshold,
        "flags": flags or [],
        "detail": detail,
    }
    result.update(extra)
    return result


def skipped_result(reason: str) -> dict[str, Any]:
    """A technique that could not run (missing input). Neutral — excluded from
    the aggregate blend rather than scored as tampered."""
    return {"score": None, "threshold": None, "pass": None, "skipped": True, "flags": [], "detail": reason}


def aggregate(
    techniques: dict[str, dict[str, Any]],
    weights: dict[str, float] | None = None,
    tamper_threshold: float = DEFAULT_TAMPER_THRESHOLD,
    hard_confidence: float = DEFAULT_HARD_CONFIDENCE,
) -> dict[str, Any]:
    """Blend the per-technique authenticity scores into one verdict.

    Skipped techniques are excluded (their weight is not counted). Returns the
    aggregate ``tamper_score`` (0 clean .. 1 tampered), ``tamper_authenticity``
    (its inverse), ``tamper_confidence`` (the single strongest tamper signal),
    ``tamper_passed`` and merged ``flags``.

    ``hard_flag`` mirrors the §6 risk hard-override: one technique reporting
    tamper evidence at/above ``hard_confidence`` (e.g. an exact-pixel clone) fails
    the forensic gate on its own, regardless of how the weighted blend dilutes it
    — strong, localized fraud must not be averaged away by the clean stages.
    """
    weights = weights or DEFAULT_WEIGHTS
    num = 0.0
    den = 0.0
    strongest = 0.0
    flags: list[str] = []

    for name, result in techniques.items():
        if result.get("skipped") or result.get("score") is None:
            continue
        tamper_signal = 1.0 - float(result["score"])  # 0 clean .. 1 tampered
        weight = float(weights.get(name, 0.0))
        num += weight * tamper_signal
        den += weight
        strongest = max(strongest, tamper_signal)
        flags.extend(result.get("flags", []))

    tamper_score = (num / den) if den > 0 else 0.0
    tamper_authenticity = 1.0 - tamper_score
    hard_flag = strongest >= hard_confidence

    return {
        "tamper_score": round(tamper_score, 4),
        "tamper_authenticity": round(tamper_authenticity, 4),
        "tamper_confidence": round(strongest, 4),
        "hard_flag": hard_flag,
        "tamper_passed": tamper_authenticity >= tamper_threshold and not hard_flag,
        "techniques": techniques,
        "flags": flags,
    }
