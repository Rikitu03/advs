"""POST /v1/classify — Stage 3, ResNet-50 document classification (LIVE).

Reuses the preprocessing contract of ``scripts/predict_classifier.py`` but
takes the model from the load-once registry instead of reloading per call.
"""

from __future__ import annotations

import io

from fastapi import APIRouter, File, HTTPException, Request, UploadFile
from PIL import Image, UnidentifiedImageError

from ..schemas import ClassifyResponse

router = APIRouter()


def run_classification(entry: dict, image: Image.Image, threshold: float) -> dict:
    """Classify one PIL page with the registry's classifier entry.

    The model expects RAW 0-255 RGB input: train_classifier.py embeds
    ``resnet50.preprocess_input`` inside the saved graph, so applying it
    again here would double-preprocess and corrupt predictions.
    """
    import numpy as np

    model = entry["model"]
    class_names = entry["class_names"]

    input_shape = model.input_shape
    if len(input_shape) < 3 or input_shape[1] is None or input_shape[2] is None:
        raise ValueError(f"Unexpected model input shape: {input_shape}")
    target_size = (int(input_shape[1]), int(input_shape[2]))

    batch = np.expand_dims(
        np.asarray(image.convert("RGB").resize(target_size), dtype=np.float32), axis=0
    )

    probabilities = model.predict(batch, verbose=0)[0]
    index = int(np.argmax(probabilities))
    label = class_names[index] if index < len(class_names) else str(index)
    confidence = float(probabilities[index])

    return {
        "label": label,
        "confidence": confidence,
        "probabilities": {
            class_names[i] if i < len(class_names) else str(i): float(score)
            for i, score in enumerate(probabilities)
        },
        "threshold": threshold,
        "passed_threshold": confidence >= threshold,
    }


@router.post("/v1/classify", response_model=ClassifyResponse)
async def classify(request: Request, file: UploadFile = File(...)) -> dict:
    entry = request.app.state.registry.require("classifier")
    settings = request.app.state.settings

    data = await file.read()
    try:
        image = Image.open(io.BytesIO(data))
        image.load()
    except (UnidentifiedImageError, OSError) as exc:
        raise HTTPException(status_code=422, detail=f"Unreadable image upload: {exc}") from exc

    return run_classification(entry, image, settings.classification_confidence_threshold)
