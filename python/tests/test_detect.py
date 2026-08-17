"""Unit tests for Stage 4 detection label handling (api/routers/detect.py).

Pure-Python: the detector is stubbed, so no ultralytics/torch weights load. The
contract under test is the label vocabulary — the trained YOLOv8 emits
``stamp_seal`` but the rest of the pipeline verifies stamps by the canonical
``stamp`` label, so run_detection must normalise it or real stamps are dropped.
"""

from __future__ import annotations

import sys
from pathlib import Path
from types import SimpleNamespace

import numpy as np

PY_ROOT = Path(__file__).resolve().parents[1]
if str(PY_ROOT) not in sys.path:
    sys.path.insert(0, str(PY_ROOT))

from PIL import Image  # noqa: E402

from api.routers.detect import run_detection  # noqa: E402


class _StubBox:
    def __init__(self, cls: int, conf: float, xyxy: list[float]) -> None:
        self.cls = cls
        self.conf = conf
        self.xyxy = np.array([xyxy], dtype=float)


class _StubResult:
    def __init__(self, names: dict[int, str], boxes: list[_StubBox]) -> None:
        self.names = names
        self.boxes = boxes


class _StubDetector:
    """Mirrors the real weights: {0: logo, 1: signature, 2: stamp_seal}."""

    names = {0: "logo", 1: "signature", 2: "stamp_seal"}

    def __init__(self, boxes: list[_StubBox]) -> None:
        self._boxes = boxes
        self.calls: list[dict] = []

    def predict(self, source, conf, verbose=False, **kwargs):  # noqa: ANN001
        self.calls.append({"source": source, "conf": conf, "verbose": verbose, **kwargs})
        return [_StubResult(self.names, self._boxes)]


_SETTINGS = SimpleNamespace(yolo_detection_confidence=0.5)
_IMAGE = Image.new("RGB", (64, 64))


def test_stamp_seal_is_normalised_to_stamp():
    model = _StubDetector([_StubBox(cls=2, conf=0.92, xyxy=[10, 10, 50, 50])])

    out = run_detection(model, _IMAGE, _SETTINGS)
    labels = [d["label"] for d in out["detections"]]

    assert "stamp" in labels, "a stamp_seal detection must surface as 'stamp'"
    assert "stamp_seal" not in labels
    # A stamp was found, so no missing-stamp flag; the absent signature/logo are flagged.
    assert "no_stamp_detected" not in out["flags"]
    assert "no_signature_detected" in out["flags"]
    assert "no_logo_detected" in out["flags"]


def test_signature_detection_passes_through_unaliased():
    model = _StubDetector([_StubBox(cls=1, conf=0.88, xyxy=[5, 5, 40, 20])])

    out = run_detection(model, _IMAGE, _SETTINGS)
    labels = [d["label"] for d in out["detections"]]

    assert labels == ["signature"]
    assert "no_signature_detected" not in out["flags"]
    # stamp + logo remain undetected -> both flagged (stamp via its canonical name).
    assert "no_stamp_detected" in out["flags"]
    assert "no_logo_detected" in out["flags"]
    assert model.calls[0]["conf"] == 0.5
    assert "imgsz" not in model.calls[0]


def test_enrollment_overrides_are_forwarded_without_changing_defaults():
    model = _StubDetector([_StubBox(cls=1, conf=0.25, xyxy=[5, 5, 40, 20])])

    out = run_detection(model, _IMAGE, _SETTINGS, confidence=0.20, imgsz=1280)

    assert out["detections"][0]["label"] == "signature"
    assert model.calls[0]["conf"] == 0.20
    assert model.calls[0]["imgsz"] == 1280
