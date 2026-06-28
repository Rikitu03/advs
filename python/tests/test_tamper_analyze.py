"""Deterministic unit tests for the document-tampering forensics (Stage T).

Each technique is exercised with a clean sample and a synthetically tampered one
generated at runtime (no committed binaries). The image techniques assert that a
tampered sample is scored as *more suspicious* than its clean counterpart; the
pure-Python techniques (font, cross-reference) assert exact flag behaviour.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

import numpy as np
import pytest

PY_ROOT = Path(__file__).resolve().parents[1]
if str(PY_ROOT) not in sys.path:
    sys.path.insert(0, str(PY_ROOT))

from forensics import aggregate, copy_move, cross_reference, ela, font_consistency, metadata  # noqa: E402

cv2 = pytest.importorskip("cv2")


# --------------------------------------------------------------------------- #
# fixtures / helpers
# --------------------------------------------------------------------------- #
def _smooth_image(h=320, w=320, seed=0):
    """A photo-like image: low-frequency colour gradient + mild texture."""
    rng = np.random.default_rng(seed)
    yy, xx = np.mgrid[0:h, 0:w]
    base = np.stack([
        (xx / w * 200 + 30),
        (yy / h * 200 + 30),
        ((xx + yy) / (w + h) * 200 + 30),
    ], axis=-1)
    base += rng.normal(0, 4, base.shape)
    return np.clip(base, 0, 255).astype(np.uint8)


def _noise_image(h=400, w=400, seed=1):
    rng = np.random.default_rng(seed)
    return rng.integers(0, 256, size=(h, w, 3), dtype=np.uint8)


def _word(text, x, y, w, h, conf=90):
    return {"text": text, "conf": conf, "bbox": [x, y, w, h]}


# --------------------------------------------------------------------------- #
# T4 font consistency (pure python)
# --------------------------------------------------------------------------- #
def test_font_consistency_flags_outlier_field():
    words = [_word(f"WORD{i}", 10 + i * 60, 50, 50, 30) for i in range(10)]
    words.append(_word("TAMPERED", 10, 200, 90, 58))  # ~2x the baseline height
    result = font_consistency.analyze(words)
    assert result["pass"] is False
    assert any("TAMPERED" in f for f in result["flags"])
    assert result["outliers"] and result["outliers"][0]["text"] == "TAMPERED"


def test_font_consistency_clean_document_passes():
    words = [_word(f"WORD{i}", 10 + i * 60, 50, 50, 30) for i in range(12)]
    result = font_consistency.analyze(words)
    assert result["pass"] is True
    assert result["flags"] == []


def test_font_consistency_skips_when_too_few_words():
    result = font_consistency.analyze([_word("A", 0, 0, 10, 10)])
    assert result.get("skipped") is True


# --------------------------------------------------------------------------- #
# T5 cross reference (pure python)
# --------------------------------------------------------------------------- #
def test_cross_reference_accepts_valid_tin():
    text = "BUREAU OF INTERNAL REVENUE\nTIN: 274-118-902-000\nRegistered Name: SANTOS"
    result = cross_reference.analyze(text)
    assert result["pass"] is True
    assert result["checks"]["tin"]["valid"] is True


def test_cross_reference_flags_malformed_tin():
    text = "TIN: 274-118-90\nRegistered Name: SANTOS"
    result = cross_reference.analyze(text)
    assert result["pass"] is False
    assert any("Malformed TIN" in f for f in result["flags"])


def test_cross_reference_validate_tin_helper():
    assert cross_reference.validate_tin("274-118-902-000") is True
    assert cross_reference.validate_tin("274-118-902") is True
    assert cross_reference.validate_tin("274-118-9") is False


# --------------------------------------------------------------------------- #
# T1 metadata
# --------------------------------------------------------------------------- #
def test_metadata_flags_editor_software(tmp_path):
    import piexif
    from PIL import Image

    path = tmp_path / "edited.jpg"
    Image.fromarray(_smooth_image()).save(path, "JPEG")
    exif = {"0th": {piexif.ImageIFD.Software: b"Adobe Photoshop 25.0 (Windows)"}, "Exif": {}, "1st": {}, "GPS": {}, "Interop": {}}
    piexif.insert(piexif.dump(exif), str(path))

    result = metadata.analyze(str(path))
    assert result["pass"] is False
    assert any("editor" in f.lower() or "photoshop" in f.lower() for f in result["flags"])


def test_metadata_clean_image_has_no_editor_flag(tmp_path):
    from PIL import Image

    path = tmp_path / "clean.jpg"
    Image.fromarray(_smooth_image()).save(path, "JPEG")
    result = metadata.analyze(str(path))
    assert not any("editor" in f.lower() for f in result["flags"])


# --------------------------------------------------------------------------- #
# T2 ELA
# --------------------------------------------------------------------------- #
def test_ela_detects_pasted_region(tmp_path):
    clean_path = tmp_path / "clean.jpg"
    cv2.imwrite(str(clean_path), _smooth_image(), [int(cv2.IMWRITE_JPEG_QUALITY), 92])

    # Tamper: paste an uncompressed high-detail patch into the recompressed image.
    base = cv2.imread(str(clean_path))
    patch = _noise_image(64, 64, seed=7)
    base[120:184, 120:184] = patch
    tampered_path = tmp_path / "tampered.jpg"
    cv2.imwrite(str(tampered_path), base, [int(cv2.IMWRITE_JPEG_QUALITY), 92])

    clean = ela.analyze(str(clean_path))
    tampered = ela.analyze(str(tampered_path))

    assert clean["pass"] is True
    assert tampered["regions"], "ELA should flag the pasted region"
    assert tampered["score"] < clean["score"]


# --------------------------------------------------------------------------- #
# T3 copy-move
# --------------------------------------------------------------------------- #
def test_copy_move_detects_clone(tmp_path):
    img = _noise_image(420, 420, seed=3)
    img[20:120, 20:120] = img[260:360, 260:360]  # clone a 100x100 region elsewhere
    path = tmp_path / "cloned.png"
    cv2.imwrite(str(path), img)

    result = copy_move.analyze(str(path))
    assert result["pass"] is False
    assert result["top_votes"] >= copy_move.MIN_CLONE_MATCHES


def test_copy_move_clean_image_passes(tmp_path):
    path = tmp_path / "clean.png"
    cv2.imwrite(str(path), _noise_image(420, 420, seed=11))
    result = copy_move.analyze(str(path))
    assert result["pass"] is True


# --------------------------------------------------------------------------- #
# aggregate + entry point
# --------------------------------------------------------------------------- #
def test_aggregate_blends_and_flags_tampering():
    techniques = {
        "metadata": metadata.analyze(None),                # skipped
        "ela": {"score": 0.4, "threshold": 0.85, "pass": False, "flags": ["ela"], "detail": ""},
        "copy_move": {"score": 0.2, "threshold": 0.85, "pass": False, "flags": ["clone"], "detail": ""},
        "font": {"score": 1.0, "threshold": 0.999, "pass": True, "flags": [], "detail": ""},
        "cross_reference": {"score": 1.0, "threshold": 0.999, "pass": True, "flags": [], "detail": ""},
    }
    verdict = aggregate(techniques)
    assert 0.0 < verdict["tamper_score"] < 1.0
    assert verdict["tamper_confidence"] == pytest.approx(0.8, abs=1e-6)  # strongest = 1 - 0.2
    assert set(verdict["flags"]) == {"ela", "clone"}


def test_aggregate_excludes_skipped_weight():
    techniques = {"ela": metadata.skipped_result("x"), "font": {"score": 1.0, "threshold": 0.9, "pass": True, "flags": []}}
    verdict = aggregate(techniques)
    assert verdict["tamper_score"] == 0.0
    assert verdict["tamper_passed"] is True


def test_runner_end_to_end(tmp_path):
    import importlib.util

    spec = importlib.util.spec_from_file_location("tamper_analyze", PY_ROOT / "scripts" / "tamper_analyze.py")
    runner = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(runner)

    img = _noise_image(420, 420, seed=5)
    img[10:110, 10:110] = img[280:380, 280:380]  # clone
    img_path = tmp_path / "doc.png"
    cv2.imwrite(str(img_path), img)

    payload = {
        "original_path": str(img_path),
        "page_images": [str(img_path)],
        "ocr_text": "TIN: 274-118-90",  # malformed
        "ocr_words": [],
    }
    in_path = tmp_path / "payload.json"
    out_path = tmp_path / "result.json"
    in_path.write_text(json.dumps(payload))

    rc = runner.main(["--input", str(in_path), "--output", str(out_path)])
    assert rc == 0

    result = json.loads(out_path.read_text())
    assert result["tamper_passed"] is False
    assert result["tamper_score"] > 0
    assert any("Copy-move" in f for f in result["flags"])
    assert any("Malformed TIN" in f for f in result["flags"])
