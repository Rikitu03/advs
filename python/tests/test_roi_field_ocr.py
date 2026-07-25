"""Deterministic unit tests for scripts/roi_field_ocr.py (ROI+TrOCR field
recognition). Pure-function tests need no detector/TrOCR models - boxes and
crops are provided directly, matching test_ocr_dryrun.py's style of feeding
captured data straight into the function under test.
"""
from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
from unittest.mock import MagicMock

import pytest

_SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "roi_field_ocr.py"
_spec = importlib.util.spec_from_file_location("roi_field_ocr", _SCRIPT)
roi_field_ocr = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(roi_field_ocr)

ROI_CONFIG_PATH = Path(__file__).resolve().parents[1] / "api" / "ocr_roi_config.json"


# --------------------------------------------------------------------------- #
# match_rois
# --------------------------------------------------------------------------- #
def test_match_rois_unions_boxes_inside_the_roi():
    # A 1000x1000 page; the ROI covers x:[0.1,0.5] y:[0.1,0.3] in fractions.
    roi_config = {"tin": {"x": 0.1, "y": 0.1, "w": 0.4, "h": 0.2}}
    boxes = [
        (120.0, 120.0, 100.0, 30.0),   # fully inside
        (300.0, 150.0, 80.0, 40.0),    # fully inside, different row -> unioned
        (900.0, 900.0, 50.0, 20.0),    # far outside -> ignored
    ]
    matched = roi_field_ocr.match_rois(boxes, roi_config, image_size=(1000, 1000))
    assert set(matched) == {"tin"}
    x, y, w, h = matched["tin"]
    assert x == 120.0 and y == 120.0
    assert x + w == 380.0  # union reaches box 2's right edge (300+80)
    assert y + h == 190.0  # union reaches box 2's bottom edge (150+40)


def test_match_rois_respects_overlap_threshold():
    # ROI ends at x=200; box spans x:170-270 (100 wide) -> only 30% of the
    # box's own area is inside the ROI -> below the default 0.5 -> no match.
    roi_config = {"field": {"x": 0.0, "y": 0.0, "w": 0.2, "h": 1.0}}
    boxes = [(170.0, 0.0, 100.0, 100.0)]
    matched = roi_field_ocr.match_rois(boxes, roi_config, image_size=(1000, 100))
    assert matched == {}


def test_match_rois_returns_empty_for_field_with_no_hits():
    roi_config = {"a": {"x": 0.0, "y": 0.0, "w": 0.1, "h": 0.1},
                  "b": {"x": 0.9, "y": 0.9, "w": 0.1, "h": 0.1}}
    boxes = [(10.0, 10.0, 20.0, 20.0)]  # only inside "a"
    matched = roi_field_ocr.match_rois(boxes, roi_config, image_size=(1000, 1000))
    assert set(matched) == {"a"}


# --------------------------------------------------------------------------- #
# resolve_roi_config
# --------------------------------------------------------------------------- #
def test_resolve_roi_config_bir_and_dti_are_direct():
    roi_config = {"bir": {"tin": {}}, "dti": {"business_name": {}}}
    assert roi_field_ocr.resolve_roi_config(roi_config, "bir") == {"tin": {}}
    assert roi_field_ocr.resolve_roi_config(roi_config, "dti") == {"business_name": {}}


def test_resolve_roi_config_business_permit_needs_city():
    roi_config = {"business_permit": {"digos": {"trade_name": {}}}}
    assert roi_field_ocr.resolve_roi_config(roi_config, "business_permit", city=None) is None
    assert roi_field_ocr.resolve_roi_config(roi_config, "business_permit", city="CITY OF DIGOS") == {
        "trade_name": {}
    }
    assert roi_field_ocr.resolve_roi_config(roi_config, "business_permit", city="Digos") == {
        "trade_name": {}
    }


def test_resolve_roi_config_business_permit_unmapped_city_falls_back():
    # A city with no ROI config entry at all (e.g. a future LGU layout not
    # yet added to generate_roi_config.py's _CITY_LAYOUTS) must resolve to
    # None, not raise, so the caller's fallback to label+regex kicks in.
    roi_config = {"business_permit": {"digos": {"trade_name": {}}}}
    assert roi_field_ocr.resolve_roi_config(roi_config, "business_permit", city="Quezon City") is None


def test_resolve_roi_config_business_permit_covers_all_five_cities():
    """generate_roi_config.py maps Digos, Makati, Manila, Marikina, and
    Taguig - each with its own field vocabulary translated to the generic
    BUSINESS_PERMIT_FIELD_SPECS keys (see that script's _CITY_LAYOUTS)."""
    roi_config = json.loads(ROI_CONFIG_PATH.read_text(encoding="utf-8"))
    assert set(roi_config["business_permit"]) == {
        "digos", "makati", "manila", "marikina", "taguig",
    }
    # Every city maps at least trade_name and business_location - the two
    # fields present under some name on every layout that was inspected.
    for city, fields in roi_config["business_permit"].items():
        assert "trade_name" in fields, city
        assert "business_location" in fields, city


# --------------------------------------------------------------------------- #
# generation_kwargs
# --------------------------------------------------------------------------- #
def test_generation_kwargs_enables_the_kv_cache():
    """microsoft/trocr-large-printed's own generation_config.json ships
    ``"use_cache": false``, which makes the decoder recompute every past
    key/value at each step - measured 2.2x slower for byte-identical output.
    The kwarg has to be passed explicitly, so this guards a silent 2.2x
    regression on any re-bake of the weights."""
    kwargs = roi_field_ocr.generation_kwargs(32)

    assert kwargs["use_cache"] is True
    assert kwargs["num_beams"] == 1      # greedy; beams multiply CPU cost
    assert kwargs["max_new_tokens"] == 32


# --------------------------------------------------------------------------- #
# is_onnx_export - which runtime a baked recognizer dir needs
# --------------------------------------------------------------------------- #
def test_is_onnx_export_distinguishes_an_onnx_dir_from_a_torch_snapshot(tmp_path):
    """The two recognizer layouts live side by side under models/ and load
    through completely different runtimes (onnxruntime vs torch), so the
    registry has to tell them apart from the directory alone."""
    exported = tmp_path / "onnx"
    exported.mkdir()
    (exported / "encoder_model.onnx").write_bytes(b"")
    assert roi_field_ocr.is_onnx_export(exported) is True

    # Quantization renames the files (encoder_model_quantized.onnx) — still ONNX.
    quantized = tmp_path / "onnx-int8"
    quantized.mkdir()
    (quantized / "encoder_model_quantized.onnx").write_bytes(b"")
    assert roi_field_ocr.is_onnx_export(quantized) is True

    torch_snapshot = tmp_path / "torch"
    torch_snapshot.mkdir()
    (torch_snapshot / "model.safetensors").write_bytes(b"")
    assert roi_field_ocr.is_onnx_export(torch_snapshot) is False

    assert roi_field_ocr.is_onnx_export(tmp_path / "does-not-exist") is False


# --------------------------------------------------------------------------- #
# export_trocr_onnx.graphs_to_quantize
# --------------------------------------------------------------------------- #
def _export_script():
    path = Path(__file__).resolve().parents[1] / "scripts" / "export_trocr_onnx.py"
    spec = importlib.util.spec_from_file_location("export_trocr_onnx", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def test_graphs_to_quantize_skips_already_quantized_graphs(tmp_path):
    """Re-running the quantization pass must be idempotent: picking up a
    *_quantized.onnx graph would quantize an already-INT8 graph."""
    for name in ("encoder_model.onnx", "decoder_model.onnx",
                 "decoder_model_quantized.onnx", "config.json"):
        (tmp_path / name).write_bytes(b"")

    graphs = _export_script().graphs_to_quantize(tmp_path)

    assert [p.name for p in graphs] == ["decoder_model.onnx", "encoder_model.onnx"]


def test_graphs_to_quantize_can_leave_the_vision_encoder_in_fp32(tmp_path):
    """The fallback when INT8 costs recognition accuracy: keep the encoder
    exact and take the win on the decoder, which carries the sequential cost."""
    for name in ("encoder_model.onnx", "decoder_model.onnx",
                 "decoder_with_past_model.onnx"):
        (tmp_path / name).write_bytes(b"")

    graphs = _export_script().graphs_to_quantize(tmp_path, skip_encoder=True)

    assert [p.name for p in graphs] == ["decoder_model.onnx", "decoder_with_past_model.onnx"]


# --------------------------------------------------------------------------- #
# select_fields_for_recognition - which crops are worth ~10-30s of TrOCR each
# --------------------------------------------------------------------------- #
def _field(matched: bool, confidence: float | None, required: bool = True) -> dict:
    return {"name": "F", "value": "x" if matched else None,
            "required": required, "matched": matched, "confidence": confidence}


def test_select_skips_a_field_tesseract_already_read_confidently():
    matched_rois = {"tin": (0.0, 0.0, 10.0, 10.0)}
    fields = {"tin": _field(matched=True, confidence=92.0)}

    assert roi_field_ocr.select_fields_for_recognition(
        matched_rois, fields, low_conf_floor=60) == []


def test_select_includes_a_field_tesseract_failed_to_match():
    matched_rois = {"tin": (0.0, 0.0, 10.0, 10.0)}
    fields = {"tin": _field(matched=False, confidence=None)}

    assert roi_field_ocr.select_fields_for_recognition(
        matched_rois, fields, low_conf_floor=60) == ["tin"]


def test_select_includes_a_matched_field_below_the_confidence_floor():
    """Tesseract "matched" a value but read it badly - exactly the case
    ROI+TrOCR exists for."""
    matched_rois = {"tin": (0.0, 0.0, 10.0, 10.0)}
    fields = {"tin": _field(matched=True, confidence=41.0)}

    assert roi_field_ocr.select_fields_for_recognition(
        matched_rois, fields, low_conf_floor=60) == ["tin"]


def test_select_orders_required_fields_before_optional_ones():
    """score_quality() scores on REQUIRED fields only, so when the time budget
    cuts the pass short it must be the optional fields that are sacrificed."""
    matched_rois = {
        "rdo_code": (0.0, 0.0, 10.0, 10.0),      # optional, listed first
        "tin": (0.0, 20.0, 10.0, 10.0),          # required
        "form_no": (0.0, 40.0, 10.0, 10.0),      # optional
        "registered_name": (0.0, 60.0, 10.0, 10.0),  # required
    }
    fields = {
        "rdo_code": _field(matched=False, confidence=None, required=False),
        "tin": _field(matched=False, confidence=None, required=True),
        "form_no": _field(matched=False, confidence=None, required=False),
        "registered_name": _field(matched=False, confidence=None, required=True),
    }

    assert roi_field_ocr.select_fields_for_recognition(
        matched_rois, fields, low_conf_floor=60,
    ) == ["tin", "registered_name", "rdo_code", "form_no"]


def test_select_returns_every_roi_when_there_are_no_template_fields():
    """No field extraction ran (template "none"), so there is nothing to gate
    on - never silently drop the ROIs."""
    matched_rois = {"a": (0.0, 0.0, 1.0, 1.0), "b": (0.0, 0.0, 1.0, 1.0)}

    assert roi_field_ocr.select_fields_for_recognition(
        matched_rois, {}, low_conf_floor=60) == ["a", "b"]


# --------------------------------------------------------------------------- #
# extract_fields_via_roi - orchestration / fail-forward behavior
# --------------------------------------------------------------------------- #
def test_extract_fields_via_roi_noop_without_models():
    import numpy as np

    image = np.zeros((100, 100), dtype="uint8")
    outcome = roi_field_ocr.extract_fields_via_roi(
        image, "bir", {"bir": {"tin": {"x": 0, "y": 0, "w": 1, "h": 1}}},
        detector=None, trocr=None,
    )
    assert outcome.fields == {}


def test_extract_fields_via_roi_noop_when_template_has_no_roi_config():
    import numpy as np

    image = np.zeros((100, 100), dtype="uint8")
    outcome = roi_field_ocr.extract_fields_via_roi(
        image, "dti", {"bir": {"tin": {"x": 0, "y": 0, "w": 1, "h": 1}}},
        detector=MagicMock(), trocr=(MagicMock(), MagicMock()),
    )
    assert outcome.fields == {}


def test_extract_fields_via_roi_validates_against_field_spec_regex(monkeypatch):
    """A field whose FIELD_SPECS regex the recognized text fails to match is
    dropped (falls back to label+regex), not returned as-is."""
    import numpy as np

    image = np.zeros((200, 200), dtype="uint8")
    roi_config = {"bir": {"tin": {"x": 0.1, "y": 0.1, "w": 0.5, "h": 0.5}}}
    field_specs = [{"key": "tin", "regex": r"(\d{3}-\d{3}-\d{3}-\d{3,4})"}]

    monkeypatch.setattr(roi_field_ocr, "detect_boxes", lambda image, detector: [(20.0, 20.0, 50.0, 15.0)])
    monkeypatch.setattr(roi_field_ocr, "recognize_crop",
                         lambda image, box, processor, model, **kw: "not a tin at all")

    outcome = roi_field_ocr.extract_fields_via_roi(
        image, "bir", roi_config, detector=MagicMock(), trocr=(MagicMock(), MagicMock()),
        field_specs=field_specs,
    )
    assert outcome.fields == {}  # regex mismatch -> dropped, caller falls back


def test_extract_fields_via_roi_extracts_regex_group_on_match(monkeypatch):
    import numpy as np

    image = np.zeros((200, 200), dtype="uint8")
    roi_config = {"bir": {"tin": {"x": 0.1, "y": 0.1, "w": 0.5, "h": 0.5}}}
    field_specs = [{"key": "tin", "regex": r"(\d{3}-\d{3}-\d{3}-\d{3,4})"}]

    monkeypatch.setattr(roi_field_ocr, "detect_boxes", lambda image, detector: [(20.0, 20.0, 50.0, 15.0)])
    monkeypatch.setattr(roi_field_ocr, "recognize_crop",
                         lambda image, box, processor, model, **kw: "TIN: 123-456-789-000 ")

    outcome = roi_field_ocr.extract_fields_via_roi(
        image, "bir", roi_config, detector=MagicMock(), trocr=(MagicMock(), MagicMock()),
        field_specs=field_specs,
    )
    assert outcome.fields == {"tin": "123-456-789-000"}


def test_extract_fields_via_roi_accepts_unregexed_field_as_is(monkeypatch):
    import numpy as np

    image = np.zeros((200, 200), dtype="uint8")
    roi_config = {"bir": {"registered_name": {"x": 0.1, "y": 0.1, "w": 0.5, "h": 0.5}}}
    field_specs = [{"key": "registered_name"}]  # no regex -> no validation, pass through

    monkeypatch.setattr(roi_field_ocr, "detect_boxes", lambda image, detector: [(20.0, 20.0, 50.0, 15.0)])
    monkeypatch.setattr(roi_field_ocr, "recognize_crop",
                         lambda image, box, processor, model, **kw: "ACME TRADING CORP")

    outcome = roi_field_ocr.extract_fields_via_roi(
        image, "bir", roi_config, detector=MagicMock(), trocr=(MagicMock(), MagicMock()),
        field_specs=field_specs,
    )
    assert outcome.fields == {"registered_name": "ACME TRADING CORP"}


# --------------------------------------------------------------------------- #
# extract_fields_via_roi - time budget and gating (bounded, fail-forward)
# --------------------------------------------------------------------------- #
def _four_field_setup(monkeypatch, recognized: str = "VALUE"):
    """Four ROIs on distinct rows, each with its own detected box, none of
    which Tesseract matched -> all four are candidates for recognition."""
    import numpy as np

    image = np.zeros((400, 200), dtype="uint8")
    roi_config = {"bir": {
        "a": {"x": 0.0, "y": 0.00, "w": 1.0, "h": 0.2},
        "b": {"x": 0.0, "y": 0.25, "w": 1.0, "h": 0.2},
        "c": {"x": 0.0, "y": 0.50, "w": 1.0, "h": 0.2},
        "d": {"x": 0.0, "y": 0.75, "w": 1.0, "h": 0.2},
    }}
    boxes = [(10.0, 10.0, 50.0, 15.0), (10.0, 110.0, 50.0, 15.0),
             (10.0, 210.0, 50.0, 15.0), (10.0, 310.0, 50.0, 15.0)]
    monkeypatch.setattr(roi_field_ocr, "detect_boxes", lambda image, detector: boxes)
    monkeypatch.setattr(roi_field_ocr, "recognize_crop",
                         lambda image, box, processor, model, **kw: recognized)
    fields = {key: _field(matched=False, confidence=None) for key in "abcd"}
    return image, roi_config, fields


def test_extract_fields_via_roi_stops_at_the_time_budget(monkeypatch):
    """Each crop costs real CPU seconds, so the pass must stop once the budget
    is spent and hand the rest back to the label+regex fallback - never run
    long enough to take the whole /v1/validate call down with it."""
    image, roi_config, fields = _four_field_setup(monkeypatch)

    # 10s per crop against a 25s budget: crops start at t=0, 10, 20 (all under
    # budget) and the 4th is refused at t=30.
    ticks = iter([0.0, 0.0, 10.0, 20.0, 30.0, 30.0])
    outcome = roi_field_ocr.extract_fields_via_roi(
        image, "bir", roi_config, detector=MagicMock(), trocr=(MagicMock(), MagicMock()),
        fields=fields, budget_seconds=25.0, clock=lambda: next(ticks),
    )

    assert set(outcome.fields) == {"a", "b", "c"}
    assert outcome.diagnostics["skipped_over_budget"] == ["d"]
    assert outcome.diagnostics["recognized"] == ["a", "b", "c"]


def test_extract_fields_via_roi_returns_everything_within_budget(monkeypatch):
    image, roi_config, fields = _four_field_setup(monkeypatch)

    ticks = iter([0.0, 0.0, 1.0, 2.0, 3.0, 4.0])
    outcome = roi_field_ocr.extract_fields_via_roi(
        image, "bir", roi_config, detector=MagicMock(), trocr=(MagicMock(), MagicMock()),
        fields=fields, budget_seconds=60.0, clock=lambda: next(ticks),
    )

    assert set(outcome.fields) == {"a", "b", "c", "d"}
    assert outcome.diagnostics["skipped_over_budget"] == []
    assert outcome.diagnostics["elapsed_s"] == 4.0


def test_extract_fields_via_roi_does_not_re_read_fields_tesseract_got_right(monkeypatch):
    """The gate applied end to end: only the two fields Tesseract failed on
    are attempted, so a clean scan costs almost no TrOCR time at all."""
    image, roi_config, fields = _four_field_setup(monkeypatch)
    fields["a"] = _field(matched=True, confidence=95.0)
    fields["c"] = _field(matched=True, confidence=88.0)

    outcome = roi_field_ocr.extract_fields_via_roi(
        image, "bir", roi_config, detector=MagicMock(), trocr=(MagicMock(), MagicMock()),
        fields=fields, low_conf_floor=60,
    )

    assert set(outcome.fields) == {"b", "d"}
    assert outcome.diagnostics["attempted"] == ["b", "d"]


# --------------------------------------------------------------------------- #
# ocr_roi_config.json - the actual generated config, sanity-checked
# --------------------------------------------------------------------------- #
def test_ocr_roi_config_matches_field_specs_keys():
    """Every ROI key must be a real FIELD_SPECS/DTI_FIELD_SPECS/
    BUSINESS_PERMIT_FIELD_SPECS key, and every fraction must be in (0, 1] -
    guards against a stale config after a template/box recalibration."""
    od_path = Path(__file__).resolve().parents[1] / "scripts" / "ocr_dryrun.py"
    od_spec = importlib.util.spec_from_file_location("ocr_dryrun", od_path)
    ocr_dryrun = importlib.util.module_from_spec(od_spec)
    od_spec.loader.exec_module(ocr_dryrun)

    roi_config = json.loads(ROI_CONFIG_PATH.read_text(encoding="utf-8"))

    bir_keys = {s["key"] for s in ocr_dryrun.FIELD_SPECS}
    dti_keys = {s["key"] for s in ocr_dryrun.DTI_FIELD_SPECS}
    permit_keys = {s["key"] for s in ocr_dryrun.BUSINESS_PERMIT_FIELD_SPECS}

    assert set(roi_config["bir"]) <= bir_keys
    assert set(roi_config["dti"]) <= dti_keys
    for city_config in roi_config["business_permit"].values():
        assert set(city_config) <= permit_keys

    for template_config in (roi_config["bir"], roi_config["dti"],
                             *roi_config["business_permit"].values()):
        for field, box in template_config.items():
            for key in ("x", "y", "w", "h"):
                assert 0.0 <= box[key] <= 1.0, f"{field}.{key} out of range"
            assert box["x"] + box["w"] <= 1.0001, f"{field} extends past page width"
            assert box["y"] + box["h"] <= 1.0001, f"{field} extends past page height"


# --------------------------------------------------------------------------- #
# live detector smoke test (rapidocr-onnxruntime is a hard dependency, always
# installed; skip only if it somehow isn't)
# --------------------------------------------------------------------------- #
def test_detect_boxes_on_real_bir_sample():
    pytest.importorskip("rapidocr_onnxruntime")
    import cv2

    sample = (Path(__file__).resolve().parents[1] / "data" / "training" / "classifier_data"
              / "bir_certificate" / "synthetic_bir_00001_clean.png")
    if not sample.is_file():
        pytest.skip("synthetic BIR sample not present")

    detector = roi_field_ocr.load_detector()
    image = cv2.imread(str(sample))
    boxes = roi_field_ocr.detect_boxes(image, detector)
    assert len(boxes) > 10  # a filled certificate has plenty of text
    for x, y, w, h in boxes:
        assert w > 0 and h > 0


# --------------------------------------------------------------------------- #
# live TrOCR recognition smoke test - a real (heavy) model load + forward
# pass, needs the baked snapshot dir at PY_ROOT/models/trocr-large-printed
# (~2.3GB, not bundled like rapidocr's weights). Opt-in gated the same way
# as test_api.py's real-resnet50 test: ADVS_API_REAL_MODEL_TESTS=1 AND the
# weights present, so a routine `pytest` run never silently pays for a
# transformer forward pass just because the model happens to be baked.
# --------------------------------------------------------------------------- #
_TROCR_DIR = Path(__file__).resolve().parents[1] / "models" / "trocr-large-printed"


@pytest.mark.skipif(
    not os.environ.get("ADVS_API_REAL_MODEL_TESTS") or not _TROCR_DIR.is_dir(),
    reason="real-weights test; set ADVS_API_REAL_MODEL_TESTS=1 with trocr-large-printed baked",
)
def test_recognize_crop_reads_real_text_from_a_real_sample():
    pytest.importorskip("transformers")
    import cv2

    sample = (Path(__file__).resolve().parents[1] / "data" / "training" / "classifier_data"
              / "bir_certificate" / "synthetic_bir_00001_clean.png")
    if not sample.is_file():
        pytest.skip("synthetic BIR sample not present")

    detector = roi_field_ocr.load_detector()
    image = cv2.imread(str(sample))
    boxes = roi_field_ocr.detect_boxes(image, detector)
    assert boxes, "detector must find at least one text box to recognize"

    # Largest detected box - most likely to contain a clean, legible word/line.
    box = max(boxes, key=lambda b: b[2] * b[3])

    processor, model = roi_field_ocr.load_trocr(str(_TROCR_DIR))
    text = roi_field_ocr.recognize_crop(image, box, processor, model)
    assert text.strip() != ""


@pytest.mark.parametrize("city,filename", [
    ("digos", "synthetic_permit_00001_clean.png"),
    ("makati", "synthetic_permit_makati_00001_clean.png"),
    ("manila", "synthetic_permit_manila_00001_clean.png"),
    ("marikina", "synthetic_permit_marikina_00001_clean.png"),
    ("taguig", "synthetic_permit_taguig_00001_clean.png"),
])
def test_roi_matches_every_mapped_field_on_a_real_sample_per_city(city, filename):
    """End-to-end proof (not just config sanity) that every field
    generate_roi_config.py's KEY_MAP claims for a city actually detects on a
    real rendered sample of that city's layout - run through the same
    Otsu+2x preprocessing the production pipeline uses."""
    pytest.importorskip("rapidocr_onnxruntime")
    import cv2

    sample = (Path(__file__).resolve().parents[1] / "data" / "training" / "classifier_data"
              / "business_permit" / filename)
    if not sample.is_file():
        pytest.skip(f"synthetic {city} sample not present")

    od_path = Path(__file__).resolve().parents[1] / "scripts" / "ocr_dryrun.py"
    od_spec = importlib.util.spec_from_file_location("ocr_dryrun", od_path)
    ocr_dryrun = importlib.util.module_from_spec(od_spec)
    od_spec.loader.exec_module(ocr_dryrun)

    roi_config = json.loads(ROI_CONFIG_PATH.read_text(encoding="utf-8"))
    tmpl_roi = roi_field_ocr.resolve_roi_config(roi_config, "business_permit", city)
    assert tmpl_roi, f"no ROI config for {city}"

    detector = roi_field_ocr.load_detector()
    image = cv2.imread(str(sample))
    preprocessed = ocr_dryrun.preprocess(image, {"upscale_factor": 2})
    boxes = roi_field_ocr.detect_boxes(preprocessed, detector)
    height, width = preprocessed.shape[:2]
    matched = roi_field_ocr.match_rois(boxes, tmpl_roi, (width, height))

    assert set(matched) == set(tmpl_roi), (
        f"{city}: expected all of {sorted(tmpl_roi)} to match, got {sorted(matched)}"
    )
