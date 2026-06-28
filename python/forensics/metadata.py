"""T1 — Metadata analysis.

Every digital document carries hidden provenance: who/what created it, when, and
when it was last modified. Forgeries betray themselves here — a certificate
"issued in 2019" whose file was last written by Photoshop last week, a modify
date later than the printed issue date, or metadata that has been wholesale
stripped to hide an edit.

Handles two container families:
    * raster images (JPG/PNG/TIFF) — EXIF via Pillow + piexif
    * PDF — document info + XMP via pikepdf

Heavy deps are imported lazily so the rest of the forensics package keeps
importing on a bare interpreter.
"""

from __future__ import annotations

import re
from datetime import datetime
from pathlib import Path
from typing import Any

from . import technique_result, skipped_result

# Software signatures that indicate an image *editor* touched the file (as
# opposed to a camera/scanner/government issuing system). Lower-cased substrings.
EDITOR_SIGNATURES = (
    "photoshop", "gimp", "adobe", "affinity", "pixelmator", "paint.net",
    "lightroom", "snapseed", "canva", "inkscape", "imagemagick", "krita",
)

EDITOR_PENALTY = 0.6          # editor software signature present
MODIFIED_AFTER_CREATE_PENALTY = 0.3   # last-modified later than created
MODIFIED_AFTER_ISSUE_PENALTY = 0.5    # last-modified later than the printed issue date
STRIPPED_PENALTY = 0.15       # no provenance metadata at all (possibly scrubbed)

_DATE_FORMATS = ("%Y:%m:%d %H:%M:%S", "%Y-%m-%dT%H:%M:%S", "%Y-%m-%d %H:%M:%S", "%Y%m%d%H%M%S")


def _parse_dt(value: str | None) -> datetime | None:
    if not value:
        return None
    cleaned = value.strip().lstrip("D:").strip()  # PDF dates look like "D:20240115..."
    cleaned = re.sub(r"[+\-Z].*$", "", cleaned).strip()
    for fmt in _DATE_FORMATS:
        try:
            return datetime.strptime(cleaned[: len(datetime.now().strftime(fmt))], fmt)
        except (ValueError, TypeError):
            continue
    return None


def _read_image_metadata(path: Path) -> dict[str, Any]:
    from PIL import Image, ExifTags  # lazy

    meta: dict[str, Any] = {"container": "image"}
    with Image.open(path) as im:
        exif = im.getexif()
        tag_names = {v: k for k, v in ExifTags.TAGS.items()}  # name -> id
        for name in ("Software", "DateTime", "DateTimeOriginal", "DateTimeDigitized", "Artist", "Make", "Model"):
            tag_id = tag_names.get(name)
            if tag_id is not None and tag_id in exif:
                meta[name] = str(exif.get(tag_id))
        meta["has_exif"] = bool(exif)
    return meta


def _read_pdf_metadata(path: Path) -> dict[str, Any]:
    import pikepdf  # lazy

    meta: dict[str, Any] = {"container": "pdf"}
    with pikepdf.open(path) as pdf:
        info = pdf.docinfo
        for key, out in (("/Producer", "Producer"), ("/Creator", "Software"),
                         ("/CreationDate", "CreateDate"), ("/ModDate", "ModifyDate"),
                         ("/Author", "Artist")):
            if key in info:
                meta[out] = str(info[key])
        meta["has_exif"] = bool(dict(info))
    return meta


def analyze(path: str | None, issue_date: str | None = None) -> dict[str, Any]:
    """Inspect a file's provenance metadata for tampering signatures.

    ``issue_date`` (optional, ISO ``YYYY-MM-DD``) is the date PRINTED on the
    document, parsed from OCR upstream — a modify timestamp after it is a strong
    tell. Returns a neutral skipped result if the file can't be read.
    """
    if not path:
        return skipped_result("no file path supplied")
    p = Path(path)
    if not p.is_file():
        return skipped_result(f"file not found: {p}")

    try:
        if p.suffix.lower() == ".pdf":
            meta = _read_pdf_metadata(p)
        else:
            meta = _read_image_metadata(p)
    except Exception as exc:  # noqa: BLE001 — forensics must fail-forward, never abort the pipeline
        return skipped_result(f"could not read metadata: {exc}")

    flags: list[str] = []
    penalty = 0.0

    software = " ".join(str(meta.get(k, "")) for k in ("Software", "Producer")).lower()
    matched_editor = next((sig for sig in EDITOR_SIGNATURES if sig in software), None)
    if matched_editor:
        penalty += EDITOR_PENALTY
        flags.append(f"File last written by an image editor ('{matched_editor}') — not an issuing/scanning system")

    created = _parse_dt(meta.get("CreateDate") or meta.get("DateTimeOriginal") or meta.get("DateTimeDigitized"))
    modified = _parse_dt(meta.get("ModifyDate") or meta.get("DateTime"))
    if created and modified and modified > created:
        penalty += MODIFIED_AFTER_CREATE_PENALTY
        flags.append(f"Last-modified ({modified.date()}) is after creation ({created.date()})")

    issue = _parse_dt(issue_date)
    if issue and modified and modified.date() > issue.date():
        penalty += MODIFIED_AFTER_ISSUE_PENALTY
        flags.append(f"File modified ({modified.date()}) after the document's printed issue date ({issue.date()})")

    if not meta.get("has_exif") and not flags:
        penalty += STRIPPED_PENALTY
        flags.append("No provenance metadata present — may have been stripped to hide edits")

    score = max(0.0, 1.0 - penalty)
    detail = "No metadata anomalies." if not flags else f"{len(flags)} metadata anomaly(ies) detected."
    return technique_result(score=score, threshold=0.999, flags=flags, detail=detail,
                            metadata={k: v for k, v in meta.items() if k != "has_exif"})
