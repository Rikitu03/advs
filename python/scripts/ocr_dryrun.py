"""ADVS - OCR extraction quality dry-run for BIR forms (Stage 2 test harness).

A standalone diagnostic for the ADVS OCR stage. Stage 1 preprocessing uses the
top-scoring path from the --compare montage (grayscale -> 2x bicubic upscale ->
Otsu auto-threshold) instead of the §9 fixed binarize@150 + morph-open, which
loses text under the colored security background on BIR forms. It then runs
PyTesseract OCR and extracts the document
into a STRUCTURED set of fields (TIN, registered name, RDO code, dates, ...)
using a BIR template, and finally scores OCR quality the way Stage 2 does
(matched vs. missing fields -> text-validation score + flags).

It is fail-forward like the real pipeline: poor scans do not abort, they lower
the quality score and raise flags.

This is NOT part of the queue pipeline - it is a manual quality harness you run
against a sample document to gauge how well OCR will perform before wiring the
production `ocr_runner.py`.

Two modes (mirrors the run-advs-training scripts):
    --dry-run   Stdlib + OpenCV only. Validates the input, runs Stage 1
                preprocessing, optionally saves the preprocessed PNG, and
                reports which OCR dependencies are installed. No Tesseract
                needed. Exit 0 if the plumbing is sound.
    (default)   Full OCR extraction. Needs pytesseract + the Tesseract engine
                (and pdf2image + Poppler for PDF input). Prints a field report
                and OCR quality metrics; optionally writes a JSON result.

Examples:
    python scripts/ocr_dryrun.py --input sample_2303.jpg --dry-run
    python scripts/ocr_dryrun.py --input sample_2303.jpg --save-preprocessed pre.png --dry-run
    python scripts/ocr_dryrun.py --input sample_2303.jpg --output result.json
    python scripts/ocr_dryrun.py --input cert.pdf --tesseract-cmd "C:/Program Files/Tesseract-OCR/tesseract.exe"

Heavy imports (pytesseract, pdf2image) are lazy so --dry-run works on the bare
venv (which has cv2 + numpy but not pytesseract).
"""

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import sys
import time
from pathlib import Path

PY_ROOT = Path(__file__).resolve().parents[1]

# ===========================================================================
# EDIT THIS to run the file manually: paste the full path to your BIR form
# between the quotes, then just run the script (IDE "Run" button / no CLI args).
# Passing --input on the command line still overrides this.
# ===========================================================================
DEFAULT_INPUT = r"C:\xampp\htdocs\projects\advs\python\data\training\classifier_data\bir_certificate\bir2.jpg"   # e.g. r"C:\Users\Profile\Desktop\sample_2303.jpg"

# False = on Run, the OCR field-extraction report: the structured-field table,
#         OCR quality metrics, and the extracted text - the usual meaningful run.
# True  = build the side-by-side preprocessing comparison image instead (original
#         + spec + Otsu variants, each labelled with its OCR score) and open it.
# CLI --compare / --no-compare and --open / --no-open override this either way.
DEFAULT_COMPARE = False

# --- Tunables. Preprocessing values are the ADVS defaults (System Reference
# --- §9). The thresholds below the rule are harness-local quality gates; the
# --- text-validation pass mark mirrors the §6 drill-down example (70%).
CONFIG: dict = {
    # Stage 1 now uses Otsu + upscale (top scorer in the --compare montage). The
    # fixed threshold/kernel stay for the SPEC comparison tile + §9 reference.
    "upscale_factor": 2,              # 2x bicubic upscale before Otsu (more px/glyph)
    "binarization_threshold": 150,    # §9 BINARIZATION_THRESHOLD (SPEC tile only)
    "morph_kernel": (2, 2),           # §9 MORPH_KERNEL_SIZE (SPEC tile only)
    "tesseract_psm": 6,               # CLAUDE.md Phase 5: --psm 6 (uniform block of text)
    "tesseract_oem": 3,               # default LSTM engine
    "tesseract_lang": "eng",
    "pdf_dpi": 300,                   # §9 PDF_DPI
    "max_pdf_pages": 2,               # §9 MAX_PDF_PAGES
    "text_validation_threshold": 0.70,  # §6 drill-down "Text Validation (OCR)" pass mark
    "low_conf_floor": 60,             # per-word Tesseract confidence below this = "low" (0-100)
    "min_words_for_text": 15,         # fewer recognised words than this -> "insufficient_text" flag
    # Per-FIELD warning thresholds (annotate_field_warnings). These grade one
    # extracted value for the officer's drill-down; they never feed a score or a
    # risk component. Deliberately looser than low_conf_floor: a whole page can
    # average 60% and still be usable, but a single value read at 75% is worth a
    # second look, since Tesseract reports 68-78% on values it read WRONG
    # (see api/config.py roi_tesseract_confidence_floor for the measurements).
    "field_confidence_floor": 80,     # mean per-word confidence below this = "unsure" value
    "noise_symbol_ratio": 0.15,       # share of chars outside the plausible set before "noisy"
    "noise_consonant_run": 5,         # consonants in a row inside one token before "noisy"
    # Stamp recovery (experimental, --compare only). The BIR seal prints the
    # REVENUE REGION/DISTRICT numbers in red ink over an orange guilloche; a single
    # colour channel separates the two where grayscale cannot. ROI is a fractional
    # (y0, y1, x0, x1) band over the top seal on Form 2303 - widen it if the seal
    # sits elsewhere on your sample.
    "stamp_roi": (0.0, 0.18, 0.28, 0.78),
    "stamp_channel": "green",         # B/G/R channel that best splits red ink from orange pattern
    "stamp_clahe_clip": 2.0,          # CLAHE contrast cap; higher lifts faint ink harder (+ more noise)
    "stamp_clahe_tile": 8,            # CLAHE tile grid (NxN) over the ROI
    # FFT notch filter: the guilloche is a PERIODIC pattern, so it concentrates in
    # a few bright spectral peaks; zeroing those subtracts the pattern while the
    # aperiodic digits (low-frequency core) survive. Fractions are of min(H, W).
    "notch_protect_frac": 0.06,       # keep this low-frequency core (the digit strokes)
    "notch_radius_frac": 0.03,        # radius zeroed around each detected peak
    "notch_peaks": 14,                # how many brightest periodic peaks to notch out
}


def log(msg: str) -> None:
    print(f"[ocr-dryrun] {msg}", flush=True)


def section(title: str) -> None:
    print("\n" + "=" * 70 + f"\n  {title}\n" + "=" * 70, flush=True)


def _utf8_stdout() -> None:
    """Force UTF-8 stdout so the extracted-text dump (which can carry non-cp1252
    OCR glyphs) prints without a UnicodeEncodeError on Windows consoles."""
    reconfigure = getattr(sys.stdout, "reconfigure", None)  # present on real TextIOWrapper
    if reconfigure is not None:
        try:
            reconfigure(encoding="utf-8", errors="replace")
        except (ValueError, OSError):
            pass


class OcrError(RuntimeError):
    """Expected, user-actionable failure (missing input / missing engine)."""


# ---------------------------------------------------------------------------
# BIR Certificate of Registration (Form 2303) field template.
#
# Each spec locates one structured field. `labels` are the on-form caption
# tokens used to find the line; `regex` extracts/validates a value. `scope`
# "global" searches the whole OCR blob, "line" searches the text after the
# label (falling back to the next non-empty line). `required` fields feed the
# missing-component flag and the text-validation score.
#
# `value_regex` is VALIDATION-ONLY (annotate_field_warnings) and never used to
# extract anything. It exists because most `regex` patterns are context-anchored
# - they match surrounding prose and capture group 1 (DTI's date is
# `valid from\s+(\d{1,2}/...)`, capturing "09/18/2022") - so re-applying `regex`
# to the extracted VALUE always fails. `value_regex` is fullmatched against the
# final value, whichever engine produced it (label+regex, positional, or
# ROI+TrOCR). Fields whose value is free text (names, addresses) get no
# `value_regex`; the noise heuristic grades those instead.
# ---------------------------------------------------------------------------

# "MON DD YYYY" with a REAL month name (abbreviated or full), an optional comma
# after the day, and the year bound to 19xx/20xx. Shared by date_issued's
# extraction pattern and its value_regex so the two can never disagree.
_MONTH_DATE = (
    r"(?:JAN(?:UARY)?|FEB(?:RUARY)?|MAR(?:CH)?|APR(?:IL)?|MAY|JUN(?:E)?|"
    r"JUL(?:Y)?|AUG(?:UST)?|SEP(?:T(?:EMBER)?)?|OCT(?:OBER)?|NOV(?:EMBER)?|"
    r"DEC(?:EMBER)?)\.?\s+\d{1,2},?\s+(?:19|20)\d{2}"
)

FIELD_SPECS: list[dict] = [
    {"key": "form_no", "name": "Form No.", "scope": "global",
     # Still extracted for the report, but NOT part of the officer's required
     # keyword set, so a missing Form No. no longer penalises text-validation.
     "regex": r"\b(2303)\b", "value_regex": r"2303", "required": False},
    {"key": "ocn", "name": "OCN", "scope": "global",
     # OCN prints ABOVE its caption and is digit-heavy (e.g. 1RC0001016814), so
     # match the format directly. A label scan returns the line after "OCN",
     # which is "CERTIFICATE OF REGISTRATION" -> the old regex grabbed that.
     "regex": r"\b(\d[A-Z]{1,3}\d{7,})\b", "value_regex": r"\d[A-Z]{1,3}\d{7,}",
     "required": True},
    {"key": "tin", "name": "TIN", "scope": "global",
     "regex": r"(\d{3}\s*[-–]\s*\d{3}\s*[-–]\s*\d{3}\s*[-–]\s*\d{3,4})",
     # _normalise_value re-emits a matched TIN as 999-999-999-9999, so validate
     # that canonical form - a value still carrying OCR spacing/en-dashes (or a
     # short digit run) never went through the normaliser and IS suspect.
     "value_regex": r"\d{3}-\d{3}-\d{3}-\d{3,4}",
     "required": True},
    {"key": "registered_name", "name": "Registered Name", "scope": "line",
     "labels": ["REGISTERED NAME", "NAME"], "custom": "header_table_name",
     "required": True},
    {"key": "registration_date", "name": "Registration Date", "scope": "line",
     "labels": ["REGISTRATION DATE"], "regex": r"(\d{1,2}/\d{1,2}/\d{2,4})",
     "value_regex": r"\d{1,2}/\d{1,2}/\d{2,4}",
     "global_fallback": True, "required": True},
    {"key": "registered_address", "name": "Registered Address", "scope": "line",
     "labels": ["REGISTERED ADDRESS"], "required": True},
    {"key": "revenue_region_no", "name": "Revenue Region No.", "scope": "line",
     "labels": ["REVENUE REGION NO", "REVENUE REGION"], "regex": r"(\d{1,3}[A-Z]?)",
     "value_regex": r"\d{1,3}[A-Z]?",
     # the region code can carry a trailing letter (e.g. 09B), so a digits-only
     # regex would drop the "B". Value sits on the caption's own stamp row, so
     # same_line keeps it from falling through to the district line below.
     # Extracted + merged from the seal pass, but not a required keyword.
     "same_line": True, "required": False},
    {"key": "rdo_code", "name": "Revenue District No. (RDO)", "scope": "line",
     "labels": ["REVENUE DISTRICT NO", "REVENUE DISTRICT", "RDO"], "regex": r"(\d{1,3})",
     "value_regex": r"\d{1,3}",
     # value is column-aligned on the caption's own row; without same_line the
     # scan falls to the next line and grabs the "1" from the OCN (1RC0001016814).
     # "REVENUE DISTRICT" (no "NO") tolerates OCR dropping the noisy caption token.
     # Extracted for the report, but not a required keyword.
     "same_line": True, "required": False},
    {"key": "line_of_business", "name": "Line of Business / PSIC", "scope": "line",
     "labels": ["LINE OF BUSINESS", "INDUSTRY"], "required": True},
    {"key": "trade_name", "name": "Trade Name", "scope": "line",
     "labels": ["TRADE NAME"], "required": True},
    {"key": "tax_types", "name": "Registered Activity(ies)", "scope": "line",
     "labels": ["REGISTERED ACTIVITIES", "REGISTERED ACTIVITY", "TAX TYPE"], "required": True},
    {"key": "revenue_district_officer", "name": "Revenue District Officer", "scope": "line",
     "labels": ["REVENUE DISTRICT OFFICER", "DISTRICT OFFICER"], "required": False},
    {"key": "date_issued", "name": "Date Issued", "scope": "global",
     # The issue date floats anywhere on the form as "MON DD YYYY" (e.g. MAR 09
     # 2017). Anchoring on a REAL month keeps a stray 3-letter token + numbers
     # like "RDO 39 2018" from being mistaken for a date (see _MONTH_DATE).
     "regex": rf"\b({_MONTH_DATE})\b", "value_regex": _MONTH_DATE,
     "required": True},
]

# Keywords that should appear anywhere in a genuine Form 2303 (a quick template
# sanity check independent of field extraction).
TEMPLATE_KEYWORDS = [
    "CERTIFICATE OF REGISTRATION", "BUREAU OF INTERNAL REVENUE",
    "REGISTERED", "TIN", "REVENUE REGION", "REVENUE DISTRICT",
]

# Ordered annotation keywords - one per structured field - exposed for external
# tooling (e.g. annotate_boxes.py, the bounding-box annotator that calibrates the
# dataset generator's FIELD_BOXES). Derived from FIELD_SPECS so the two never
# drift: a box drawn for keyword "tin" maps straight to the "tin" field.
KEYWORD_LIST = [spec["key"] for spec in FIELD_SPECS]


# ---------------------------------------------------------------------------
# LGU Business Permit (Mayor's Permit) field template.
#
# Unlike the BIR form, the fill VALUE prints on the line ABOVE its caption
# (a permit "write-on-the-line, label-underneath" layout), so the label fields
# use ``value_above`` and read the previous non-empty line. City + issue date are
# sentence/header text matched globally. Field keys mirror
# business_permit_dataset_generator.py so the synthetic data and the template
# never drift.
# ---------------------------------------------------------------------------
BUSINESS_PERMIT_FIELD_SPECS: list[dict] = [
    {"key": "city_issued", "name": "City Issued", "scope": "global",
     # The issuing city prints in the header as "CITY OF <NAME>". Keep the
     # separator to spaces/tabs (not \s, which would run the match across the
     # newline into the following caption lines).
     "regex": r"CITY OF ([A-Z][A-Za-z]+(?:[ \t]+[A-Z][A-Za-z]+)*)",
     "value_regex": r"[A-Z][A-Za-z]+(?:[ \t]+[A-Z][A-Za-z]+)*", "required": True},
    {"key": "name_of_proprietor", "name": "Name of Proprietor", "scope": "line",
     "labels": ["NAME OF PROPRIETOR", "PROPRIETOR"], "value_above": True, "required": True},
    {"key": "trade_name", "name": "Trade Name", "scope": "line",
     "labels": ["TRADE NAME"], "value_above": True, "required": True},
    {"key": "business_location", "name": "Business Address / Location", "scope": "line",
     "labels": ["BUSINESS LOCATION", "BUSINESS ADDRESS", "LOCATION"],
     "value_above": True, "required": True},
    {"key": "kind_of_business", "name": "Kind of Business", "scope": "line",
     "labels": ["KIND OF BUSINESS", "NATURE OF BUSINESS"], "value_above": True, "required": True},
    {"key": "date_issued", "name": "Issued Date", "scope": "global",
     # "Issued this 26th day of AUGUST , 2012, at ..." — capture day + month + year.
     "regex": r"(?i)issued this\s+(\d{1,2}(?:st|nd|rd|th)?\s+day of\s+[A-Za-z]+\s*,?\s*(?:19|20)\d{2})",
     "value_regex": r"(?i)\d{1,2}(?:st|nd|rd|th)?\s+day of\s+[A-Za-z]+\s*,?\s*(?:19|20)\d{2}",
     "required": True},
]

BUSINESS_PERMIT_KEYWORDS = [
    "BUSINESS PERMIT", "PROPRIETOR", "TRADE NAME", "KIND OF BUSINESS", "CITY OF",
]


# ---------------------------------------------------------------------------
# DTI Business Name Registration certificate field template.
#
# The certificate is prose, not a form: the business name/address sit in the
# "This certifies that ..." block, the owner after "certificate issued to", the
# validity as "valid from <date> to <date>", and the certificate/TRN on their own
# captioned lines. Field keys mirror dti_registration_dataset_generator.py.
# ---------------------------------------------------------------------------
DTI_FIELD_SPECS: list[dict] = [
    {"key": "business_name", "name": "Business Name", "scope": "line",
     "custom": "dti_business_name", "required": True},
    {"key": "business_address", "name": "Business Address", "scope": "line",
     "custom": "dti_business_address", "required": True},
    {"key": "owner_representative_name", "name": "Owner / Representative Name", "scope": "line",
     "labels": ["ISSUED TO"], "required": True},
    {"key": "date_issued", "name": "Valid Date", "scope": "global",
     "regex": r"(?i)valid from\s+(\d{1,2}/\d{1,2}/\d{2,4})",
     "value_regex": r"\d{1,2}/\d{1,2}/\d{2,4}", "required": True},
    {"key": "expiry_date", "name": "Expiration Date", "scope": "global",
     "regex": r"(?i)valid from\s+\d{1,2}/\d{1,2}/\d{2,4}\s+to\s+(\d{1,2}/\d{1,2}/\d{2,4})",
     "value_regex": r"\d{1,2}/\d{1,2}/\d{2,4}", "required": True},
    {"key": "certificate_no", "name": "Certificate Number", "scope": "line",
     "labels": ["CERTIFICATE NO", "CERTIFICATE NUMBER"],
     "regex": r"((?:BN\s*)?\d{5,})", "value_regex": r"(?:BN\s*)?\d{5,}",
     "same_line": True, "required": True},
    {"key": "trn_no", "name": "TRN", "scope": "line",
     "labels": ["TRN"], "regex": r"(DTI-\d{4}-\d+|[A-Z0-9-]{6,})",
     "value_regex": r"DTI-\d{4}-\d+|[A-Z0-9-]{6,}",
     "same_line": True, "required": True},
]

DTI_KEYWORDS = [
    "DEPARTMENT OF TRADE", "BUSINESS NAME", "CERTIFICATE", "TRN",
]


# ---------------------------------------------------------------------------
# Dependency / engine resolution
# ---------------------------------------------------------------------------
def check_dependencies() -> dict:
    import importlib.util

    def have(mod: str) -> bool:
        return importlib.util.find_spec(mod) is not None

    return {
        "cv2": have("cv2"),
        "numpy": have("numpy"),
        "PIL": have("PIL"),
        "pytesseract": have("pytesseract"),
        "pdf2image": have("pdf2image"),
        "tesseract_engine": resolve_tesseract_cmd(None),
    }


def resolve_tesseract_cmd(explicit: str | None) -> str | None:
    """Find the tesseract.exe engine: --flag, env, PATH, then common installs."""
    candidates = [
        explicit,
        os.environ.get("TESSERACT_CMD"),
        shutil.which("tesseract"),
        r"C:\Program Files\Tesseract-OCR\tesseract.exe",
        r"C:\Program Files (x86)\Tesseract-OCR\tesseract.exe",
    ]
    for cand in candidates:
        if cand and Path(cand).exists():
            return cand
        if cand and shutil.which(cand):
            return shutil.which(cand)
    return None


def report_dependencies(deps: dict) -> None:
    section("Dependency check")
    for mod in ("cv2", "numpy", "PIL", "pytesseract", "pdf2image"):
        print(f"  {mod:14} {'OK' if deps[mod] else 'MISSING'}")
    eng = deps["tesseract_engine"]
    print(f"  {'tesseract':14} {eng if eng else 'NOT FOUND'}")
    if not deps["pytesseract"] or not eng:
        print("\n  To enable the full OCR pass:")
        print("    1) pip install pytesseract pdf2image   (into python/env)")
        print("    2) Install the Tesseract engine (Windows: UB Mannheim build)")
        print("       https://github.com/UB-Mannheim/tesseract/wiki")
        print("    3) (PDF input only) install Poppler and put bin/ on PATH")
        print("    Then re-run without --dry-run, or pass --tesseract-cmd <path>.")


# ---------------------------------------------------------------------------
# Stage 1: OpenCV preprocessing (faithful to System Reference §5 Stage 1)
# ---------------------------------------------------------------------------
def load_images(input_path: Path, cfg: dict):
    """Return a list of BGR numpy images (PDF -> first N pages, else 1 image)."""
    import numpy as np

    if input_path.suffix.lower() == ".pdf":
        try:
            from pdf2image import convert_from_path
        except ImportError as exc:
            raise OcrError(
                "PDF input needs pdf2image + Poppler. Install them, or pass a PNG/JPG."
            ) from exc
        pages = convert_from_path(
            str(input_path), dpi=cfg["pdf_dpi"], first_page=1,
            last_page=cfg["max_pdf_pages"],
        )
        import cv2
        return [cv2.cvtColor(np.array(p), cv2.COLOR_RGB2BGR) for p in pages]

    import cv2
    img = cv2.imread(str(input_path), cv2.IMREAD_COLOR)
    if img is None:
        raise OcrError(f"OpenCV could not read image: {input_path}")
    return [img]


def preprocess(img, cfg: dict):
    """Grayscale -> 2x bicubic upscale -> Otsu auto-threshold (black on white).

    Replaces the §9 fixed binarize@150 + morph-open path: on BIR forms with a
    colored guilloche security background a global 150 cut turns the pattern into
    foreground and buries the text. Otsu derives the threshold per-image from the
    histogram, and the 2x upscale gives Tesseract more pixels per glyph. This was
    the top scorer in the --compare montage (see preprocess_variants). THRESH_BINARY
    already yields black text on white, so no bitwise-not is needed.
    """
    import cv2

    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    scale = cfg["upscale_factor"]
    if scale and scale != 1:
        gray = cv2.resize(gray, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
    _, binary = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    return binary


# ---------------------------------------------------------------------------
# Stage 2: OCR + light NLP cleanup
# ---------------------------------------------------------------------------
def run_ocr(preprocessed, cfg: dict, tesseract_cmd: str) -> dict:
    """OCR one preprocessed page. Returns text, per-word confidences, and the
    per-word bounding boxes (kept for positional / column-aware extraction)."""
    import pytesseract
    from pytesseract import Output

    pytesseract.pytesseract.tesseract_cmd = tesseract_cmd
    config = f"--oem {cfg['tesseract_oem']} --psm {cfg['tesseract_psm']} -l {cfg['tesseract_lang']}"

    raw_text = pytesseract.image_to_string(preprocessed, config=config)
    data = pytesseract.image_to_data(preprocessed, config=config, output_type=Output.DICT)

    confs, token_conf, words = [], {}, []
    for i in range(len(data["text"])):
        word = data["text"][i]
        try:
            c = float(data["conf"][i])
        except (TypeError, ValueError):
            continue
        if c < 0 or not word.strip():
            continue
        confs.append(c)
        token_conf.setdefault(_norm_token(word), []).append(c)
        words.append({
            "text": word, "conf": c,
            "left": int(data["left"][i]), "top": int(data["top"][i]),
            "width": int(data["width"][i]), "height": int(data["height"][i]),
        })
    return {"raw_text": raw_text, "confidences": confs,
            "token_conf": token_conf, "words": words}


def _norm_token(word: str) -> str:
    return re.sub(r"[^A-Z0-9]", "", word.upper())


def _levenshtein(a: str, b: str) -> int:
    """Character-level edit distance (substitutions/insertions/deletions)."""
    if a == b:
        return 0
    if not a:
        return len(b)
    if not b:
        return len(a)
    prev = list(range(len(b) + 1))
    for i, ca in enumerate(a, 1):
        cur = [i]
        for j, cb in enumerate(b, 1):
            cur.append(min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (ca != cb)))
        prev = cur
    return prev[-1]


def _fuzzy_token_eq(a: str, b: str, max_typos: int = 0) -> bool:
    """True if two tokens are equal within ``max_typos`` character edits.

    Both sides are normalised with ``_norm_token`` first. The tolerance is capped
    at one less than the shorter token's length, so a short caption ("NAME",
    "TIN", "DATE") can never fuzzy-match a wholly different token even with a large
    budget - long captions get the full 1-4 budget, short ones are called with a
    small one. ``max_typos=0`` means exact match (the default).
    """
    a, b = _norm_token(a), _norm_token(b)
    if not a or not b:
        return False
    if a == b:
        return True
    if max_typos < 1:
        return False
    tol = min(max_typos, min(len(a), len(b)) - 1)
    if tol < 1:
        return False
    return _levenshtein(a, b) <= tol


def clean_text(raw: str) -> str:
    """Light NLP cleanup: trim, collapse intra-line whitespace, drop blanks."""
    lines = []
    for line in raw.splitlines():
        line = re.sub(r"[ \t]+", " ", line).strip()
        if line:
            lines.append(line)
    return "\n".join(lines)


# Edge separators/noise to peel off a line. Leading-strip includes "." (a line
# should not START on a period); trailing-strip omits "." so a genuine terminal
# sentence period survives.
_TIDY_LEAD = " \t|/\\,.;:!?-–—_=+~^*"
_TIDY_TRAIL = " \t|/\\,;:!?-–—_=+~^*"


def tidy_text(raw: str) -> str:
    """Light sentence/grammar tidy for the *messy* OCR dump (cosmetic only).

    Per line: collapse whitespace, pull punctuation back onto the preceding word
    ("WORD ," -> "WORD,"), squash repeated punctuation ("AMENDED.." -> "AMENDED."),
    peel stray separator noise off both ends (keeping a terminal period), and drop
    lines that carry no real token (>=2 letters/digits) - pure OCR garbage like
    "{" or "1 :". This makes the extracted-text section read like sentences.

    It is NOT used for field extraction or scoring, so it can never change what is
    matched; it only improves how the dump reads back.
    """
    lines = []
    for line in raw.splitlines():
        line = re.sub(r"[ \t]+", " ", line).strip()
        line = re.sub(r"\s+([,.;:!?])", r"\1", line)      # "WORD ," -> "WORD,"
        line = re.sub(r"([,.;:!?\-])\1+", r"\1", line)    # "AMENDED.." -> "AMENDED."
        line = line.lstrip(_TIDY_LEAD).rstrip(_TIDY_TRAIL)
        line = re.sub(r"\s+", " ", line).strip()
        if re.search(r"[A-Za-z0-9]{2,}", line):           # keep only lines with a real token
            lines.append(line)
    return "\n".join(lines)


# ---------------------------------------------------------------------------
# Structured field extraction (BIR template)
# ---------------------------------------------------------------------------
def _field_confidence(value: str, token_conf: dict) -> float | None:
    confs = []
    for tok in (_norm_token(t) for t in value.split()):
        if tok and tok in token_conf:
            confs.append(sum(token_conf[tok]) / len(token_conf[tok]))
    return round(sum(confs) / len(confs), 1) if confs else None


_LABEL_SEP = " \t:.-–—/()|,"


def _strip_leading_labels(text: str, labels: list[str]) -> str:
    """Peel leading separators and any repeated caption tokens off a value.

    Handles combined captions like 'LINE OF BUSINESS / INDUSTRY  7414 ...' by
    stripping 'LINE OF BUSINESS', the '/ ' separators, then 'INDUSTRY'.
    """
    s = text
    changed = True
    while changed:
        changed = False
        stripped = s.lstrip(_LABEL_SEP)
        if stripped != s:
            s, changed = stripped, True
        upper = s.upper()
        for label in labels:
            if upper.startswith(label):
                s, changed = s[len(label):], True
                break
    return s.strip()


def _find_by_label(lines: list[str], labels: list[str], allow_next_line: bool = True):
    """Return text after the first matching label.

    Falls back to the next non-empty line when nothing follows the caption,
    unless ``allow_next_line`` is False - for fields whose value is column-aligned
    on the caption's own row, where the next line belongs to a different field.
    """
    for i, line in enumerate(lines):
        upper = line.upper()
        for label in labels:
            pos = upper.find(label)
            if pos == -1:
                continue
            tail = _strip_leading_labels(line[pos + len(label):], labels)
            if tail:
                return tail
            if allow_next_line and i + 1 < len(lines):
                return lines[i + 1].strip()
            return ""
    return None


def _find_by_label_above(lines: list[str], labels: list[str]):
    """Return the nearest non-empty line ABOVE the first matching caption.

    LGU business permits print the fill value on the line above its printed
    caption ("<value>" then "NAME OF PROPRIETOR"), the reverse of the BIR form, so
    the value is read from the previous non-empty line rather than after the label.
    """
    for i, line in enumerate(lines):
        upper = line.upper()
        if any(label in upper for label in labels):
            for j in range(i - 1, -1, -1):
                prev = lines[j].strip()
                if prev:
                    return prev
            return ""
    return None


def _dti_certified_block(lines: list[str]) -> list[str]:
    """The value lines inside a DTI "This certifies that ..." block: the business
    name followed by its address, stopping at the boilerplate that follows."""
    for i, line in enumerate(lines):
        if "CERTIFIES THAT" in line.upper():
            block: list[str] = []
            for cont in lines[i + 1:]:
                if re.search(r"IS A BUSINESS NAME REGISTERED|PURSUANT|IN COMPLIANCE",
                             cont.upper()):
                    break
                if cont.strip():
                    block.append(cont.strip())
                if len(block) >= 2:
                    break
            return block
    return []


def _dti_certified_line(lines: list[str], index: int) -> str | None:
    block = _dti_certified_block(lines)
    return block[index] if index < len(block) else None


_TIN_TOKEN = re.compile(r"\d{3}\s*[-–]\s*\d{3}\s*[-–]\s*\d{3}\s*[-–]\s*\d{3,4}")
_DATE_TOKEN = re.compile(r"\d{1,2}/\d{1,2}/\d{2,4}")


def _line_is_name_header(line: str) -> bool:
    """True if a line is the 'TIN | NAME | REGISTRATION DATE' header row, matched
    fuzzily so an OCR typo in the long caption (e.g. REGISTRAUION) still flags it.
    NAME/DATE use a 1-typo budget (short, only checked for presence); the long
    REGISTRATION caption gets the full 4."""
    toks = line.split()
    return (
        any(_fuzzy_token_eq(t, "NAME", 1) for t in toks)
        and any(_fuzzy_token_eq(t, "REGISTRATION", 4) for t in toks)
        and any(_fuzzy_token_eq(t, "DATE", 1) for t in toks)
    )


def _extract_registered_name(lines: list[str]) -> str | None:
    """Pull the registrant name from the TIN | NAME | REGISTRATION DATE table.

    The three captions share one header row and their values share the row(s)
    below, so a plain label scan returns the neighbouring caption. Locate the
    header row, take the value line(s) up to the next section, then strip the
    flanking TIN and date (both format-distinctive) and the column divider.
    """
    for i, line in enumerate(lines):
        if _line_is_name_header(line):  # the TIN|NAME|REGISTRATION DATE caption row
            parts: list[str] = []
            for cont in lines[i + 1:]:
                if re.search(r"REGISTERED (ADDRESS|ACTIVIT)", cont.upper()):
                    break
                parts.append(cont)
                if len(parts) >= 2:  # the name plus its wrap line is enough
                    break
            value = " ".join(parts)
            value = _TIN_TOKEN.sub(" ", value)
            value = _DATE_TOKEN.sub(" ", value)
            value = value.replace("|", " ")
            value = re.sub(r"\s+", " ", value).strip(" :|-")
            return value or None
    return None


def extract_fields(text: str, token_conf: dict, specs: list[dict] | None = None) -> dict:
    """Extract structured fields for a template. ``specs`` selects the field
    template (defaults to the BIR ``FIELD_SPECS``); see ``TEMPLATES``."""
    specs = specs if specs is not None else FIELD_SPECS
    lines = text.splitlines()
    out: dict = {}
    for spec in specs:
        raw_value = None
        custom = spec.get("custom")
        if custom == "header_table_name":
            raw_value = _extract_registered_name(lines)
        elif custom == "dti_business_name":
            raw_value = _dti_certified_line(lines, 0)
        elif custom == "dti_business_address":
            raw_value = _dti_certified_line(lines, 1)
        elif spec["scope"] == "global":
            m = re.search(spec["regex"], text)
            raw_value = m.group(1) if m else None
        else:  # line scope
            if spec.get("value_above"):
                region = _find_by_label_above(lines, spec["labels"])
            else:
                region = _find_by_label(
                    lines, spec["labels"], allow_next_line=not spec.get("same_line", False)
                )
            if region is not None and spec.get("regex"):
                m = re.search(spec["regex"], region)
                raw_value = m.group(1) if m else region
            else:
                raw_value = region
            if raw_value is None and spec.get("global_fallback") and spec.get("regex"):
                m = re.search(spec["regex"], text)
                raw_value = m.group(1) if m else None

        value = _normalise_value(spec["key"], raw_value) if raw_value else None
        out[spec["key"]] = {
            "name": spec["name"],
            "value": value,
            "required": spec["required"],
            "matched": value is not None,
            "confidence": _field_confidence(value, token_conf) if value else None,
        }
    return out


def _normalise_value(key: str, value: str) -> str:
    value = value.strip(" :.-–—").strip()
    if key == "tin":
        digits = re.sub(r"\D", "", value)
        if len(digits) >= 12:
            return f"{digits[0:3]}-{digits[3:6]}-{digits[6:9]}-{digits[9:13]}"
    if key == "date_issued":
        return re.sub(r"\s+", " ", value)  # collapse stray OCR gaps: "DEC  12   2020"
    return value


# ---------------------------------------------------------------------------
# Positional (bounding-box) extraction for the two-column body table.
#
# The TRADE NAME | LINE OF BUSINESS block and the TAX TYPE list sit in side-by-
# side columns that flatten into interleaved text, and the district officer's
# name prints ABOVE its caption - text scanning cannot separate those. These
# helpers use the per-word boxes from image_to_data: locate a caption, then read
# the words in the column band beneath (or above) it. Coordinates are in the
# 2x-upscaled pixel space the Otsu pipeline produces, so the y-offsets are tuned
# for Form 2303 at that scale.
# ---------------------------------------------------------------------------
def _find_phrase(words: list[dict], phrase: str, max_typos: int = 0):
    """Box (left, top, right, bottom) of the first consecutive run of words whose
    normalised text matches the phrase tokens; None if absent. ``max_typos`` > 0
    allows fuzzy per-token matching (tolerates OCR typos in a caption); the
    default 0 is an exact match, preserving the original behaviour."""
    tokens = [_norm_token(t) for t in phrase.split()]
    for i in range(len(words) - len(tokens) + 1):
        seg = words[i:i + len(tokens)]
        if all(_fuzzy_token_eq(w["text"], tok, max_typos)
               for w, tok in zip(seg, tokens)):
            return (
                min(w["left"] for w in seg),
                min(w["top"] for w in seg),
                max(w["left"] + w["width"] for w in seg),
                max(w["top"] + w["height"] for w in seg),
            )
    return None


def _words_in_box(words: list[dict], x0: float, x1: float,
                  y0: float, y1: float) -> list[dict]:
    """Words whose centre falls inside the rectangle, in reading order."""
    sel = [
        w for w in words
        if x0 <= w["left"] + w["width"] / 2 < x1
        and y0 <= w["top"] + w["height"] / 2 < y1
    ]
    sel.sort(key=lambda w: (round(w["top"] / 20), w["left"]))  # rows top->bottom, L->R
    return sel


def _join_clean(words: list[dict]) -> str | None:
    """Join word texts, stripping edge punctuation and dropping noise-only tokens."""
    parts = []
    for w in words:
        tok = re.sub(r"^[^A-Za-z0-9]+|[^A-Za-z0-9]+$", "", w["text"])
        if tok:
            parts.append(tok)
    return re.sub(r"\s+", " ", " ".join(parts)).strip() or None


def _positional_registered_name(words: list[dict]) -> str | None:
    """Read the registrant name from the TIN | NAME | REGISTRATION DATE header
    table using word boxes: the name sits in the middle column, below the NAME
    caption and between the TIN (left) and REGISTRATION DATE (right) columns.

    A plain text scan returns the neighbouring caption because the three columns
    flatten into one line; reading the column band beneath the NAME caption keeps
    only the name. Fuzzy caption matching tolerates an OCR typo in the long
    REGISTRATION caption (e.g. REGISTRAUION). The band stops just above the
    REGISTERED ADDRESS caption so the name's wrap line is kept but the next
    section is not, and any TIN/date that bleeds into the band is stripped.
    """
    name_cap = _find_phrase(words, "NAME", max_typos=1)
    date_cap = _find_phrase(words, "REGISTRATION DATE", max_typos=4)
    if not (name_cap and date_cap):
        return None
    if abs(name_cap[1] - date_cap[1]) > 40:  # captions must share the header row
        return None
    x0 = name_cap[0] - 40                     # left of NAME caption, past TIN column
    x1 = date_cap[0] - 30                     # right edge = start of the DATE column
    header_bottom = max(name_cap[3], date_cap[3])
    addr_cap = _find_phrase(words, "REGISTERED ADDRESS", max_typos=4)
    y0 = header_bottom + 6
    y1 = addr_cap[1] - 5 if addr_cap and addr_cap[1] > header_bottom else header_bottom + 80
    value = _join_clean(_words_in_box(words, x0, x1, y0, y1))
    if value:
        value = _TIN_TOKEN.sub(" ", value)
        value = _DATE_TOKEN.sub(" ", value)
        value = re.sub(r"\s+", " ", value).strip(" :|-")
    return value or None


def _positional_fields(words: list[dict]) -> dict:
    """Recover the column-split body fields from word boxes. A key is present
    only when its caption was located."""
    out: dict = {}

    trade = _find_phrase(words, "TRADE NAME")
    business = _find_phrase(words, "LINE OF BUSINESS")
    if trade and business:
        divider = business[0] - 50  # right column begins at the LINE OF BUSINESS caption
        y0 = max(trade[3], business[3]) + 25  # skip the blank gap row under the captions
        y1 = y0 + 100
        out["trade_name"] = _join_clean(_words_in_box(words, 0, divider, y0, y1))
        out["line_of_business"] = _join_clean(_words_in_box(words, divider, 1e9, y0, y1))

    tax = _find_phrase(words, "TAX TYPE")
    if tax:
        y1 = trade[1] - 2 if trade else tax[3] + 130
        out["tax_types"] = _join_clean(_words_in_box(words, 0, 1e9, tax[3] + 2, y1))

    officer = _find_phrase(words, "REVENUE DISTRICT OFFICER")
    if officer:  # the name prints just above the caption, within its horizontal span
        out["revenue_district_officer"] = _join_clean(
            _words_in_box(words, officer[0] - 30, officer[2] + 40,
                          officer[1] - 95, officer[1] - 5)
        )

    out["registered_name"] = _positional_registered_name(words)

    return {k: v for k, v in out.items() if v}


def refine_fields_with_positions(fields: dict, words: list[dict], token_conf: dict) -> dict:
    """Override the two-column body fields with positionally-extracted values
    when word boxes are available (text-only paths leave ``fields`` untouched)."""
    if not words:
        return fields
    for key, value in _positional_fields(words).items():
        if key in fields:
            fields[key]["value"] = value
            fields[key]["matched"] = True
            fields[key]["confidence"] = _field_confidence(value, token_conf)
    return fields


# ---------------------------------------------------------------------------
# Per-document-type template registry.
#
# Stage 2 selects a template by the document's type (Laravel maps document_type
# code -> template name). Each entry carries its field specs, the sanity-keyword
# list for the quality report, and the optional positional refiner (the two-column
# Form 2303 box logic is BIR-only; the permit/DTI templates are label-scan only).
# ---------------------------------------------------------------------------
TEMPLATES: dict[str, dict] = {
    "bir": {
        "field_specs": FIELD_SPECS,
        "keywords": TEMPLATE_KEYWORDS,
        "positional": refine_fields_with_positions,
    },
    "business_permit": {
        "field_specs": BUSINESS_PERMIT_FIELD_SPECS,
        "keywords": BUSINESS_PERMIT_KEYWORDS,
        "positional": None,
    },
    "dti": {
        "field_specs": DTI_FIELD_SPECS,
        "keywords": DTI_KEYWORDS,
        "positional": None,
    },
}


# ---------------------------------------------------------------------------
# Per-field warnings (officer drill-down; never feeds a score)
#
# score_quality() grades the PAGE. These grade one VALUE, so the officer's
# key/value table can mark the individual pairs worth re-reading rather than
# leaving them to spot "EE Bincn" in a wall of text. Purely presentational:
# nothing here changes an extracted value, a flag, or the risk score.
# ---------------------------------------------------------------------------

# Characters Tesseract emits when it hallucinates structure out of rules, seals
# and guilloche patterns. None of them appear in a real Philippine business
# name, address or activity list, so any occurrence marks the value noisy.
_GARBLE_CHARS = set("|~^\\_«»§¢£¥°¬{}[]<>*+=@")

# The plausible character set for a free-text field value. Anything outside it
# counts toward noise_symbol_ratio.
_PLAUSIBLE_VALUE_CHARS = re.compile(r"[A-Za-z0-9 .,&/'#()\-]")

_VOWELS = set("AEIOUY")

# A lone letter that is not an initial ("J." is fine, a bare "J" is not).
_STRAY_LETTER = re.compile(r"(?<![\w.])[A-Za-z](?![\w.])")

# A letter running straight into a digit inside one token, e.g. "NORTHZ2N".
_ALNUM_BOUNDARY = re.compile(r"[A-Za-z]\d|\d[A-Za-z]")

# Tokens up to this length are exempt from the letter/digit rule: unit, lot and
# block designators are routinely alphanumeric in a Philippine address
# ("G09 LO2 GEMINI STREET", "BLK 5", "L2"), and flagging them would put a
# standing warning on every address field. Real garble runs longer than this
# ("NORTHZ2N"), so the rule still catches what it was written for.
_SHORT_ALNUM_TOKEN = 4


def _looks_noisy(value: str, cfg: dict) -> bool:
    """Whether a free-text value reads as OCR garble.

    This detects GARBLE, not grammar - there is no language model here, and a
    grammatical check is not what separates "GEMINI STREZT" from "GEMINI
    STREET". It looks for the shapes Tesseract actually produces on a degraded
    scan: hallucinated symbols, unpronounceable consonant runs, letters fused
    to digits, and orphaned single letters. Tuned to under-report - a clean
    value must never be flagged, since a false warning on every row would train
    officers to ignore the column.
    """
    value = value.strip()
    if len(value) < 3:
        return True

    if _GARBLE_CHARS & set(value):
        return True

    implausible = len(_PLAUSIBLE_VALUE_CHARS.sub("", value))
    if implausible / len(value) > cfg["noise_symbol_ratio"]:
        return True

    if len(_STRAY_LETTER.findall(value)) > 1:
        return True

    tokens = value.split()
    # A 1-2 letter ALL-CAPS fragment inside an otherwise mixed-case value, e.g.
    # "EE Bincn" - the leftovers of a caption or a signature Tesseract tried to
    # read as text. Capped at 2 letters on purpose: a real acronym in a business
    # name ("ABC Trading Corporation") is 3+, so it stays unflagged.
    if any(any(ch.islower() for ch in tok) for tok in tokens):
        for token in tokens:
            letters = "".join(ch for ch in token if ch.isalpha())
            if 0 < len(letters) <= 2 and letters.isupper():
                return True

    run = cfg["noise_consonant_run"]
    for token in tokens:
        if len(token) > _SHORT_ALNUM_TOKEN and _ALNUM_BOUNDARY.search(token):
            return True
        letters = "".join(ch for ch in token if ch.isalpha()).upper()
        # A pure-digit or mixed token (dates, codes) has no letters to judge.
        if len(letters) < 4:
            continue
        if not (_VOWELS & set(letters)):
            return True
        consecutive = 0
        for char in letters:
            consecutive = 0 if char in _VOWELS else consecutive + 1
            if consecutive >= run:
                return True

    return False


def annotate_field_warnings(fields: dict, specs: list[dict], cfg: dict) -> dict:
    """Add a ``warnings`` list to every field in ``fields`` (mutated in place).

    Call this AFTER every engine that can write a value (label+regex, the
    positional refiner, and the ROI+TrOCR merge), so the warnings describe the
    value the officer actually sees rather than an intermediate one.

    Tokens: ``not_found`` (required but absent), ``format_mismatch`` (violates
    the spec's ``value_regex``), ``low_confidence`` (read below
    ``field_confidence_floor``), ``noisy_text`` (see :func:`_looks_noisy`).
    """
    specs_by_key = {spec["key"]: spec for spec in specs}

    for key, field in fields.items():
        spec = specs_by_key.get(key, {})
        warnings: list[str] = []
        value = field.get("value")

        if not field.get("matched") or not value:
            if field.get("required"):
                warnings.append("not_found")
            field["warnings"] = warnings
            continue

        value_regex = spec.get("value_regex")
        if value_regex is not None:
            if re.fullmatch(value_regex, value.strip()) is None:
                warnings.append("format_mismatch")
        elif _looks_noisy(value, cfg):
            # Only free-text fields get the heuristic. A format-checked field is
            # already graded by its pattern, and running both would double-flag
            # e.g. an OCN ("1RC0001016814" trips the letter/digit boundary).
            warnings.append("noisy_text")

        confidence = field.get("confidence")
        # confidence is None on the ROI+TrOCR path (routers/ocr.py sets it), which
        # reports no confidence AND is the more accurate recogniser - treating
        # "unknown" as "unsure" there would invert the signal.
        if confidence is not None and confidence < cfg["field_confidence_floor"]:
            warnings.append("low_confidence")

        field["warnings"] = warnings

    return fields


# ---------------------------------------------------------------------------
# Quality scoring (fail-forward, mirrors Stage 2 output)
# ---------------------------------------------------------------------------
def score_quality(text: str, fields: dict, ocr: dict, cfg: dict,
                  keywords: list[str] | None = None) -> dict:
    keywords = keywords if keywords is not None else TEMPLATE_KEYWORDS
    confs = ocr["confidences"]
    word_count = len(confs)
    low = [c for c in confs if c < cfg["low_conf_floor"]]
    mean_conf = round(sum(confs) / word_count, 1) if word_count else 0.0

    expected = [k for k, f in fields.items() if f["required"]]
    matched_required = [k for k in expected if fields[k]["matched"]]
    missing_required = [k for k in expected if not fields[k]["matched"]]
    all_matched = [k for k, f in fields.items() if f["matched"]]

    text_validation_score = (
        round(len(matched_required) / len(expected), 3) if expected else 0.0
    )
    upper = text.upper()
    keywords_found = [kw for kw in keywords if kw in upper]

    flags = []
    if word_count < cfg["min_words_for_text"]:
        flags.append("insufficient_text")
    if word_count and mean_conf < cfg["low_conf_floor"]:
        flags.append("low_ocr_confidence")
    if missing_required:
        flags.append("missing_required_fields:" + ",".join(missing_required))

    return {
        "word_count": word_count,
        "char_count": len(text),
        "mean_confidence": mean_conf,
        "low_confidence_words": len(low),
        "low_confidence_ratio": round(len(low) / word_count, 3) if word_count else 0.0,
        "fields_total": len(fields),
        "fields_matched": len(all_matched),
        "required_total": len(expected),
        "required_matched": len(matched_required),
        "missing_required": missing_required,
        "template_keywords_found": keywords_found,
        "template_keywords_total": len(keywords),
        "text_validation_score": text_validation_score,
        "passes_text_validation": text_validation_score >= cfg["text_validation_threshold"],
        "flags": flags,
    }


# ---------------------------------------------------------------------------
# Reporting
# ---------------------------------------------------------------------------
def _render_table(headers: list[str], rows: list[list[str]], caps: list[int]) -> str:
    """Render a plain-ASCII table. `caps` caps each column's width; longer cell
    text wraps onto extra lines within the same row."""
    import textwrap

    cols = range(len(headers))
    wrapped = [[textwrap.wrap(str(cell), caps[i]) or [""] for i, cell in enumerate(row)]
               for row in rows]
    widths = [len(headers[i]) for i in cols]
    for cells in wrapped:
        for i in cols:
            widths[i] = min(caps[i], max([widths[i]] + [len(ln) for ln in cells[i]]))

    border = "+" + "+".join("-" * (widths[i] + 2) for i in cols) + "+"

    def line(cells: list[str]) -> str:
        return "| " + " | ".join(cells[i].ljust(widths[i]) for i in cols) + " |"

    out = [border, line(headers), border]
    for cells in wrapped:
        height = max(len(c) for c in cells)
        for r in range(height):
            out.append(line([cells[i][r] if r < len(cells[i]) else "" for i in cols]))
    out.append(border)
    return "\n".join(out)


def print_report(result: dict) -> None:
    section("Structured fields (BIR Form 2303 template)")
    rows = []
    for f in result["structured_fields"].values():
        name = ("* " if f["required"] else "  ") + f["name"]
        value = f["value"] if f["value"] else "-"
        if f["value"] and f.get("source") == "stamp_roi":
            value += "  (stamp)"  # recovered by the secondary seal pass, not the page
        conf = f"{f['confidence']}%" if f["confidence"] is not None else "-"
        if not f["matched"]:
            status = "MISSING"
        elif f.get("source") == "stamp_roi":
            status = "REVIEW"  # came from the degraded seal pass - officer must verify
        else:
            status = "ok"
        rows.append([name, value, conf, status])
    print(_render_table(["Field", "Extracted value", "Conf", "Status"], rows,
                        caps=[30, 50, 7, 8]))
    print("  (* = required field   (stamp)/REVIEW = recovered from the secondary seal pass, verify it)")

    q = result["quality"]
    section("OCR quality metrics")
    metrics = [
        ["words recognised", str(q["word_count"])],
        ["mean word confidence", f"{q['mean_confidence']}%"],
        ["low-confidence words",
         f"{q['low_confidence_words']} ({q['low_confidence_ratio'] * 100:.0f}% "
         f"below {CONFIG['low_conf_floor']}%)"],
        ["template keywords found",
         f"{len(q['template_keywords_found'])}/{q['template_keywords_total']}"],
        ["fields matched",
         f"{q['fields_matched']}/{q['fields_total']} "
         f"(required {q['required_matched']}/{q['required_total']})"],
        ["text-validation score",
         f"{q['text_validation_score'] * 100:.1f}%  "
         f"({'PASS' if q['passes_text_validation'] else 'FAIL'} "
         f"@ {CONFIG['text_validation_threshold'] * 100:.0f}%)"],
        ["flags", ", ".join(q["flags"]) if q["flags"] else "none"],
    ]
    print(_render_table(["Metric", "Value"], metrics, caps=[24, 60]))

    sr = result.get("stamp_recovery")
    if sr:
        section("Stamp recovery (secondary ROI pass)")
        print(f"  preprocessing : {sr['variant']}")
        print("  Fills REGION/DISTRICT only when the page pass missed them; values are")
        print("  tagged (stamp)/REVIEW for officer verification - the seal is degraded,")
        print("  so a high OCR confidence still does NOT mean the digits are correct.")
        print("  recovered seal text:")
        print(sr["raw_text"] or "(none)")

    section("Extracted text (OCR)")
    print(result.get("readable_text") or result.get("raw_text", "") or "(none)")


# ---------------------------------------------------------------------------
# Orchestration
# ---------------------------------------------------------------------------
def run_full(input_path: Path, cfg: dict, deps: dict, args) -> int:
    if not deps["pytesseract"] or not deps["tesseract_engine"]:
        log("ERROR: OCR engine not available - cannot run the full pass.")
        report_dependencies(deps)
        return 3
    tesseract_cmd = deps["tesseract_engine"]

    pages = load_images(input_path, cfg)
    log(f"loaded {len(pages)} page(s) from {input_path.name}")

    all_text, all_confs, token_conf = [], [], {}
    first_words: list[dict] = []
    for idx, page in enumerate(pages, 1):
        pre = preprocess(page, cfg)
        if args.save_preprocessed and idx == 1:
            import cv2
            cv2.imwrite(args.save_preprocessed, pre)
            log(f"saved preprocessed page 1 -> {args.save_preprocessed}")
        ocr = run_ocr(pre, cfg, tesseract_cmd)
        if idx == 1:  # template fields all live on page 1; use its boxes for layout
            first_words = ocr["words"]
        all_text.append(clean_text(ocr["raw_text"]))
        all_confs.extend(ocr["confidences"])
        for tok, cs in ocr["token_conf"].items():
            token_conf.setdefault(tok, []).extend(cs)

    text = "\n".join(all_text)
    ocr_agg = {"confidences": all_confs, "token_conf": token_conf}
    fields = extract_fields(text, token_conf)
    fields = refine_fields_with_positions(fields, first_words, token_conf)
    # Secondary seal pass: the benchmarked winner recovers REGION/DISTRICT the
    # full-page pass buries. Merge fills only what the page pass missed, before
    # scoring, so a recovered value counts toward the field/required totals.
    stamp = recover_stamp_fields(pages[0], cfg, tesseract_cmd)
    fields = merge_stamp_fields(fields, stamp["fields"])
    quality = score_quality(text, fields, ocr_agg, cfg)

    result = {
        "input": str(input_path),
        "pages": len(pages),
        "document_template": "bir_certificate_of_registration_2303",
        "ocr_engine": {"tool": "tesseract", "cmd": tesseract_cmd,
                       "psm": cfg["tesseract_psm"], "lang": cfg["tesseract_lang"]},
        "structured_fields": fields,
        "quality": quality,
        "raw_text": text,
        "readable_text": tidy_text(text),  # light NLP tidy for the displayed dump
        "stamp_recovery": {"variant": stamp["variant"], "raw_text": stamp["raw_text"]},
    }

    print_report(result)
    if args.output:
        Path(args.output).write_text(json.dumps(result, indent=2), encoding="utf-8")
        log(f"wrote JSON result -> {args.output}")
    return 0


def run_dry(input_path: Path, cfg: dict, deps: dict, args) -> int:
    """Validate input + Stage 1 preprocessing without needing Tesseract."""
    report_dependencies(deps)

    if not deps["cv2"]:
        log("STRUCTURE ERROR: OpenCV (cv2) not importable - cannot preprocess.")
        return 2

    section("Stage 1 preprocessing (dry-run)")
    try:
        pages = load_images(input_path, cfg)
    except OcrError as exc:
        log(f"STRUCTURE ERROR: {exc}")
        return 2
    pre = preprocess(pages[0], cfg)
    h, w = pre.shape[:2]
    log(f"page 1 preprocessed OK -> {w}x{h} binarized image "
        f"(Otsu auto-threshold, {cfg['upscale_factor']}x upscale)")
    if args.save_preprocessed:
        import cv2
        cv2.imwrite(args.save_preprocessed, pre)
        log(f"saved preprocessed page 1 -> {args.save_preprocessed}")

    section("DRY RUN - input + preprocessing valid")
    log("Plumbing is sound. Install Tesseract + pytesseract, then re-run "
        "without --dry-run for the full OCR extraction.")
    return 0


# ---------------------------------------------------------------------------
# Stamp-targeted preprocessing (experimental): recover the REVENUE REGION /
# DISTRICT numbers printed inside the colored BIR seal at the top of the form.
#
# The seal's numbers are darker red ink over a lighter orange guilloche whose
# pattern is itself repeated micro-text ("BUREAU OF INTERNAL REVENUE"). The
# full-page Stage 1 cut buries them because Otsu thresholds the whole page, not
# the seal. Several levers are offered as montage candidates, SCORED side by side:
#   1) crop to the seal ROI so Otsu's threshold adapts to the seal histogram;
#   2) split a single colour channel where the ink/pattern diverge most;
#   3) CLAHE (+ denoise) to lift faint ink before thresholding;
#   4) an FFT notch filter to subtract the periodic guilloche in the frequency domain.
# Empirically on the bundled bir1.jpg, (1) grayscale ROI + Otsu wins (recovers the
# captions + the district digit); the colour split is worse; (3) CLAHE BACKFIRES -
# it amplifies the guilloche micro-text into readable noise that drowns the digits;
# and (4) the notch clears the periodic pattern (the DISTRICT caption comes back
# clean) but rings and loses the digit. See BENCHMARK_RESULTS below for the scored
# outcomes. The ceiling here is the micro-text overlapping the strokes at this scan
# DPI, not the threshold choice - so measure, don't assume. These feed the
# --compare montage only; they do NOT change the production preprocess() path.
# ---------------------------------------------------------------------------
_CHANNEL_INDEX = {"blue": 0, "green": 1, "red": 2}


def _crop_fractional_roi(img, roi: tuple):
    """Sub-image for an ROI given as (y0, y1, x0, x1) fractions of (H, W)."""
    h, w = img.shape[:2]
    y0, y1, x0, x1 = roi
    return img[int(y0 * h):int(y1 * h), int(x0 * w):int(x1 * w)]


def _otsu_2x(single_channel, cfg: dict, invert: bool = False):
    """Upscale a single-channel image and Otsu-threshold it to black-on-white."""
    import cv2

    scale = cfg.get("upscale_factor", 2)
    if scale and scale != 1:
        single_channel = cv2.resize(single_channel, None, fx=scale, fy=scale,
                                    interpolation=cv2.INTER_CUBIC)
    mode = cv2.THRESH_BINARY_INV if invert else cv2.THRESH_BINARY
    _, binary = cv2.threshold(single_channel, 0, 255, mode + cv2.THRESH_OTSU)
    return binary


def _adaptive_2x(single_channel, cfg: dict):
    """Upscale a single-channel image and adaptive-gaussian threshold it. Handles
    an uneven guilloche background better than a single global Otsu cut."""
    import cv2

    scale = cfg.get("upscale_factor", 2)
    if scale and scale != 1:
        single_channel = cv2.resize(single_channel, None, fx=scale, fy=scale,
                                    interpolation=cv2.INTER_CUBIC)
    return cv2.adaptiveThreshold(
        single_channel, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 31, 15
    )


def _clahe(gray, clip: float = 2.0, tile: int = 8):
    """Contrast-limited adaptive histogram equalization: lifts faint local ink
    (e.g. the seal digits) out of a low-contrast background before thresholding."""
    import cv2

    return cv2.createCLAHE(clipLimit=clip, tileGridSize=(tile, tile)).apply(gray)


def notch_filter_periodic(gray, cfg: dict):
    """Remove a PERIODIC background (the guilloche) via an FFT notch filter.

    A repeating pattern concentrates its energy in a few bright peaks of the
    magnitude spectrum, away from the DC centre. Zeroing a small disc around each
    of the brightest peaks (outside a protected low-frequency core that holds the
    aperiodic digit strokes) subtracts the pattern; the inverse transform returns
    a de-patterned grayscale image, renormalised to 0-255.
    """
    import cv2
    import numpy as np

    h, w = gray.shape[:2]
    spectrum = np.fft.fftshift(np.fft.fft2(gray.astype(np.float32)))
    cy, cx = h // 2, w // 2
    yy, xx = np.ogrid[:h, :w]
    dist2 = (yy - cy) ** 2 + (xx - cx) ** 2

    base = float(min(h, w))
    protect = (cfg.get("notch_protect_frac", 0.06) * base) ** 2
    notch_r2 = (cfg.get("notch_radius_frac", 0.03) * base) ** 2

    search = np.abs(spectrum).copy()
    search[dist2 <= protect] = 0.0  # never notch the protected low-frequency core
    for _ in range(int(cfg.get("notch_peaks", 14))):
        if search.max() <= 0:
            break
        py, px = divmod(int(np.argmax(search)), w)
        peak2 = (yy - py) ** 2 + (xx - px) ** 2
        spectrum[peak2 <= notch_r2] = 0.0       # subtract this periodic component
        search[peak2 <= notch_r2 * 4] = 0.0     # and don't re-pick its neighbourhood

    back = np.fft.ifft2(np.fft.ifftshift(spectrum)).real
    return cv2.normalize(back, None, 0, 255, cv2.NORM_MINMAX).astype(np.uint8)


def isolate_stamp_ink(bgr, cfg: dict):
    """Isolate the colored stamp ink via single-channel + Otsu (black on white).

    Splits one BGR channel (default green) instead of channel-averaging, so red
    ink and an orange guilloche that share a luminance can still separate when
    they diverge in that channel. Whether it beats grayscale is image-dependent -
    it is one scored candidate in the --compare montage, not a guaranteed win.
    """
    idx = _CHANNEL_INDEX.get(cfg.get("stamp_channel", "green"), 1)
    return _otsu_2x(bgr[:, :, idx], cfg)


def stamp_variants(img, cfg: dict) -> list[tuple[str, object]]:
    """[(name, image)] of stamp-recovery preprocessings over the top-seal ROI."""
    import cv2

    roi = _crop_fractional_roi(img, cfg["stamp_roi"])
    gray = cv2.cvtColor(roi, cv2.COLOR_BGR2GRAY)
    channel = cfg.get("stamp_channel", "green")
    chan = roi[:, :, _CHANNEL_INDEX.get(channel, 1)]
    clip, tile = cfg.get("stamp_clahe_clip", 2.0), cfg.get("stamp_clahe_tile", 8)
    clahe = _clahe(gray, clip, tile)
    denoise_clahe = _clahe(cv2.fastNlMeansDenoising(gray, None, 10, 7, 21), clip, tile)
    notched = notch_filter_periodic(gray, cfg)
    return [
        ("STAMP ROI: original (color)", roi),
        ("STAMP ROI: grayscale + Otsu + 2x", _otsu_2x(gray, cfg)),
        (f"STAMP ROI: {channel} channel + Otsu + 2x", _otsu_2x(chan, cfg)),
        (f"STAMP ROI: {channel} channel + adaptive + 2x", _adaptive_2x(chan, cfg)),
        ("STAMP ROI: CLAHE + Otsu + 2x", _otsu_2x(clahe, cfg)),
        ("STAMP ROI: denoise + CLAHE + Otsu + 2x", _otsu_2x(denoise_clahe, cfg)),
        ("STAMP ROI: FFT notch + Otsu + 2x", _otsu_2x(notched, cfg)),
        ("STAMP ROI: FFT notch + CLAHE + Otsu + 2x", _otsu_2x(_clahe(notched, clip, tile), cfg)),
    ]


def ocr_plain_text(image, cfg: dict, tesseract_cmd: str | None) -> str:
    """One-line OCR transcription of a tile (whitespace-collapsed) - for directly
    eyeballing whether the stamp digits were recovered. Empty-ish if no engine."""
    if not tesseract_cmd:
        return "(engine not installed)"
    import pytesseract

    pytesseract.pytesseract.tesseract_cmd = tesseract_cmd
    config = f"--oem {cfg['tesseract_oem']} --psm {cfg['tesseract_psm']} -l {cfg['tesseract_lang']}"
    raw = pytesseract.image_to_string(image, config=config)
    return re.sub(r"\s+", " ", raw).strip() or "(no text)"


def _normalize_stamp_text(text: str) -> str:
    """Rejoin caption tokens the seal OCR splits with a stray hyphen/dot so the
    label scan can find them: 'REVENUE-REGION' / 'REVENUE.DISTRICT' -> spaced."""
    return re.sub(r"(?<=[A-Za-z])[-.](?=[A-Za-z])", " ", text)


def recover_stamp_fields(page_bgr, cfg: dict, tesseract_cmd: str) -> dict:
    """Secondary OCR pass over the top-seal ROI with the benchmarked winner
    (grayscale ROI + Otsu + 2x, see BENCHMARK_RESULTS) to recover the REVENUE
    REGION/DISTRICT captions the full-page pass buries under the guilloche.

    Returns the recovered (normalised) text plus the structured fields parsed from
    it. The seal is degraded, so treat its values as review hints to verify against
    the document, not ground truth (Tesseract can read a wrong digit confidently) -
    merge_stamp_fields only uses them to fill what the primary pass missed.
    """
    import cv2

    roi = _crop_fractional_roi(page_bgr, cfg["stamp_roi"])
    pre = _otsu_2x(cv2.cvtColor(roi, cv2.COLOR_BGR2GRAY), cfg)
    ocr = run_ocr(pre, cfg, tesseract_cmd)
    text = _normalize_stamp_text(clean_text(ocr["raw_text"]))
    return {
        "variant": "STAMP ROI: grayscale + Otsu + 2x",
        "raw_text": text,
        "fields": extract_fields(text, ocr["token_conf"]),
    }


def merge_stamp_fields(fields: dict, stamp_fields: dict,
                       keys: tuple = ("revenue_region_no", "rdo_code")) -> dict:
    """Fill region/district from the stamp pass ONLY where the primary pass came
    up empty; never overwrite a value the primary pass already matched. Filled
    fields are tagged ``source="stamp_roi"`` so the report can flag them."""
    for key in keys:
        primary, stamp = fields.get(key), stamp_fields.get(key)
        if primary and stamp and not primary["matched"] and stamp["matched"]:
            fields[key] = {**stamp, "source": "stamp_roi"}
    return fields


# ---------------------------------------------------------------------------
# Best results observed on the bundled sample (bir1.jpg), recorded so the next
# reader does not have to re-run the whole montage to learn what works. These are
# OCR transcriptions under each preprocessing path; re-measure with --compare
# after any change. The stamp winner is surfaced at the top of the --compare
# stamp section and is the basis for the §9 stamp-recovery recommendation.
# ---------------------------------------------------------------------------
BENCHMARK_RESULTS: dict = {
    "full_page_best": {
        "variant": "OTSU + 2x upscale (current Stage-1 path)",
        "ocr_conf": 76.9, "template_keywords": "4/6",
        "note": "best whole-page text recovery; the production preprocess() path.",
    },
    "stamp_recovery_best": {
        "variant": "STAMP ROI: grayscale + Otsu + 2x",
        "ocr_conf": 65.6, "template_keywords": "1/6",
        "ocr": "REVENUE-REGION NO, 61 REVENUE DISTRICT no.",
        "note": "WINNER for the seal - recovers both captions + the district digit.",
    },
    "stamp_recovery_runner_up": {
        "variant": "STAMP ROI: FFT notch + Otsu + 2x",
        "ocr_conf": 25.9, "template_keywords": "1/6",
        "ocr": "REVENUE DISTRICT NO. (caption clean; region garbled, digit lost)",
        "note": "notch removes the periodic guilloche but rings; loses the digit.",
    },
    "rejected": {
        "STAMP ROI: green channel": "worse - ink/guilloche do not separate in green here.",
        "STAMP ROI: CLAHE / denoise+CLAHE": "BACKFIRES - amplifies the guilloche micro-text.",
    },
    "ceiling": (
        "The guilloche security micro-printing overlaps the digit strokes at this "
        "scan DPI, so 09B / 061 are not cleanly separable in software. Next real "
        "lever: a higher-DPI rescan of the seal, or officer fill-in via the "
        "fail-forward review path (the field already flags MISSING, not fabricated)."
    ),
}


# ---------------------------------------------------------------------------
# Visual comparison: original vs. each preprocessing strategy, annotated with
# the OCR score it yields. Lets you SEE why one binarization beats another.
# ---------------------------------------------------------------------------
def preprocess_variants(img, cfg: dict) -> list[tuple[str, object]]:
    """Return [(name, image)] for the original + each preprocessing strategy."""
    import cv2
    import numpy as np

    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

    _, t = cv2.threshold(gray, cfg["binarization_threshold"], 255, cv2.THRESH_BINARY_INV)
    spec = cv2.bitwise_not(cv2.morphologyEx(t, cv2.MORPH_OPEN, np.ones(cfg["morph_kernel"], np.uint8)))

    _, otsu = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)

    up = cv2.resize(gray, None, fx=2, fy=2, interpolation=cv2.INTER_CUBIC)
    _, otsu2x = cv2.threshold(up, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)

    adaptive = cv2.adaptiveThreshold(
        gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 31, 15
    )
    # Colour-split candidate: Otsu on a single channel (green) instead of a
    # channel-averaged grayscale - sometimes keeps red stamp/seal ink the average
    # buries. A scored option here; on bir1.jpg it does not beat OTSU+2x.
    green2x = isolate_stamp_ink(img, cfg)
    return [
        ("ORIGINAL (input)", img),
        (f"SPEC: thresh {cfg['binarization_threshold']} + morph-open (§9 default)", spec),
        ("GRAYSCALE only", gray),
        ("OTSU (auto threshold)", otsu),
        ("OTSU + 2x upscale (current pipeline)", otsu2x),
        ("ADAPTIVE gaussian", adaptive),
        (f"{cfg['stamp_channel'].upper()} channel + Otsu + 2x (color-split)", green2x),
    ]


def ocr_score(image, cfg: dict, tesseract_cmd: str | None) -> str:
    """One-line OCR quality summary for a single preprocessed image."""
    if not tesseract_cmd:
        return "OCR: engine not installed"
    import pytesseract
    from pytesseract import Output

    pytesseract.pytesseract.tesseract_cmd = tesseract_cmd
    config = f"--oem {cfg['tesseract_oem']} --psm {cfg['tesseract_psm']} -l {cfg['tesseract_lang']}"
    data = pytesseract.image_to_data(image, config=config, output_type=Output.DICT)
    confs = []
    for word, conf in zip(data["text"], data["conf"]):
        try:
            c = float(conf)
        except (TypeError, ValueError):
            continue
        if c >= 0 and word.strip():
            confs.append(c)
    mean = round(sum(confs) / len(confs), 1) if confs else 0.0
    text_upper = pytesseract.image_to_string(image, config=config).upper()
    kw = sum(k in text_upper for k in TEMPLATE_KEYWORDS)
    return f"OCR conf {mean}%   keywords {kw}/{len(TEMPLATE_KEYWORDS)}   words {len(confs)}"


def _label_tile(image, title: str, subtitle: str, width: int = 460):
    import cv2
    import numpy as np

    bgr = image if getattr(image, "ndim", 2) == 3 else cv2.cvtColor(image, cv2.COLOR_GRAY2BGR)
    h, w = bgr.shape[:2]
    bgr = cv2.resize(bgr, (width, int(h * width / w)), interpolation=cv2.INTER_AREA)
    header = np.full((54, width, 3), 255, np.uint8)
    cv2.putText(header, title, (8, 22), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (0, 0, 0), 1, cv2.LINE_AA)
    cv2.putText(header, subtitle, (8, 44), cv2.FONT_HERSHEY_SIMPLEX, 0.46, (150, 40, 40), 1, cv2.LINE_AA)
    tile = np.vstack([header, bgr])
    return cv2.copyMakeBorder(tile, 5, 5, 5, 5, cv2.BORDER_CONSTANT, value=(40, 40, 40))


def _montage(tiles, cols: int = 2):
    import cv2
    import numpy as np

    width = max(t.shape[1] for t in tiles)
    tiles = [
        t if t.shape[1] == width
        else cv2.copyMakeBorder(t, 0, 0, 0, width - t.shape[1], cv2.BORDER_CONSTANT, value=(255, 255, 255))
        for t in tiles
    ]
    rows = []
    for i in range(0, len(tiles), cols):
        row = tiles[i:i + cols]
        rh = max(t.shape[0] for t in row)
        row = [cv2.copyMakeBorder(t, 0, rh - t.shape[0], 0, 0, cv2.BORDER_CONSTANT, value=(255, 255, 255)) for t in row]
        while len(row) < cols:
            row.append(np.full((rh, width, 3), 255, np.uint8))
        rows.append(np.hstack(row))
    return np.vstack(rows)


def _try_open(path: str, enabled: bool) -> None:
    if not enabled:
        return
    try:
        os.startfile(path)  # Windows: open in the default image viewer
        log("opened the comparison image in your default viewer.")
    except (AttributeError, OSError):
        log("open the saved image manually to view the comparison.")


def run_compare(input_path: Path, cfg: dict, deps: dict, args) -> int:
    """Build + save (+ open) a labelled preprocessing comparison montage."""
    if not deps["cv2"]:
        log("STRUCTURE ERROR: OpenCV (cv2) not importable - cannot build comparison.")
        return 2
    import cv2

    try:
        pages = load_images(input_path, cfg)
    except OcrError as exc:
        log(f"STRUCTURE ERROR: {exc}")
        return 2

    tesseract_cmd = deps["tesseract_engine"]
    if not deps["pytesseract"] or not tesseract_cmd:
        log("note: OCR engine missing - building the visual comparison without scores.")

    engine = tesseract_cmd if deps["pytesseract"] else None

    section("Preprocessing comparison (page 1)")
    tiles = []
    for name, im in preprocess_variants(pages[0], cfg):
        sub = ocr_score(im, cfg, engine)
        print(f"  {name:52} {sub}")
        tiles.append(_label_tile(im, name, sub))

    # Stamp-targeted tiles: isolate the REVENUE REGION/DISTRICT numbers out of the
    # colored seal. Print the literal OCR transcription of each binarized tile so
    # you can see directly whether the region/district codes came back legibly.
    section("Stamp recovery (REVENUE REGION / DISTRICT) - page 1")
    best = BENCHMARK_RESULTS["stamp_recovery_best"]
    print(f"  recorded winner ({best['ocr_conf']}% conf): {best['variant']}")
    print(f'      best OCR seen -> "{best["ocr"]}"')
    print("  re-measuring all candidates live below:\n")
    for name, im in stamp_variants(pages[0], cfg):
        sub = ocr_score(im, cfg, engine)
        print(f"  {name:48} {sub}")
        tiles.append(_label_tile(im, name, sub))
        if engine and "color" not in name:  # the "original (color)" tile is not binarized
            print(f"      OCR -> {ocr_plain_text(im, cfg, engine)}")

    out = args.compare_out or str(input_path.with_name(input_path.stem + "_ocr_compare.png"))
    cv2.imwrite(out, _montage(tiles, cols=2))
    log(f"saved comparison image -> {out}")
    _try_open(out, args.open)
    return 0


def parse_args(argv: list[str]) -> argparse.Namespace:
    ap = argparse.ArgumentParser(
        description="ADVS OCR extraction quality dry-run for BIR forms (Form 2303)."
    )
    ap.add_argument("--input", default=DEFAULT_INPUT,
                    help="Path to a BIR form image (PNG/JPG) or PDF. "
                         "Defaults to DEFAULT_INPUT at the top of the file.")
    ap.add_argument("--output",
                    help="Optional path to write the JSON result.")
    ap.add_argument("--save-preprocessed",
                    help="Optional path to save the binarized page-1 PNG.")
    ap.add_argument("--tesseract-cmd",
                    help="Path to tesseract.exe (overrides auto-detect).")
    ap.add_argument("--dry-run", action="store_true",
                    help="Validate input + Stage 1 only; no Tesseract needed.")
    ap.add_argument("--compare", action=argparse.BooleanOptionalAction, default=DEFAULT_COMPARE,
                    help="Build a side-by-side preprocessing comparison image instead of the report.")
    ap.add_argument("--compare-out",
                    help="Where to save the comparison image (default: next to --input).")
    ap.add_argument("--open", action=argparse.BooleanOptionalAction, default=True,
                    help="Open the comparison image in the default viewer after saving.")
    return ap.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv if argv is not None else sys.argv[1:])
    _utf8_stdout()  # the extracted-text dump can contain non-cp1252 OCR glyphs
    cfg = dict(CONFIG)

    if not args.input:
        log("STRUCTURE ERROR: no input file. Set DEFAULT_INPUT at the top of "
            "this file to your BIR form path, or pass --input <path>.")
        return 2

    input_path = Path(args.input)
    log(f"input={input_path}  dry-run={args.dry_run}")
    if not input_path.is_file():
        log(f"STRUCTURE ERROR: input file not found: {input_path}")
        return 2

    deps = check_dependencies()
    deps["tesseract_engine"] = resolve_tesseract_cmd(args.tesseract_cmd)

    started = time.time()
    if args.compare:
        rc = run_compare(input_path, cfg, deps, args)
    elif args.dry_run:
        rc = run_dry(input_path, cfg, deps, args)
    else:
        rc = run_full(input_path, cfg, deps, args)
    section(f"Done in {time.time() - started:.1f}s")
    return rc


if __name__ == "__main__":
    raise SystemExit(main())
