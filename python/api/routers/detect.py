"""POST /v1/detect — Stage 4, YOLOv8 signature/stamp/logo region detection.

Returns 503 ``model_not_loaded`` until training produces the detector weights
(configure DETECTOR_MODEL_PATH). The inference path below is written against
the ultralytics API but is untested until those weights exist.
"""

from __future__ import annotations

import io

from fastapi import APIRouter, File, HTTPException, Request, UploadFile
from PIL import Image, UnidentifiedImageError

from ..config import Settings
from ..schemas import DetectResponse

router = APIRouter()

DETECTABLE = ("signature", "stamp", "logo")

# The trained detector names its stamp class "stamp_seal", but the rest of the
# pipeline (validate.py Stage 4b, Laravel's MlPipelineService) keys stamp/logo
# verification off the canonical "stamp" label. Normalise at the detection
# boundary so a single vocabulary flows downstream and a real stamp isn't
# silently dropped.
LABEL_ALIASES = {"stamp_seal": "stamp"}


def _canonical(label: str) -> str:
    return LABEL_ALIASES.get(label, label)


def run_detection(
    model,
    image: Image.Image,
    settings: Settings,
    *,
    confidence: float | None = None,
    imgsz: int | None = None,
) -> dict:
    """Detect regions on one PIL page; no detection is a flag, never an error."""
    prediction_options = {
        "source": image.convert("RGB"),
        "conf": settings.yolo_detection_confidence if confidence is None else confidence,
        "verbose": False,
    }
    if imgsz is not None:
        prediction_options["imgsz"] = imgsz

    results = model.predict(**prediction_options)

    detections = []
    for result in results:
        names = result.names
        for box in result.boxes:
            detections.append({
                "label": _canonical(str(names[int(box.cls)])),
                "confidence": float(box.conf),
                "box": [float(v) for v in box.xyxy[0].tolist()],
            })

    found = {d["label"] for d in detections}
    known = {_canonical(str(n)) for n in getattr(model, "names", {}).values()}
    flags = [
        f"no_{label}_detected"
        for label in DETECTABLE
        if label in known and label not in found
    ]
    return {"detections": detections, "flags": flags}


@router.post("/v1/detect", response_model=DetectResponse)
async def detect(request: Request, file: UploadFile = File(...)) -> dict:
    model = request.app.state.registry.require("detector")

    data = await file.read()
    try:
        image = Image.open(io.BytesIO(data))
        image.load()
    except (UnidentifiedImageError, OSError) as exc:
        raise HTTPException(status_code=422, detail=f"Unreadable image upload: {exc}") from exc

    return run_detection(model, image, request.app.state.settings)
