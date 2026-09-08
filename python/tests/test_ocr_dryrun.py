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


def test_revenue_region_is_optional() -> None:
    # Revenue Region / District / Form No. are BIR-genuine fields still extracted
    # for the report + stamp-recovery merge, but they are NOT part of the required
    # keyword set the officer expects (that set is locked in the test below), so a
    # missing region must no longer penalise the text-validation score.
    assert _stamp_fields()["revenue_region_no"]["required"] is False


def test_bir_required_set_is_the_expected_keyword_list() -> None:
    required = {spec["key"] for spec in ocr_dryrun.FIELD_SPECS if spec["required"]}
    assert required == {
        "tin", "registered_name", "registration_date", "ocn",
        "registered_address", "tax_types", "trade_name",
        "line_of_business", "date_issued",
    }


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


# --- fuzzy phrase finder over word boxes --------------------------------------

def _word(text: str, left: int, top: int, width: int = 80, height: int = 20) -> dict:
    return {"text": text, "conf": 90, "left": left, "top": top,
            "width": width, "height": height}


def test_find_phrase_exact_by_default() -> None:
    words = [_word("TRADE", 10, 10, 50), _word("NAME", 70, 10, 50)]
    assert ocr_dryrun._find_phrase(words, "TRADE NAME") == (10, 10, 120, 30)
    assert ocr_dryrun._find_phrase(words, "TRADE NAMEX") is None  # exact: no match


def test_find_phrase_fuzzy_tolerates_caption_typo() -> None:
    words = [_word("REGISTRAUION", 100, 50, 180), _word("DATE", 290, 50, 60)]
    assert ocr_dryrun._find_phrase(words, "REGISTRATION DATE", max_typos=4) == (100, 50, 350, 70)
    assert ocr_dryrun._find_phrase(words, "REGISTRATION DATE") is None  # exact fails


# --- positional registered_name (middle column of the header table) -----------

def _name_header_words(registration_token: str = "REGISTRATION") -> list:
    """Synthetic TIN | NAME | REGISTRATION DATE header with a two-row name value,
    plus the REGISTERED ADDRESS caption that bounds the band below. Mirrors the
    bir2.jpg layout; ``registration_token`` lets a test inject the OCR typo."""
    return [
        _word("TIN", 30, 100, 40), _word("NAME", 400, 100, 70),
        _word(registration_token, 1000, 100, 180), _word("DATE", 1200, 100, 60),
        _word("000-132-541-000", 100, 140, 250),
        _word("MINING", 410, 140, 110), _word("AND", 530, 140, 60),
        _word("PETROLEUM", 600, 140, 150), _word("SERVICES", 760, 140, 130),
        _word("08/12/1998", 1040, 140, 160),
        _word("CORPORATION", 410, 175, 200),
        _word("REGISTERED", 400, 215, 200), _word("ADDRESS", 610, 215, 130),
    ]


def test_positional_registered_name_reads_middle_column() -> None:
    name = ocr_dryrun._positional_fields(_WORDS)["registered_name"].upper()
    assert "CENTER FOR LOCAL GOVERNANCE" in name
    assert "PROFESSIONAL DEVT" in name
    assert "009-028-463-000" not in name   # flanking TIN column excluded
    assert "06/01/2015" not in name        # flanking date column excluded
    assert "REGISTERED ADDRESS" not in name  # next section not pulled in


def test_positional_registered_name_tolerates_header_typo() -> None:
    name = ocr_dryrun._positional_registered_name(
        _name_header_words(registration_token="REGISTRAUION")
    ).upper()
    assert "MINING AND PETROLEUM SERVICES" in name
    assert "CORPORATION" in name
    assert "000-132-541-000" not in name
    assert "08/12/1998" not in name


def test_positional_registered_name_none_without_header() -> None:
    # No NAME/REGISTRATION DATE captions -> nothing to anchor on -> None.
    assert ocr_dryrun._positional_registered_name(
        [_word("PUROK", 100, 100, 80), _word("ORIENTAL", 190, 100, 120)]
    ) is None


# --- text-only fallback tolerates the header typo too -------------------------

def test_extract_registered_name_text_fallback_tolerates_typo() -> None:
    text = (
        "TIN NAME REGISTRAUION DATE\n"
        "000-132-541-000 MINING AND PETROLEUM SERVICES 08/12/1998\n"
        "CORPORATION\n"
        "REGISTERED ADDRESS\n"
    )
    val = ocr_dryrun.extract_fields(text, {})["registered_name"]["value"]
    assert val is not None                      # header found despite REGISTRAUION
    name = val.upper()
    assert "MINING AND PETROLEUM SERVICES" in name
    assert "CORPORATION" in name
    assert "000-132-541-000" not in name        # flanking TIN stripped
    assert "08/12/1998" not in name             # flanking date stripped


def test_extract_registered_name_text_fallback_still_matches_clean_header() -> None:
    # Regression: the existing clean-header path must keep working.
    name = ocr_dryrun.extract_fields(SAMPLE_OCR_TEXT, {})["registered_name"]["value"].upper()
    assert "GOVERNANCE" in name
    assert "REGISTRATION DATE" not in name


# --- per-document-type templates: Business Permit -----------------------------

# Captured OCR (Otsu+2x) for a synthetic LGU Business Permit. The fill value is
# printed on the line ABOVE its caption ("<value>" then "NAME OF PROPRIETOR"),
# the reverse of the BIR form, so extraction reads the previous line.
BUSINESS_PERMIT_OCR_TEXT = """\
Republic of the Philippines
Province of Davao del Sur
CITY OF DIGOS
BUSINESS PERMIT
UR SEPTIC DEAN PERRY
NAME OF PROPRIETOR
DYBU LIBERTY CITY CONSTRUCTION INC.
TRADE NAME
4057A MOONSTONE DRIVE, ALITAGTAG, 2832 ROMBLON
BUSINESS LOCATION
GENERAL SERVICES
KIND OF BUSINESS
Issued this 26th day of AUGUST , 2012, at Digos City, Davao del Sur, Philippines.
"""


def _business_permit_fields() -> dict:
    return ocr_dryrun.extract_fields(
        BUSINESS_PERMIT_OCR_TEXT, {}, ocr_dryrun.BUSINESS_PERMIT_FIELD_SPECS
    )


def test_business_permit_reads_value_above_its_caption() -> None:
    f = _business_permit_fields()
    assert f["name_of_proprietor"]["value"] == "UR SEPTIC DEAN PERRY"
    # _normalise_value strips the trailing separator period, as it does for every field.
    assert f["trade_name"]["value"] == "DYBU LIBERTY CITY CONSTRUCTION INC"
    assert f["business_location"]["value"] == "4057A MOONSTONE DRIVE, ALITAGTAG, 2832 ROMBLON"
    assert f["kind_of_business"]["value"] == "GENERAL SERVICES"


def test_business_permit_city_issued_from_header() -> None:
    assert _business_permit_fields()["city_issued"]["value"] == "DIGOS"


def test_business_permit_issued_date_from_issued_this_clause() -> None:
    value = _business_permit_fields()["date_issued"]["value"]
    assert value is not None
    assert "AUGUST" in value.upper() and "2012" in value


def test_business_permit_required_set_is_the_expected_keyword_list() -> None:
    required = {spec["key"] for spec in ocr_dryrun.BUSINESS_PERMIT_FIELD_SPECS if spec["required"]}
    assert required == {
        "city_issued", "name_of_proprietor", "trade_name",
        "business_location", "kind_of_business", "date_issued",
    }


def test_business_permit_has_no_bir_fields() -> None:
    # The whole point: a permit must NOT be scored against BIR's TIN / Revenue
    # Region etc., so those keys are absent from its template entirely.
    keys = {spec["key"] for spec in ocr_dryrun.BUSINESS_PERMIT_FIELD_SPECS}
    assert "tin" not in keys
    assert "revenue_region_no" not in keys


def test_business_permit_aliases_and_city_required_schemas_cover_all_named_lgus() -> None:
    assert ocr_dryrun.canonical_business_permit_city("CITY OF MAKATI") == "Makati"
    assert ocr_dryrun.canonical_business_permit_city("Maynila City") == "Manila"
    assert ocr_dryrun.canonical_business_permit_city("LUNGSOD NG MARIKINA") == "Marikina"
    assert ocr_dryrun.canonical_business_permit_city("TAGUIG CITY") == "Taguig"
    assert ocr_dryrun.canonical_business_permit_city("CITY OF DIGOS") == "Digos"

    required_by_city = {
        city: {spec["key"] for spec in ocr_dryrun.business_permit_field_specs(city) if spec["required"]}
        for city in ("Digos", "Makati", "Manila", "Marikina", "Taguig")
    }
    assert "valid_until" in required_by_city["Makati"]
    assert "permit_no" in required_by_city["Manila"]
    assert "permit_no" in required_by_city["Marikina"]
    assert "lcn_or_account_no" in required_by_city["Taguig"]
    assert "valid_until" not in required_by_city["Manila"]


def test_makati_real_ocr_style_extracts_city_owner_address_nature_or_and_dates() -> None:
    text = """LUNGSOD NG MAKATI
NA SI/ANG:
(THAT) ANNETTE FOSTER
(with postal address at)
B14 L21 55TH STREET, AGOHO COVE, BRGY. SAN LORENZO, MAKATI CITY
(Republic ... permit to operate as)
a May TRANSPORT SERVICE
ON : 1sth JULY 2024
(this permit expires on) DECEMBER 31, 2024
TAX YEAR 2024
O.R. NO. 6020150"""
    city = ocr_dryrun.canonical_business_permit_city(text)
    fields = ocr_dryrun.extract_fields(text, {}, ocr_dryrun.business_permit_field_specs(city))

    assert city == "Makati"
    assert fields["name_of_proprietor"]["value"] == "ANNETTE FOSTER"
    assert "SAN LORENZO" in fields["business_location"]["value"]
    assert fields["kind_of_business"]["value"] == "TRANSPORT SERVICE"
    assert fields["date_issued"]["value"] is not None
    assert "JULY" in fields["date_issued"]["value"].upper()
    assert fields["valid_until"]["value"] == "DECEMBER 31, 2024"
    assert fields["or_no"]["value"] == "6020150"
    assert fields["tax_year"]["value"] == "2024"


def test_manila_inline_layout_extracts_required_identity_and_payment_fields() -> None:
    text = """LUNGSOD NG MAYNILA
BUSINESS PERMIT
NAME OF PROPRIETOR: JUAN DELA CRUZ
TRADE NAME: MAYNILA EATS
BUSINESS ADDRESS: 100 ESCOLTA STREET, BINONDO, MANILA
NATURE OF BUSINESS: FOOD SERVICE
BUSINESS IDENTIFICATION NO.: BIN-2026-00125
O.R. NO.: 778899"""
    city = ocr_dryrun.canonical_business_permit_city(text)
    fields = ocr_dryrun.extract_fields(text, {}, ocr_dryrun.business_permit_field_specs(city))

    assert city == "Manila"
    assert fields["name_of_proprietor"]["value"] == "JUAN DELA CRUZ"
    assert fields["trade_name"]["value"] == "MAYNILA EATS"
    assert fields["business_location"]["value"].startswith("100 ESCOLTA STREET")
    assert fields["kind_of_business"]["value"] == "FOOD SERVICE"
    assert fields["permit_no"]["value"] == "BIN-2026-00125"
    assert fields["or_no"]["value"] == "778899"


def test_marikina_value_above_layout_extracts_required_permit_and_dates() -> None:
    text = """CITY OF MARIKINA
MARIA SANTOS
NAME OF PROPRIETOR
MARIKINA BAKESHOP
TRADE NAME
25 SHOE AVENUE, CONCEPCION, MARIKINA CITY
BUSINESS LOCATION
BAKERY
NATURE OF BUSINESS
PERMIT NO.: MK-2026-4455
DATE ISSUED: JANUARY 15, 2026
VALID UNTIL: DECEMBER 31, 2026"""
    city = ocr_dryrun.canonical_business_permit_city(text)
    fields = ocr_dryrun.extract_fields(text, {}, ocr_dryrun.business_permit_field_specs(city))

    assert city == "Marikina"
    assert fields["name_of_proprietor"]["value"] == "MARIA SANTOS"
    assert fields["trade_name"]["value"] == "MARIKINA BAKESHOP"
    assert fields["business_location"]["value"].startswith("25 SHOE AVENUE")
    assert fields["kind_of_business"]["value"] == "BAKERY"
    assert fields["permit_no"]["value"] == "MK-2026-4455"
    assert fields["date_issued"]["value"] == "JANUARY 15, 2026"
    assert fields["valid_until"]["value"] == "DECEMBER 31, 2026"


def test_taguig_inline_layout_extracts_entity_location_lcn_and_validity() -> None:
    text = """CITY OF TAGUIG
BUSINESS PERMIT
NAME OF ENTITY: GLOBAL TRANSIT INC.
TRADE NAME: TAGUIG SHUTTLE
LOCATION: 8 BONIFACIO DRIVE, FORT BONIFACIO, TAGUIG CITY
NATURE OF BUSINESS: TRANSPORT SERVICE
LCN: TG-2026-9001
DATE ISSUED: FEBRUARY 1, 2026
VALIDITY UNTIL: DECEMBER 31, 2026"""
    city = ocr_dryrun.canonical_business_permit_city(text)
    fields = ocr_dryrun.extract_fields(text, {}, ocr_dryrun.business_permit_field_specs(city))

    assert city == "Taguig"
    assert fields["name_of_proprietor"]["value"] == "GLOBAL TRANSIT INC"
    assert fields["trade_name"]["value"] == "TAGUIG SHUTTLE"
    assert fields["business_location"]["value"].startswith("8 BONIFACIO DRIVE")
    assert fields["kind_of_business"]["value"] == "TRANSPORT SERVICE"
    assert fields["lcn_or_account_no"]["value"] == "TG-2026-9001"
    assert fields["date_issued"]["value"] == "FEBRUARY 1, 2026"
    assert fields["valid_until"]["value"] == "DECEMBER 31, 2026"


# --- per-document-type templates: DTI Business Name Registration --------------

# Captured OCR (Otsu+2x) for a synthetic DTI Business Name certificate. Fields are
# sentence-embedded ("This certifies that <name>", "issued to <owner>",
# "valid from <date> to <date>", "Certificate No. <no>", "TRN: <trn>").
DTI_OCR_TEXT = """\
DEPARTMENT OF
TRADE & INDUSTRY
PHILIPPINES
This certifies that
MABUHAY CARINDERIA
3444 BROWN STREET, BARANGAY HAGONOY, MANILA CITY
is a business name registered in this office pursuant to the provisions of Act 3883, as
amended by Act 4147 and Republic Act No. 863, and in compliance with the applicable
rules and regulations prescribed by the Department of Trade and Industry. This
certificate issued to
AMBER BARKER
is valid from 02/05/2026 to 02/05/2031 subject to continuing compliance
Business Name Registration
Certificate No. BN 1760595
TRN: DTI-2026-35374211
"""


def _dti_fields() -> dict:
    return ocr_dryrun.extract_fields(DTI_OCR_TEXT, {}, ocr_dryrun.DTI_FIELD_SPECS)


def test_dti_business_name_and_address_from_certifies_block() -> None:
    f = _dti_fields()
    assert f["business_name"]["value"] == "MABUHAY CARINDERIA"
    assert f["business_address"]["value"] == "3444 BROWN STREET, BARANGAY HAGONOY, MANILA CITY"


def test_dti_owner_from_issued_to_clause() -> None:
    assert _dti_fields()["owner_representative_name"]["value"] == "AMBER BARKER"


def test_dti_valid_and_expiry_dates_from_valid_from_to_clause() -> None:
    f = _dti_fields()
    assert f["date_issued"]["value"] == "02/05/2026"
    assert f["expiry_date"]["value"] == "02/05/2031"


def test_dti_certificate_number_and_trn() -> None:
    f = _dti_fields()
    assert f["certificate_no"]["value"] == "BN 1760595"
    assert f["trn_no"]["value"] == "DTI-2026-35374211"
    assert f["trn_no"]["name"] == "Transaction Reference Number (TRN)"


def test_dti_required_set_is_the_expected_keyword_list() -> None:
    required = {spec["key"] for spec in ocr_dryrun.DTI_FIELD_SPECS if spec["required"]}
    assert required == {
        "business_name", "business_address", "owner_representative_name",
        "date_issued", "expiry_date", "certificate_no", "trn_no",
    }


# --- template registry --------------------------------------------------------

def test_templates_registry_exposes_the_three_supported_templates() -> None:
    assert set(ocr_dryrun.TEMPLATES) == {"bir", "business_permit", "dti"}
    assert ocr_dryrun.TEMPLATES["bir"]["field_specs"] is ocr_dryrun.FIELD_SPECS
    assert ocr_dryrun.TEMPLATES["business_permit"]["field_specs"] is ocr_dryrun.BUSINESS_PERMIT_FIELD_SPECS
    assert ocr_dryrun.TEMPLATES["dti"]["field_specs"] is ocr_dryrun.DTI_FIELD_SPECS


def test_extract_fields_defaults_to_bir_specs() -> None:
    # Backward compatibility: the 2-arg call the whole BIR suite uses is unchanged.
    assert ocr_dryrun.extract_fields(SAMPLE_OCR_TEXT, {})["tin"]["value"] == "009-028-463-000"


# ---------------------------------------------------------------------------
# Per-field warnings (annotate_field_warnings / _looks_noisy)
# ---------------------------------------------------------------------------
def _noisy(value: str) -> bool:
    return ocr_dryrun._looks_noisy(value, ocr_dryrun.CONFIG)


def test_looks_noisy_passes_real_field_values() -> None:
    # A false positive here is the expensive failure: warn on every row and the
    # officer stops reading the column.
    for value in [
        "NORTHERN STAR FINANCE CORPORATION",
        "GEMINI STREET, BRGY. SAN ANTONIO, PASIG CITY",
        "Wholesale of construction materials",
        "REBEKAH TURNER",
        "ABC Trading Corporation",          # 3-letter acronym is a real name, not garble
        "INCOME TAX PERCENTAGE TAX - MONTHLY",
        # Alphanumeric unit/lot/block codes are normal in a PH address — this is
        # a real extracted value, and flagging it would warn on every address.
        "G09 LO2 GEMINI STREET, SCHWARTZ VILLAGE, PASIG CITY",
        "BLK 5 L2 MARIA CLARA ST.",
    ]:
        assert not _noisy(value), value


def test_looks_noisy_catches_garble_shapes() -> None:
    for value in [
        "EE Bincn",                 # caps fragment beside a mixed-case token
        "NORTHZ2N STAR FINANCE",    # letter fused to a digit
        "| Certificate of .",       # hallucinated rule character
        "a b c d",                  # orphaned single letters
        "X",                        # too short to be a value
        "BRGY WRKGTN LOPEZ",        # unpronounceable consonant run
    ]:
        assert _noisy(value), value


def _field(value, *, required=True, confidence=95.0, **extra) -> dict:
    return {"name": "X", "value": value, "required": required,
            "matched": value is not None, "confidence": confidence, **extra}


def _warn(key: str, field: dict, specs=None) -> list:
    specs = specs if specs is not None else ocr_dryrun.FIELD_SPECS
    return ocr_dryrun.annotate_field_warnings({key: field}, specs,
                                              ocr_dryrun.CONFIG)[key]["warnings"]


def test_clean_field_raises_no_warning() -> None:
    assert _warn("tin", _field("009-028-463-000")) == []
    assert _warn("registered_name", _field("NORTHERN STAR FINANCE CORPORATION")) == []


def test_value_violating_its_format_is_flagged() -> None:
    # A line-scope regex that doesn't match falls back to the whole region, so
    # the caption itself can land in the value (extract_fields' `else region`).
    assert _warn("rdo_code", _field("REVENUE DISTRICT")) == ["format_mismatch"]
    # A TIN that never reached _normalise_value's canonical 3-3-3-4 form.
    assert _warn("tin", _field("009-028-463")) == ["format_mismatch"]


def test_free_text_value_is_graded_by_the_noise_heuristic() -> None:
    assert _warn("trade_name", _field("EE Bincn")) == ["noisy_text"]


def test_format_checked_field_skips_the_noise_heuristic() -> None:
    # The OCN's real format (1RC0001016814) trips the letter/digit rule, so
    # running both graders would flag every valid OCN.
    assert _warn("ocn", _field("1RC0001016814")) == []


def test_low_confidence_value_is_flagged() -> None:
    floor = ocr_dryrun.CONFIG["field_confidence_floor"]
    assert _warn("registered_address", _field("PUROK ORIENTAL", confidence=floor - 10)) \
        == ["low_confidence"]
    assert _warn("registered_address", _field("PUROK ORIENTAL", confidence=floor)) == []


def test_roi_trocr_value_is_not_treated_as_unsure() -> None:
    # The ROI pass reports no confidence AND is the more accurate recogniser
    # (routers/ocr.py), so "unknown" must not read as "low".
    field = _field("PUROK ORIENTAL", confidence=None, source="roi_trocr")
    assert _warn("registered_address", field) == []


def test_missing_required_field_is_flagged_but_optional_one_is_not() -> None:
    assert _warn("line_of_business", _field(None)) == ["not_found"]
    assert _warn("revenue_district_officer", _field(None, required=False)) == []


def test_warnings_are_ordered_by_priority() -> None:
    field = _field("REVENUE DISTRICT", confidence=10.0)
    assert _warn("rdo_code", field) == ["format_mismatch", "low_confidence"]


def test_every_template_annotates_without_error() -> None:
    # Guards against a value_regex added to one template's specs but not wired
    # through TEMPLATES, which would silently drop warnings for that type.
    for template in ("bir", "business_permit", "dti"):
        specs = ocr_dryrun.TEMPLATES[template]["field_specs"]
        fields = ocr_dryrun.extract_fields("", {}, specs)
        annotated = ocr_dryrun.annotate_field_warnings(fields, specs, ocr_dryrun.CONFIG)
        assert all("warnings" in f for f in annotated.values()), template


def test_dti_date_value_regex_accepts_the_extracted_value() -> None:
    # Regression for the reason value_regex exists: date_issued's extraction
    # pattern is anchored on "valid from", so reusing it to validate the
    # captured "09/18/2022" would report every DTI date as malformed.
    fields = ocr_dryrun.extract_fields(
        "This certificate is valid from 09/18/2022 to 09/18/2027 subject to",
        {}, ocr_dryrun.DTI_FIELD_SPECS,
    )
    annotated = ocr_dryrun.annotate_field_warnings(
        fields, ocr_dryrun.DTI_FIELD_SPECS, ocr_dryrun.CONFIG
    )
    assert annotated["date_issued"]["value"] == "09/18/2022"
    assert annotated["date_issued"]["warnings"] == []
    assert annotated["expiry_date"]["value"] == "09/18/2027"
    assert annotated["expiry_date"]["warnings"] == []


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
