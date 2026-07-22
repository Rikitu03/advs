"""POST /v1/ocr — Stage 2, PyTesseract OCR + field extraction (LIVE).

Reuses ``scripts/ocr_dryrun.py`` end to end: Otsu+2x preprocessing, OCR with
word boxes, the BIR field template, positional refinement, and the
fail-forward quality report (text-validation score + flags).
"""

from __future__ import annotations

import tempfile
from pathlib import Path

from fastapi import APIRouter, File, Form, HTTPException, Request, UploadFile

from ..compat import load_script
from ..config import Settings
from ..uploads import ocr_cfg, save_upload

router = APIRouter()

# Field templates the OCR stage can apply. The three document templates come from
# ocr_dryrun.TEMPLATES (bir / business_permit / dti); "none" skips field
# extraction (a type with no structured template, e.g. an ID or contract).
TEMPLATES = ("bir", "business_permit", "dti", "none")


def resolve_engine(settings: Settings) -> str:
    od = load_script("ocr_dryrun")
    cmd = od.resolve_tesseract_cmd(settings.tesseract_cmd)
    if cmd is None:
        raise HTTPException(
            status_code=503,
            detail={"reason": "tesseract_not_found",
                    "hint": "Install the Tesseract engine or set TESSERACT_CMD."},
        )
    return cmd


def run_ocr_stage(original: Path, settings: Settings, template: str = "bir") -> dict:
    """OCR every page of the upload; returns ``{page_count, pages: [...]}}``."""
    od = load_script("ocr_dryrun")
    cfg = ocr_cfg(settings)
    engine = resolve_engine(settings)

    try:
        images = od.load_images(original, cfg)
    except od.OcrError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc

    # Select the per-document-type field template (None => no field extraction).
    tmpl = od.TEMPLATES.get(template)

    pages = []
    for image in images:
        preprocessed = od.preprocess(image, cfg)
        ocr = od.run_ocr(preprocessed, cfg, engine)
        text = od.clean_text(ocr["raw_text"])

        fields = None
        keywords = None
        if tmpl is not None:
            keywords = tmpl["keywords"]
            fields = od.extract_fields(text, ocr["token_conf"], tmpl["field_specs"])
            if tmpl["positional"] is not None:
                fields = tmpl["positional"](fields, ocr["words"], ocr["token_conf"])

        pages.append({
            "text": text,
            "words": ocr["words"],
            "fields": fields,
            "quality": od.score_quality(text, fields or {}, ocr, cfg, keywords),
        })

    return {"page_count": len(pages), "pages": pages}


@router.post("/v1/ocr")
async def ocr(
    request: Request,
    file: UploadFile = File(...),
    template: str = Form("bir"),
) -> dict:
    if template not in TEMPLATES:
        raise HTTPException(status_code=422, detail=f"Unknown template '{template}'; use one of {TEMPLATES}.")

    data = await file.read()
    with tempfile.TemporaryDirectory(prefix="advs_ocr_") as tmp_dir:
        original = save_upload(file, data, tmp_dir)
        return run_ocr_stage(original, request.app.state.settings, template)
