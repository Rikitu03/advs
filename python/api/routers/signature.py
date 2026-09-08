"""POST /v1/signature/{embed,verify} — Stage 4a, Siamese signature verification.

Stateless: the per-vendor 128-D reference embedding is enrolled at vendor
REGISTRATION and stored by Laravel; callers pass it back here for verify.
/embed exists precisely for that registration-time enrollment.

Both routes return 503 ``model_not_loaded`` until training produces the
Siamese weights (SIAMESE_MODEL_PATH — defaults to the encoder,
``models/siamese_encoder.h5``, which is what train_signature.py saves).
"""

from __future__ import annotations

import io
import logging
import tempfile
from pathlib import Path

from fastapi import APIRouter, File, Form, HTTPException, Request, UploadFile
from PIL import Image, ImageOps, UnidentifiedImageError

from .. import embedding as emb
from ..config import Settings
from ..schemas import SignatureEmbedResponse, SignatureEnrollResponse, SignatureVerifyResponse
from ..uploads import save_upload
from .detect import run_detection

logger = logging.getLogger("advs.api.signature")
router = APIRouter()

# Safety ceiling on how many signature crops one enrollment photo may embed. The
# registration flow expects exactly 3 (Laravel enforces the count); this only
# bounds the work if the detector floods the page with boxes.
MAX_ENROLL_SIGNATURES = 10
ENROLL_SIGNATURE_IOU_THRESHOLD = 0.5


def _normalize_contrast(image: Image.Image) -> Image.Image:
    """Enhance contrast for blank-paper signature photos.

    Registration photos are often low-contrast (dark ink on white paper).
    CLAHE (Contrast Limited Adaptive Histogram Equalization) on the luminance
    channel can make signature strokes more distinct to the detector without
    altering the signature geometry. This is a lightweight preprocessing step
    that only runs when the initial detection finds no signatures.
    """
    from PIL import ImageEnhance, ImageFilter

    # Convert to grayscale for luminance-based enhancement
    enhanced = image.convert("L")
    # Apply autocontrast to stretch the histogram
    enhanced = ImageEnhance.Contrast(enhanced).enhance(1.5)
    # Sharpen to emphasize strokes
    enhanced = enhanced.filter(ImageFilter.SHARPEN)
    # Convert back to RGB for the detector
    return enhanced.convert("RGB")


def _resnet_preprocess(batch):
    # Phase 6 trains the towers on a ResNet50 base -> resnet50 preprocessing.
    from tensorflow.keras.applications.resnet50 import preprocess_input

    return preprocess_input(batch)


def embed_signature(model, image: Image.Image) -> list[float]:
    return emb.embed(model, image, _resnet_preprocess)


def run_signature_verify(model, image: Image.Image, reference: list[float], settings: Settings) -> dict:
    vector = embed_signature(model, image)
    emb.require_same_length(reference, vector, "reference_embedding")

    distance = emb.euclidean(vector, reference)
    threshold = settings.resolved_signature_distance_threshold()

    return {
        # No empirical threshold yet (§9: determined during model validation)
        # -> report the distance and let the caller decide; never invent one.
        "match": (distance <= threshold) if threshold is not None else None,
        "distance": distance,
        "similarity": 1.0 / (1.0 + distance),
        "threshold": threshold,
        "embedding": vector,
    }


async def _read_crop(file: UploadFile) -> Image.Image:
    data = await file.read()
    return _decode_image(data)


def _decode_image(data: bytes) -> Image.Image:
    try:
        image = Image.open(io.BytesIO(data))
        image.load()
        return image
    except (UnidentifiedImageError, OSError) as exc:
        raise HTTPException(status_code=422, detail=f"Unreadable image upload: {exc}") from exc


def _crop_box(page: Image.Image, box: list[float]) -> Image.Image:
    x1, y1, x2, y2 = (max(0, int(v)) for v in box)
    return page.crop((x1, y1, x2, y2))


def _intersection_over_union(first: list[float], second: list[float]) -> float:
    first_x1, first_y1, first_x2, first_y2 = first
    second_x1, second_y1, second_x2, second_y2 = second
    intersection_width = max(0.0, min(first_x2, second_x2) - max(first_x1, second_x1))
    intersection_height = max(0.0, min(first_y2, second_y2) - max(first_y1, second_y1))
    intersection = intersection_width * intersection_height
    first_area = max(0.0, first_x2 - first_x1) * max(0.0, first_y2 - first_y1)
    second_area = max(0.0, second_x2 - second_x1) * max(0.0, second_y2 - second_y1)
    union = first_area + second_area - intersection
    return intersection / union if union else 0.0


def _select_signature_boxes(detections: list[dict]) -> list[dict]:
    selected: list[dict] = []
    for detection in sorted(
        (d for d in detections if d["label"] == "signature"),
        key=lambda d: d["confidence"],
        reverse=True,
    ):
        if all(
            _intersection_over_union(detection["box"], chosen["box"])
            < ENROLL_SIGNATURE_IOU_THRESHOLD
            for chosen in selected
        ):
            selected.append(detection)
        if len(selected) == MAX_ENROLL_SIGNATURES:
            break
    return selected


def _enrollment_forensics(original: Path) -> dict:
    """LENIENT edited/pasted-on-top check for registration (§5 Stage 4a authenticity
    gate). Runs only the two pixel/provenance techniques that evidence a real edit —
    metadata (image-editor signature, modify-after-issue) and copy-move (a signature
    cloned/placed on top) — and ignores the soft heuristics (ELA, font, cross-ref)
    plus stripped-EXIF, which false-positive on a genuine phone photo of bond paper.

    These are also exactly ``forensics.HARD_FLAG_TECHNIQUES`` — the same two
    techniques allowed to decide alone in the document pipeline — and stripped-EXIF
    is no longer a flag at source (``forensics.metadata``), so no filtering is
    needed here.
    """
    from forensics import copy_move as fcopy_move  # lazy: heavy cv2/PIL deps
    from forensics import metadata as fmetadata

    meta = fmetadata.analyze(str(original))
    clone = fcopy_move.analyze(str(original))

    reasons = list(meta.get("flags", [])) + list(clone.get("flags", []))
    return {
        "hard_flag": bool(reasons),
        "reasons": reasons,
        "techniques": {"metadata": meta, "copy_move": clone},
    }


@router.post("/v1/signature/embed", response_model=SignatureEmbedResponse)
async def signature_embed(request: Request, file: UploadFile = File(...)) -> dict:
    model = request.app.state.registry.require("siamese")
    image = await _read_crop(file)
    return {"embedding": embed_signature(model, image)}


@router.post("/v1/signature/verify", response_model=SignatureVerifyResponse)
async def signature_verify(
    request: Request,
    file: UploadFile = File(...),
    reference_embedding: str = Form(...),
) -> dict:
    model = request.app.state.registry.require("siamese")
    reference = emb.parse_reference(reference_embedding, "reference_embedding")
    image = await _read_crop(file)
    return run_signature_verify(model, image, reference, request.app.state.settings)


@router.post("/v1/signature/enroll", response_model=SignatureEnrollResponse)
async def signature_enroll(
    request: Request,
    file: UploadFile = File(...),
    signature_enroll_detection_confidence: float | None = Form(None),
) -> dict:
    """Registration-time signature reference capture (§5 Stage 4a).

    The vendor uploads one photo of THREE signatures on bond paper. This detects the
    signature regions (YOLOv8), embeds each (128-D Siamese), reports the inter-signature
    consistency (mean pairwise cosine), a unit-norm ``centroid`` reference vector, and a
    lenient edited/pasted-on-top forensics summary. It reports raw measurements only —
    the count/consistency thresholds and the accept/reject decision live in Laravel's
    ``SignatureEnrollmentService`` (mirroring how /v1/validate leaves risk scoring to the
    orchestrator).
    """
    registry = request.app.state.registry
    settings: Settings = request.app.state.settings
    detection_confidence = (
        settings.signature_enroll_detection_confidence
        if signature_enroll_detection_confidence is None
        else signature_enroll_detection_confidence
    )
    if not 0.0 <= detection_confidence <= 1.0:
        raise HTTPException(
            status_code=422,
            detail="signature_enroll_detection_confidence must be between 0 and 1.",
        )
    detector = registry.get("signature_enroll_detector") or registry.require("detector")
    siamese = registry.require("siamese")
    
    detector_name = "signature_enroll_detector" if registry.get("signature_enroll_detector") else "detector"

    data = await file.read()
    image = ImageOps.exif_transpose(_decode_image(data))
    
    logger.info(
        "signature_enroll: image=%dx%d, detector=%s, confidence=%.2f, imgsz=%d",
        image.width, image.height, detector_name,
        detection_confidence,
        settings.signature_enroll_detection_imgsz,
    )

    detection = run_detection(
        detector,
        image,
        settings,
        confidence=detection_confidence,
        imgsz=settings.signature_enroll_detection_imgsz,
    )
    
    signature_boxes = _select_signature_boxes(detection["detections"])
    
    logger.info(
        "signature_enroll: raw_detections=%d, signature_detections=%d",
        len(detection["detections"]), len(signature_boxes)
    )
    
    # Preprocessing fallback: if no signatures detected, try contrast enhancement
    # This helps with low-contrast blank-paper photos that are out-of-distribution
    # for the document-trained detector.
    if not signature_boxes:
        logger.info("signature_enroll: no signatures found, attempting contrast enhancement fallback")
        enhanced_image = _normalize_contrast(image)
        
        enhanced_detection = run_detection(
            detector,
            enhanced_image,
            settings,
            confidence=detection_confidence,
            imgsz=settings.signature_enroll_detection_imgsz,
        )
        
        signature_boxes = _select_signature_boxes(enhanced_detection["detections"])
        
        logger.info(
            "signature_enroll: enhanced raw_detections=%d, signature_detections=%d",
            len(enhanced_detection["detections"]), len(signature_boxes)
        )

    samples: list[dict] = []
    embeddings: list[list[float]] = []
    for box in signature_boxes:
        vector = embed_signature(siamese, _crop_box(image, box["box"]))
        embeddings.append(vector)
        samples.append({"box": box["box"], "confidence": box["confidence"], "embedding": vector})

    with tempfile.TemporaryDirectory(prefix="advs_sig_enroll_") as tmp_dir:
        original = save_upload(file, data, tmp_dir)
        forensics = _enrollment_forensics(original)

    return {
        "signatures": samples,
        "count": len(samples),
        "consistency": emb.mean_pairwise_cosine(embeddings),
        "centroid": emb.centroid(embeddings) if embeddings else None,
        "forensics": forensics,
    }
