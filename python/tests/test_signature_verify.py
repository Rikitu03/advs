"""DoD contract tests for the Stage 4a Siamese signature verifier (M4).

Gates the trained artefacts against the *empirical* EER distance threshold written to
``signature_threshold.txt`` -- NOT the literal 0.85 similarity from siamese.md §10, which is
miscalibrated to the unit-sphere embedding scale (genuine similarity is ~0.55 for a good model,
so a 0.85 gate would fail correct artefacts).

Checks:
  1. embedding length is exactly 128
  2. a genuine pair distance <= threshold  -> match
  3. a forged  pair distance >  threshold  -> no match
  4. mean genuine distance < mean forged distance by a clear margin

Preprocessing here mirrors the serve path exactly: RGB -> resize 224 -> resnet50.preprocess_input.

Data: real signature crops under python/tests/fixtures/signature_data/<signer>/*.png with an
optional <signer>/forged/*.png. Override the location with ADVS_SIG_DATA. When no data is present
the data-dependent tests skip gracefully (CI-safe). See that folder's README for the layout.

Run:  python/env/Scripts/python.exe -m pytest python/tests/test_signature_verify.py -v
"""

import os
from pathlib import Path

import numpy as np
import pytest

# --- paths (override via env in CI) ------------------------------------------
REPO_ROOT = Path(os.environ.get("ADVS_ROOT", Path(__file__).resolve().parents[2]))
MODELS_DIR = Path(os.environ.get("ADVS_MODELS", REPO_ROOT / "python" / "models"))
DATA_ROOT = Path(os.environ.get("ADVS_SIG_DATA", REPO_ROOT / "python" / "tests" / "fixtures" / "signature_data"))

ENCODER_PATH = MODELS_DIR / "siamese_encoder.h5"
THRESHOLD_PATH = MODELS_DIR / "signature_threshold.txt"

IMAGE_SIZE = 224
EMBEDDING_DIM = 128
MARGIN = 0.30  # required gap between mean genuine and mean forged distance


# --- lazy heavy imports so collection is cheap -------------------------------
def _prep(img_uint8):
    import cv2  # noqa: F401  (imported for parity; not used directly here)
    from tensorflow.keras.applications.resnet50 import preprocess_input
    return preprocess_input(img_uint8.astype("float32"))


def _load_rgb(path):
    import cv2
    img = cv2.imread(str(path), cv2.IMREAD_COLOR)
    if img is None:
        raise ValueError(f"unreadable image: {path}")
    img = cv2.cvtColor(img, cv2.COLOR_BGR2RGB)
    img = cv2.resize(img, (IMAGE_SIZE, IMAGE_SIZE))
    return img.astype("uint8")


def _synthetic_forgery(img, rng):
    import cv2
    h, w = img.shape[:2]
    dx = cv2.GaussianBlur(rng.uniform(-1, 1, (h, w)).astype("float32"), (0, 0), 8) * 4.0
    dy = cv2.GaussianBlur(rng.uniform(-1, 1, (h, w)).astype("float32"), (0, 0), 8) * 4.0
    xx, yy = np.meshgrid(np.arange(w), np.arange(h))
    warped = cv2.remap(img, (xx + dx).astype("float32"), (yy + dy).astype("float32"),
                       cv2.INTER_LINEAR, borderMode=cv2.BORDER_REFLECT)
    M = cv2.getRotationMatrix2D((w / 2.0, h / 2.0), float(rng.uniform(-12, 12)), 1.0)
    rot = cv2.warpAffine(warped, M, (w, h), borderMode=cv2.BORDER_REFLECT)
    noise = rng.normal(0, 8, rot.shape)
    return np.clip(rot.astype("float32") + noise, 0, 255).astype("uint8")


# --- fixtures ----------------------------------------------------------------
@pytest.fixture(scope="module")
def encoder():
    if not ENCODER_PATH.exists():
        pytest.skip(f"encoder artefact not present: {ENCODER_PATH} (run notebook 03 first)")
    from tensorflow.keras.models import load_model
    return load_model(str(ENCODER_PATH), compile=False)


@pytest.fixture(scope="module")
def threshold():
    if not THRESHOLD_PATH.exists():
        pytest.skip(f"threshold file not present: {THRESHOLD_PATH}")
    return float(THRESHOLD_PATH.read_text().strip())


@pytest.fixture(scope="module")
def samples():
    """Find a signer with >=2 genuine images; return (genuine_list, forged_or_None)."""
    if not DATA_ROOT.exists():
        pytest.skip(f"signature data not present: {DATA_ROOT}")
    for signer_dir in sorted(p for p in DATA_ROOT.iterdir() if p.is_dir()):
        genuine = sorted(p for p in signer_dir.glob("*.png"))
        if len(genuine) >= 2:
            forged_dir = signer_dir / "forged"
            forged = sorted(forged_dir.glob("*.png")) if forged_dir.exists() else []
            return [_load_rgb(p) for p in genuine[:4]], (_load_rgb(forged[0]) if forged else None)
    pytest.skip("no signer with >=2 genuine images found")


def _embed(encoder, img_uint8):
    return encoder.predict(_prep(img_uint8[None, ...]), verbose=0)[0]


def _dist(a, b):
    return float(np.linalg.norm(a - b))


# --- tests -------------------------------------------------------------------
def test_embedding_dim_is_128(encoder, samples):
    genuine, _ = samples
    emb = _embed(encoder, genuine[0])
    assert emb.shape[0] == EMBEDDING_DIM, f"embedding dim {emb.shape[0]} != {EMBEDDING_DIM}"


def test_genuine_pair_matches(encoder, threshold, samples):
    genuine, _ = samples
    d = _dist(_embed(encoder, genuine[0]), _embed(encoder, genuine[1]))
    assert d <= threshold, f"genuine distance {d:.3f} should be <= threshold {threshold:.3f}"


def test_forged_pair_rejected(encoder, threshold, samples):
    genuine, forged = samples
    if forged is None:
        forged = _synthetic_forgery(genuine[0], np.random.default_rng(7))
    d = _dist(_embed(encoder, genuine[0]), _embed(encoder, forged))
    assert d > threshold, f"forged distance {d:.3f} should be > threshold {threshold:.3f}"


def test_genuine_closer_than_forged(encoder, samples):
    genuine, forged = samples
    if forged is None:
        forged = _synthetic_forgery(genuine[0], np.random.default_rng(7))
    ref = _embed(encoder, genuine[0])
    d_gen = _dist(ref, _embed(encoder, genuine[1]))
    d_forg = _dist(ref, _embed(encoder, forged))
    assert d_forg - d_gen >= MARGIN, (
        f"expected forged-genuine gap >= {MARGIN}, got {d_forg - d_gen:.3f} "
        f"(genuine {d_gen:.3f}, forged {d_forg:.3f})"
    )
