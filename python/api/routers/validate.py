"""POST /v1/validate — the full pipeline in one round trip, FAIL-FORWARD.

The HF-guide design: Laravel sends the document (plus any reference vectors it
owns) once, instead of five HTTP calls per validation. Every stage that cannot
run — model not trained yet, engine missing, no reference passed, upstream
stage unavailable — is recorded as ``{"skipped": true, "reason": ...}`` and
contributes a flag; nothing aborts the document (ADVS_System_Reference.md §5).

The response carries per-stage component results and merged flags ONLY. The
composite risk score (Stage 5) is Laravel's RiskScoreService — never computed
here.
"""

from __future__ import annotations

import json
import logging
import tempfile
from pathlib import Path

from fastapi import APIRouter, File, Form, Request, UploadFile
from fastapi import HTTPException
from PIL import Image

from .. import embedding as emb
from ..config import Settings
from ..registry import ModelRegistry
from ..uploads import load_pages, save_upload
from .classify import run_classification
from .detect import run_detection
from .ocr import run_ocr_stage
from .signature import run_signature_verify
from .stamp import run_stamp_verify
from .tamper import run_tamper_stage

logger = logging.getLogger("advs.api.validate")

router = APIRouter()


def _skipped(reason: str) -> dict:
    return {"skipped": True, "reason": reason}


def _parse_forensics(raw: str | None) -> dict:
    """Optional admin-tuned forensics config (weights / tamper_threshold) from the
    caller, merged into the Stage-T context so operator overrides apply here too.
    Malformed JSON is ignored — forensics fail forward to the script defaults."""
    if not raw:
        return {}
    try:
        parsed = json.loads(raw)
    except ValueError:
        return {}
    if not isinstance(parsed, dict):
        return {}
    return {key: parsed[key] for key in ("weights", "tamper_threshold") if parsed.get(key) is not None}


def _crop(page: Image.Image, box: list[float]) -> Image.Image:
    x1, y1, x2, y2 = (max(0, int(v)) for v in box)
    return page.crop((x1, y1, x2, y2))


def _best_box(detections: list[dict], labels: tuple[str, ...]) -> dict | None:
    candidates = [d for d in detections if d["label"] in labels]
    return max(candidates, key=lambda d: d["confidence"]) if candidates else None


def _first_page_image(pages_bgr: list) -> Image.Image | None:
    if not pages_bgr:
        return None
    import cv2

    return Image.fromarray(cv2.cvtColor(pages_bgr[0], cv2.COLOR_BGR2RGB))


@router.post("/v1/validate")
async def validate(
    request: Request,
    file: UploadFile = File(...),
    template: str = Form("bir"),
    document_type: str | None = Form(None),
    city: str | None = Form(None),
    issue_date: str | None = Form(None),
    signature_reference: str | None = Form(None),
    stamp_reference: str | None = Form(None),
    forensics: str | None = Form(None),
) -> dict:
    settings: Settings = request.app.state.settings
    registry: ModelRegistry = request.app.state.registry

    sig_reference = emb.parse_reference(signature_reference, "signature_reference") if signature_reference else None
    logo_reference = emb.parse_reference(stamp_reference, "stamp_reference") if stamp_reference else None
    forensics_ctx = _parse_forensics(forensics)

    stages: dict[str, dict] = {}
    flags: list[str] = []
    data = await file.read()

    with tempfile.TemporaryDirectory(prefix="advs_validate_") as tmp_dir:
        original = save_upload(file, data, tmp_dir)
        pages_bgr, page_paths = load_pages(original, tmp_dir, settings)
        first_page = _first_page_image(pages_bgr)
        if first_page is None:
            flags.append("unreadable_page_image")

        # ── Stage 3: classification ────────────────────────────────────────
        classifier = registry.get("classifier")
        if classifier is None:
            stages["classification"] = _skipped("model_not_loaded")
        elif first_page is None:
            stages["classification"] = _skipped("no_page_image")
        else:
            try:
                stage = run_classification(
                    classifier, first_page, settings.classification_confidence_threshold
                )
                stages["classification"] = stage
                if not stage["passed_threshold"]:
                    flags.append("low_classification_confidence")
            except Exception as exc:
                logger.exception("classification stage failed")
                stages["classification"] = _skipped(f"error: {exc}")

        # ── Stage 2: OCR + fields ──────────────────────────────────────────
        ocr_context: dict = {}
        try:
            stage = run_ocr_stage(original, settings, template, registry=registry, city=city)
            stages["ocr"] = stage
            if stage["pages"]:
                page = stage["pages"][0]
                fields = page["fields"] or {}
                ocr_context = {
                    "ocr_text": page["text"],
                    "ocr_words": [
                        {"text": w["text"], "conf": w["conf"],
                         "bbox": [w["left"], w["top"], w["width"], w["height"]]}
                        for w in page["words"]
                    ],
                    "fields": {
                        key: field["value"]
                        for key, field in fields.items()
                        if field.get("matched")
                    },
                }
                flags.extend(page["quality"]["flags"])
        except HTTPException as exc:
            reason = exc.detail["reason"] if isinstance(exc.detail, dict) else str(exc.detail)
            stages["ocr"] = _skipped(reason)
        except Exception as exc:
            logger.exception("ocr stage failed")
            stages["ocr"] = _skipped(f"error: {exc}")

        # ── Stage 4: detection ─────────────────────────────────────────────
        detections: list[dict] = []
        detector = registry.get("detector")
        if detector is None:
            stages["detection"] = _skipped("model_not_loaded")
        elif first_page is None:
            stages["detection"] = _skipped("no_page_image")
        else:
            try:
                stage = run_detection(detector, first_page, settings)
                stages["detection"] = stage
                detections = stage["detections"]
                flags.extend(stage["flags"])
            except Exception as exc:
                logger.exception("detection stage failed")
                stages["detection"] = _skipped(f"error: {exc}")

        # ── Stage 4a: signature verify (reference enrolled at registration) ─
        siamese = registry.get("siamese")
        signature_box = _best_box(detections, ("signature",))
        if siamese is None:
            stages["signature"] = _skipped("model_not_loaded")
        elif sig_reference is None:
            stages["signature"] = _skipped("no_reference_embedding")
        elif not stages.get("detection") or stages["detection"].get("skipped"):
            stages["signature"] = _skipped("detection_unavailable")
        elif signature_box is None:
            stages["signature"] = _skipped("no_signature_detected")
        else:
            try:
                stages["signature"] = run_signature_verify(
                    siamese, _crop(first_page, signature_box["box"]), sig_reference, settings
                )
            except Exception as exc:
                logger.exception("signature stage failed")
                stages["signature"] = _skipped(f"error: {exc}")

        # ── Stage 4b: issuer logo verify ───────────────────────────────────
        stamp_model = registry.get("stamp")
        stamp_box = _best_box(detections, ("stamp", "logo"))
        if stamp_model is None:
            stages["stamp"] = _skipped("model_not_loaded")
        elif not stages.get("detection") or stages["detection"].get("skipped"):
            stages["stamp"] = _skipped("detection_unavailable")
        elif stamp_box is None:
            stages["stamp"] = _skipped("no_stamp_detected")
        else:
            try:
                stages["stamp"] = run_stamp_verify(
                    stamp_model, _crop(first_page, stamp_box["box"]),
                    logo_reference, settings, document_type, city,
                )
                if stages["stamp"].get("reason"):
                    flags.append(stages["stamp"]["reason"])
            except Exception as exc:
                logger.exception("stamp stage failed")
                stages["stamp"] = _skipped(f"error: {exc}")

        # ── Stage T: forensic tampering — always runs, on the ORIGINAL ─────
        try:
            verdict = run_tamper_stage(
                original, page_paths,
                {**ocr_context, "issue_date": issue_date, **forensics_ctx},
            )
            stages["tamper"] = verdict
            flags.extend(verdict.get("flags", []))
        except Exception as exc:
            logger.exception("tamper stage failed")
            stages["tamper"] = _skipped(f"error: {exc}")

    return {
        "stages": stages,
        "flags": list(dict.fromkeys(flags)),  # dedupe, keep first-seen order
    }
