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

from fastapi import APIRouter, File, Form, HTTPException, Request, UploadFile
from PIL import Image, UnidentifiedImageError

from .. import embedding as emb
from ..config import Settings
from ..schemas import SignatureEmbedResponse, SignatureVerifyResponse

router = APIRouter()


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
    try:
        image = Image.open(io.BytesIO(data))
        image.load()
        return image
    except (UnidentifiedImageError, OSError) as exc:
        raise HTTPException(status_code=422, detail=f"Unreadable image upload: {exc}") from exc


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
