"""Registration-time signature enrollment (POST /v1/signature/enroll).

Two layers:
  * pure-unit — ``mean_pairwise_cosine`` / ``centroid`` embedding helpers (no model);
  * endpoint contract — the detector + Siamese encoder are stubbed (no ultralytics/TF
    weights load), so the route's own logic is what's under test: count the detected
    signatures, embed each, report inter-signature consistency, a unit-norm centroid,
    and a LENIENT forensics summary (metadata + copy-move only).

Run:  python/env/Scripts/python.exe -m pytest python/tests/test_signature_enroll.py -v
"""

from __future__ import annotations

import io
import sys
from pathlib import Path
from types import SimpleNamespace

import numpy as np
import pytest

PY_ROOT = Path(__file__).resolve().parents[1]
if str(PY_ROOT) not in sys.path:
    sys.path.insert(0, str(PY_ROOT))

from api import embedding as emb  # noqa: E402


# --------------------------------------------------------------------------- #
# pure-unit: consistency + centroid helpers
# --------------------------------------------------------------------------- #
def test_mean_pairwise_cosine_of_identical_vectors_is_one():
    v = [1.0, 0.0, 0.0, 0.0]
    assert emb.mean_pairwise_cosine([v, v, v]) == pytest.approx(1.0)


def test_mean_pairwise_cosine_of_orthogonal_vectors_is_zero():
    assert emb.mean_pairwise_cosine([[1.0, 0.0], [0.0, 1.0]]) == pytest.approx(0.0)


def test_mean_pairwise_cosine_averages_all_pairs():
    # pairs: (a,b)=1, (a,c)=0, (b,c)=0  -> mean 1/3
    a, b, c = [1.0, 0.0], [1.0, 0.0], [0.0, 1.0]
    assert emb.mean_pairwise_cosine([a, b, c]) == pytest.approx(1.0 / 3.0)


def test_mean_pairwise_cosine_needs_two_vectors():
    assert emb.mean_pairwise_cosine([[1.0, 0.0]]) is None
    assert emb.mean_pairwise_cosine([]) is None


def test_centroid_is_the_unit_normalised_mean_direction():
    c = emb.centroid([[2.0, 0.0], [0.0, 2.0]])
    assert np.linalg.norm(c) == pytest.approx(1.0)
    assert c == pytest.approx([2 ** -0.5, 2 ** -0.5])


def test_centroid_of_a_single_vector_is_that_vector_normalised():
    assert emb.centroid([[3.0, 4.0]]) == pytest.approx([0.6, 0.8])


def test_centroid_requires_at_least_one_vector():
    with pytest.raises(ValueError):
        emb.centroid([])


# --------------------------------------------------------------------------- #
# endpoint contract (stubbed detector + siamese)
# --------------------------------------------------------------------------- #
pytest.importorskip("fastapi")
from fastapi.testclient import TestClient  # noqa: E402

from api.config import Settings  # noqa: E402
from api.main import create_app  # noqa: E402

TOKEN = "test-token"
AUTH = {"Authorization": f"Bearer {TOKEN}"}


def _settings(tmp_path: Path, **overrides) -> Settings:
    values = {
        "api_token": TOKEN,
        "model_dir": tmp_path / "models",
        "threshold_store_path": tmp_path / "thresholds.json",
        # Keep endpoint tests on the shared stub detector unless a test is
        # specifically exercising the dedicated enrollment detector.
        "signature_enroll_detector_model_path": None,
    }
    values.update(overrides)
    return Settings.model_validate(values)


@pytest.fixture(scope="module")
def jpeg_bytes() -> bytes:
    """Sanitized landscape equivalent of a three-signature enrollment photo."""
    from PIL import Image, ImageDraw

    image = Image.new("RGB", (1946, 1460), "white")
    draw = ImageDraw.Draw(image)
    for offset in (250, 800, 1350):
        draw.line(
            [
                (offset, 700),
                (offset + 90, 560),
                (offset + 170, 850),
                (offset + 250, 590),
                (offset + 360, 820),
            ],
            fill="black",
            width=12,
            joint="curve",
        )

    buffer = io.BytesIO()
    image.save(buffer, format="JPEG", quality=90)
    return buffer.getvalue()


def _upload(data: bytes, name: str = "signatures.jpg"):
    return {"file": (name, data, "image/jpeg")}


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
    """Mirrors the trained weights vocabulary: {0: logo, 1: signature, 2: stamp_seal}."""

    names = {0: "logo", 1: "signature", 2: "stamp_seal"}

    def __init__(self, boxes: list[_StubBox]) -> None:
        self._boxes = boxes
        self.calls: list[dict] = []

    def predict(self, source, conf, verbose=False, **kwargs):  # noqa: ANN001
        self.calls.append({"source": source, "conf": conf, "verbose": verbose, **kwargs})
        return [_StubResult(self.names, self._boxes)]


class _StubEmbedderModel:
    """Keras-like extractor: embedding = the crop's per-channel means, so distinct
    crops embed distinctly and identical crops embed identically."""

    input_shape = (None, 64, 64, 3)

    def predict(self, batch, verbose=0):
        assert batch.shape == (1, 64, 64, 3)
        return batch.reshape(1, -1, 3).mean(axis=1)


def _three_signature_detector() -> _StubDetector:
    return _StubDetector([
        _StubBox(cls=1, conf=0.95, xyxy=[220, 500, 700, 950]),
        _StubBox(cls=1, conf=0.90, xyxy=[770, 500, 1250, 950]),
        _StubBox(cls=1, conf=0.88, xyxy=[1320, 500, 1800, 950]),
    ])


def _enroll(app, jpeg_bytes):
    with TestClient(app) as client:
        return client.post("/v1/signature/enroll", headers=AUTH, files=_upload(jpeg_bytes))


def test_enroll_requires_bearer_token(tmp_path, jpeg_bytes):
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        assert client.post("/v1/signature/enroll", files=_upload(jpeg_bytes)).status_code == 401


def test_enroll_503_when_models_absent(tmp_path, jpeg_bytes):
    # Empty model_dir -> detector/siamese not loaded -> require() -> 503.
    assert _enroll(create_app(_settings(tmp_path)), jpeg_bytes).status_code == 503


def test_enroll_counts_embeds_and_scores_three_signatures(tmp_path, jpeg_bytes):
    pytest.importorskip("tensorflow")  # resnet50 preprocess_input in embed_signature

    app = create_app(_settings(tmp_path))
    detector = _three_signature_detector()
    with TestClient(app) as client:
        app.state.registry._models["detector"] = detector
        app.state.registry._models["siamese"] = _StubEmbedderModel()
        response = client.post("/v1/signature/enroll", headers=AUTH, files=_upload(jpeg_bytes))

    assert response.status_code == 200
    body = response.json()

    assert body["count"] == 3
    assert len(body["signatures"]) == 3
    assert detector.calls[0]["conf"] == pytest.approx(0.20)
    assert detector.calls[0]["imgsz"] == 640
    for sample in body["signatures"]:
        assert len(sample["box"]) == 4
        assert 0.0 <= sample["confidence"] <= 1.0
        assert len(sample["embedding"]) == 3  # stub embeds to RGB means

    assert body["consistency"] is not None
    assert -1.0 <= body["consistency"] <= 1.0
    assert body["centroid"] is not None
    assert len(body["centroid"]) == 3
    assert np.linalg.norm(body["centroid"]) == pytest.approx(1.0)

    forensics = body["forensics"]
    assert set(forensics) == {"hard_flag", "reasons", "techniques"}
    assert isinstance(forensics["hard_flag"], bool)
    assert set(forensics["techniques"]) == {"metadata", "copy_move"}
    # A clean synthetic JPEG (no editor metadata, no clone) must not hard-flag,
    # and a merely stripped-EXIF photo is tolerated (lenient gate).
    assert forensics["hard_flag"] is False


def test_enroll_normalizes_exif_orientation_before_detection(tmp_path, jpeg_bytes):
    pytest.importorskip("tensorflow")
    from PIL import Image, ImageOps

    source = Image.open(io.BytesIO(jpeg_bytes)).convert("RGB")
    exif = source.getexif()
    exif[274] = 6
    output = io.BytesIO()
    source.save(output, format="JPEG", exif=exif.tobytes())

    app = create_app(_settings(tmp_path))
    detector = _three_signature_detector()
    with TestClient(app) as client:
        app.state.registry._models["detector"] = detector
        app.state.registry._models["siamese"] = _StubEmbedderModel()
        response = client.post(
            "/v1/signature/enroll", headers=AUTH, files=_upload(output.getvalue())
        )

    assert response.status_code == 200
    assert response.json()["count"] == 3
    assert detector.calls[0]["source"].size == ImageOps.exif_transpose(source).size


def test_enroll_prefers_a_dedicated_detector_when_configured(tmp_path, jpeg_bytes):
    pytest.importorskip("tensorflow")

    shared_detector = _StubDetector([])
    enrollment_detector = _three_signature_detector()
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["detector"] = shared_detector
        app.state.registry._models["signature_enroll_detector"] = enrollment_detector
        app.state.registry._models["siamese"] = _StubEmbedderModel()
        body = client.post(
            "/v1/signature/enroll", headers=AUTH, files=_upload(jpeg_bytes)
        ).json()

    assert body["count"] == 3
    assert shared_detector.calls == []
    assert enrollment_detector.calls[0]["imgsz"] == 640


def test_enroll_uses_the_default_dedicated_detector_path(tmp_path):
    settings = Settings(
        api_token=TOKEN,
        model_dir=tmp_path / "models",
        threshold_store_path=tmp_path / "thresholds.json",
    )

    assert settings.signature_enroll_detector_model_path == (
        PY_ROOT / "models" / "signature_detector_best_raw.pt"
    )


def test_enroll_counts_only_signatures_not_stamps(tmp_path, jpeg_bytes):
    pytest.importorskip("tensorflow")

    detector = _StubDetector([
        _StubBox(cls=1, conf=0.95, xyxy=[10, 10, 120, 60]),
        _StubBox(cls=2, conf=0.99, xyxy=[10, 80, 120, 130]),  # stamp_seal, ignored
    ])
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["detector"] = detector
        app.state.registry._models["siamese"] = _StubEmbedderModel()
        body = client.post(
            "/v1/signature/enroll", headers=AUTH, files=_upload(jpeg_bytes)
        ).json()

    assert body["count"] == 1
    assert body["consistency"] is None  # <2 signatures -> no pairwise score


def test_enroll_deduplicates_overlapping_signature_boxes(tmp_path, jpeg_bytes):
    pytest.importorskip("tensorflow")

    detector = _StubDetector([
        _StubBox(cls=1, conf=0.95, xyxy=[10, 10, 120, 100]),
        _StubBox(cls=1, conf=0.90, xyxy=[15, 15, 115, 95]),
        _StubBox(cls=1, conf=0.88, xyxy=[150, 10, 260, 100]),
    ])
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["detector"] = detector
        app.state.registry._models["siamese"] = _StubEmbedderModel()
        body = client.post(
            "/v1/signature/enroll", headers=AUTH, files=_upload(jpeg_bytes)
        ).json()

    assert body["count"] == 2
    assert [sample["confidence"] for sample in body["signatures"]] == [0.95, 0.88]


def test_enroll_rejects_unreadable_image(tmp_path):
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["detector"] = _three_signature_detector()
        app.state.registry._models["siamese"] = _StubEmbedderModel()
        response = client.post(
            "/v1/signature/enroll", headers=AUTH,
            files={"file": ("x.jpg", b"not-an-image", "image/jpeg")},
        )
    assert response.status_code == 422


def test_enroll_zero_signatures_returns_null_consistency_and_centroid(tmp_path, jpeg_bytes):
    """No signatures detected → count=0, consistency=None, centroid=None."""
    pytest.importorskip("tensorflow")

    detector = _StubDetector([])  # No detections at all
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["detector"] = detector
        app.state.registry._models["siamese"] = _StubEmbedderModel()
        body = client.post(
            "/v1/signature/enroll", headers=AUTH, files=_upload(jpeg_bytes)
        ).json()

    assert body["count"] == 0
    assert body["signatures"] == []
    assert body["consistency"] is None
    assert body["centroid"] is None
    # Forensics still runs even with zero signatures
    assert "forensics" in body
    assert isinstance(body["forensics"]["hard_flag"], bool)


def test_enroll_contrast_enhancement_fallback_detects_signatures(tmp_path, jpeg_bytes):
    """When initial detection finds no signatures, contrast enhancement is tried."""
    pytest.importorskip("tensorflow")

    # First call (normal image) returns no detections
    # Second call (enhanced image) returns 3 signatures
    class _FallbackDetector:
        names = {0: "logo", 1: "signature", 2: "stamp_seal"}
        
        def __init__(self):
            self.calls = []
        
        def predict(self, source, conf, verbose=False, **kwargs):
            self.calls.append({"source": source, "conf": conf})
            # First call: no detections; second call (enhanced): 3 signatures
            if len(self.calls) == 1:
                return [_StubResult(self.names, [])]
            else:
                return [_StubResult(self.names, [
                    _StubBox(cls=1, conf=0.95, xyxy=[10, 10, 120, 60]),
                    _StubBox(cls=1, conf=0.90, xyxy=[10, 80, 120, 130]),
                    _StubBox(cls=1, conf=0.88, xyxy=[10, 150, 120, 200]),
                ])]

    detector = _FallbackDetector()
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["detector"] = detector
        app.state.registry._models["siamese"] = _StubEmbedderModel()
        body = client.post(
            "/v1/signature/enroll", headers=AUTH, files=_upload(jpeg_bytes)
        ).json()

    # Should have made 2 detection calls (original + enhanced)
    assert len(detector.calls) == 2
    # Enhanced detection found 3 signatures
    assert body["count"] == 3
    assert len(body["signatures"]) == 3
    assert body["consistency"] is not None
    assert body["centroid"] is not None
