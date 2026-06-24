"""Unit tests for the pure (non-Tk) logic of annotate_boxes.py.

Covers the tricky coordinate conversions (scale + centering padding), box
normalization, JSON export shape, and the keyword-loading + fallback. Importing
the module does NOT open a Tk window (all Tk lives inside main()/AnnotatorApp),
so these run headless.
"""
from __future__ import annotations

import sys
from pathlib import Path

# annotate_boxes.py lives at the project root (two levels up from python/tests/).
PROJECT_ROOT = Path(__file__).resolve().parents[2]
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

import annotate_boxes as ab  # noqa: E402


def test_compute_fit_full_size_is_identity():
    scale, ox, oy, dw, dh = ab.compute_fit(700, 887, 700, 887)
    assert scale == 1.0
    assert (ox, oy) == (0.0, 0.0)
    assert (dw, dh) == (700.0, 887.0)


def test_compute_fit_preserves_aspect_and_centers():
    # Width is the binding dimension: 400/800 = 0.5; height has slack -> padded.
    scale, ox, oy, dw, dh = ab.compute_fit(800, 400, 400, 400)
    assert abs(scale - 0.5) < 1e-9
    assert abs(dw - 400) < 1e-9 and abs(dh - 200) < 1e-9
    assert abs(ox - 0) < 1e-9          # no horizontal slack
    assert abs(oy - 100) < 1e-9        # 200px vertical slack split top/bottom


def test_canvas_image_roundtrip():
    scale, ox, oy = 0.5, 30.0, 12.0
    cx, cy = ab.image_to_canvas(123, 456, scale, ox, oy)
    ix, iy = ab.canvas_to_image(cx, cy, scale, ox, oy, 700, 887)
    assert abs(ix - 123) < 1e-6 and abs(iy - 456) < 1e-6


def test_canvas_to_image_clamps_into_bounds():
    # Off the top-left of the image -> clamped to (0, 0).
    ix, iy = ab.canvas_to_image(-100, -100, 0.5, 20, 20, 700, 887)
    assert (ix, iy) == (0.0, 0.0)
    # Far off the bottom-right -> clamped to (img_w, img_h).
    ix, iy = ab.canvas_to_image(10_000, 10_000, 0.5, 20, 20, 700, 887)
    assert (ix, iy) == (700.0, 887.0)


def test_normalize_box_handles_any_drag_direction():
    assert ab.normalize_box(100, 80, 40, 20) == (40, 20, 60, 60)   # up-left drag
    assert ab.normalize_box(40, 20, 100, 80) == (40, 20, 60, 60)   # down-right drag


def test_boxes_to_json_shape():
    store = {"tin": (10, 20, 30, 40)}
    assert ab.boxes_to_json(store) == {"tin": {"x": 10, "y": 20, "w": 30, "h": 40}}


def test_resolve_keywords_falls_back_to_default_when_empty():
    assert ab.resolve_keywords([]) == ab.DEFAULT_KEYWORDS
    assert ab.resolve_keywords(["a", "b"]) == ["a", "b"]


def test_keywords_loaded_from_ocr_dryrun_include_field_keys():
    # Validates the documented import path AND the KEYWORD_LIST added to ocr_dryrun.py.
    kws = ab.keywords_from_ocr_module()
    assert "tin" in kws
    assert "registered_name" in kws
    assert "registration_date" in kws
    # Detection regions are NOT OCR fields, so they must not be in the field list.
    assert "dry_seal" not in kws
    assert "signature_over_name" not in kws


def test_load_keywords_appends_detection_regions_after_fields():
    kws = ab.load_keywords()
    # OCR fields still present...
    assert "tin" in kws and "registered_name" in kws
    # ...and the two detection regions are appended at the end, de-duplicated.
    assert "signature_over_name" in kws
    assert "dry_seal" in kws
    assert kws[-2:] == ["signature_over_name", "dry_seal"]
    assert len(kws) == len(set(kws))
