"""POST /v1/stamp/{embed,verify} — Stage 4b, EfficientNet issuer-logo verification.

Stateless and ISSUER-keyed: reference logo vectors live in Laravel's
``logo_references`` (by document_type, +city for LGU) and are passed back here
for verify. /embed exists for the seeding path — Laravel's EnrollReferenceJob
computes the issuer vector from the first officer-approved document's crop.

No ``reference_vector`` in the request means the issuer has no reference yet
-> ``{"match": false, "reason": "unreferenced_logo"}`` (§5 Stage 4b); callers
with ``issuer_scope = null`` document types should not call this at all
(``no_issuer_logo`` is decided Laravel-side).

Both routes return 503 ``model_not_loaded`` until training produces the
EfficientNet weights (STAMP_MODEL_PATH — defaults to
``models/efficientnet_feature_extractor.h5``, what train_stamp.py saves).
"""

from __future__ import annotations

import base64
import io
import json
import logging
import tempfile

from fastapi import APIRouter, File, Form, HTTPException, Request, UploadFile
from PIL import Image, UnidentifiedImageError

from .. import embedding as emb
from ..config import Settings
from ..logo_catalog import CatalogError, embed_candidates, embed_stamp, resolve_candidates
from ..schemas import StampEmbedResponse, StampVerifyResponse
from ..uploads import load_pages, save_upload

router = APIRouter()
logger = logging.getLogger("advs.api.stamp")


def _parse_box(raw: str) -> list[int]:
    """Parse a JSON ``[x1, y1, x2, y2]`` detection box, clamped to non-negative ints."""
    try:
        parsed = json.loads(raw)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=f"box must be JSON: {exc}") from exc

    if not isinstance(parsed, list) or len(parsed) != 4:
        raise HTTPException(status_code=422, detail="box must be a JSON array of 4 numbers [x1,y1,x2,y2].")

    try:
        return [max(0, int(float(value))) for value in parsed]
    except (TypeError, ValueError) as exc:
        raise HTTPException(status_code=422, detail=f"box values must be numbers: {exc}") from exc


def _crop_box(page: Image.Image, box: list[int]) -> Image.Image:
    """Crop ``box`` from ``page``, ordered and clipped to the page bounds (mirrors
    validate.py's Stage-4b crop so both paths see identical pixels)."""
    x1, y1, x2, y2 = box
    left, right = min(x1, x2), max(x1, x2)
    top, bottom = min(y1, y2), max(y1, y2)

    return page.crop((
        min(left, page.width), min(top, page.height),
        min(right, page.width), min(bottom, page.height),
    ))


def run_tamper_check(classifier, vector: list[float], threshold: float) -> dict:
    """EfficientNet texture verdict for one logo crop (§5 Stage 4b).

    train_stamp.py fits a LogisticRegression over the 1280-D feature vector with
    class 1 = genuine wet ink and class 0 = a photocopied/scanned/edited
    reproduction, so column 1 of predict_proba is the genuine probability.
    """
    import numpy as np

    probabilities = np.asarray(classifier.predict_proba(np.asarray([vector], dtype=np.float64)))
    genuine = float(probabilities[0][1])

    return {"stamp_tampered": genuine < threshold, "genuine_probability": round(genuine, 6)}


def run_stamp_verify(
    model,
    image: Image.Image,
    reference: list[float] | None,
    settings: Settings,
    document_type: str | None = None,
    city: str | None = None,
    classifier=None,
    missing_reason: str = "unreferenced_logo",
    reference_candidates: list[dict] | None = None,
) -> dict:
    """Stage 4b: texture check first, issuer comparison second.

    The crop is ALWAYS embedded, reference or not — §5 Stage 4b runs the
    wet-ink-vs-reproduction check "even before any reference exists", so the
    fraud signal does not wait on an issuer being seeded. ``missing_reason``
    lets the caller say WHY there is no reference (no issuer seeded yet, or an
    LGU city that OCR could not read) without losing that check.
    """
    vector = embed_stamp(model, image)
    tamper = (
        run_tamper_check(classifier, vector, settings.stamp_tamper_threshold)
        if classifier is not None
        else {"stamp_tampered": None, "genuine_probability": None}
    )
    base = {"document_type": document_type, "city": city, **tamper}

    candidates = list(reference_candidates or [])
    if not candidates and reference is not None:
        candidates = [{
            "key": "enrolled-reference",
            "label": "Reference (enrolled)",
            "source": "enrolled",
            "city": city,
            "vector": reference,
        }]

    if not candidates:
        return {**base, "match": False, "reason": missing_reason,
                "similarity_score": None, "threshold": None,
                "reference_source": None, "best_reference_key": None,
                "reference_matches": []}

    threshold = settings.resolved_stamp_similarity_threshold()
    matches = []
    for candidate in candidates:
        candidate_vector = candidate.get("vector")
        if not isinstance(candidate_vector, list) or not candidate_vector:
            continue
        emb.require_same_length(candidate_vector, vector, "reference_vector")
        similarity = emb.cosine_similarity(vector, candidate_vector)
        matches.append({
            "key": str(candidate.get("key") or "reference"),
            "label": str(candidate.get("label") or "Issuer reference"),
            "source": str(candidate.get("source") or "enrolled"),
            "city": candidate.get("city"),
            "similarity_score": similarity,
            "match": similarity >= threshold,
        })

    if not matches:
        return {**base, "match": False, "reason": missing_reason,
                "similarity_score": None, "threshold": None,
                "reference_source": None, "best_reference_key": None,
                "reference_matches": []}

    best = max(matches, key=lambda candidate: candidate["similarity_score"])

    return {
        **base,
        "match": best["match"],
        "similarity_score": best["similarity_score"],
        "threshold": threshold,
        "reason": None,
        "reference_source": best["source"],
        "best_reference_key": best["key"],
        "reference_matches": matches,
    }


async def _read_crop(file: UploadFile) -> Image.Image:
    data = await file.read()
    try:
        image = Image.open(io.BytesIO(data))
        image.load()
        return image
    except (UnidentifiedImageError, OSError) as exc:
        raise HTTPException(status_code=422, detail=f"Unreadable image upload: {exc}") from exc


@router.post("/v1/stamp/embed", response_model=StampEmbedResponse)
async def stamp_embed(
    request: Request,
    file: UploadFile = File(...),
    box: str | None = Form(None),
) -> dict:
    """Embed a logo crop for the issuer-reference seeding path (EnrollReferenceJob).

    Without ``box`` the upload is embedded as-is (a caller-side crop) — unchanged
    behaviour. With ``box`` (a JSON ``[x1, y1, x2, y2]``) the FULL document is sent
    and cropped here, because that is where the coordinates came from: Stage 4's
    detection boxes are in the space of the page image this service rendered, so
    for a PDF only this service can reproduce that space. The crop is returned
    alongside the vector so the caller can persist exactly what was embedded.
    """
    model = request.app.state.registry.require("stamp")
    settings: Settings = request.app.state.settings

    if box is None:
        return {"vector": embed_stamp(model, await _read_crop(file))}

    region = _parse_box(box)
    data = await file.read()
    with tempfile.TemporaryDirectory(prefix="advs_stamp_embed_") as tmp_dir:
        original = save_upload(file, data, tmp_dir)
        pages_bgr, _ = load_pages(original, tmp_dir, settings)
        if not pages_bgr:
            raise HTTPException(status_code=422, detail="Could not render a page image from the upload.")

        import cv2

        page = Image.fromarray(cv2.cvtColor(pages_bgr[0], cv2.COLOR_BGR2RGB))

    crop = _crop_box(page, region)
    if crop.width == 0 or crop.height == 0:
        raise HTTPException(status_code=422, detail=f"Box {region} is empty within the {page.size} page.")

    buffer = io.BytesIO()
    crop.convert("RGB").save(buffer, format="PNG")

    return {
        "vector": embed_stamp(model, crop),
        "crop_png_base64": base64.b64encode(buffer.getvalue()).decode("ascii"),
    }


@router.post("/v1/stamp/verify", response_model=StampVerifyResponse)
async def stamp_verify(
    request: Request,
    file: UploadFile = File(...),
    reference_vector: str | None = Form(None),
    document_type: str | None = Form(None),
    city: str | None = Form(None),
    issuer_scope: str | None = Form(None),
) -> dict:
    model = request.app.state.registry.require("stamp")
    reference = emb.parse_reference(reference_vector, "reference_vector") if reference_vector else None
    image = await _read_crop(file)
    curated = []
    try:
        curated = embed_candidates(
            model,
            resolve_candidates(document_type, issuer_scope, city),
        )
    except CatalogError as exc:
        logger.warning("curated issuer references unavailable: %s", exc)

    return run_stamp_verify(
        model, image, reference, request.app.state.settings, document_type, city,
        classifier=request.app.state.registry.get("stamp_classifier"),
        reference_candidates=curated,
    )
