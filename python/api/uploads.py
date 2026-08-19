"""Shared upload/page helpers for the stage routers.

Every request works inside its own ``tempfile`` directory (created by the
router, cleaned in ``finally``), mirroring the Laravel side's
``python_payloads`` hygiene rule: nothing an upload produces may outlive the
request.
"""

from __future__ import annotations

from pathlib import Path

from fastapi import HTTPException, UploadFile

from .compat import load_script
from .config import Settings

IMAGE_SUFFIXES = {".png", ".jpg", ".jpeg"}
ACCEPTED_MIME_TYPES = {
    ".pdf": "application/pdf",
    ".png": "image/png",
    ".jpg": "image/jpeg",
    ".jpeg": "image/jpeg",
}


def validate_upload(file: UploadFile, data: bytes, settings: Settings) -> str:
    suffix = Path(file.filename or "").suffix.lower()
    expected_mime = ACCEPTED_MIME_TYPES.get(suffix)
    if expected_mime is None:
        raise HTTPException(status_code=422, detail="Unsupported upload type; use PDF, PNG, or JPEG.")
    if file.content_type != expected_mime:
        raise HTTPException(
            status_code=422,
            detail=f"MIME type {file.content_type!r} does not match {suffix or 'the filename'}.",
        )
    if not data:
        raise HTTPException(status_code=422, detail="The uploaded file is empty.")
    if len(data) > settings.max_file_size_mb * 1024 * 1024:
        raise HTTPException(
            status_code=422,
            detail=f"The uploaded file exceeds the {settings.max_file_size_mb} MB limit.",
        )

    detected = _detect_magic(data)
    if detected != expected_mime:
        raise HTTPException(status_code=422, detail="File contents do not match the declared upload type.")
    return suffix


def _detect_magic(data: bytes) -> str | None:
    if data.startswith(b"%PDF-"):
        return "application/pdf"
    if data.startswith(b"\x89PNG\r\n\x1a\n"):
        return "image/png"
    if data.startswith(b"\xff\xd8\xff"):
        return "image/jpeg"
    return None


def save_upload(file: UploadFile, data: bytes, tmp_dir: str, settings: Settings | None = None) -> Path:
    suffix = (
        validate_upload(file, data, settings)
        if settings is not None
        else Path(file.filename or "upload.bin").suffix.lower() or ".bin"
    )
    path = Path(tmp_dir) / f"upload{suffix}"
    path.write_bytes(data)
    return path


def is_pdf(path: Path) -> bool:
    return path.suffix.lower() == ".pdf"


def ocr_cfg(settings: Settings) -> dict:
    """The reused ocr_dryrun CONFIG with the env-tunable §9 values applied."""
    od = load_script("ocr_dryrun")
    cfg = dict(od.CONFIG)
    cfg["pdf_dpi"] = settings.pdf_dpi
    cfg["max_pdf_pages"] = settings.max_pdf_pages
    return cfg


def load_pages(
    original: Path,
    tmp_dir: str,
    settings: Settings,
    *,
    strict: bool = False,
) -> tuple[list, list[Path]]:
    """Return ``(bgr_pages, page_image_paths)`` for an upload.

    Raster uploads are their own single page (and their own full-DPI page
    image for Stage T). PDFs are rendered to PNG — first ``MAX_PDF_PAGES``
    pages at ``PDF_DPI`` (§2) — via the reused ``ocr_dryrun.load_images``.
    A PDF that cannot be rendered (no Poppler) yields ``([], [])`` so callers
    fail forward instead of aborting.
    """
    od = load_script("ocr_dryrun")

    try:
        pages = od.load_images(original, ocr_cfg(settings))
    except od.OcrError as exc:
        if strict:
            raise HTTPException(status_code=422, detail=f"Unreadable document: {exc}") from exc
        return [], []

    if not is_pdf(original):
        return pages, [original]

    import cv2

    paths: list[Path] = []
    for index, page in enumerate(pages, start=1):
        path = Path(tmp_dir) / f"page_{index}.png"
        cv2.imwrite(str(path), page)
        paths.append(path)
    return pages, paths
