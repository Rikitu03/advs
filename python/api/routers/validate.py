"""Canonical, versioned full-document validation endpoint.

Every rendered page flows through the independent pipeline stages. Failures
remain fail-forward and are represented as typed stage statuses; upload and
request-contract errors are rejected with 422 before inference starts.
"""

from __future__ import annotations

import hashlib
import json
import logging
import math
import tempfile
import time
from pathlib import Path
from typing import Any, Callable

from fastapi import APIRouter, File, Form, HTTPException, Request, UploadFile
from PIL import Image

from .. import embedding as emb
from ..compat import load_script
from ..logo_catalog import (
    CatalogError,
    canonical_city,
    embed_candidates,
    resolve_candidates,
)
from ..config import Settings
from ..registry import ModelRegistry
from ..uploads import load_pages, save_upload
from .classify import run_classification
from .detect import run_detection
from .ocr import TEMPLATES, run_ocr_stage
from .signature import run_signature_verify
from .stamp import run_stamp_verify
from .tamper import run_tamper_stage

logger = logging.getLogger("advs.api.validate")

router = APIRouter()

SCHEMA_VERSION = "1.0"
SUPPORTED_DOCUMENT_TYPES = {
    "bir_certificate": "bir",
    "business_permit": "business_permit",
    "dti_registration": "dti",
}
RUNTIME_SETTING_KEYS = (
    "classification_confidence_threshold",
    "yolo_detection_confidence",
    "stamp_similarity_threshold",
    "stamp_tamper_threshold",
    "signature_distance_threshold",
    "pdf_dpi",
    "max_pdf_pages",
    "max_file_size_mb",
)
SETTING_ALIASES = {
    key: key for key in RUNTIME_SETTING_KEYS
} | {
    "CLASSIFICATION_CONFIDENCE_THRESHOLD": "classification_confidence_threshold",
    "YOLO_DETECTION_CONFIDENCE": "yolo_detection_confidence",
    "STAMP_SIMILARITY_THRESHOLD": "stamp_similarity_threshold",
    "STAMP_TAMPER_THRESHOLD": "stamp_tamper_threshold",
    "SIGNATURE_DISTANCE_THRESHOLD": "signature_distance_threshold",
    "PDF_DPI": "pdf_dpi",
    "MAX_PDF_PAGES": "max_pdf_pages",
    "MAX_FILE_SIZE_MB": "max_file_size_mb",
}


def _skipped(reason: str) -> dict:
    return {"status": "skipped", "skipped": True, "reason": reason}


def _failed(reason: str) -> dict:
    return {"status": "failed", "skipped": True, "reason": reason}


def _completed(result: dict) -> dict:
    return {"status": "completed", **result}


def _timed(call: Callable[[], dict]) -> tuple[dict, float]:
    started = time.perf_counter()
    result = call()
    return result, round((time.perf_counter() - started) * 1000, 3)


def _parse_forensics(raw: str | None) -> dict:
    """Parse optional Stage-T overrides while preserving fail-forward behavior."""
    if not raw:
        return {}
    try:
        parsed = json.loads(raw)
    except ValueError:
        return {}
    if not isinstance(parsed, dict):
        return {}
    return {
        key: parsed[key]
        for key in ("weights", "tamper_threshold", "hard_confidence")
        if parsed.get(key) is not None
    }


def _parse_settings_snapshot(raw: str | None, base: Settings) -> tuple[Settings, dict, str]:
    """Build the immutable settings used by this validation and its audit hash.

    Laravel may send its complete settings snapshot. Unknown keys remain in the
    hash for provenance but only the Python-owned aliases above affect runtime.
    """
    parsed: dict[str, Any] = {}
    if raw:
        try:
            value = json.loads(raw)
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=f"Invalid settings_snapshot: {exc}") from exc
        if not isinstance(value, dict):
            raise HTTPException(status_code=422, detail="settings_snapshot must be a JSON object.")
        parsed = value

    runtime = base.model_copy(deep=True)
    for source, value in parsed.items():
        target = SETTING_ALIASES.get(source)
        if target is None:
            continue
        _validate_runtime_setting(target, value)
        setattr(runtime, target, value)

    effective = {key: getattr(runtime, key) for key in RUNTIME_SETTING_KEYS}
    hash_payload = parsed if parsed else effective
    try:
        canonical = json.dumps(hash_payload, sort_keys=True, separators=(",", ":"), allow_nan=False)
    except (TypeError, ValueError) as exc:
        raise HTTPException(status_code=422, detail=f"Invalid settings_snapshot values: {exc}") from exc
    settings_hash = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    return runtime, effective, settings_hash


def _validate_runtime_setting(key: str, value: Any) -> None:
    if value is None and key == "signature_distance_threshold":
        return
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(float(value)):
        raise HTTPException(status_code=422, detail=f"settings_snapshot.{key} must be a finite number.")

    numeric = float(value)
    if key in {
        "classification_confidence_threshold",
        "yolo_detection_confidence",
        "stamp_similarity_threshold",
        "stamp_tamper_threshold",
    } and not 0.0 <= numeric <= 1.0:
        raise HTTPException(status_code=422, detail=f"settings_snapshot.{key} must be between 0 and 1.")
    if key == "signature_distance_threshold" and numeric < 0.0:
        raise HTTPException(status_code=422, detail="Signature distance threshold cannot be negative.")
    if key == "pdf_dpi" and not 72 <= numeric <= 600:
        raise HTTPException(status_code=422, detail="PDF_DPI must be between 72 and 600.")
    if key == "max_pdf_pages" and not 1 <= numeric <= 10:
        raise HTTPException(status_code=422, detail="MAX_PDF_PAGES must be between 1 and 10.")
    if key == "max_file_size_mb" and not 1 <= numeric <= 100:
        raise HTTPException(status_code=422, detail="MAX_FILE_SIZE_MB must be between 1 and 100.")


def parse_reference_map(raw: str | None, expected_length: int | None = None) -> dict[str, list[float]]:
    if not raw:
        return {}
    try:
        parsed = json.loads(raw)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=f"Invalid stamp_references: {exc}") from exc
    if not isinstance(parsed, dict):
        raise HTTPException(status_code=422, detail="stamp_references must be a JSON object.")

    references: dict[str, list[float]] = {}
    for key, value in parsed.items():
        if not isinstance(key, str):
            raise HTTPException(status_code=422, detail="stamp_references keys must be city names.")
        references[canonical_city(key) or ""] = emb.parse_reference(
            json.dumps(value), f"stamp_references[{key!r}]", expected_length
        )
    return references


def resolve_issuer_reference(
    references: dict[str, list[float]],
    single: list[float] | None,
    issuer_scope: str | None,
    city: str | None,
) -> tuple[list[float] | None, str | None]:
    if issuer_scope == "lgu":
        key = canonical_city(city)
        if key is None:
            return None, "city_not_identified"
        return references.get(key), None
    return references.get("", single), None


def classification_flags(stage: dict, document_type: str | None,
                         class_names: list[str]) -> list[str]:
    flags = []
    label = stage.get("label")
    if label == "fake":
        flags.append("classified_as_fake")
    elif (
        stage.get("passed_threshold")
        and document_type is not None
        and document_type in class_names
        and label != document_type
    ):
        flags.append("document_type_mismatch")
    return flags


def _crop(page: Image.Image, box: list[float]) -> Image.Image:
    x1, y1, x2, y2 = (max(0, int(v)) for v in box)
    return page.crop((x1, y1, x2, y2))


def _best_box(detections: list[dict], labels: tuple[str, ...]) -> dict | None:
    candidates = [d for d in detections if d["label"] in labels]
    return max(candidates, key=lambda d: d["confidence"]) if candidates else None


def _signature_boxes(detections: list[dict]) -> list[dict]:
    return [detection for detection in detections if detection["label"] == "signature"]


def _stamp_boxes(detections: list[dict]) -> list[dict]:
    return [detection for detection in detections if detection["label"] in ("stamp", "logo")]


def _page_images(pages_bgr: list) -> list[Image.Image]:
    import cv2
    return [Image.fromarray(cv2.cvtColor(page, cv2.COLOR_BGR2RGB)) for page in pages_bgr]


def _ocr_context(page: dict | None) -> dict:
    if not page or page.get("skipped"):
        return {}
    fields = page.get("fields") or {}
    return {
        "ocr_text": page.get("text", ""),
        "ocr_words": [
            {
                "text": word["text"],
                "conf": word["conf"],
                "bbox": [word["left"], word["top"], word["width"], word["height"]],
            }
            for word in page.get("words", [])
        ],
        "fields": {
            key: field.get("value")
            for key, field in fields.items()
            if field.get("matched")
        },
    }


def _detected_issue_date(context: dict, explicit: str | None) -> str | None:
    if explicit:
        return explicit
    fields = context.get("fields", {})
    for key in ("issue_date", "date_issued", "registration_date"):
        if fields.get(key):
            return str(fields[key])
    return None


def _forensic_unavailable_flags(stage: dict) -> list[str]:
    return [
        f"tamper_{name}_unavailable"
        for name, result in stage.get("techniques", {}).items()
        if result.get("skipped") or result.get("score") is None
    ]


def _aggregate_classification(page_stages: list[dict]) -> dict:
    completed = [stage for stage in page_stages if stage.get("status") == "completed"]
    if not completed:
        return page_stages[0] if page_stages else _skipped("no_pages")
    fake = [stage for stage in completed if stage.get("label") == "fake"]
    pool = fake or completed
    return min(pool, key=lambda stage: float(stage.get("authenticity", 1.0)))


def _aggregate_detection(page_stages: list[dict]) -> dict:
    completed = [stage for stage in page_stages if stage.get("status") == "completed"]
    if not completed:
        return page_stages[0] if page_stages else _skipped("no_pages")
    detections: list[dict] = []
    flags: list[str] = []
    for index, stage in enumerate(page_stages, start=1):
        for detection in stage.get("detections", []):
            detections.append({**detection, "page_index": index})
        flags.extend(stage.get("flags", []))
    return _completed({"detections": detections, "flags": list(dict.fromkeys(flags))})


def _aggregate_verification(page_stages: list[dict], score_key: str) -> dict:
    scored = [
        stage for stage in page_stages
        if stage.get("status") == "completed" and stage.get(score_key) is not None
    ]
    if scored:
        return min(scored, key=lambda stage: float(stage[score_key]))
    completed = [stage for stage in page_stages if stage.get("status") == "completed"]
    if completed:
        return completed[0]
    return page_stages[0] if page_stages else _skipped("no_pages")


def _aggregate_signature_verification(page_stages: list[dict]) -> dict:
    comparisons = [
        {**comparison, "page_index": page_index}
        for page_index, stage in enumerate(page_stages, start=1)
        for comparison in stage.get("comparisons", [])
    ]
    completed = [stage for stage in page_stages if stage.get("status") == "completed"]
    if comparisons:
        selected = min(comparisons, key=lambda comparison: float(comparison.get("similarity", 0.0)))
        return {**selected, "comparisons": comparisons}
    if completed:
        return {**completed[0], "comparisons": []}
    return page_stages[0] if page_stages else _skipped("no_pages")


def _aggregate_stamp_verification(page_stages: list[dict]) -> dict:
    comparisons = [
        {**comparison, "page_index": page_index}
        for page_index, stage in enumerate(page_stages, start=1)
        for comparison in stage.get("comparisons", [])
    ]
    scored = [
        comparison for comparison in comparisons
        if comparison.get("similarity_score") is not None
    ]
    if scored:
        selected = max(
            scored,
            key=lambda comparison: (
                float(comparison["similarity_score"]),
                float(comparison.get("confidence", 0.0)),
                -int(comparison["page_index"]),
            ),
        )
        return {**selected, "comparisons": comparisons}

    completed = [stage for stage in page_stages if stage.get("status") == "completed"]
    if completed:
        return {**completed[0], "comparisons": comparisons}
    return page_stages[0] if page_stages else _skipped("no_pages")


def _aggregate_tamper(page_stages: list[dict]) -> dict:
    completed = [stage for stage in page_stages if stage.get("status") == "completed"]
    if not completed:
        return page_stages[0] if page_stages else _skipped("no_pages")
    worst = max(completed, key=lambda stage: float(stage.get("tamper_score", 0.0)))
    flags = [flag for stage in completed for flag in stage.get("flags", [])]
    return {
        **worst,
        "tamper_score": max(float(stage.get("tamper_score", 0.0)) for stage in completed),
        "tamper_authenticity": min(
            float(stage.get("tamper_authenticity", 1.0)) for stage in completed
        ),
        "tamper_confidence": max(float(stage.get("tamper_confidence", 0.0)) for stage in completed),
        "hard_flag": any(bool(stage.get("hard_flag")) for stage in completed),
        "tamper_passed": all(bool(stage.get("tamper_passed")) for stage in completed),
        "flags": list(dict.fromkeys(flags)),
        "page_count": len(completed),
    }


def _aggregate_ocr(pages: list[dict]) -> tuple[dict, list[str]]:
    completed = [page for page in pages if page.get("status") == "completed"]
    if not completed:
        return (pages[0] if pages else _skipped("no_pages")), []

    selected_fields: dict[str, dict] = {}
    values: dict[str, set[str]] = {}
    permit_evidence: dict[str, Any] | None = None
    for page_index, page in enumerate(completed, start=1):
        page_permit = page.get("business_permit")
        if isinstance(page_permit, dict):
            candidate = {**page_permit, "source_page": page_index}
            if permit_evidence is None:
                permit_evidence = {**candidate, "fields": {}, "conflicts": {}}
            elif candidate.get("issuer_city_canonical") and not permit_evidence.get("issuer_city_canonical"):
                permit_evidence["issuer_city_canonical"] = candidate["issuer_city_canonical"]
                permit_evidence["issuer_city_raw"] = candidate.get("issuer_city_raw")
                permit_evidence["issuer_city_confidence"] = candidate.get("issuer_city_confidence")
                permit_evidence["layout_key"] = candidate.get("layout_key")
            for key, value in (candidate.get("fields") or {}).items():
                if not isinstance(value, dict):
                    continue
                current = permit_evidence["fields"].get(key)
                if (current is not None and current.get("matched") and value.get("matched")
                        and str(current.get("value")) != str(value.get("value"))):
                    permit_evidence["conflicts"].setdefault(key, []).extend([
                        current.get("value"), value.get("value"),
                    ])
                current_rank = (
                    bool(current.get("matched")),
                    101.0 if current.get("confidence") is None else float(current.get("confidence")),
                ) if current else (False, -1.0)
                candidate_rank = (
                    bool(value.get("matched")),
                    101.0 if value.get("confidence") is None else float(value.get("confidence")),
                )
                if current is None or candidate_rank > current_rank:
                    permit_evidence["fields"][key] = {**value, "source_page": page_index}
        for key, field in (page.get("fields") or {}).items():
            if not field.get("matched") or field.get("value") in (None, ""):
                continue
            values.setdefault(key, set()).add(str(field["value"]).strip())
            current = selected_fields.get(key)
            confidence = field.get("confidence")
            rank = 101.0 if confidence is None else float(confidence)
            current_conf = current.get("confidence") if current else None
            current_rank = -1.0 if current is None else (101.0 if current_conf is None else float(current_conf))
            if rank > current_rank:
                selected_fields[key] = {**field, "source_page": page_index}

    conflicts = [key for key, found in values.items() if len(found) > 1]
    quality_flags = [
        flag for page in completed for flag in (page.get("quality") or {}).get("flags", [])
    ]
    if conflicts:
        quality_flags.append("ocr_field_conflict")

    required = [field for field in selected_fields.values() if field.get("required")]
    required_total = max(
        ((page.get("quality") or {}).get("required_total", 0) for page in completed), default=0
    )
    required_matched = len(required)
    scores = [float((page.get("quality") or {}).get("text_validation_score", 0.0)) for page in completed]
    mean_confidences = [float((page.get("quality") or {}).get("mean_confidence", 0.0)) for page in completed]
    aggregate_page = _completed({
        "text": "\n\n".join(page.get("text", "") for page in completed if page.get("text")),
        "words": [word for page in completed for word in page.get("words", [])],
        "fields": selected_fields or None,
        "roi": None,
        "quality": {
            "mean_confidence": round(max(mean_confidences, default=0.0), 1),
            "required_total": required_total,
            "required_matched": required_matched,
            "text_validation_score": max(scores, default=0.0),
            "passes_text_validation": any(
                bool((page.get("quality") or {}).get("passes_text_validation")) for page in completed
            ),
            "flags": list(dict.fromkeys(quality_flags)),
            "conflicting_fields": conflicts,
        },
    })
    aggregate = {
        "page_count": len(completed),
        "pages": [aggregate_page],
        "source_pages": completed,
    }
    if permit_evidence is not None:
        permit_evidence["conflicts"] = {
            key: list(dict.fromkeys(v for v in values if v not in (None, "")))
            for key, values in permit_evidence.get("conflicts", {}).items()
        }
        permit_evidence["fields"] = {
            key: {**value, "source_page": value.get("source_page") or permit_evidence.get("source_page")}
            for key, value in (permit_evidence.get("fields") or {}).items()
        }
        aggregate["business_permit"] = permit_evidence
    return _completed(aggregate), (["ocr_field_conflict"] if conflicts else [])


@router.post("/v1/validate")
async def validate(
    request: Request,
    file: UploadFile = File(...),
    template: str = Form("bir"),
    document_type: str | None = Form(None),
    city: str | None = Form(None),
    issuer_scope: str | None = Form(None),
    stamp_references: str | None = Form(None),
    issue_date: str | None = Form(None),
    signature_reference: str | None = Form(None),
    stamp_reference: str | None = Form(None),
    forensics: str | None = Form(None),
    settings_snapshot: str | None = Form(None),
) -> dict:
    request_started = time.perf_counter()
    registry: ModelRegistry = request.app.state.registry
    settings, _, settings_hash = _parse_settings_snapshot(
        settings_snapshot, request.app.state.settings
    )

    normalized_type = document_type.strip().lower() if document_type else None
    unsupported = normalized_type is not None and normalized_type not in SUPPORTED_DOCUMENT_TYPES
    effective_template = (
        "none" if unsupported else SUPPORTED_DOCUMENT_TYPES.get(normalized_type, template)
    )
    if effective_template not in TEMPLATES:
        raise HTTPException(
            status_code=422,
            detail=f"Unknown template '{effective_template}'; use one of {TEMPLATES}.",
        )

    signature_dimension = registry.output_dimension("siamese")
    stamp_dimension = registry.output_dimension("stamp")
    sig_reference = (
        emb.parse_reference(signature_reference, "signature_reference", signature_dimension)
        if signature_reference else None
    )
    single_logo_reference = (
        emb.parse_reference(stamp_reference, "stamp_reference", stamp_dimension)
        if stamp_reference else None
    )
    logo_references = parse_reference_map(stamp_references, stamp_dimension)
    forensics_ctx = _parse_forensics(forensics)

    data = await file.read()
    page_results: list[dict] = []
    timing_totals: dict[str, float] = {
        name: 0.0 for name in ("classification", "ocr", "detection", "signature", "stamp", "tamper")
    }

    with tempfile.TemporaryDirectory(prefix="advs_validate_") as tmp_dir:
        original = save_upload(file, data, tmp_dir, settings)
        pages_bgr, page_paths = load_pages(original, tmp_dir, settings, strict=True)
        pages = _page_images(pages_bgr)
        if not pages:
            raise HTTPException(status_code=422, detail="The upload did not produce a readable page.")

        try:
            raw_ocr, ocr_ms = _timed(
                lambda: run_ocr_stage(
                    original, settings, effective_template, registry=registry, city=city
                )
            )
            ocr_pages = [_completed(page) for page in raw_ocr.get("pages", [])]
        except HTTPException as exc:
            reason = exc.detail.get("reason") if isinstance(exc.detail, dict) else str(exc.detail)
            ocr_pages = [_skipped(reason) for _ in pages]
            ocr_ms = 0.0
        except Exception as exc:
            logger.exception("ocr stage failed")
            ocr_pages = [_failed(f"error: {exc}") for _ in pages]
            ocr_ms = 0.0
        timing_totals["ocr"] = ocr_ms

        for page_index, page in enumerate(pages, start=1):
            stages: dict[str, dict] = {}
            flags: list[str] = []
            page_timings: dict[str, float] = {"ocr": round(ocr_ms / len(pages), 3)}
            page_ocr = ocr_pages[page_index - 1] if page_index <= len(ocr_pages) else _skipped("ocr_page_missing")
            stages["ocr"] = page_ocr
            flags.extend((page_ocr.get("quality") or {}).get("flags", []))
            context = _ocr_context(page_ocr)

            classifier = registry.get("classifier")
            if unsupported:
                stages["classification"] = _skipped("unsupported_document_type")
                flags.append("unsupported_document_type")
                page_timings["classification"] = 0.0
            elif classifier is None:
                stages["classification"] = _skipped("model_not_loaded")
                page_timings["classification"] = 0.0
            else:
                try:
                    result, elapsed = _timed(lambda: run_classification(
                        classifier, page, settings.classification_confidence_threshold
                    ))
                    stages["classification"] = _completed(result)
                    page_timings["classification"] = elapsed
                    if not result["passed_threshold"]:
                        flags.append("low_classification_confidence")
                    flags.extend(classification_flags(
                        result, normalized_type, classifier.get("class_names") or []
                    ))
                except Exception as exc:
                    logger.exception("classification stage failed on page %s", page_index)
                    stages["classification"] = _failed(f"error: {exc}")
                    page_timings["classification"] = 0.0
                    flags.append("classification_failed")

            detections: list[dict] = []
            detector = registry.get("detector")
            if detector is None:
                stages["detection"] = _skipped("model_not_loaded")
                page_timings["detection"] = 0.0
            else:
                try:
                    result, elapsed = _timed(lambda: run_detection(detector, page, settings))
                    stages["detection"] = _completed(result)
                    detections = result["detections"]
                    flags.extend(result["flags"])
                    page_timings["detection"] = elapsed
                except Exception as exc:
                    logger.exception("detection stage failed on page %s", page_index)
                    stages["detection"] = _failed(f"error: {exc}")
                    page_timings["detection"] = 0.0
                    flags.append("detection_failed")

            siamese = registry.get("siamese")
            signature_boxes = _signature_boxes(detections)
            if siamese is None:
                stages["signature"] = _skipped("model_not_loaded")
                page_timings["signature"] = 0.0
            elif sig_reference is None:
                stages["signature"] = _skipped("no_reference_embedding")
                page_timings["signature"] = 0.0
            elif stages["detection"].get("status") != "completed":
                stages["signature"] = _skipped("detection_unavailable")
                page_timings["signature"] = 0.0
            elif not signature_boxes:
                stages["signature"] = _skipped("no_signature_detected")
                page_timings["signature"] = 0.0
            else:
                try:
                    comparisons = []
                    elapsed_total = 0.0
                    for signature_box in signature_boxes:
                        result, elapsed = _timed(lambda box=signature_box: run_signature_verify(
                            siamese, _crop(page, box["box"]), sig_reference, settings
                        ))
                        comparisons.append({
                            **result,
                            "box": signature_box["box"],
                            "confidence": signature_box["confidence"],
                        })
                        elapsed_total += elapsed
                    selected = min(comparisons, key=lambda comparison: float(comparison["similarity"]))
                    stages["signature"] = _completed({
                        **selected,
                        "comparisons": comparisons,
                    })
                    page_timings["signature"] = elapsed_total
                except Exception as exc:
                    logger.exception("signature stage failed on page %s", page_index)
                    stages["signature"] = _failed(f"error: {exc}")
                    page_timings["signature"] = 0.0
                    flags.append("signature_failed")

            stamp_model = registry.get("stamp")
            stamp_boxes = _stamp_boxes(detections)
            permit_evidence = page_ocr.get("business_permit") or {}
            detected_city = canonical_city(
                permit_evidence.get("issuer_city_canonical")
                or context.get("fields", {}).get("city_issued")
            )
            resolved_city = canonical_city(city) or detected_city
            scope = issuer_scope.lower() if issuer_scope else None
            if scope is None or scope in ("none", "null"):
                stages["stamp"] = _skipped("no_issuer_logo")
                page_timings["stamp"] = 0.0
                flags.append("no_issuer_logo")
            elif stamp_model is None:
                stages["stamp"] = _skipped("model_not_loaded")
                page_timings["stamp"] = 0.0
            elif stages["detection"].get("status") != "completed":
                stages["stamp"] = _skipped("detection_unavailable")
                page_timings["stamp"] = 0.0
            elif not stamp_boxes:
                stages["stamp"] = _skipped("no_stamp_detected")
                page_timings["stamp"] = 0.0
            else:
                try:
                    curated_references = []
                    try:
                        curated_references = embed_candidates(
                            stamp_model,
                            resolve_candidates(normalized_type, scope, resolved_city),
                        )
                    except CatalogError as exc:
                        logger.warning("curated issuer references unavailable: %s", exc)

                    logo_reference = None
                    reference_flag = None
                    if not curated_references:
                        logo_reference, reference_flag = resolve_issuer_reference(
                            logo_references, single_logo_reference, scope, resolved_city
                        )
                    comparisons = []
                    elapsed_total = 0.0
                    for stamp_box in stamp_boxes:
                        result, elapsed = _timed(lambda box=stamp_box: run_stamp_verify(
                            stamp_model,
                            _crop(page, box["box"]),
                            logo_reference,
                            settings,
                            normalized_type,
                            resolved_city,
                            classifier=registry.get("stamp_classifier"),
                            missing_reason=reference_flag or "unreferenced_logo",
                            reference_candidates=curated_references,
                        ))
                        comparisons.append({
                            **result,
                            "box": stamp_box["box"],
                            "confidence": stamp_box["confidence"],
                        })
                        elapsed_total += elapsed

                        if result.get("reason"):
                            flags.append(result["reason"])
                        if result.get("stamp_tampered") is True:
                            flags.append("stamp_tampered")
                        if result.get("stamp_tampered") is None:
                            flags.append("stamp_tamper_unavailable")

                    scored = [
                        comparison for comparison in comparisons
                        if comparison.get("similarity_score") is not None
                    ]
                    selected = max(
                        scored,
                        key=lambda comparison: (
                            float(comparison["similarity_score"]),
                            float(comparison.get("confidence", 0.0)),
                        ),
                    ) if scored else comparisons[0]
                    stages["stamp"] = _completed({
                        **selected,
                        "comparisons": comparisons,
                    })
                    page_timings["stamp"] = elapsed_total
                except Exception as exc:
                    logger.exception("stamp stage failed on page %s", page_index)
                    stages["stamp"] = _failed(f"error: {exc}")
                    page_timings["stamp"] = 0.0
                    flags.append("stamp_failed")

            try:
                page_path = page_paths[page_index - 1] if page_index <= len(page_paths) else original
                result, elapsed = _timed(lambda: run_tamper_stage(
                    original,
                    [page_path],
                    {
                        **context,
                        "issue_date": _detected_issue_date(context, issue_date),
                        **forensics_ctx,
                    },
                ))
                unavailable = _forensic_unavailable_flags(result)
                result["flags"] = list(dict.fromkeys(result.get("flags", []) + unavailable))
                stages["tamper"] = _completed(result)
                flags.extend(result["flags"])
                page_timings["tamper"] = elapsed
            except Exception as exc:
                logger.exception("tamper stage failed on page %s", page_index)
                stages["tamper"] = _failed(f"error: {exc}")
                page_timings["tamper"] = 0.0
                flags.append("tamper_failed")

            for name, elapsed in page_timings.items():
                if name != "ocr":
                    timing_totals[name] += elapsed
            page_results.append({
                "page_index": page_index,
                "status": "completed",
                "stages": stages,
                "flags": list(dict.fromkeys(flags)),
                "timings": {
                    **{f"{key}_ms": value for key, value in page_timings.items()},
                    "total_ms": round(sum(page_timings.values()), 3),
                },
            })

    ocr_stage, ocr_flags = _aggregate_ocr([page["stages"]["ocr"] for page in page_results])
    stages = {
        "classification": _aggregate_classification([
            page["stages"]["classification"] for page in page_results
        ]),
        "ocr": ocr_stage,
        "detection": _aggregate_detection([page["stages"]["detection"] for page in page_results]),
        "signature": _aggregate_signature_verification(
            [page["stages"]["signature"] for page in page_results]
        ),
        "stamp": _aggregate_stamp_verification(
            [page["stages"]["stamp"] for page in page_results]
        ),
        "tamper": _aggregate_tamper([page["stages"]["tamper"] for page in page_results]),
    }
    flags = [flag for page in page_results for flag in page["flags"]] + ocr_flags
    artifact_report = registry.artifact_report()

    business_permit = ocr_stage.get("business_permit") if isinstance(ocr_stage, dict) else None

    response = {
        "schema_version": SCHEMA_VERSION,
        "status": "completed",
        "stages": stages,
        "flags": list(dict.fromkeys(flags)),
        "pages": page_results,
        "models": artifact_report["models"],
        "settings_hash": settings_hash,
        "timings": {
            "total_ms": round((time.perf_counter() - request_started) * 1000, 3),
            "stages": {
                f"{key}_ms": round(value, 3) for key, value in timing_totals.items()
            },
        },
    }
    if isinstance(business_permit, dict):
        response["business_permit"] = business_permit
    return response
