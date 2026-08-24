"""POST /v1/ocr — Stage 2, PyTesseract OCR + field extraction (LIVE).

Reuses ``scripts/ocr_dryrun.py`` end to end: Otsu+2x preprocessing, OCR with
word boxes, the BIR field template, positional refinement, and the
fail-forward quality report (text-validation score + flags).
"""

from __future__ import annotations

import json
import tempfile
from functools import lru_cache
from pathlib import Path
from typing import Any

from fastapi import APIRouter, File, Form, HTTPException, Request, UploadFile

from ..compat import load_script
from ..config import Settings
from ..registry import ModelRegistry
from ..uploads import ocr_cfg, save_upload

router = APIRouter()

# Field templates the OCR stage can apply. The three document templates come from
# ocr_dryrun.TEMPLATES (bir / business_permit / dti); "none" skips field
# extraction (a type with no structured template, e.g. an ID or contract).
TEMPLATES = ("bir", "business_permit", "dti", "none")

ROI_CONFIG_PATH = Path(__file__).resolve().parents[1] / "ocr_roi_config.json"


@lru_cache(maxsize=1)
def _load_roi_config() -> dict:
    """Calibrated per-template ROI zones (generate_roi_config.py); read once
    per process, same singleton-on-first-use spirit as ModelRegistry."""
    return json.loads(ROI_CONFIG_PATH.read_text(encoding="utf-8"))


def _merge_roi_fields(fields: dict, roi_results: dict[str, str]) -> dict:
    """ROI+TrOCR recognizes a field's value from a tight crop of the detected
    text, independent of whether Tesseract read a caption label correctly -
    when it produces a validated result for a field the template defines, it
    replaces today's label+regex+positional value (Tesseract's own
    recognition accuracy is the reported problem, not the field-mapping
    logic). Fields ROI didn't confidently match keep their existing result
    (matched or not) unchanged - the fallback this whole design depends on."""
    for key, text in roi_results.items():
        existing = fields.get(key)
        if existing is None:
            continue  # ROI matched a field this template doesn't define; ignore
        fields[key] = {**existing, "value": text, "matched": True,
                        "confidence": None, "source": "roi_trocr"}
    return fields


def _permit_city(od: Any, text: str, fields: dict[str, Any]) -> tuple[str | None, str | None]:
    """Resolve the issuer before selecting a city-specific ROI profile."""
    raw = ((fields.get("city_issued") or {}).get("raw_value")
           or (fields.get("city_issued") or {}).get("value")
           if isinstance(fields.get("city_issued"), dict) else None)
    canonical = od.canonical_business_permit_city(raw)
    if canonical is not None:
        return str(raw), canonical

    upper = text.upper()
    for city, aliases in od.BUSINESS_PERMIT_CITY_ALIASES.items():
        for alias in aliases:
            if alias in upper:
                return alias, city
    return (str(raw) if raw else None), None


def _business_permit_evidence(
    fields: dict[str, Any],
    raw_city: str | None,
    canonical_city: str | None,
    *,
    source_page: int | None = None,
    layout_version: str | None = None,
) -> dict[str, Any]:
    aliases = {
        "permit_no": "permit_no",
        "or_no": "or_no",
        "issue_date": "date_issued",
        "owner_or_proprietor": "name_of_proprietor",
        "trade_name": "trade_name",
        "business_address": "business_location",
        "nature_of_business": "kind_of_business",
        "valid_until": "valid_until",
        "tax_year": "tax_year",
        "lcn_or_account_no": "lcn_or_account_no",
    }
    evidence: dict[str, Any] = {}
    for key, source_key in aliases.items():
        field = fields.get(source_key)
        if not isinstance(field, dict):
            continue
        item = {
            "name": field.get("name"),
            "value": field.get("value"),
            "normalized_value": str(field.get("value") or "").strip().upper() or None,
            "confidence": field.get("confidence"),
            "source_page": source_page,
            "bbox": field.get("bbox"),
            "source": field.get("source", "tesseract"),
            "warnings": list(field.get("warnings") or []),
            "matched": bool(field.get("matched")),
            "required": bool(field.get("required")),
        }
        evidence[key] = item

    return {
        "issuer_city_raw": raw_city,
        "issuer_city_canonical": canonical_city,
        "issuer_city_confidence": (fields.get("city_issued") or {}).get("confidence"),
        "layout_key": canonical_city.lower() if canonical_city else None,
        "layout_version": layout_version,
        "fields": evidence,
    }


def resolve_recognizer(registry: ModelRegistry, template: str,
                       accurate_templates: tuple[str, ...]) -> Any:
    """Which TrOCR recognizer this template should use.

    Benchmarked on the real samples: trocr-base and trocr-large produce
    identical field values on DTI and Business Permit with base ~3x faster,
    but on BIR base misreads values — including the issue YEAR ("FEB 24 2025"
    on a 2023 certificate), which feeds expiration monitoring. So the
    templates listed in ``TROCR_ACCURATE_TEMPLATES`` pay for the accurate
    model and the rest do not.

    Falls back to whichever recognizer is actually loaded, since baking only
    one is a normal deployment; ``None`` means neither is, and the ROI pass is
    skipped entirely.
    """
    fast = registry.get("trocr")
    accurate = registry.get("trocr_accurate")
    preferred, other = (accurate, fast) if template in accurate_templates else (fast, accurate)
    return preferred if preferred is not None else other


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


def run_ocr_stage(
    original: Path,
    settings: Settings,
    template: str = "bir",
    registry: ModelRegistry | None = None,
    city: str | None = None,
) -> dict:
    """OCR every page of the upload; returns ``{page_count, pages: [...]}}``.

    ``registry``/``city`` enable the optional ROI+TrOCR field-recognition
    pass (roi_field_ocr.py) on top of today's label+regex+positional
    extraction. Omitting ``registry`` (or either model not being loaded)
    leaves behavior byte-identical to before this pass existed.
    """
    od = load_script("ocr_dryrun")
    cfg = ocr_cfg(settings)
    engine = resolve_engine(settings)

    try:
        images = od.load_images(original, cfg)
    except od.OcrError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc

    # Select the per-document-type field template (None => no field extraction).
    tmpl = od.TEMPLATES.get(template)

    rapid_detector = registry.get("rapid_detector") if registry is not None else None
    trocr = (
        resolve_recognizer(registry, template, settings.accurate_templates())
        if registry is not None else None
    )
    roi_active = rapid_detector is not None and trocr is not None
    roi_module = load_script("roi_field_ocr") if roi_active else None
    roi_config = _load_roi_config() if roi_active else None

    pages = []
    for page_number, image in enumerate(images, start=1):
        preprocessed = od.preprocess(image, cfg)
        ocr = od.run_ocr(preprocessed, cfg, engine)
        text = od.clean_text(ocr["raw_text"])

        fields = None
        keywords = None
        roi_diagnostics = None
        permit_city_raw = None
        permit_city = None
        if tmpl is not None:
            keywords = tmpl["keywords"]
            field_specs = tmpl["field_specs"]
            if template == "business_permit":
                preliminary = od.extract_fields(text, ocr["token_conf"], od.BUSINESS_PERMIT_FIELD_SPECS)
                permit_city_raw, permit_city = _permit_city(od, text, preliminary)
                field_specs = od.business_permit_field_specs(permit_city)
                fields = od.extract_fields(text, ocr["token_conf"], field_specs)
            else:
                fields = od.extract_fields(text, ocr["token_conf"], field_specs)
            if tmpl["positional"] is not None:
                fields = tmpl["positional"](fields, ocr["words"], ocr["token_conf"])

            if roi_module is not None:
                page_city = city or permit_city or (fields.get("city_issued") or {}).get("value")
                outcome = roi_module.extract_fields_via_roi(
                    preprocessed, template, roi_config, rapid_detector, trocr,
                    city=page_city, field_specs=field_specs,
                    fields=fields,
                    low_conf_floor=settings.roi_tesseract_confidence_floor,
                    budget_seconds=settings.roi_budget_seconds,
                    max_new_tokens=settings.trocr_max_new_tokens,
                )
                fields = _merge_roi_fields(fields, outcome.fields)
                roi_diagnostics = outcome.diagnostics

            # Last, so each warning grades the value the officer will see -
            # whichever engine above produced it.
            fields = od.annotate_field_warnings(fields, field_specs, cfg)

        page = {
            "text": text,
            "words": ocr["words"],
            "fields": fields,
            # None = the extension isn't loaded at all, which is a different
            # story from "it ran and recognized nothing".
            "roi": roi_diagnostics,
            "quality": od.score_quality(text, fields or {}, ocr, cfg, keywords),
        }
        if template == "business_permit":
            page["business_permit"] = _business_permit_evidence(
                fields or {}, permit_city_raw, permit_city,
                source_page=page_number,
                layout_version=getattr(od, "BUSINESS_PERMIT_LAYOUT_VERSION", None),
            )
        pages.append(page)

    return {"page_count": len(pages), "pages": pages}


@router.post("/v1/ocr")
async def ocr(
    request: Request,
    file: UploadFile = File(...),
    template: str = Form("bir"),
    city: str | None = Form(None),
) -> dict:
    if template not in TEMPLATES:
        raise HTTPException(status_code=422, detail=f"Unknown template '{template}'; use one of {TEMPLATES}.")

    data = await file.read()
    with tempfile.TemporaryDirectory(prefix="advs_ocr_") as tmp_dir:
        original = save_upload(file, data, tmp_dir)
        return run_ocr_stage(
            original, request.app.state.settings, template,
            registry=request.app.state.registry, city=city,
        )
