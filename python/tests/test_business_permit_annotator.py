"""Unit tests for the pure (non-Tk) logic of scripts/annotator.py.

(The module was renamed from ``business_permit_annotator.py``; this file keeps
its original name so the test path stays stable in history.)

Covers the runtime label prompt (slugify -> de-dup -> ordering), the prompt
loop's re-ask / EOF behavior, template resolution, and the fact that the tricky
coordinate/box helpers are REUSED from annotate_boxes (so the BIR and
business-permit annotators stay pixel-for-pixel consistent). Importing the
module does NOT open a Tk window (all Tk lives inside main()/the App class), so
these run headless.
"""
from __future__ import annotations

import sys
from pathlib import Path

# annotator.py lives in python/scripts/ (sibling dir to python/tests/),
# exactly like annotate_boxes.py.
SCRIPTS_DIR = Path(__file__).resolve().parents[1] / "scripts"
if str(SCRIPTS_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_DIR))

import annotate_boxes as ab  # noqa: E402
import annotator as bpa  # noqa: E402


# ----- slugify_label --------------------------------------------------------
def test_slugify_label_snake_cases_a_field_name():
    assert bpa.slugify_label("NAME OF PROPRIETOR") == "name_of_proprietor"
    assert bpa.slugify_label("  Trade   Name  ") == "trade_name"
    assert bpa.slugify_label("Kind of Business!!") == "kind_of_business"


def test_slugify_label_empty_for_punctuation_or_blank_only():
    assert bpa.slugify_label("   ") == ""
    assert bpa.slugify_label("--/--") == ""


# ----- parse_labels ---------------------------------------------------------
def test_parse_labels_splits_commas_semicolons_and_newlines():
    raw = "name_of_proprietor, trade name; business location\nkind of business"
    assert bpa.parse_labels(raw) == [
        "name_of_proprietor",
        "trade_name",
        "business_location",
        "kind_of_business",
    ]


def test_parse_labels_dedups_preserving_first_seen_order():
    assert bpa.parse_labels("trade name, Trade  Name, trade_name, mayor") == ["trade_name", "mayor"]


def test_parse_labels_drops_blanks_and_returns_empty_for_no_input():
    assert bpa.parse_labels("") == []
    assert bpa.parse_labels("   ,  ; \n") == []


# ----- prompt_labels (injectable I/O) --------------------------------------
def test_prompt_labels_returns_parsed_labels_on_first_valid_line():
    out: list[str] = []
    labels = bpa.prompt_labels(input_func=lambda _prompt: "trade name, mayor", output=out.append)
    assert labels == ["trade_name", "mayor"]


def test_prompt_labels_reprompts_until_a_valid_label_is_entered():
    answers = iter(["", "   ", "business location"])
    labels = bpa.prompt_labels(input_func=lambda _prompt: next(answers), output=lambda *_: None)
    assert labels == ["business_location"]


def test_prompt_labels_returns_empty_on_eof():
    def _raise_eof(_prompt):
        raise EOFError

    assert bpa.prompt_labels(input_func=_raise_eof, output=lambda *_: None) == []


# ----- resolve_template -----------------------------------------------------
def test_resolve_template_prefers_explicit_existing_path(tmp_path):
    img = tmp_path / "custom_permit.png"
    img.write_bytes(b"not-a-real-png")
    assert bpa.resolve_template(str(img)) == img


def test_resolve_template_returns_none_for_missing_explicit_path():
    assert bpa.resolve_template("does/not/exist.png") is None


def test_resolve_template_defaults_to_a_bundled_business_permit():
    # The blank Digos template ships in the repo; the no-arg call should find it.
    template = bpa.resolve_template()
    assert template is not None
    assert template.exists()
    assert template.suffix.lower() == ".png"


# ----- geometry reuse (not duplication) ------------------------------------
def test_geometry_helpers_are_the_same_objects_as_annotate_boxes():
    assert bpa.compute_fit is ab.compute_fit
    assert bpa.canvas_to_image is ab.canvas_to_image
    assert bpa.image_to_canvas is ab.image_to_canvas
    assert bpa.normalize_box is ab.normalize_box
    assert bpa.boxes_to_json is ab.boxes_to_json


def test_boxes_to_json_shape_via_reused_helper():
    store = {"trade_name": (10, 20, 30, 40)}
    assert bpa.boxes_to_json(store) == {"trade_name": {"x": 10, "y": 20, "w": 30, "h": 40}}
