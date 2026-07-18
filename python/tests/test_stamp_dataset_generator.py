"""Tests for scripts/stamp_dataset_generator.py.

Pure transforms (alpha flattening, genuine variance, forgery perturbations,
split math) are exercised on tiny in-memory images; the batch test writes only
into tmp_path, never the real stamp_data folders. Module loaded by path (repo
convention).
"""
import importlib.util
from pathlib import Path

import cv2
import numpy as np
import pytest

_SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "stamp_dataset_generator.py"
_spec = importlib.util.spec_from_file_location("stamp_dataset_generator", _SCRIPT)
gen = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(gen)


def _stamp_like(size: int = 96) -> np.ndarray:
    """A colourful ring on white — enough structure for the transforms."""
    img = np.full((size, size, 3), 255, dtype="uint8")
    cv2.circle(img, (size // 2, size // 2), size // 3, (200, 40, 40), 4)
    cv2.putText(img, "OK", (size // 3, size // 2 + 5),
                cv2.FONT_HERSHEY_SIMPLEX, 0.6, (40, 40, 200), 2)
    return img


def test_discover_assets_finds_the_repo_artwork():
    assets = gen.discover_assets(Path(__file__).resolve().parents[1] / "data")
    for expected in ("bir_officer_stamp", "bir_seal", "dti_logo", "makati"):
        assert expected in assets, f"missing {expected} in {sorted(assets)}"


def test_load_asset_flattens_alpha_onto_white(tmp_path):
    rgba = np.zeros((40, 40, 4), dtype="uint8")
    rgba[10:30, 10:30] = (0, 0, 255, 255)  # opaque red square (BGRA), rest transparent
    src = tmp_path / "art.png"
    cv2.imwrite(str(src), rgba)

    out = gen.load_asset(src)
    assert out.shape == (gen.CANVAS, gen.CANVAS, 3)
    assert out[0, 0].tolist() == [255, 255, 255]  # transparent corner -> white
    assert (out == 255).mean() < 1.0              # the artwork survived


def _channel_spread(img: np.ndarray) -> float:
    out = img.astype(int)
    return float(np.abs(out[:, :, 0] - out[:, :, 2]).mean())


def test_genuine_variant_keeps_ink_colour():
    rng = np.random.default_rng(0)
    src = _stamp_like()
    for _ in range(5):
        assert _channel_spread(gen.genuine_variant(src, rng)) > 3.0
        assert gen.genuine_variant(src, rng).shape == src.shape


def test_photocopy_forgery_removes_ink_colour():
    rng = np.random.default_rng(1)
    out = gen.forge(_stamp_like(), rng, "photocopy")
    assert _channel_spread(out) < 1.0  # grayscale-ish: R ~= B everywhere


def test_erase_forgery_blanks_a_region():
    rng = np.random.default_rng(2)
    src = _stamp_like()
    src[:, :] = (128, 128, 128)  # uniform grey so erased white blocks stand out
    out = gen.forge(src, rng, "erase")
    assert (out == 255).all(axis=2).sum() > 0


def test_forge_rejects_unknown_kind():
    with pytest.raises(ValueError):
        gen.forge(_stamp_like(), np.random.default_rng(3), "solvent")


def test_split_counts_holds_out_a_validation_share():
    assert gen.split_counts(40, 0.2) == (32, 8)
    assert gen.split_counts(1, 0.2) == (1, 0)


def test_generate_writes_the_train_stamp_layout(tmp_path):
    data_root = tmp_path / "assets"
    (data_root / "stamps").mkdir(parents=True)
    cv2.imwrite(str(data_root / "stamps" / "TEST_STAMP.png"), _stamp_like())

    out_root = tmp_path / "out"
    counts = gen.generate(data_root, out_root, per_asset=5, val_fraction=0.2, seed=0)

    assert counts == {"train_genuine": 4, "train_forged": 4,
                      "val_genuine": 1, "val_forged": 1}
    for split, kind, n in (("training", "genuine", 4), ("training", "forged", 4),
                           ("validation", "genuine", 1), ("validation", "forged", 1)):
        files = list((out_root / split / "stamp_data" / kind).glob("*.png"))
        assert len(files) == n
        assert cv2.imread(str(files[0])) is not None
    forged_names = [p.name for p in (out_root / "training" / "stamp_data" / "forged").iterdir()]
    assert all(any(k in n for k in gen.FORGERY_KINDS) for n in forged_names)


def test_generate_replaces_stale_output(tmp_path):
    data_root = tmp_path / "assets"
    (data_root / "stamps").mkdir(parents=True)
    cv2.imwrite(str(data_root / "stamps" / "TEST_STAMP.png"), _stamp_like())

    out_root = tmp_path / "out"
    stale = out_root / "training" / "stamp_data" / "genuine"
    stale.mkdir(parents=True)
    (stale / "leftover_fixture.png").write_bytes(b"noise")

    counts = gen.generate(data_root, out_root, per_asset=5, val_fraction=0.2, seed=0)
    assert counts["train_genuine"] == 4
    assert not (stale / "leftover_fixture.png").exists()
    assert len(list(stale.glob("*.png"))) == 4


def test_generate_without_assets_raises():
    with pytest.raises(ValueError):
        gen.generate(Path("does-not-exist"), Path("out"), 5, 0.2, 0)
