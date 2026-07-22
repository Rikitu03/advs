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

    def predict(self, batch, verbose=0):
        assert batch.shape == (1, 64, 64, 3)
        return np.array([[0.15, 0.85]])


# --------------------------------------------------------------------------- #
# health + auth
# --------------------------------------------------------------------------- #
def test_health_is_open_and_reports_missing_models(client):
    response = client.get("/health")
    assert response.status_code == 200

    body = response.json()
    assert body["status"] == "ok"
    assert set(body["models"]) == {"classifier", "detector", "siamese", "stamp"}
    for status in body["models"].values():
        assert status["loaded"] is False
        assert status["error"] == "weights_not_found"


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
    pytest.importorskip("tensorflow")  # run_classification uses resnet50 preprocess_input

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


def test_ocr_rejects_unknown_template(client, jpeg_bytes):
    response = client.post(
        "/v1/ocr", headers=AUTH, files=_upload(jpeg_bytes), data={"template": "sec"}
    )
    assert response.status_code == 422


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
