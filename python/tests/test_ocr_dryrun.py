"""Deterministic unit tests for BIR Form 2303 field extraction in
scripts/ocr_dryrun.py.

These feed a captured PyTesseract OCR blob straight into ``extract_fields``, so
they exercise the template/label MAPPING logic in isolation - no OpenCV, no
Tesseract engine, no sample image required. They lock the column-layout fixes:
the registrant name is pulled from the value row (not the neighbouring caption),
OCN is matched by its format (not the word "CERTIFICATE"), and the RDO is not
fabricated from a digit bleeding in from an adjacent line.
"""

import importlib.util
import json
from pathlib import Path

_SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "ocr_dryrun.py"
_spec = importlib.util.spec_from_file_location("ocr_dryrun", _SCRIPT)
ocr_dryrun = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(ocr_dryrun)

# Real pytesseract image_to_data boxes for bir1.jpg under the Otsu+2x pipeline,
# used to exercise the positional (column-aware) extraction deterministically.
_WORDS = json.loads(
    (Path(__file__).resolve().parent / "fixtures" / "bir1_words.json").read_text(encoding="utf-8")
)

# Real OCR output for python/data/.../bir1.jpg under the Otsu + 2x pipeline
# (non-ASCII noise glyphs dropped; the lines the asserts touch are verbatim).
SAMPLE_OCR_TEXT = """\
REPUBLIBALRGPILIPINAS
KAGAWAR Ay ms NANALAPI
KAWANITLANING RESIPAS INTERNAS
REVE NEN GION NOD e+
BIR 2303 REVENUE DISTRICT NO.
Form No. 1RC0001016814
Revised July 1997 OCN
CERTIFICATE OF REGISTRATION :
TIN NAME REGISTRATION DATE
009-028-463-000 | CENTER FOR LOCAL GOVERNANCE AND 06/01/2015
PROFESSIONAL DEVT.
REGISTERED ADDRESS
PUROK ORIENTAL
BRGY PANSOL LOPEZ
QUEZON 4316
REGISTERED ACTIVITY(IES)
TAX TYPE ;
INCOME TAX PERCENTAGE TAX - MONTHLY ;
REGISTRATION FEE
TRADE NAME { LINE OF BUSINESS / INDUSTRY
CENTER FOR LOCAL GOVERNANCE AN| 7414 BUSINESS AND MANAGEMENT .
D PROFESSIONAL Fi CONSULTANCY ACTIVITIES
; DEVELOPMENT INC. |
"""


def _fields() -> dict:
    return ocr_dryrun.extract_fields(SAMPLE_OCR_TEXT, {})


def test_simple_fields_match() -> None:
    f = _fields()
    assert f["form_no"]["value"] == "2303"
    assert f["tin"]["value"] == "009-028-463-000"
    assert f["registration_date"]["value"] == "06/01/2015"
    addr = f["registered_address"]["value"]
    assert addr and "PUROK" in addr.upper()


def test_registered_name_reads_value_row_not_column_header() -> None:
    name = _fields()["registered_name"]["value"]
    assert name is not None
    assert "REGISTRATION DATE" not in name.upper()  # was grabbing the caption
    assert "GOVERNANCE" in name.upper()
    assert "PROFESSIONAL DEVT" in name.upper()
    assert "009-028-463-000" not in name  # flanking TIN stripped
    assert "06/01/2015" not in name  # flanking date stripped


def test_ocn_matched_by_format_not_certificate() -> None:
    assert _fields()["ocn"]["value"] == "1RC0001016814"


def test_rdo_not_fabricated_from_neighbour_line() -> None:
    # The RDO digit is illegible in the scan (stamp overlap); the honest result
    # is "missing", not the stray "1" bled in from the OCN line below the label.
    assert _fields()["rdo_code"]["value"] is None


# --- revenue region / district stamp extraction -------------------------------

# A clean read of the BIR stamp block: the region code carries a trailing letter
# (09B) and the district code is a 3-digit number (061), each on its caption row.
STAMP_OCR_TEXT = """\
BUREAU OF INTERNAL REVENUE
REVENUE REGION NO. 09B
REVENUE DISTRICT NO. 061
"""


def _stamp_fields() -> dict:
    return ocr_dryrun.extract_fields(STAMP_OCR_TEXT, {})


def test_revenue_region_no_extracted_with_letter_suffix() -> None:
    # A digits-only regex would drop the trailing "B"; the whole token must survive.
    assert _stamp_fields()["revenue_region_no"]["value"] == "09B"


def test_revenue_district_no_extracted_as_three_digit_code() -> None:
    assert _stamp_fields()["rdo_code"]["value"] == "061"


def test_region_and_district_tolerate_missing_no_token() -> None:
    # OCR often drops the noisy "NO." token off the stamp caption; the value must
    # still attach to the right field via the shortened caption label.
    f = ocr_dryrun.extract_fields("REVENUE REGION 8A\nREVENUE DISTRICT 113\n", {})
    assert f["revenue_region_no"]["value"] == "8A"
    assert f["rdo_code"]["value"] == "113"


def test_revenue_region_is_required() -> None:
    assert _stamp_fields()["revenue_region_no"]["required"] is True


def test_revenue_region_not_fabricated_when_caption_illegible() -> None:
    # In bir1.jpg the stamp caption OCRs as garbage ("REVE NEN GION NOD"); the
    # honest result is missing, not a stray value scraped out of the noise.
    assert _fields()["revenue_region_no"]["value"] is None


def test_revenue_region_and_district_are_template_keywords() -> None:
    assert "REVENUE REGION" in ocr_dryrun.TEMPLATE_KEYWORDS
    assert "REVENUE DISTRICT" in ocr_dryrun.TEMPLATE_KEYWORDS


# --- date issued: floating "MON DD YYYY" catch --------------------------------

def test_date_issued_catches_floating_month_day_year() -> None:
    # The issue date floats anywhere in the doc as MON DD YYYY; catch it globally.
    text = "SOME HEADER\nISSUED AT QUEZON CITY MAR 09 2017\nFOOTER LINE"
    assert ocr_dryrun.extract_fields(text, {})["date_issued"]["value"] == "MAR 09 2017"


def test_date_issued_accepts_comma_and_full_month_name() -> None:
    assert ocr_dryrun.extract_fields("X SEP 5, 2019 Y", {})["date_issued"]["value"] == "SEP 5, 2019"
    assert ocr_dryrun.extract_fields("ISSUED SEPTEMBER 5 2019", {})["date_issued"]["value"] == "SEPTEMBER 5 2019"


def test_date_issued_ignores_non_month_three_letter_token() -> None:
    # A non-month 3-letter token + numbers (e.g. an RDO line) must NOT be read as a
    # date - the old [A-Z]{3} regex wrongly matched these.
    assert ocr_dryrun.extract_fields("RDO 39 2018 OFFICE", {})["date_issued"]["value"] is None


def test_date_issued_not_taken_from_slash_registration_date() -> None:
    assert ocr_dryrun.extract_fields("REGISTRATION DATE 06/01/2015", {})["date_issued"]["value"] is None


def test_date_issued_collapses_internal_whitespace() -> None:
    assert ocr_dryrun.extract_fields("DEC  12   2020", {})["date_issued"]["value"] == "DEC 12 2020"


# --- positional / column-aware extraction (needs the bounding-box fixture) ----

def test_positional_trade_name_is_left_column() -> None:
    name = ocr_dryrun._positional_fields(_WORDS)["trade_name"].upper()
    assert "CENTER FOR LOCAL GOVERNANCE" in name
    assert "DEVELOPMENT INC" in name
    assert "7414" not in name and "INDUSTRY" not in name  # not the right column


def test_positional_line_of_business_is_right_column() -> None:
    lob = ocr_dryrun._positional_fields(_WORDS)["line_of_business"].upper()
    assert "7414" in lob
    assert "BUSINESS AND MANAGEMENT" in lob
    assert "CONSULTANCY ACTIVITIES" in lob
    assert "CENTER FOR LOCAL" not in lob  # not the left column


def test_positional_tax_types() -> None:
    tax = ocr_dryrun._positional_fields(_WORDS)["tax_types"].upper()
    assert "INCOME TAX" in tax
    assert "PERCENTAGE TAX" in tax
    assert "REGISTRATION FEE" in tax


def test_positional_district_officer_reads_above_caption() -> None:
    officer = ocr_dryrun._positional_fields(_WORDS)["revenue_district_officer"].upper()
    assert "SOCRATES" in officer
    assert "REGALA" in officer
    assert "TAU-MAN" not in officer and "PUNY" not in officer  # excluded signature scrawl


# --- report rendering (tabular result + extracted-text section) ---------------

def test_render_table_has_borders_and_content() -> None:
    out = ocr_dryrun._render_table(["Field", "Value"],
                                   [["TIN", "009-028-463-000"]], caps=[20, 30])
    lines = out.splitlines()
    assert lines[0].count("+") == 3  # 2-column top border
    assert "Field" in lines[1] and "Value" in lines[1]
    assert any("009-028-463-000" in ln for ln in lines)


def test_render_table_wraps_long_value() -> None:
    longval = "ALPHA BETA GAMMA DELTA EPSILON ZETA ETA THETA IOTA KAPPA LAMBDA MU"
    out = ocr_dryrun._render_table(["F", "V"], [["x", longval]], caps=[5, 18])
    assert "LAMBDA" in out
    assert not any(longval in ln for ln in out.splitlines())  # wrapped, not on one line


def test_print_report_is_tabular_and_includes_extracted_text() -> None:
    import contextlib
    import io

    result = {
        "structured_fields": {
            "tin": {"name": "TIN", "value": "009-028-463-000",
                    "required": True, "matched": True, "confidence": 91.0},
            "date_issued": {"name": "Date Issued", "value": None,
                            "required": False, "matched": False, "confidence": None},
        },
        "quality": {
            "word_count": 298, "mean_confidence": 76.9,
            "low_confidence_words": 68, "low_confidence_ratio": 0.23,
            "template_keywords_found": ["TIN", "REVENUE DISTRICT"],
            "template_keywords_total": 5,
            "fields_matched": 1, "fields_total": 2,
            "required_matched": 1, "required_total": 1,
            "text_validation_score": 1.0, "passes_text_validation": True,
            "flags": [],
        },
        "raw_text": "REPUBLIKA NG PILIPINAS\nCERTIFICATE OF REGISTRATION\nTIN 009-028-463-000",
    }
    buf = io.StringIO()
    with contextlib.redirect_stdout(buf):
        ocr_dryrun.print_report(result)
    out = buf.getvalue()
    assert "Extracted text (OCR)" in out  # the new section
    assert "CERTIFICATE OF REGISTRATION" in out  # raw OCR text is shown
    assert "009-028-463-000" in out  # field value rendered in the table
    assert "MISSING" in out  # status column for the unmatched field
    assert out.count("+---") >= 2  # table borders present


# --- light NLP tidy of the messy OCR dump (cosmetic, display only) -------------

def test_tidy_text_strips_leading_separator_noise() -> None:
    # "REVENUE REGION" stamp line OCRs with stray leading separators + a noise
    # tail; the readable line should start at the first real token.
    out = ocr_dryrun.tidy_text(": ; REVE NEN GION NOD e+")
    assert out.startswith("REVE")
    assert ":" not in out and ";" not in out


def test_tidy_text_drops_pure_noise_lines() -> None:
    out = ocr_dryrun.tidy_text("REGISTERED ADDRESS\n{\ni\n1 :\nPUROK ORIENTAL")
    assert out == "REGISTERED ADDRESS\nPUROK ORIENTAL"


def test_tidy_text_fixes_space_before_punctuation_and_dangling_separators() -> None:
    assert ocr_dryrun.tidy_text("TRADE NAME , OWNERSHIP ;") == "TRADE NAME, OWNERSHIP"


def test_tidy_text_collapses_repeats_but_keeps_terminal_period() -> None:
    assert ocr_dryrun.tidy_text("REVENUE CODE,, AS AMENDED..") == "REVENUE CODE, AS AMENDED."


def test_tidy_text_preserves_template_keywords() -> None:
    # The cosmetic pass must never destroy the tokens field/keyword matching needs.
    assert "REVENUE DISTRICT" in ocr_dryrun.tidy_text("BIR 2303 REVENUE DISTRICT NO.").upper()


def test_print_report_shows_tidied_readable_text_over_raw() -> None:
    import contextlib
    import io

    result = {
        "structured_fields": {},
        "quality": {
            "word_count": 1, "mean_confidence": 0.0, "low_confidence_words": 0,
            "low_confidence_ratio": 0.0, "template_keywords_found": [],
            "template_keywords_total": 6, "fields_matched": 0, "fields_total": 0,
            "required_matched": 0, "required_total": 0,
            "text_validation_score": 0.0, "passes_text_validation": False, "flags": [],
        },
        "raw_text": "MESSY :: TEXT ;;",
        "readable_text": "TIDIED TEXT",
    }
    buf = io.StringIO()
    with contextlib.redirect_stdout(buf):
        ocr_dryrun.print_report(result)
    out = buf.getvalue()
    assert "TIDIED TEXT" in out  # the cleaned-up rendering is shown
    assert "MESSY :: TEXT ;;" not in out  # the raw messy dump is replaced


def test_default_run_is_the_extraction_report_not_compare() -> None:
    # Running the file with no args must produce the meaningful field report,
    # not the preprocessing-comparison montage.
    assert ocr_dryrun.parse_args([]).compare is False
    assert ocr_dryrun.parse_args(["--compare"]).compare is True


# --- stamp-targeted preprocessing (REVENUE REGION/DISTRICT recovery) -----------

def _synthetic_stamp():
    # Orange guilloche background (high green) with a darker-red "ink" block (low
    # green), mimicking the BIR seal where region/district numbers print in red
    # over the pattern.
    import numpy as np

    img = np.zeros((40, 120, 3), dtype=np.uint8)
    img[:, :] = (60, 140, 200)         # BGR orange-ish background
    img[15:25, 30:90] = (40, 40, 150)  # BGR darker-red ink block
    return img


def test_crop_fractional_roi_returns_expected_band() -> None:
    import numpy as np

    img = np.zeros((100, 200, 3), dtype=np.uint8)
    roi = ocr_dryrun._crop_fractional_roi(img, (0.0, 0.20, 0.25, 0.80))
    assert roi.shape[0] == 20   # 0.20 * 100 rows
    assert roi.shape[1] == 110  # (0.80 - 0.25) * 200 cols


def test_isolate_stamp_ink_makes_red_text_black_on_white() -> None:
    import numpy as np

    cfg = dict(ocr_dryrun.CONFIG, upscale_factor=1)
    out = ocr_dryrun.isolate_stamp_ink(_synthetic_stamp(), cfg)
    assert set(np.unique(out).tolist()).issubset({0, 255})  # binarized
    assert out[15:25, 30:90].mean() < 64                    # ink block -> black
    assert out[0:10, 0:20].mean() > 192                     # guilloche -> white


def test_isolate_stamp_ink_keys_on_green_not_luminance() -> None:
    # Ink and background share the SAME grayscale luminance but differ in green;
    # a brightness/grayscale cut is blind to them, the green-channel split is not.
    # Proves the isolation separates by colour, the whole point over plain Otsu.
    import cv2
    import numpy as np

    cfg = dict(ocr_dryrun.CONFIG, upscale_factor=1)
    img = np.zeros((40, 120, 3), dtype=np.uint8)
    img[:, :] = (200, 160, 80)          # BGR: high green (orange-ish guilloche)
    img[15:25, 30:90] = (255, 60, 255)  # BGR: low green, ~equal luminance (ink)

    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    assert abs(int(gray[20, 60]) - int(gray[5, 5])) <= 4  # luminance-blind by design

    out = ocr_dryrun.isolate_stamp_ink(img, cfg)
    assert abs(out[15:25, 30:90].mean() - out[0:10, 0:20].mean()) > 200  # cleanly split


def test_stamp_variants_expose_roi_and_channel_isolation() -> None:
    import numpy as np

    img = np.zeros((100, 200, 3), dtype=np.uint8)
    img[:, :] = (60, 140, 200)
    names = [n.lower() for n, _ in ocr_dryrun.stamp_variants(img, ocr_dryrun.CONFIG)]
    assert any("original" in n for n in names)
    assert any("channel" in n for n in names)


def test_clahe_stretches_low_contrast_into_wider_range() -> None:
    # A faint, low-contrast feature (20 grey levels) must come out spanning a
    # wider dynamic range - that lift is what lets Otsu separate seal digits.
    import numpy as np

    ramp = np.linspace(110, 130, 64, dtype=np.uint8)
    img = np.tile(ramp, (64, 1))  # 64x64 low-contrast gradient
    out = ocr_dryrun._clahe(img, clip=4.0, tile=8)
    assert out.shape == img.shape and out.dtype == np.uint8
    assert (int(out.max()) - int(out.min())) > (int(img.max()) - int(img.min()))


def test_stamp_variants_include_clahe_tile() -> None:
    import numpy as np

    img = np.zeros((100, 200, 3), dtype=np.uint8)
    img[:, :] = (60, 140, 200)
    names = [n.lower() for n, _ in ocr_dryrun.stamp_variants(img, ocr_dryrun.CONFIG)]
    assert any("clahe" in n for n in names)


def test_notch_filter_attenuates_periodic_pattern() -> None:
    # A high-frequency periodic ripple (the guilloche analogue) must be largely
    # removed: an FFT notch zeroes its spectral peaks, flattening the row variance.
    import numpy as np

    x = np.arange(64)
    stripes = (40 * np.sin(2 * np.pi * 16 * x / 64)).astype(np.float32)  # period-4 ripple
    img = np.clip(128 + np.tile(stripes, (64, 1)), 0, 255).astype(np.uint8)
    out = ocr_dryrun.notch_filter_periodic(img, ocr_dryrun.CONFIG)
    assert out.std() < img.std() * 0.5  # ripple attenuated


def test_notch_filter_preserves_low_frequency_shapes() -> None:
    # The aperiodic digit-scale content lives in the protected low-frequency core
    # and must survive: a large dark block stays darker than the background.
    import numpy as np

    img = np.full((64, 64), 255, np.uint8)
    img[24:40, 16:48] = 30  # big low-frequency block (a "digit"-scale feature)
    out = ocr_dryrun.notch_filter_periodic(img, ocr_dryrun.CONFIG)
    assert out[24:40, 16:48].mean() < out[0:8, 0:8].mean()


def test_stamp_variants_include_notch_tile() -> None:
    import numpy as np

    img = np.zeros((100, 200, 3), dtype=np.uint8)
    img[:, :] = (60, 140, 200)
    names = [n.lower() for n, _ in ocr_dryrun.stamp_variants(img, ocr_dryrun.CONFIG)]
    assert any("notch" in n for n in names)


# --- secondary stamp-recovery pass wired into the default run -----------------

def test_normalize_stamp_text_rejoins_hyphenated_caption() -> None:
    # The seal OCR splits the caption with a stray hyphen ("REVENUE-REGION"); the
    # label scan needs it spaced to match.
    assert "REVENUE REGION" in ocr_dryrun._normalize_stamp_text("REVENUE-REGION NO, 61").upper()


def test_stamp_text_extraction_recovers_region_value() -> None:
    # After normalization the recovered seal text yields the region code the
    # full-page pass missed.
    text = ocr_dryrun._normalize_stamp_text("REVENUE-REGION NO, 61\nREVENUE DISTRICT no.\n")
    assert ocr_dryrun.extract_fields(text, {})["revenue_region_no"]["value"] == "61"


def test_merge_stamp_fields_fills_missing_but_keeps_confident_primary() -> None:
    main = {
        "revenue_region_no": {"name": "Revenue Region No.", "value": None,
                              "required": True, "matched": False, "confidence": None},
        "rdo_code": {"name": "Revenue District No. (RDO)", "value": "061",
                     "required": True, "matched": True, "confidence": 90.0},
    }
    stamp = {
        "revenue_region_no": {"name": "Revenue Region No.", "value": "61",
                              "required": True, "matched": True, "confidence": 42.0},
        "rdo_code": {"name": "Revenue District No. (RDO)", "value": "99",
                     "required": True, "matched": True, "confidence": 30.0},
    }
    out = ocr_dryrun.merge_stamp_fields(main, stamp)
    assert out["revenue_region_no"]["value"] == "61"            # filled from stamp pass
    assert out["revenue_region_no"]["source"] == "stamp_roi"    # and tagged
    assert out["rdo_code"]["value"] == "061"                    # confident primary kept
    assert out["rdo_code"].get("source") != "stamp_roi"         # not overwritten


def test_print_report_marks_stamp_value_and_shows_recovery_section() -> None:
    import contextlib
    import io

    result = {
        "structured_fields": {
            "revenue_region_no": {"name": "Revenue Region No.", "value": "61",
                                  "required": True, "matched": True,
                                  "confidence": 42.0, "source": "stamp_roi"},
        },
        "quality": {
            "word_count": 1, "mean_confidence": 0.0, "low_confidence_words": 0,
            "low_confidence_ratio": 0.0, "template_keywords_found": [],
            "template_keywords_total": 6, "fields_matched": 1, "fields_total": 1,
            "required_matched": 1, "required_total": 1,
            "text_validation_score": 1.0, "passes_text_validation": True, "flags": [],
        },
        "raw_text": "x",
        "stamp_recovery": {
            "variant": "STAMP ROI: grayscale + Otsu + 2x",
            "raw_text": "REVENUE REGION NO, 61\nREVENUE DISTRICT NO.",
        },
    }
    buf = io.StringIO()
    with contextlib.redirect_stdout(buf):
        ocr_dryrun.print_report(result)
    out = buf.getvalue()
    assert "(stamp)" in out                       # the value is tagged in the table
    assert "Stamp recovery" in out                # the secondary-pass section renders
    assert "REVENUE REGION NO, 61" in out         # recovered seal text is shown


# --- fuzzy caption matching primitives ----------------------------------------

def test_levenshtein_counts_single_substitution() -> None:
    assert ocr_dryrun._levenshtein("REGISTRATION", "REGISTRAUION") == 1


def test_levenshtein_zero_for_identical() -> None:
    assert ocr_dryrun._levenshtein("NAME", "NAME") == 0


def test_fuzzy_token_eq_tolerates_long_caption_typo() -> None:
    # REGISTRATION read as REGISTRAUION is one substitution -> within a 4 budget.
    assert ocr_dryrun._fuzzy_token_eq("REGISTRAUION", "REGISTRATION", 4) is True


def test_fuzzy_token_eq_exact_when_budget_zero() -> None:
    assert ocr_dryrun._fuzzy_token_eq("NAME", "NAME", 0) is True
    assert ocr_dryrun._fuzzy_token_eq("NAME", "NAVE", 0) is False


def test_fuzzy_token_eq_short_token_needs_low_budget() -> None:
    # With a 1-typo budget NAME must NOT match the unrelated DATE (distance 2).
    assert ocr_dryrun._fuzzy_token_eq("DATE", "NAME", 1) is False


def test_fuzzy_token_eq_caps_tolerance_below_token_length() -> None:
    # A 4 budget on a 3-letter caption is capped to 2, so TIN can't match FOR.
    assert ocr_dryrun._fuzzy_token_eq("TIN", "FOR", 4) is False


if __name__ == "__main__":  # runnable without pytest: `python tests/test_ocr_dryrun.py`
    import sys
    import traceback

    cases = [v for k, v in sorted(globals().items())
             if k.startswith("test_") and callable(v)]
    failed = 0
    for case in cases:
        try:
            case()
            print(f"PASS  {case.__name__}")
        except AssertionError:
            failed += 1
            print(f"FAIL  {case.__name__}")
            traceback.print_exc()
    print(f"\n{len(cases) - failed}/{len(cases)} passed")
    sys.exit(1 if failed else 0)
