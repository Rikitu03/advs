"""T5 — OCR cross-reference (identifier format / checksum validation).

OCR extracts the text; this technique checks the structured identifiers in it
against the formats they must follow. A doctored TIN or a fabricated DTI/business
registration number frequently breaks its canonical format, so a cheap regex /
checksum gate catches a whole class of forgeries before any DB is consulted.

This module owns the FORMAT layer only; the authoritative DB cross-reference
(does this TIN/number actually exist in the registry?) lives on the Laravel side
(``App\\Support\\TinValidator`` / ``RegistrationNumberValidator``) where the
Eloquent connection is, and is wired in by ``TamperDetectionService``.

Pure-Python (regex + stdlib), importable on a bare interpreter. Input is the raw
OCR ``text`` and/or a ``fields`` dict of already-extracted values.
"""

from __future__ import annotations

import re
from typing import Any

from . import technique_result, skipped_result

# Philippine TIN: 9 base digits + optional 3/5-digit branch code, dash-grouped.
#   274-118-902      (9-digit, older)
#   274-118-902-000  (with 000 branch — most common on BIR 2303)
TIN_RE = re.compile(r"\b(\d{3}-\d{3}-\d{3}(?:-\d{3,5})?)\b")
# A loose "looks like someone tried to write a TIN" matcher, to catch malformed
# ones (wrong digit grouping) that the strict matcher above would skip silently.
TIN_LOOSE_RE = re.compile(r"\bTIN[:\s]*([\d\- ]{7,20})", re.IGNORECASE)
# DTI / business-name registration certificate numbers vary, but are typically a
# year prefix + a 6-8 digit serial, optionally dashed.
REG_NO_RE = re.compile(r"\b(?:Certificate|Registration|Reg\.?)\s*(?:No\.?|Number)[:\s]*([A-Z0-9\-]{6,20})", re.IGNORECASE)

MALFORMED_TIN_PENALTY = 0.6
MISSING_TIN_PENALTY = 0.15
MALFORMED_REG_PENALTY = 0.4


def _normalize_digits(raw: str) -> str:
    return re.sub(r"\D", "", raw)


def validate_tin(raw: str) -> bool:
    """A well-formed PH TIN has 9 base digits (12/13/14 with a branch code)."""
    digits = _normalize_digits(raw)
    return len(digits) in (9, 12, 13, 14)


def analyze(text: str | None, fields: dict[str, Any] | None = None) -> dict[str, Any]:
    """Validate identifier formats found in the OCR text / extracted fields."""
    fields = fields or {}
    blob = text or ""
    if not blob.strip() and not fields:
        return skipped_result("no OCR text or extracted fields supplied")

    flags: list[str] = []
    checks: dict[str, Any] = {}
    penalty = 0.0

    # --- TIN -------------------------------------------------------------
    tin_value = fields.get("tin")
    if not tin_value:
        strict = TIN_RE.search(blob)
        loose = TIN_LOOSE_RE.search(blob)
        if strict:
            tin_value = strict.group(1)
        elif loose:
            tin_value = loose.group(1).strip()

    if tin_value:
        ok = validate_tin(tin_value)
        checks["tin"] = {"value": tin_value, "valid": ok}
        if not ok:
            penalty += MALFORMED_TIN_PENALTY
            flags.append(f"Malformed TIN '{tin_value}' — does not match the 9-digit (NNN-NNN-NNN[-NNN]) format")
    else:
        # Only penalise a missing TIN when the document clearly references one.
        if re.search(r"\bTIN\b", blob, re.IGNORECASE):
            penalty += MISSING_TIN_PENALTY
            checks["tin"] = {"value": None, "valid": False}
            flags.append("Document references a TIN but no well-formed TIN could be read")

    # --- Registration / certificate number -------------------------------
    reg_value = fields.get("registration_number")
    if not reg_value:
        m = REG_NO_RE.search(blob)
        if m:
            reg_value = m.group(1).strip()
    if reg_value:
        ok = bool(re.search(r"\d", reg_value)) and len(_normalize_digits(reg_value)) >= 5
        checks["registration_number"] = {"value": reg_value, "valid": ok}
        if not ok:
            penalty += MALFORMED_REG_PENALTY
            flags.append(f"Malformed registration number '{reg_value}'")

    if not checks:
        return skipped_result("no recognisable identifiers in the document text")

    score = max(0.0, 1.0 - penalty)
    detail = (
        "All recognised identifiers are well-formed."
        if not flags
        else f"{len(flags)} identifier format issue(s) detected."
    )
    return technique_result(score=score, threshold=0.999, flags=flags, detail=detail, checks=checks)
