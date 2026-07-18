"""POST /v1/tamper — Stage T, document-tampering forensics (LIVE).

Reuses ``scripts/tamper_analyze.run`` (the deterministic five-technique blend)
over the ORIGINAL upload — the forensic layer must never see the binarized
Stage 1 output. Optional JSON ``context`` carries the same keys Laravel's
``TamperDetectionService::buildPayload`` produces (ocr_text, ocr_words,
fields, issue_date, weights, tamper_threshold).
"""

from __future__ import annotations

import json
import tempfile
from pathlib import Path

from fastapi import APIRouter, File, Form, HTTPException, Request, UploadFile

from ..compat import load_script
from ..uploads import is_pdf, load_pages, save_upload

router = APIRouter()

CONTEXT_KEYS = ("ocr_text", "ocr_words", "fields", "issue_date", "weights", "tamper_threshold")


def run_tamper_stage(original: Path, page_images: list[Path], context: dict) -> dict:
    ta = load_script("tamper_analyze")
    payload = {
        "original_path": str(original),
        "page_images": [str(p) for p in page_images],
        **{key: context[key] for key in CONTEXT_KEYS if context.get(key) is not None},
    }
    return ta.run(payload)


@router.post("/v1/tamper")
async def tamper(
    request: Request,
    file: UploadFile = File(...),
    context: str | None = Form(None),
) -> dict:
    parsed: dict = {}
    if context:
        try:
            parsed = json.loads(context)
            if not isinstance(parsed, dict):
                raise ValueError("context must be a JSON object")
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=f"Invalid context JSON: {exc}") from exc

    data = await file.read()
    with tempfile.TemporaryDirectory(prefix="advs_tamper_") as tmp_dir:
        original = save_upload(file, data, tmp_dir)
        if is_pdf(original):
            # Full-DPI rendered pages; [] when Poppler is unavailable —
            # forensics then fail forward to metadata-only.
            _, page_images = load_pages(original, tmp_dir, request.app.state.settings)
        else:
            page_images = [original]
        return run_tamper_stage(original, page_images, parsed)
