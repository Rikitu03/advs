"""Contract tests for the ADVS ML API (python/api).

Fast by default: apps are built with model paths pointed at an empty tmp dir,
so no TensorFlow/YOLO weights are ever loaded; the classifier happy path uses
a stub Keras-like model injected into the registry. Set
``ADVS_API_REAL_MODEL_TESTS=1`` to also exercise the real classifier weights
when they exist under python/models/.
"""

from __future__ import annotations

import io
import json
import os
import sys
from pathlib import Path

import numpy as np
import pytest

PY_ROOT = Path(__file__).resolve().parents[1]
if str(PY_ROOT) not in sys.path:
    sys.path.insert(0, str(PY_ROOT))

pytest.importorskip("fastapi")
from fastapi.testclient import TestClient  # noqa: E402

from api.config import Settings  # noqa: E402
from api.main import create_app  # noqa: E402

TOKEN = "test-token"
AUTH = {"Authorization": f"Bearer {TOKEN}"}


# --------------------------------------------------------------------------- #
# fixtures / helpers
# --------------------------------------------------------------------------- #
def _settings(tmp_path: Path, **overrides) -> Settings:
    """Settings isolated from the developer's .env.api, the OS env defaults,
    and the shared runtime-threshold store."""
    values = {
        "api_token": TOKEN,
        "model_dir": tmp_path / "models",
        "threshold_store_path": tmp_path / "thresholds.json",
    }
    values.update(overrides)
    return Settings(_env_file=None, **values)


@pytest.fixture()
def client(tmp_path):
    with TestClient(create_app(_settings(tmp_path))) as test_client:
        yield test_client


@pytest.fixture(scope="module")
def jpeg_bytes() -> bytes:
    """A photo-like synthetic page (same recipe as test_tamper_analyze)."""
    from PIL import Image

    rng = np.random.default_rng(0)
    yy, xx = np.mgrid[0:320, 0:320]
    base = np.stack([
        (xx / 320 * 200 + 30),
        (yy / 320 * 200 + 30),
        ((xx + yy) / 640 * 200 + 30),
    ], axis=-1)
    base += rng.normal(0, 4, base.shape)
    image = Image.fromarray(np.clip(base, 0, 255).astype("uint8"))
    buffer = io.BytesIO()
    image.save(buffer, format="JPEG", quality=90)
    return buffer.getvalue()


def _upload(data: bytes, name: str = "doc.jpg"):
    return {"file": (name, data, "image/jpeg")}


class _StubKerasModel:
    """Just enough of the Keras predict surface for run_classification."""

    input_shape = (None, 64, 64, 3)

    def __init__(self):
        self.last_batch = None

    def predict(self, batch, verbose=0):
        assert batch.shape == (1, 64, 64, 3)
        self.last_batch = np.array(batch, copy=True)
        return np.array([[0.15, 0.85]])


# --------------------------------------------------------------------------- #
# health + auth
# --------------------------------------------------------------------------- #
def test_health_is_open_and_reports_missing_models(client):
    response = client.get("/health")
    assert response.status_code == 200

    body = response.json()
    assert body["status"] == "ok"
    assert set(body["models"]) == {
        "classifier", "detector", "siamese", "stamp", "stamp_classifier",
        "rapid_detector", "trocr", "trocr_accurate",
    }
    # File/dir-gated models: an empty tmp model_dir means none of these are
    # configured, so all report "not loaded" the same way.
    for name in ("classifier", "detector", "siamese", "stamp", "stamp_classifier",
                 "trocr", "trocr_accurate"):
        status = body["models"][name]
        assert status["loaded"] is False
        assert status["error"] == "weights_not_found"
    # rapid_detector (RapidOCR) bundles its own weights inside the pip
    # package — no path to gate on, so it always loads.
    assert body["models"]["rapid_detector"]["loaded"] is True


def test_v1_routes_require_bearer_token(client, jpeg_bytes):
    assert client.post("/v1/classify", files=_upload(jpeg_bytes)).status_code == 401
    wrong = {"Authorization": "Bearer wrong-token"}
    assert client.post("/v1/classify", headers=wrong, files=_upload(jpeg_bytes)).status_code == 401


def test_unconfigured_token_fails_closed(tmp_path, jpeg_bytes):
    with TestClient(create_app(_settings(tmp_path, api_token=""))) as client:
        response = client.post("/v1/tamper", headers=AUTH, files=_upload(jpeg_bytes))
    assert response.status_code == 503
    assert "API_TOKEN" in response.json()["detail"]


# --------------------------------------------------------------------------- #
# model_not_loaded gating (weights configured later)
# --------------------------------------------------------------------------- #
@pytest.mark.parametrize("route", [
    "/v1/classify", "/v1/detect", "/v1/signature/embed", "/v1/stamp/embed",
])
def test_missing_weights_yield_503_with_configured_path(client, jpeg_bytes, route):
    response = client.post(route, headers=AUTH, files=_upload(jpeg_bytes))
    assert response.status_code == 503

    detail = response.json()["detail"]
    assert detail["reason"] == "model_not_loaded"
    assert detail["error"] == "weights_not_found"
    assert detail["configured_path"]  # the path to configure is surfaced


# --------------------------------------------------------------------------- #
# classify (stubbed model — no TF weights involved)
# --------------------------------------------------------------------------- #
def test_classify_contract_with_stub_model(tmp_path, jpeg_bytes):
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        registry = app.state.registry
        registry._models["classifier"] = {
            "model": _StubKerasModel(),
            "class_names": ["bir_certificate", "fake"],
        }

        response = client.post("/v1/classify", headers=AUTH, files=_upload(jpeg_bytes))

    assert response.status_code == 200
    body = response.json()
    assert body["label"] == "fake"
    assert body["confidence"] == pytest.approx(0.85)
    assert body["passed_threshold"] is True
    assert body["threshold"] == pytest.approx(0.70)
    assert set(body["probabilities"]) == {"bir_certificate", "fake"}


def test_classify_feeds_raw_pixels_not_double_preprocessed(tmp_path, jpeg_bytes):
    """Regression: train_classifier.py embeds resnet50.preprocess_input INSIDE
    the saved graph, so the API must feed raw 0-255 RGB. Applying ImageNet
    preprocessing again flipped genuine documents to 'fake' in production."""
    from PIL import Image

    stub = _StubKerasModel()
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["classifier"] = {
            "model": stub, "class_names": ["bir_certificate", "fake"],
        }
        response = client.post("/v1/classify", headers=AUTH, files=_upload(jpeg_bytes))

    assert response.status_code == 200
    expected = np.asarray(
        Image.open(io.BytesIO(jpeg_bytes)).convert("RGB").resize((64, 64)),
        dtype=np.float32,
    )
    # Raw pixels: exact resize values, still in 0-255 (ImageNet mean-centering
    # would shift channels negative and swap RGB->BGR).
    assert stub.last_batch is not None
    np.testing.assert_allclose(stub.last_batch[0], expected)


def test_classify_rejects_unreadable_image(tmp_path):
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["classifier"] = {
            "model": _StubKerasModel(), "class_names": ["a", "b"],
        }
        response = client.post(
            "/v1/classify", headers=AUTH, files={"file": ("x.jpg", b"not-an-image", "image/jpeg")}
        )
    assert response.status_code == 422


def _classifier_entry(probabilities: list[float], class_names: list[str]) -> dict:
    class _StubModel:
        input_shape = (None, 64, 64, 3)

        def predict(self, batch, verbose=0):
            return np.array([probabilities])

    return {"model": _StubModel(), "class_names": class_names}


CLASSES = ["bir_certificate", "business_permit", "dti_registration", "fake"]


def test_classification_authenticity_discounts_the_fake_probability(tmp_path, jpeg_bytes):
    """A confident 'fake' must not reach the risk blend as high authenticity —
    the classification component is (1 - risk), so P(fake) is what matters."""
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["classifier"] = _classifier_entry(
            [0.01, 0.01, 0.00, 0.98], CLASSES
        )
        body = client.post("/v1/classify", headers=AUTH, files=_upload(jpeg_bytes)).json()

    assert body["label"] == "fake"
    assert body["confidence"] == pytest.approx(0.98)
    assert body["authenticity"] == pytest.approx(0.02)


def test_classification_authenticity_equals_confidence_without_a_fake_class(tmp_path, jpeg_bytes):
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["classifier"] = _classifier_entry(
            [0.15, 0.85], ["bir_certificate", "business_permit"]
        )
        body = client.post("/v1/classify", headers=AUTH, files=_upload(jpeg_bytes)).json()

    assert body["authenticity"] == pytest.approx(1.0)


@pytest.mark.skipif(
    not os.environ.get("ADVS_API_REAL_MODEL_TESTS")
    or not (PY_ROOT / "models" / "resnet50_best.keras").is_file(),
    reason="real-weights test; set ADVS_API_REAL_MODEL_TESTS=1 with trained weights present",
)
def test_classify_with_real_weights(jpeg_bytes):
    with TestClient(create_app(Settings(_env_file=None, api_token=TOKEN))) as client:
        response = client.post("/v1/classify", headers=AUTH, files=_upload(jpeg_bytes))

    assert response.status_code == 200
    body = response.json()
    assert isinstance(body["label"], str)
    assert 0.0 <= body["confidence"] <= 1.0


@pytest.mark.skipif(
    not os.environ.get("ADVS_API_REAL_MODEL_TESTS")
    or not (PY_ROOT / "models" / "trocr-base-printed").is_dir()
    or not (PY_ROOT / "models" / "trocr-large-printed").is_dir(),
    reason="real-weights test; set ADVS_API_REAL_MODEL_TESTS=1 with both TrOCR models baked",
)
def test_health_reports_both_trocr_recognizers_loaded_with_real_weights():
    """Per-template routing needs both baked locally: the fast one for
    DTI/Business Permit, the accurate one for BIR."""
    with TestClient(create_app(Settings(_env_file=None, api_token=TOKEN))) as client:
        response = client.get("/health")

    assert response.status_code == 200
    models = response.json()["models"]
    assert models["trocr"]["loaded"] is True
    assert models["trocr_accurate"]["loaded"] is True


# --------------------------------------------------------------------------- #
# tamper (live, deterministic)
# --------------------------------------------------------------------------- #
def test_tamper_verdict_contract(client, jpeg_bytes):
    response = client.post("/v1/tamper", headers=AUTH, files=_upload(jpeg_bytes))
    assert response.status_code == 200

    verdict = response.json()
    for key in ("tamper_score", "tamper_authenticity", "tamper_confidence",
                "tamper_passed", "hard_flag", "techniques", "flags"):
        assert key in verdict
    assert set(verdict["techniques"]) == {"metadata", "ela", "copy_move", "font", "cross_reference"}
    assert 0.0 <= verdict["tamper_score"] <= 1.0


def test_tamper_rejects_malformed_context(client, jpeg_bytes):
    response = client.post(
        "/v1/tamper", headers=AUTH, files=_upload(jpeg_bytes),
        data={"context": "not-json"},
    )
    assert response.status_code == 422


# --------------------------------------------------------------------------- #
# ocr (live when the Tesseract engine is installed)
# --------------------------------------------------------------------------- #
def _tesseract_available() -> bool:
    pytesseract = pytest.importorskip("pytesseract")  # noqa: F841
    from api.compat import load_script

    return load_script("ocr_dryrun").resolve_tesseract_cmd(None) is not None


def test_ocr_page_contract(client, jpeg_bytes):
    if not _tesseract_available():
        pytest.skip("Tesseract engine not installed")

    response = client.post("/v1/ocr", headers=AUTH, files=_upload(jpeg_bytes))
    assert response.status_code == 200

    body = response.json()
    assert body["page_count"] == 1
    page = body["pages"][0]
    for key in ("text", "words", "fields", "quality"):
        assert key in page
    assert "text_validation_score" in page["quality"]


def test_ocr_page_reports_roi_diagnostics(client, jpeg_bytes):
    """Each ROI+TrOCR field crop costs real CPU seconds, so a page has to say
    what that pass did - which fields it attempted, which it recognized, which
    the time budget refused. ``None`` distinguishes "the extension isn't
    loaded" (this client's model dir is empty) from "it ran and did nothing".
    """
    if not _tesseract_available():
        pytest.skip("Tesseract engine not installed")

    page = client.post(
        "/v1/ocr", headers=AUTH, files=_upload(jpeg_bytes), data={"template": "bir"}
    ).json()["pages"][0]

    assert "roi" in page
    assert page["roi"] is None  # trocr weights absent -> pass never ran


class _StubRegistry:
    """Just the registry surface resolve_recognizer needs."""

    def __init__(self, models: dict):
        self._models = models

    def get(self, name):
        return self._models.get(name)


def test_bir_routes_to_the_accurate_recognizer_and_other_templates_to_the_fast_one():
    """Benchmarked on the real samples: trocr-base and trocr-large produce
    IDENTICAL field values on DTI and Business Permit (base ~3x faster), but
    on BIR base misreads values — including the issue YEAR ("FEB 24 2025" for
    a 2023 certificate), which feeds expiration monitoring. So BIR pays for
    the accurate model and the other templates do not.
    """
    from api.routers.ocr import resolve_recognizer

    registry = _StubRegistry({"trocr": "base", "trocr_accurate": "large"})

    assert resolve_recognizer(registry, "bir", ("bir",)) == "large"
    assert resolve_recognizer(registry, "dti", ("bir",)) == "base"
    assert resolve_recognizer(registry, "business_permit", ("bir",)) == "base"


def test_recognizer_falls_back_to_whichever_model_is_actually_loaded():
    """One recognizer baked instead of two is a normal deployment (the Docker
    image bakes one by default) — Stage 2 must use it rather than silently
    skipping the ROI pass."""
    from api.routers.ocr import resolve_recognizer

    assert resolve_recognizer(_StubRegistry({"trocr": "base"}), "bir", ("bir",)) == "base"
    assert resolve_recognizer(_StubRegistry({"trocr_accurate": "large"}), "dti", ("bir",)) == "large"
    assert resolve_recognizer(_StubRegistry({}), "bir", ("bir",)) is None


def test_trocr_decode_cap_is_long_enough_for_a_real_address_field(tmp_path):
    """Measured on a real BIR page: registered_address decodes to 70 chars
    ("...GUINGOLUNGAN, 3209 PAMPANGA") at ~2.1 chars/token, i.e. ~34 tokens.
    A 32-token cap silently truncated it to "3209 PAMPAN" - a wrong value
    that still passes every regex. Don't trim this back as an "optimisation":
    generation stops at EOS anyway, so short fields never pay for the headroom.
    """
    assert _settings(tmp_path).trocr_max_new_tokens >= 48


def test_ocr_rejects_unknown_template(client, jpeg_bytes):
    response = client.post(
        "/v1/ocr", headers=AUTH, files=_upload(jpeg_bytes), data={"template": "sec"}
    )
    assert response.status_code == 422


def test_ocr_accepts_business_permit_and_dti_templates(client, jpeg_bytes):
    # The per-type templates are valid now: they pass template validation and
    # proceed to OCR (200 with an engine, 503 without) — never 422 like "sec".
    for template in ("business_permit", "dti"):
        response = client.post(
            "/v1/ocr", headers=AUTH, files=_upload(jpeg_bytes), data={"template": template}
        )
        assert response.status_code != 422, template


def test_ocr_business_permit_template_selects_permit_fields(client, jpeg_bytes):
    if not _tesseract_available():
        pytest.skip("Tesseract engine not installed")

    body = client.post(
        "/v1/ocr", headers=AUTH, files=_upload(jpeg_bytes),
        data={"template": "business_permit"},
    ).json()
    fields = body["pages"][0]["fields"]
    # The tiny blank fixture matches nothing, but the field KEYS reflect which
    # template was applied — proving the router routed by template, not BIR-always.
    assert fields is not None
    assert {"name_of_proprietor", "trade_name", "kind_of_business", "city_issued"} <= set(fields)
    assert "tin" not in fields  # would be present under the BIR template


# --------------------------------------------------------------------------- #
# runtime threshold config (admin dashboard ML Models page)
# --------------------------------------------------------------------------- #
def test_config_requires_auth_and_reports_defaults(client):
    assert client.get("/v1/config").status_code == 401

    response = client.get("/v1/config", headers=AUTH)
    assert response.status_code == 200

    body = response.json()
    assert body["thresholds"]["classification_confidence_threshold"] == pytest.approx(0.70)
    assert body["thresholds"]["yolo_detection_confidence"] == pytest.approx(0.50)
    assert body["thresholds"]["stamp_similarity_threshold"] == pytest.approx(0.85)
    assert body["overrides"] == {}


def test_patch_config_changes_live_behaviour(tmp_path, jpeg_bytes):
    pytest.importorskip("tensorflow")

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["classifier"] = {
            "model": _StubKerasModel(), "class_names": ["bir_certificate", "fake"],
        }

        # Stub confidence is 0.85 -> passes the 0.70 default...
        first = client.post("/v1/classify", headers=AUTH, files=_upload(jpeg_bytes)).json()
        assert first["passed_threshold"] is True

        response = client.patch(
            "/v1/config", headers=AUTH, json={"classification_confidence_threshold": 0.90}
        )
        assert response.status_code == 200
        assert response.json()["overrides"] == {"classification_confidence_threshold": 0.90}

        # ...and fails once the admin raises the threshold above it.
        second = client.post("/v1/classify", headers=AUTH, files=_upload(jpeg_bytes)).json()
        assert second["passed_threshold"] is False
        assert second["threshold"] == pytest.approx(0.90)


def test_patch_config_persists_across_restart(tmp_path):
    store = tmp_path / "thresholds.json"

    with TestClient(create_app(_settings(tmp_path))) as client:
        client.patch("/v1/config", headers=AUTH, json={"pdf_dpi": 200})
    assert store.is_file()

    # A new app on the same store (fresh Settings) boots with the override.
    with TestClient(create_app(_settings(tmp_path))) as client:
        body = client.get("/v1/config", headers=AUTH).json()
    assert body["thresholds"]["pdf_dpi"] == 200
    assert body["overrides"] == {"pdf_dpi": 200}


def test_patch_config_rejects_invalid_values(client):
    out_of_range = client.patch(
        "/v1/config", headers=AUTH, json={"classification_confidence_threshold": 1.5}
    )
    assert out_of_range.status_code == 422

    unknown_key = client.patch("/v1/config", headers=AUTH, json={"api_token": "hijack"})
    assert unknown_key.status_code == 422


def test_patch_null_resets_and_file_fallback_returns(tmp_path):
    # Training produced an empirical stamp threshold file...
    models_dir = tmp_path / "models"
    models_dir.mkdir()
    (models_dir / "stamp_threshold.txt").write_text("0.5", encoding="utf-8")

    with TestClient(create_app(_settings(tmp_path))) as client:
        body = client.get("/v1/config", headers=AUTH).json()
        assert body["effective"]["stamp_similarity_threshold"] == pytest.approx(0.5)

        # ...an admin override beats it...
        body = client.patch(
            "/v1/config", headers=AUTH, json={"stamp_similarity_threshold": 0.92}
        ).json()
        assert body["effective"]["stamp_similarity_threshold"] == pytest.approx(0.92)

        # ...and PATCHing null restores the training-file fallback.
        body = client.patch(
            "/v1/config", headers=AUTH, json={"stamp_similarity_threshold": None}
        ).json()
        assert body["effective"]["stamp_similarity_threshold"] == pytest.approx(0.5)
        assert body["overrides"] == {}
        assert body["thresholds"]["stamp_similarity_threshold"] == pytest.approx(0.85)


# --------------------------------------------------------------------------- #
# signature / stamp verification (stub embedder — no TF weights involved)
# --------------------------------------------------------------------------- #
class _StubEmbedderModel:
    """Keras-like feature extractor: embedding = the crop's per-channel means,
    so identical uploads embed identically (deterministic distances)."""

    input_shape = (None, 64, 64, 3)

    def predict(self, batch, verbose=0):
        assert batch.shape == (1, 64, 64, 3)
        return batch.reshape(1, -1, 3).mean(axis=1)


class _StubStampClassifier:
    """sklearn-like binary classifier matching train_stamp.py's labelling:
    column 1 = P(genuine wet ink), column 0 = P(reproduction)."""

    def __init__(self, genuine_probability: float):
        self._genuine = genuine_probability

    def predict_proba(self, features):
        assert np.asarray(features).shape[0] == 1
        return np.array([[1.0 - self._genuine, self._genuine]])


def test_signature_embed_and_verify_roundtrip(tmp_path, jpeg_bytes):
    pytest.importorskip("tensorflow")  # resnet50 preprocess_input

    app = create_app(_settings(tmp_path, signature_distance_threshold=0.5))
    with TestClient(app) as client:
        app.state.registry._models["siamese"] = _StubEmbedderModel()

        embedding = client.post(
            "/v1/signature/embed", headers=AUTH, files=_upload(jpeg_bytes)
        ).json()["embedding"]

        same = client.post(
            "/v1/signature/verify", headers=AUTH, files=_upload(jpeg_bytes),
            data={"reference_embedding": json.dumps(embedding)},
        ).json()
        other = client.post(
            "/v1/signature/verify", headers=AUTH, files=_upload(jpeg_bytes),
            data={"reference_embedding": json.dumps([v + 1.0 for v in embedding])},
        ).json()

    assert same["match"] is True
    assert same["distance"] == pytest.approx(0.0, abs=1e-6)
    assert same["threshold"] == pytest.approx(0.5)
    assert same["embedding"] == pytest.approx(embedding)
    assert other["match"] is False
    assert other["distance"] > 0.5


def test_signature_verify_without_threshold_reports_distance_only(tmp_path, jpeg_bytes):
    pytest.importorskip("tensorflow")

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["siamese"] = _StubEmbedderModel()
        embedding = client.post(
            "/v1/signature/embed", headers=AUTH, files=_upload(jpeg_bytes)
        ).json()["embedding"]
        body = client.post(
            "/v1/signature/verify", headers=AUTH, files=_upload(jpeg_bytes),
            data={"reference_embedding": json.dumps(embedding)},
        ).json()

    # §9: no empirical threshold yet -> report the distance, no verdict.
    assert body["match"] is None
    assert body["threshold"] is None
    assert body["distance"] == pytest.approx(0.0, abs=1e-6)


def test_signature_verify_rejects_reference_length_mismatch(tmp_path, jpeg_bytes):
    pytest.importorskip("tensorflow")

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["siamese"] = _StubEmbedderModel()
        response = client.post(
            "/v1/signature/verify", headers=AUTH, files=_upload(jpeg_bytes),
            data={"reference_embedding": json.dumps([0.1, 0.2, 0.3, 0.4])},
        )
    assert response.status_code == 422


def test_stamp_verify_matches_genuine_and_rejects_mismatch(tmp_path, jpeg_bytes):
    pytest.importorskip("tensorflow")  # efficientnet preprocess_input

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["stamp"] = _StubEmbedderModel()

        vector = client.post(
            "/v1/stamp/embed", headers=AUTH, files=_upload(jpeg_bytes)
        ).json()["vector"]

        same = client.post(
            "/v1/stamp/verify", headers=AUTH, files=_upload(jpeg_bytes),
            data={"reference_vector": json.dumps(vector), "document_type": "bir_certificate"},
        ).json()
        opposite = client.post(
            "/v1/stamp/verify", headers=AUTH, files=_upload(jpeg_bytes),
            data={"reference_vector": json.dumps([-v for v in vector])},
        ).json()

    assert same["match"] is True
    assert same["similarity_score"] == pytest.approx(1.0)
    assert same["threshold"] == pytest.approx(0.85)  # §9 default
    assert same["document_type"] == "bir_certificate"
    assert opposite["match"] is False
    assert opposite["similarity_score"] < 0.85


def test_stamp_verify_without_reference_flags_unreferenced_logo(tmp_path, jpeg_bytes):
    pytest.importorskip("tensorflow")  # the crop is now always embedded

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["stamp"] = _StubEmbedderModel()
        body = client.post(
            "/v1/stamp/verify", headers=AUTH, files=_upload(jpeg_bytes),
            data={"document_type": "business_permit", "city": "Makati"},
        ).json()

    assert body["match"] is False
    assert body["reason"] == "unreferenced_logo"
    assert body["similarity_score"] is None
    assert body["city"] == "Makati"
    # No stamp_classifier loaded -> the check could not run; not "clean".
    assert body["stamp_tampered"] is None


def test_stamp_verify_runs_the_tamper_check_without_a_reference(tmp_path, jpeg_bytes):
    """§5 Stage 4b: 'Tamper check (always runs, reference or not)'. An issuer with
    no reference logo yet must still get the wet-ink-vs-reproduction verdict."""
    pytest.importorskip("tensorflow")  # efficientnet preprocess_input

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["stamp"] = _StubEmbedderModel()
        app.state.registry._models["stamp_classifier"] = _StubStampClassifier(0.10)

        body = client.post(
            "/v1/stamp/verify", headers=AUTH, files=_upload(jpeg_bytes),
            data={"document_type": "business_permit", "city": "Makati"},
        ).json()

    assert body["reason"] == "unreferenced_logo"      # unchanged
    assert body["similarity_score"] is None           # unchanged
    assert body["stamp_tampered"] is True
    assert body["genuine_probability"] == pytest.approx(0.10)


def test_stamp_embed_crops_the_requested_box_and_returns_it(tmp_path, jpeg_bytes):
    """The issuer-reference seeding path (EnrollReferenceJob) sends the FULL document
    plus Stage 4's detection box, because those coordinates are in the page space
    this service rendered. The crop comes back so the caller can persist exactly
    what was embedded as reference_image_path."""
    import base64
    import io as _io

    from PIL import Image as _Image

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["stamp"] = _StubEmbedderModel()

        body = client.post(
            "/v1/stamp/embed", headers=AUTH, files=_upload(jpeg_bytes),
            data={"box": json.dumps([10, 20, 60, 90])},
        ).json()

    assert body["vector"]
    crop = _Image.open(_io.BytesIO(base64.b64decode(body["crop_png_base64"])))
    assert crop.size == (50, 70)  # x2-x1, y2-y1


def test_stamp_embed_without_a_box_is_unchanged(tmp_path, jpeg_bytes):
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["stamp"] = _StubEmbedderModel()
        body = client.post("/v1/stamp/embed", headers=AUTH, files=_upload(jpeg_bytes)).json()

    assert body["vector"]
    assert body["crop_png_base64"] is None


@pytest.mark.parametrize("box", ["not-json", "[1,2,3]", '["a","b","c","d"]'])
def test_stamp_embed_rejects_a_malformed_box(tmp_path, jpeg_bytes, box):
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["stamp"] = _StubEmbedderModel()
        response = client.post(
            "/v1/stamp/embed", headers=AUTH, files=_upload(jpeg_bytes), data={"box": box},
        )

    assert response.status_code == 422


# --------------------------------------------------------------------------- #
# validate (fail-forward composite)
# --------------------------------------------------------------------------- #
def test_validate_is_fail_forward_without_models(client, jpeg_bytes):
    response = client.post("/v1/validate", headers=AUTH, files=_upload(jpeg_bytes))
    assert response.status_code == 200

    body = response.json()
    stages = body["stages"]
    assert set(stages) == {"classification", "ocr", "detection", "signature", "stamp", "tamper"}

    for name in ("classification", "detection", "signature", "stamp"):
        assert stages[name]["skipped"] is True
    assert stages["classification"]["reason"] == "model_not_loaded"
    assert stages["detection"]["reason"] == "model_not_loaded"

    # Stage T always runs — it needs no trained model.
    assert stages["tamper"].get("skipped") is not True
    assert "tamper_score" in stages["tamper"]

    assert isinstance(body["flags"], list)
    assert len(body["flags"]) == len(set(body["flags"]))  # deduped


class _StubBox:
    def __init__(self, cls: int, conf: float, xyxy: list[float]):
        self.cls = cls
        self.conf = conf
        # ultralytics hands back a tensor/array row, and run_detection calls
        # .tolist() on it — a bare list would not survive that.
        self.xyxy = [np.asarray(xyxy)]


class _StubResult:
    def __init__(self, names: dict[int, str], boxes: list[_StubBox]):
        self.names = names
        self.boxes = boxes


class _StubDetector:
    """Just enough of the ultralytics YOLO surface for run_detection."""

    names = {0: "signature", 1: "stamp"}

    def predict(self, source=None, conf=0.0, verbose=False):
        return [_StubResult(self.names, [_StubBox(1, 0.9, [5.0, 5.0, 60.0, 60.0])])]


def test_validate_flags_a_tampered_stamp(tmp_path, jpeg_bytes):
    """A reproduction detected on the crop must reach the officer as a flag even
    though the issuer has no reference logo yet (§5 Stage 4b)."""
    pytest.importorskip("tensorflow")

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["detector"] = _StubDetector()
        app.state.registry._models["stamp"] = _StubEmbedderModel()
        app.state.registry._models["stamp_classifier"] = _StubStampClassifier(0.05)

        body = client.post("/v1/validate", headers=AUTH, files=_upload(jpeg_bytes)).json()

    assert body["stages"]["stamp"]["stamp_tampered"] is True
    assert "stamp_tampered" in body["flags"]
    assert "unreferenced_logo" in body["flags"]


def test_canonical_city_matches_the_form_laravel_stores():
    from api.routers.validate import canonical_city

    assert canonical_city("CITY OF DIGOS") == "City Of Digos"
    assert canonical_city("City of  Digos ") == "City Of Digos"
    assert canonical_city("") is None
    assert canonical_city(None) is None


def test_resolve_issuer_reference_uses_the_national_sentinel():
    """logo_references.city is '' for a national issuer (one logo agency-wide),
    so the city read off the page is irrelevant to the lookup (§5 Stage 4b)."""
    from api.routers.validate import resolve_issuer_reference

    references = {"": [1.0, 2.0], "Pasig": [3.0, 4.0]}

    vector, flag = resolve_issuer_reference(references, None, "national", "Pasig")

    assert vector == [1.0, 2.0]
    assert flag is None


def test_resolve_issuer_reference_scopes_an_lgu_issuer_by_city():
    from api.routers.validate import resolve_issuer_reference

    references = {"Pasig": [3.0, 4.0], "Quezon City": [5.0, 6.0]}

    assert resolve_issuer_reference(references, None, "lgu", "quezon  CITY")[0] == [5.0, 6.0]
    # An LGU city with no seeded reference yet is a miss, not a mis-scope.
    assert resolve_issuer_reference(references, None, "lgu", "Makati") == (None, None)


def test_resolve_issuer_reference_flags_an_unreadable_lgu_city():
    """§5 Stage 4b failure path: 'City not identified' — the lookup cannot be
    scoped, so verification is skipped rather than compared to a wrong city."""
    from api.routers.validate import resolve_issuer_reference

    assert resolve_issuer_reference({"Pasig": [3.0]}, None, "lgu", None) == (None, "city_not_identified")


def test_classification_flags_a_fake_verdict():
    from api.routers.validate import classification_flags

    stage = {"label": "fake", "confidence": 0.98, "passed_threshold": True}

    assert classification_flags(stage, "bir_certificate", CLASSES) == ["classified_as_fake"]


def test_classification_flags_a_confident_disagreement_with_the_declared_type():
    from api.routers.validate import classification_flags

    stage = {"label": "business_permit", "confidence": 0.93, "passed_threshold": True}

    assert classification_flags(stage, "bir_certificate", CLASSES) == ["document_type_mismatch"]


def test_classification_does_not_flag_a_type_the_model_never_learned():
    """sanitary_permit has no class, so the model CANNOT agree with it — that is
    an untrained type, not vendor misdeclaration."""
    from api.routers.validate import classification_flags

    stage = {"label": "business_permit", "confidence": 0.93, "passed_threshold": True}

    assert classification_flags(stage, "sanitary_permit", CLASSES) == []


def test_classification_does_not_flag_a_mismatch_it_is_unsure_about():
    from api.routers.validate import classification_flags

    stage = {"label": "business_permit", "confidence": 0.41, "passed_threshold": False}

    assert classification_flags(stage, "bir_certificate", CLASSES) == []


def test_validate_verifies_an_lgu_logo_against_the_matching_city_reference(tmp_path, jpeg_bytes):
    """The whole city→vector set travels with the request; this service picks
    the one for the document's city (§5 Stage 2 → Stage 4b)."""
    pytest.importorskip("tensorflow")

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["detector"] = _StubDetector()
        app.state.registry._models["stamp"] = _StubEmbedderModel()

        vector = client.post(
            "/v1/stamp/embed", headers=AUTH, files=_upload(jpeg_bytes)
        ).json()["vector"]

        body = client.post(
            "/v1/validate", headers=AUTH, files=_upload(jpeg_bytes),
            data={
                "issuer_scope": "lgu",
                "city": "PASIG  CITY",
                "stamp_references": json.dumps({
                    "Pasig City": vector,
                    "Makati": [-v for v in vector],
                }),
            },
        ).json()

    stamp = body["stages"]["stamp"]
    assert stamp["city"] == "Pasig City"
    assert stamp["match"] is True
    assert stamp["similarity_score"] == pytest.approx(1.0)
    assert "unreferenced_logo" not in body["flags"]


def test_validate_flags_an_lgu_document_with_no_readable_city(tmp_path, jpeg_bytes):
    """§5 Stage 4b: no city → 'City not identified'; the tamper check still ran."""
    pytest.importorskip("tensorflow")

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["detector"] = _StubDetector()
        app.state.registry._models["stamp"] = _StubEmbedderModel()

        body = client.post(
            "/v1/validate", headers=AUTH, files=_upload(jpeg_bytes),
            data={"issuer_scope": "lgu",
                  "stamp_references": json.dumps({"Pasig City": [1.0, 2.0, 3.0]})},
        ).json()

    assert body["stages"]["stamp"]["reason"] == "city_not_identified"
    assert "city_not_identified" in body["flags"]
    assert "stamp_tampered" in body["stages"]["stamp"]  # the check still ran


def test_validate_rejects_a_malformed_reference_map(client, jpeg_bytes):
    response = client.post(
        "/v1/validate", headers=AUTH, files=_upload(jpeg_bytes),
        data={"stamp_references": "[1, 2, 3]"},
    )
    assert response.status_code == 422


def test_validate_rejects_malformed_reference(client, jpeg_bytes):
    response = client.post(
        "/v1/validate", headers=AUTH, files=_upload(jpeg_bytes),
        data={"signature_reference": "not-a-json-array"},
    )
    assert response.status_code == 422


def test_validate_ignores_malformed_forensics(client, jpeg_bytes):
    """Bad forensics JSON fails forward (ignored), not a 422 — Stage T still runs."""
    response = client.post(
        "/v1/validate", headers=AUTH, files=_upload(jpeg_bytes),
        data={"forensics": "not-json"},
    )
    assert response.status_code == 200
    assert "tamper_score" in response.json()["stages"]["tamper"]


def test_validate_forwards_forensics_threshold_to_tamper(client, jpeg_bytes):
    """The forensics form field's tamper_threshold reaches Stage T's pass gate,
    proving Laravel's admin-tuned forensics config is honoured server-side."""
    baseline = client.post(
        "/v1/validate", headers=AUTH, files=_upload(jpeg_bytes)
    ).json()["stages"]["tamper"]
    authenticity = baseline["tamper_authenticity"]
    if baseline["hard_flag"] or not 0.02 < authenticity < 0.98:
        pytest.skip("synthetic page can't cleanly straddle the tamper gate")

    def passed(threshold: float) -> bool:
        return client.post(
            "/v1/validate", headers=AUTH, files=_upload(jpeg_bytes),
            data={"forensics": json.dumps({"tamper_threshold": threshold})},
        ).json()["stages"]["tamper"]["tamper_passed"]

    # Same document → same authenticity; only the forwarded gate should move.
    assert passed(authenticity - 0.02) is True
    assert passed(authenticity + 0.02) is False
