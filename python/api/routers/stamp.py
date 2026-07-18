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

import io

from fastapi import APIRouter, File, Form, HTTPException, Request, UploadFile
from PIL import Image, UnidentifiedImageError

from .. import embedding as emb
from ..config import Settings
from ..schemas import StampEmbedResponse, StampVerifyResponse

router = APIRouter()


def _efficientnet_preprocess(batch):
    from tensorflow.keras.applications.efficientnet import preprocess_input

    return preprocess_input(batch)


def embed_stamp(model, image: Image.Image) -> list[float]:
    return emb.embed(model, image, _efficientnet_preprocess)


def run_stamp_verify(
    model,
    image: Image.Image,
    reference: list[float] | None,
    settings: Settings,
    document_type: str | None = None,
    city: str | None = None,
) -> dict:
    base = {"document_type": document_type, "city": city}

    if reference is None:
        return {**base, "match": False, "reason": "unreferenced_logo",
                "similarity_score": None, "threshold": None}

    vector = embed_stamp(model, image)
    emb.require_same_length(reference, vector, "reference_vector")

    similarity = emb.cosine_similarity(vector, reference)
    threshold = settings.resolved_stamp_similarity_threshold()

    return {
        **base,
        "match": similarity >= threshold,
        "similarity_score": similarity,
        "threshold": threshold,
        "reason": None,
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
async def stamp_embed(request: Request, file: UploadFile = File(...)) -> dict:
    model = request.app.state.registry.require("stamp")
    image = await _read_crop(file)
    return {"vector": embed_stamp(model, image)}


@router.post("/v1/stamp/verify", response_model=StampVerifyResponse)
async def stamp_verify(
    request: Request,
    file: UploadFile = File(...),
    reference_vector: str | None = Form(None),
    document_type: str | None = Form(None),
    city: str | None = Form(None),
) -> dict:
    model = request.app.state.registry.require("stamp")
    reference = emb.parse_reference(reference_vector, "reference_vector") if reference_vector else None
    image = await _read_crop(file)
    return run_stamp_verify(model, image, reference, request.app.state.settings, document_type, city)
