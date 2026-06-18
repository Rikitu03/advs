"""Unit + small-batch tests for scripts/bir_dataset_generator.py.

Pure logic (record generation, hashing, manifest dedup, font-fit, alpha keying)
is exercised in isolation; the batch test writes only into tmp_path, never the
real classifier folder. The module is loaded by path (repo convention).
"""
import importlib.util
import re
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

_SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "bir_dataset_generator.py"
_spec = importlib.util.spec_from_file_location("bir_dataset_generator", _SCRIPT)
gen = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(gen)

FONT_DIR = Path(__file__).resolve().parents[1] / "data" / "fonts"


def test_courier_prime_is_vendored_and_loadable():
    path = FONT_DIR / "CourierPrime-Regular.ttf"
    assert path.exists(), f"vendor Courier Prime into {FONT_DIR}"
    font = ImageFont.truetype(str(path), 12)
    assert font.size == 12
