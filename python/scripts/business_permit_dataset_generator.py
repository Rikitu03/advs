"""ADVS - synthetic City Business Permit generator (Digos / Makati / Manila layouts).

The business-permit analogue of ``bir_dataset_generator.py``. It fills a blank
City Business Permit template with OCR-realistic synthetic field data, composites
the layout's ink assets (City seal and/or officer signatures), then emits a CLEAN
image and an Augraphy-degraded SCAN image into the ResNet-50 classifier's
``business_permit`` class folder. A per-layout JSON ledger records every record's
content hash so reruns never duplicate data and filenames keep incrementing.

Bounding boxes are the calibrated pixel boxes exported by
``business_permit_annotator.py`` -> ``python/json_data/business_permit_boxes_<city>.json``
(IMAGE space of each template). Non-text regions (signature boxes + the Digos
City seal) are filled with image assets, not text; boxes the template already
prints (the Makati officer names + signatures) are annotated but never filled.

The heavy, field-agnostic machinery (text fitting, alpha keying, asset
compositing, Augraphy scan degradation, manifest atomic-write) is REUSED from
``bir_dataset_generator.py`` so the generators stay consistent and the tricky
code lives in one place. This module holds only the per-city config + field
values: the original Digos layout keeps its module-level API; Makati and Manila
are ``CityLayout`` entries in ``CITY_LAYOUTS`` selected with ``--city``.

Usage (always the venv interpreter):
    python/env/Scripts/python.exe python/scripts/business_permit_dataset_generator.py --dry-run
    python/env/Scripts/python.exe python/scripts/business_permit_dataset_generator.py                 # Digos: 100 base -> 200 files
    python/env/Scripts/python.exe python/scripts/business_permit_dataset_generator.py --city makati   # Makati: 100 base -> 200 files
    python/env/Scripts/python.exe python/scripts/business_permit_dataset_generator.py --city manila --count 50 --clean-only
"""
from __future__ import annotations

import argparse
import hashlib
import json
import random
import sys
from dataclasses import dataclass, field, replace
from datetime import datetime, timezone
from pathlib import Path
from typing import Callable

from PIL import Image, ImageDraw

# Reuse the field-agnostic helpers from the BIR generator (a sibling in
# python/scripts/). Add this script's own directory to sys.path first so the
# sibling import works even when this module is loaded by path (e.g. from a test).
SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

import bir_dataset_generator as bir  # noqa: E402

PY_ROOT = Path(__file__).resolve().parents[1]            # .../python
TEMPLATE_PATH = PY_ROOT / "data" / "template" / "business_permits" / "Business Permit (Digos).png"
LOGO_PATH = PY_ROOT / "logo" / "logo_digos.png"
SIGNATURE_PATH = PY_ROOT / "data" / "stamps" / "BIR_OFFICER_STAMP.png"  # blue-ink signature asset
FONT_DIR = PY_ROOT / "data" / "fonts"
BOXES_JSON = PY_ROOT / "json_data" / "business_permit_boxes_digos.json"
OUTPUT_DIR = PY_ROOT / "data" / "training" / "classifier_data" / "business_permit"

# The City seal sits at the template's top-left. The blank template already shows
# a faint printed seal; we composite the crisp logo squarely over it (box found by
# matching the printed seal's bounds, then enlarged a touch for full coverage).
LOGO_KEY = "city_logo"
LOGO_BOX = {"x": 116, "y": 101, "w": 316, "h": 334}
SIGNATURE_KEYS = ("city_treasurer_signature", "city_administrator_signature")

# Fallback boxes (the exported business_permit_boxes.json) so the module still
# works if the JSON is missing. The JSON, when present, overrides these.
DEFAULT_FIELD_BOXES: dict[str, dict[str, int]] = {
    "name_of_proprietor": {"x": 126, "y": 356, "w": 1351, "h": 48},
    "trade_name": {"x": 130, "y": 447, "w": 1348, "h": 33},
    "business_location": {"x": 137, "y": 527, "w": 1339, "h": 40},
    "kind_of_business": {"x": 137, "y": 606, "w": 1341, "h": 41},
    "day_issued": {"x": 419, "y": 730, "w": 174, "h": 33},
    "month_issued": {"x": 667, "y": 726, "w": 177, "h": 36},
    "city_treasurer_name": {"x": 197, "y": 922, "w": 298, "h": 34},
    "city_treasurer_signature": {"x": 209, "y": 862, "w": 275, "h": 108},
    "city_administrator_name": {"x": 735, "y": 916, "w": 318, "h": 41},
    "city_administrator_signature": {"x": 761, "y": 855, "w": 270, "h": 119},
}


def load_field_boxes(path: Path = BOXES_JSON) -> dict[str, dict[str, int]]:
    """The calibrated boxes from the annotator's JSON export, plus the City-seal
    region (which the annotator does not label). Falls back to the embedded
    defaults if the JSON is absent."""
    boxes = dict(DEFAULT_FIELD_BOXES)
    try:
        if Path(path).exists():
            loaded = json.loads(Path(path).read_text(encoding="utf-8"))
            if isinstance(loaded, dict) and loaded:
                boxes = {k: {"x": int(v["x"]), "y": int(v["y"]),
                             "w": int(v["w"]), "h": int(v["h"])}
                         for k, v in loaded.items()}
    except Exception as exc:  # noqa: BLE001 - never let a bad JSON kill import
        log(f"WARN: could not read {path} ({exc}); using embedded default boxes.")
    boxes[LOGO_KEY] = dict(LOGO_BOX)
    return boxes


def log(msg: str) -> None:
    """ASCII-only status line (Windows cp1252 console safe)."""
    print(f"[permit-gen] {msg}", flush=True)


FIELD_BOXES = load_field_boxes()

# Image regions filled with assets, not text: the two signatures (luminance-keyed
# from a blue-ink asset) and the City seal (alpha-composited from the PNG logo).
IMAGE_FIELD_ASSETS: dict[str, Path] = {
    SIGNATURE_KEYS[0]: SIGNATURE_PATH,
    SIGNATURE_KEYS[1]: SIGNATURE_PATH,
    LOGO_KEY: LOGO_PATH,
}
TEXT_FIELD_KEYS: list[str] = [k for k in FIELD_BOXES if k not in IMAGE_FIELD_ASSETS]

# The template is 1600x1203 (~2.3x the BIR form), so text needs a bigger face than
# the BIR generator's 12/11. fit_text shrinks toward MIN for the small day/month
# blanks; the wide name/trade/location boxes take the top size.
PREFERRED_FONT_SIZES = (32, 30, 28)
MIN_FONT_SIZE = 16
# The officer signatures are thin blue ink the scan washes out, so deepen them
# (mirrors the BIR signature enhance).
SIGNATURE_ENHANCE = {"contrast": 1.6, "saturation": 1.9, "gain": 10.0, "darken": 0.7}


# ---------------------------------------------------------------------------
# Synthetic field values (OCR-plausible Philippine business-permit content).
# ---------------------------------------------------------------------------
KIND_OF_BUSINESS_POOL = [
    "MANUFACTURING", "WHOLESALE AND RETAIL TRADE", "RETAIL TRADE",
    "FOOD SERVICE ACTIVITIES", "RESTAURANT", "CONSTRUCTION", "GENERAL MERCHANDISE",
    "TRANSPORT SERVICE", "GENERAL SERVICES", "AGRICULTURE", "REAL ESTATE LEASING",
    "FINANCIAL SERVICES", "TRADING", "AUTO REPAIR SERVICES",
]
MONTHS_FULL = ["JANUARY", "FEBRUARY", "MARCH", "APRIL", "MAY", "JUNE", "JULY",
               "AUGUST", "SEPTEMBER", "OCTOBER", "NOVEMBER", "DECEMBER"]


def ordinal(day: int) -> str:
    """1 -> '1st', 2 -> '2nd', 24 -> '24th' (matches the permit's 'Issued this __ day')."""
    if 11 <= (day % 100) <= 13:
        suffix = "th"
    else:
        suffix = {1: "st", 2: "nd", 3: "rd"}.get(day % 10, "th")
    return f"{day}{suffix}"


def generate_record(faker, rng: random.Random) -> dict[str, str]:
    """One synthetic business-permit record (text fields only; image regions excluded)."""
    is_company = rng.random() < 0.7
    proprietor = faker.name().upper()
    trade = (faker.company() if is_company else proprietor.title()).upper()
    issued = faker.date_between(start_date="-12y", end_date="-1y")
    return {
        "name_of_proprietor": proprietor,
        "trade_name": trade,
        "business_location": faker.address().replace("\n", ", ").upper(),
        "kind_of_business": rng.choice(KIND_OF_BUSINESS_POOL),
        "day_issued": ordinal(issued.day),
        "month_issued": MONTHS_FULL[issued.month - 1],
        "city_treasurer_name": faker.name().upper(),
        "city_administrator_name": faker.name().upper(),
    }


def record_hash(record: dict[str, str]) -> str:
    """Stable, order-independent SHA-1 of a record's fields (dedup key)."""
    blob = json.dumps(record, sort_keys=True, ensure_ascii=False)
    return hashlib.sha1(blob.encode("utf-8")).hexdigest()


# ---------------------------------------------------------------------------
# Duplication ledger: a per-folder manifest of every generated record so reruns
# never duplicate data and filenames keep incrementing. Business permits have no
# natural unique key (no TIN), so we dedup on the full content hash.
# ---------------------------------------------------------------------------
def load_manifest(path: Path) -> dict:
    p = Path(path)
    if p.exists():
        data = json.loads(p.read_text(encoding="utf-8"))
        data.setdefault("version", 1)
        data.setdefault("records", [])
        data.setdefault("used_hashes", [])
        data.setdefault("next_index", len(data["records"]) + 1)
        return data
    return {"version": 1, "next_index": 1, "records": [], "used_hashes": []}


def generate_unique_record(faker, rng: random.Random, manifest: dict,
                           max_tries: int = 1000,
                           record_fn: Callable | None = None) -> dict:
    """A record whose content hash is absent from the manifest. `record_fn`
    defaults to the Digos `generate_record`; city layouts pass their own."""
    make = record_fn or generate_record
    used_hashes = set(manifest["used_hashes"])
    for _ in range(max_tries):
        rec = make(faker, rng)
        if record_hash(rec) not in used_hashes:
            return rec
    raise RuntimeError(
        f"Could not generate a unique record in {max_tries} tries "
        "(manifest saturated or seed too constrained)."
    )


def register_record(manifest: dict, record: dict, files: list[str]) -> int:
    """Append a record to the manifest, return its assigned index, bump next_index."""
    idx = manifest["next_index"]
    h = record_hash(record)
    manifest["records"].append({
        "index": idx,
        "hash": h,
        "fields": record,
        "files": files,
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
    })
    manifest["used_hashes"].append(h)
    manifest["next_index"] = idx + 1
    return idx


# ---------------------------------------------------------------------------
# Rendering. Text is centered in its box (the permit's values sit centered above
# their printed labels), at a larger face than the BIR form. Image regions are
# composited on top: signatures via luminance keying, the City seal via its PNG
# alpha (cropped to content so it fills the seal box).
# ---------------------------------------------------------------------------
def draw_text_in_box(draw: ImageDraw.ImageDraw, text: str, box: dict,
                     font_dir: Path = FONT_DIR, rng: random.Random | None = None,
                     *, center: bool = True, sizes: tuple = PREFERRED_FONT_SIZES,
                     min_size: int = MIN_FONT_SIZE, align: str | None = None,
                     nowrap: bool = False) -> None:
    """Render `text` inside `box`, horizontally + vertically centered by default,
    shrinking the face to fit. Reuses the BIR generator's wrapping/fitting.

    `align` overrides the horizontal placement ("left" | "center" | "right");
    when unset it derives from `center`. `align="right"` keeps the vertical
    centering (used for currency columns whose boxes span past the label).
    `nowrap` forces a single line (dates on real permits never wrap): the face
    shrinks until the whole text fits the width instead of wrapping."""
    if not text:
        return
    pad = 4
    x, y, w, h = box["x"], box["y"], box["w"], box["h"]
    if nowrap:
        candidates = list(sizes) + list(range(min(sizes) - 1, min_size - 1, -1))
        size = next((s for s in candidates
                     if bir._text_size(draw, text, bir.load_font(s, font_dir=str(font_dir)))[0]
                     <= w - 2 * pad), min_size)
        lines = [text]
    else:
        size, lines = bir.fit_text(text, w - 2 * pad, h - 2 * pad, font_dir=font_dir,
                                   sizes=sizes, min_size=min_size)
    font = bir.load_font(size, font_dir=str(font_dir))
    line_h = bir._text_size(draw, "Ag", font)[1] + 2
    total_h = line_h * len(lines)
    jx, jy = (rng.randint(-1, 1), rng.randint(-1, 1)) if rng else (0, 0)
    align = align or ("center" if center else "left")
    ty = y + jy + (pad if align == "left" and not center else max(0, (h - total_h) // 2))
    for ln in lines:
        line_w = bir._text_size(draw, ln, font)[0]
        if align == "right":
            tx = x + jx + max(pad, w - line_w - pad)
        elif align == "center":
            tx = x + jx + (w - line_w) // 2
        else:
            tx = x + jx + pad
        draw.text((tx, ty), ln, fill=bir.TEXT_COLOR, font=font)
        ty += line_h


def paste_logo(base: Image.Image, logo_path: Path, box: dict,
               rng: random.Random | None = None) -> None:
    """Alpha-composite the City seal PNG into `box`. Unlike the blue-ink signature
    (RGB-on-white, luminance-keyed), the logo has a real alpha channel, so we crop
    it to its non-transparent content first (the source has padding) and use that
    alpha directly so it fills the seal box cleanly."""
    logo = Image.open(logo_path).convert("RGBA")
    content = logo.split()[-1].getbbox()
    if content:
        logo = logo.crop(content)
    x, y, w, h = box["x"], box["y"], box["w"], box["h"]
    scale = min(w / logo.width, h / logo.height)
    if rng:
        scale *= rng.uniform(0.97, 1.0)
    nw, nh = max(1, int(logo.width * scale)), max(1, int(logo.height * scale))
    logo = logo.resize((nw, nh), Image.LANCZOS)
    ox = x + (w - nw) // 2
    oy = y + (h - nh) // 2
    base.alpha_composite(logo, (ox, oy))


def render_permit(record: dict, *, template_path: Path = TEMPLATE_PATH,
                  assets: dict = IMAGE_FIELD_ASSETS, font_dir: Path = FONT_DIR,
                  rng: random.Random | None = None) -> Image.Image:
    """Render a single clean Business Permit image (RGB) from a field record."""
    base = Image.open(template_path).convert("RGBA")
    draw = ImageDraw.Draw(base)
    for key in TEXT_FIELD_KEYS:
        draw_text_in_box(draw, record.get(key, ""), FIELD_BOXES[key],
                         font_dir=font_dir, rng=rng)
    for key, asset_path in assets.items():
        if key == LOGO_KEY:
            paste_logo(base, asset_path, FIELD_BOXES[key], rng=rng)
        else:  # a signature region: luminance-key the blue-ink asset
            bir.paste_asset(base, asset_path, FIELD_BOXES[key], rng=rng, **SIGNATURE_ENHANCE)
    return base.convert("RGB")


# ---------------------------------------------------------------------------
# Multi-city layouts (Makati, Manila). Digos keeps the legacy module-level path
# above; every additional city is pure data - its template, its annotator-exported
# boxes JSON, which annotated boxes are PRE-PRINTED on the template (never
# filled), which boxes get an ink asset, text alignment, font sizing, and a
# record factory producing values for exactly the layout's text boxes.
# ---------------------------------------------------------------------------
@dataclass
class CityLayout:
    """Everything city-specific the generic render/batch machinery needs."""
    name: str
    template_path: Path
    boxes_json: Path
    make_record: Callable[..., dict]
    image_assets: dict = field(default_factory=dict)          # key -> asset path
    asset_enhance: dict = field(default_factory=dict)         # key -> paste_asset kwargs
    preprinted_keys: frozenset = frozenset()                  # printed on the template
    left_align_keys: frozenset = frozenset()                  # top-left instead of centered
    right_align_keys: frozenset = frozenset()                 # currency columns
    nowrap_keys: frozenset = frozenset()                      # single-line (dates etc.)
    font_sizes: tuple = PREFERRED_FONT_SIZES
    min_font_size: int = MIN_FONT_SIZE
    stem_prefix: str = "synthetic_permit"
    manifest_name: str = "_synthetic_manifest.json"

    def load_boxes(self) -> dict:
        """The annotator's exported pixel boxes. Unlike the Digos layout there is
        no embedded fallback - the JSON is the single source of truth."""
        raw = json.loads(Path(self.boxes_json).read_text(encoding="utf-8"))
        return {k: {"x": int(v["x"]), "y": int(v["y"]),
                    "w": int(v["w"]), "h": int(v["h"])}
                for k, v in raw.items()}

    def text_keys(self, boxes: dict) -> list:
        return [k for k in boxes
                if k not in self.image_assets and k not in self.preprinted_keys]


QUARTERS = ("1ST", "2ND", "3RD", "4TH")
MAKATI_BARANGAYS = ("BEL-AIR", "POBLACION", "SAN LORENZO", "URDANETA", "BANGKAL",
                    "PIO DEL PILAR", "MAGALLANES", "OLYMPIA", "GUADALUPE NUEVO",
                    "SAN ANTONIO")
MANILA_DISTRICTS = ("TONDO", "BINONDO", "QUIAPO", "SAMPALOC", "ERMITA", "MALATE",
                    "STA. CRUZ", "PACO", "PANDACAN", "SAN MIGUEL")
NATIONALITY_POOL = ("FILIPINO",) * 8 + ("CHINESE", "AMERICAN")
MANILA_REMARKS_POOL = ("NEW", "RENEWAL", "RENEWAL - PAID IN FULL", "NEW BUSINESS",
                       "SUBJECT TO POST INSPECTION", "RENEWAL - 1ST QUARTER")

# Plausible Makati fee ranges in pesos (lo, hi). Meat inspection applies only to
# food businesses and the FSI (Fire Safety Inspection) fee is derived - 10% of
# the regulatory subtotal, per the Fire Code - so both live outside this table.
MAKATI_FEE_RANGES = {
    "mayor_permit_fee": (500, 5000),
    "business_tax": (1500, 60000),
    "sanitary_permit_fee": (100, 1200),
    "garbage_fee": (500, 3600),
    "signboard_fee": (100, 1500),
    "engineering_fee": (150, 2400),
    "individual_mp_fee": (100, 1000),
    "individual_hc_fee": (60, 600),
    "barangay_clearance_fee": (300, 1000),
}
FOOD_BUSINESS_KINDS = ("FOOD SERVICE ACTIVITIES", "RESTAURANT")


def peso(value: float) -> str:
    """1234.5 -> '1,234.50' (the comma-grouped format the fee tables print)."""
    return f"{value:,.2f}"


def generate_makati_record(faker, rng: random.Random) -> dict:
    """One synthetic Makati permit record: the certificate lines plus the
    lower-left tax table (11 fees + their computed TOTAL). The OIC / Mayor names
    and signatures are pre-printed on the template, so they are not generated."""
    issued = faker.date_between(start_date="-3y", end_date="-1m")
    year = issued.year
    kind = rng.choice(KIND_OF_BUSINESS_POOL)
    fees = {key: round(rng.uniform(lo, hi), 2)
            for key, (lo, hi) in MAKATI_FEE_RANGES.items()}
    fees["meat_inspection_fee"] = (round(rng.uniform(200, 2400), 2)
                                   if kind in FOOD_BUSINESS_KINDS else 0.0)
    fees["fsi_fee"] = round(0.10 * sum(fees.values()), 2)
    is_company = rng.random() < 0.7
    record = {
        "business_name": faker.company().upper() if is_company else faker.name().upper(),
        "address": f"{faker.street_address()}, BRGY. {rng.choice(MAKATI_BARANGAYS)}, "
                   "MAKATI CITY".upper(),
        "permissions_granted": kind,
        "issued_day": ordinal(issued.day),
        "issued_month_year": f"{MONTHS_FULL[issued.month - 1]} {year}",
        "expiry_date": f"DECEMBER 31, {year}",
        "tax_year": str(year),
        "quarter": rng.choice(QUARTERS),
        "or_number": str(rng.randint(1_000_000, 9_999_999)),
        "or_date": issued.strftime("%m/%d/%Y"),
        "total": peso(sum(fees.values())),
    }
    record.update({key: peso(value) for key, value in fees.items()})
    return record


def generate_manila_record(faker, rng: random.Random) -> dict:
    """One synthetic Manila permit record. `remakrs` (sic) matches the annotator
    export's key spelling; the secretary's signature is an image region."""
    issued = faker.date_between(start_date="-3y", end_date="-1m")
    business_tax = round(rng.uniform(1500, 80000), 2)
    fixed_fee = round(rng.uniform(500, 6000), 2)
    total = round(business_tax + fixed_fee, 2)
    kinds = rng.sample(KIND_OF_BUSINESS_POOL, k=rng.randint(1, 3))
    return {
        "name": faker.name().upper(),
        "business_name": faker.company().upper(),
        "address": f"{faker.street_address()}, {rng.choice(MANILA_DISTRICTS)}, MANILA".upper(),
        "telephone_nos": f"{rng.randint(8100, 8999)}-{rng.randint(1000, 9999)}",
        "no_of_employees": str(rng.randint(1, 250)),
        "nationality": rng.choice(NATIONALITY_POOL),
        "kind_of_business": " / ".join(kinds),
        "business_tax": peso(business_tax),
        "fixed_fee": peso(fixed_fee),
        "total_fee": peso(total),
        "remakrs": rng.choice(MANILA_REMARKS_POOL),
        "processed_by": faker.name().upper(),
        "or_no": str(rng.randint(1_000_000, 9_999_999)),
        "date": issued.strftime("%m/%d/%Y"),
        "amount_paid": peso(total),
        "bp_code": f"{issued.year}-{rng.randint(10_000, 99_999)}",
    }


# Marikina/Taguig barangay + form-value pools, modelled on the filled reference
# permits in python/data/template/reference/{marikina,taguig}.png.
MARIKINA_BARANGAYS = ("MALANDAY", "CONCEPCION UNO", "CONCEPCION DOS", "BARANGKA",
                      "CALUMPANG", "STA. ELENA", "SAN ROQUE", "STO. NINO", "PARANG",
                      "MARIKINA HEIGHTS", "FORTUNE", "TUMANA", "NANGKA",
                      "INDUSTRIAL VALLEY")
TAGUIG_BARANGAYS = ("FORT BONIFACIO", "USUSAN", "TUKTUKAN", "BAGUMBAYAN",
                    "SANTA ANA", "WAWA", "LOWER BICUTAN", "UPPER BICUTAN",
                    "WESTERN BICUTAN", "SIGNAL VILLAGE", "PINAGSAMA", "NAPINDAN")
OWNERSHIP_TYPE_POOL = ("SOLE PROPRIETORSHIP", "CORPORATION", "PARTNERSHIP",
                       "ONE PERSON CORPORATION", "COOPERATIVE")
PERMIT_STATUS_POOL = ("RENEWAL", "RENEWAL", "RENEWAL", "NEW")

# The forms carry a printed year (Marikina "2026", Taguig "2025" banner), so the
# synthetic dates are pinned to each form's year instead of faker's date ranges.
MARIKINA_FORM_YEAR = 2026
TAGUIG_FORM_YEAR = 2025

MARIKINA_FEE_RANGES = {
    "business_tax": (1000, 40000),
    "mp_fee": (500, 6000),
    "sanitary_inspection_fee": (100, 800),
    "sanitary_permit_fee": (150, 900),
    "garbage_fee": (500, 3000),
    "engineering_inspection_fee": (200, 1500),
    "fire_inspection_fee": (300, 2500),
    "plate_sticker_fee": (100, 600),
    "barangay_clearance_fee": (300, 1200),
    "community_tax_certificate": (100, 2500),
}
# Taguig's FIRE CODE RA 9514 line is derived (10% of the subtotal, like Makati's
# FSI fee), so it lives outside this table.
TAGUIG_FEE_RANGES = {
    "contractors_management": (2000, 80000),
    "mayors_fee": (500, 8000),
    "sanitary_inspection_fee": (100, 800),
    "building_inspection_fee": (100, 800),
    "electrical_inspection_fee": (100, 800),
    "plumbing_inspection_fee": (100, 600),
    "medical_fee": (100, 600),
    "fire_permit_fee": (300, 3000),
    "business_plate": (100, 500),
    "form_fee": (50, 200),
    "signboard_fee": (100, 1500),
    "barangay_fee": (300, 1200),
}


def _issue_date(rng: random.Random, year: int):
    """A permit-issue date inside the form's printed year: mostly January (the
    renewal season, cf. both reference permits), sometimes up to June."""
    from datetime import date
    month = 1 if rng.random() < 0.7 else rng.randint(2, 6)
    return date(year, month, rng.randint(2, 28))


def _long_date(d) -> str:
    """date(2026, 1, 8) -> 'January 8, 2026' (the Marikina reference format)."""
    return f"{MONTHS_FULL[d.month - 1].title()} {d.day}, {d.year}"


def generate_marikina_record(faker, rng: random.Random) -> dict:
    """One synthetic Marikina Billing and Permit record, following the filled
    reference: trade name + nature in the first panel, 'LASTNAME, FIRSTNAME' +
    account number in the second, AMOUNT-column fees with a computed TOTAL, and
    typed officer names (only the BPLO chief gets a signature)."""
    issued = _issue_date(rng, MARIKINA_FORM_YEAR)
    started = issued.replace(year=issued.year - rng.randint(0, 8))
    fees = {key: round(rng.uniform(lo, hi), 2)
            for key, (lo, hi) in MARIKINA_FEE_RANGES.items()}
    return {
        "permit_no": f"{MARIKINA_FORM_YEAR}-{rng.randint(10_000, 99_999)}",
        "date_issued": _long_date(issued),
        "business_name": faker.company().upper(),
        "nature_of_business": rng.choice(KIND_OF_BUSINESS_POOL).title(),
        "business_owner_name": f"{faker.last_name()}, {faker.first_name()}".upper(),
        "business_account_number": str(rng.randint(10_000_000, 99_999_999)),
        "business_address": f"{faker.street_address()}, "
                            f"BRGY. {rng.choice(MARIKINA_BARANGAYS)}".upper(),
        "valid_until": f"DECEMBER 31, {MARIKINA_FORM_YEAR}",
        "date_started": _long_date(started),
        "plate_number": str(rng.randint(10_000, 99_999)),
        "no_of_personnel": str(rng.randint(1, 100)),
        "business_area": f"{rng.uniform(3, 200):.2f}",
        "deadline_of_renewal": f"January 20, {MARIKINA_FORM_YEAR + 1}",
        "total": peso(sum(fees.values())),
        "encoded_by": faker.name().upper(),
        "received_by": faker.name().upper(),
        "received_date": f"{MONTHS_FULL[issued.month - 1][:3].title()} {issued.day}, {issued.year}",
        "city_mayor_name": faker.name().upper(),
        "acting_chief": faker.name().upper(),
        **{key: peso(value) for key, value in fees.items()},
    }


def generate_taguig_record(faker, rng: random.Random) -> dict:
    """One synthetic Taguig Business Permit record, following the filled
    reference: values centered on their ruled lines, MM/DD/YYYY dates pinned to
    the form's 2025 banner year, and the 13-row tax table with the FIRE CODE
    line at 10% of the subtotal."""
    issued = _issue_date(rng, TAGUIG_FORM_YEAR)
    entity = faker.company().upper()
    fees = {key: round(rng.uniform(lo, hi), 2)
            for key, (lo, hi) in TAGUIG_FEE_RANGES.items()}
    fees["fire_code"] = round(0.10 * sum(fees.values()), 2)
    return {
        "name_of_entity": entity,
        "trade_name": entity if rng.random() < 0.6 else faker.company().upper(),
        "business_location": f"{faker.street_address()}, "
                             f"BRGY. {rng.choice(TAGUIG_BARANGAYS)}, TAGUIG CITY".upper(),
        "business_owner": faker.name().upper(),
        "ownership_type": rng.choice(OWNERSHIP_TYPE_POOL),
        "status": rng.choice(PERMIT_STATUS_POOL),
        "nature_of_business": rng.choice(KIND_OF_BUSINESS_POOL),
        "lcn_no": f"{rng.randint(1, 999_999):06d}",
        "area": str(rng.randint(3, 500)),
        "date_issued": issued.strftime("%m/%d/%Y"),
        "valid_until": f"12/31/{TAGUIG_FORM_YEAR}",
        "or_number": str(rng.randint(1_000_000, 9_999_999)),
        "bplo_head": faker.name().upper(),
        "total": peso(sum(fees.values())),
        **{key: peso(value) for key, value in fees.items()},
    }


CITY_LAYOUTS: dict = {
    "makati": CityLayout(
        name="makati",
        template_path=PY_ROOT / "data" / "template" / "business_permits" / "Business Permit (Makati).png",
        boxes_json=PY_ROOT / "json_data" / "business_permit_boxes_Makati.json",
        make_record=generate_makati_record,
        # The Makati template ships with the OIC + Mayor names AND their
        # signatures already printed; the annotated boxes exist for downstream
        # detector labelling, not for filling.
        preprinted_keys=frozenset({"oic_officer", "city_mayor",
                                   "oic_officer_signature", "city_mayor_signature"}),
        font_sizes=(22, 20, 18),
        min_font_size=10,
        stem_prefix="synthetic_permit_makati",
        manifest_name="_synthetic_manifest_makati.json",
    ),
    "manila": CityLayout(
        name="manila",
        template_path=PY_ROOT / "data" / "template" / "business_permits" / "Business Permit (Manila).png",
        boxes_json=PY_ROOT / "json_data" / "business_permit_boxes_Manila.json",
        make_record=generate_manila_record,
        image_assets={"secretary_signature": SIGNATURE_PATH},
        asset_enhance={"secretary_signature": SIGNATURE_ENHANCE},
        # Values typed on the Manila form start at the left of each ruled line;
        # only the short slot fields (employees/nationality/fee column) center.
        left_align_keys=frozenset({"name", "business_name", "address", "telephone_nos",
                                   "kind_of_business", "remakrs", "processed_by",
                                   "or_no", "date", "amount_paid", "bp_code"}),
        font_sizes=(24, 22, 20),
        min_font_size=12,
        stem_prefix="synthetic_permit_manila",
        manifest_name="_synthetic_manifest_manila.json",
    ),
    "marikina": CityLayout(
        name="marikina",
        template_path=PY_ROOT / "data" / "template" / "business_permits" / "Business Permit (Marikina).png",
        boxes_json=PY_ROOT / "json_data" / "business_permit_boxes_Marikina.json",
        make_record=generate_marikina_record,
        image_assets={"acting_chief_signature": SIGNATURE_PATH},
        asset_enhance={"acting_chief_signature": SIGNATURE_ENHANCE},
        left_align_keys=frozenset({"business_address"}),
        # The annotated fee boxes stretch left of the AMOUNT column, so amounts
        # are right-aligned to land under the printed AMOUNT header.
        right_align_keys=frozenset({*MARIKINA_FEE_RANGES, "total"}),
        nowrap_keys=frozenset({"permit_no", "date_issued", "date_started",
                               "valid_until", "deadline_of_renewal", "received_date"}),
        font_sizes=(28, 26, 22),
        min_font_size=16,
        stem_prefix="synthetic_permit_marikina",
        manifest_name="_synthetic_manifest_marikina.json",
    ),
    "taguig": CityLayout(
        name="taguig",
        template_path=PY_ROOT / "data" / "template" / "business_permits" / "Business Permit (Taguig).png",
        boxes_json=PY_ROOT / "json_data" / "business_permit_boxes_Taguig.json",
        make_record=generate_taguig_record,
        image_assets={"bplo_head_signature": SIGNATURE_PATH},
        asset_enhance={"bplo_head_signature": SIGNATURE_ENHANCE},
        # The tax-table rows are ~13px tall, so the floor is far smaller than
        # the other layouts'.
        font_sizes=(24, 22, 18),
        min_font_size=9,
        stem_prefix="synthetic_permit_taguig",
        manifest_name="_synthetic_manifest_taguig.json",
    ),
}


def render_city_permit(layout: CityLayout, record: dict, *, boxes: dict | None = None,
                       font_dir: Path = FONT_DIR,
                       rng: random.Random | None = None) -> Image.Image:
    """Render a single clean permit (RGB) for a `CityLayout` from a field record."""
    boxes = boxes if boxes is not None else layout.load_boxes()
    base = Image.open(layout.template_path).convert("RGBA")
    draw = ImageDraw.Draw(base)
    for key in layout.text_keys(boxes):
        draw_text_in_box(draw, record.get(key, ""), boxes[key], font_dir=font_dir,
                         rng=rng, center=key not in layout.left_align_keys,
                         align="right" if key in layout.right_align_keys else None,
                         nowrap=key in layout.nowrap_keys,
                         sizes=layout.font_sizes, min_size=layout.min_font_size)
    for key, asset_path in layout.image_assets.items():
        bir.paste_asset(base, asset_path, boxes[key], rng=rng,
                        **layout.asset_enhance.get(key, {}))
    return base.convert("RGB")


def validate_city_assets(layout: CityLayout, font_dir: Path = FONT_DIR) -> list:
    """Human-readable problems blocking a city-layout run (empty = good to go)."""
    problems = []
    if not Path(layout.template_path).exists():
        problems.append(f"template missing: {layout.template_path}")
    if not Path(layout.boxes_json).exists():
        problems.append(f"boxes JSON missing: {layout.boxes_json}")
    if not problems:
        boxes = layout.load_boxes()
        with Image.open(layout.template_path) as im:
            bad = bir.boxes_out_of_bounds(im.size, boxes)
        if bad:
            problems.append(f"boxes outside template: {', '.join(bad)}")
    for key, path in layout.image_assets.items():
        if not Path(path).exists():
            problems.append(f"{key} asset missing: {path}")
    try:
        bir.resolve_font(font_dir)
    except FileNotFoundError as exc:
        problems.append(str(exc))
    return problems


def run_city_batch(layout: CityLayout, count: int, out_dir: Path = OUTPUT_DIR, *,
                   variants: tuple = ("clean", "scan"), seed: int | None = None,
                   font_dir: Path = FONT_DIR) -> dict:
    """`run_batch` for a `CityLayout`: `count` unique base permits, each emitted
    in the requested variants, appended to the layout's own manifest (so the
    Digos ledger and filename sequence are never disturbed)."""
    from faker import Faker

    out_dir = Path(out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    manifest_path = out_dir / layout.manifest_name
    manifest = load_manifest(manifest_path)
    boxes = layout.load_boxes()

    rng = random.Random(seed)
    faker = Faker("en_PH")
    if seed is not None:
        Faker.seed(seed)

    written: list = []
    for n in range(count):
        record = generate_unique_record(faker, rng, manifest, record_fn=layout.make_record)
        idx = manifest["next_index"]
        stem = f"{layout.stem_prefix}_{idx:05d}"
        clean = render_city_permit(layout, record, boxes=boxes, font_dir=font_dir, rng=rng)
        files: list = []
        if "clean" in variants:
            fp = out_dir / f"{stem}_clean.png"
            clean.save(fp)
            files.append(fp.name)
        if "scan" in variants:
            fp = out_dir / f"{stem}_scan.jpg"
            bir.degrade(clean, rng).save(fp, quality=85)
            files.append(fp.name)
        register_record(manifest, record, files)
        written.extend(files)
        if (n + 1) % 25 == 0:
            log(f"generated {n + 1}/{count} {layout.name} permits")

    bir.save_manifest(manifest_path, manifest)
    return {"count": count, "files": written, "manifest": str(manifest_path),
            "next_index": manifest["next_index"]}


# ---------------------------------------------------------------------------
# Validation, batch orchestration, CLI.
# ---------------------------------------------------------------------------
def validate_assets(template_path: Path = TEMPLATE_PATH, assets: dict = IMAGE_FIELD_ASSETS,
                    font_dir: Path = FONT_DIR) -> list[str]:
    """Human-readable problems blocking generation (empty list = good to go)."""
    problems = []
    if not Path(template_path).exists():
        problems.append(f"template missing: {template_path}")
    else:
        with Image.open(template_path) as im:
            bad = bir.boxes_out_of_bounds(im.size, FIELD_BOXES)
        if bad:
            problems.append(f"boxes outside template: {', '.join(bad)}")
    for key, path in assets.items():
        if not Path(path).exists():
            problems.append(f"{key} asset missing: {path}")
    try:
        bir.resolve_font(font_dir)
    except FileNotFoundError as exc:
        problems.append(str(exc))
    return problems


def run_batch(count: int, out_dir: Path = OUTPUT_DIR, *,
              variants: tuple[str, ...] = ("clean", "scan"), seed: int | None = None,
              template_path: Path = TEMPLATE_PATH, assets: dict = IMAGE_FIELD_ASSETS,
              font_dir: Path = FONT_DIR,
              manifest_name: str = "_synthetic_manifest.json") -> dict:
    """Generate `count` unique base permits, each emitted in the requested variants,
    appending to the per-folder manifest."""
    from faker import Faker

    out_dir = Path(out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    manifest_path = out_dir / manifest_name
    manifest = load_manifest(manifest_path)

    rng = random.Random(seed)
    faker = Faker("en_PH")
    if seed is not None:
        Faker.seed(seed)

    written: list[str] = []
    for n in range(count):
        record = generate_unique_record(faker, rng, manifest)
        idx = manifest["next_index"]
        stem = f"synthetic_permit_{idx:05d}"
        clean = render_permit(record, template_path=template_path,
                              assets=assets, font_dir=font_dir, rng=rng)
        files: list[str] = []
        if "clean" in variants:
            fp = out_dir / f"{stem}_clean.png"
            clean.save(fp)
            files.append(fp.name)
        if "scan" in variants:
            fp = out_dir / f"{stem}_scan.jpg"
            bir.degrade(clean, rng).save(fp, quality=85)
            files.append(fp.name)
        register_record(manifest, record, files)
        written.extend(files)
        if (n + 1) % 25 == 0:
            log(f"generated {n + 1}/{count} permits")

    bir.save_manifest(manifest_path, manifest)
    return {"count": count, "files": written, "manifest": str(manifest_path),
            "next_index": manifest["next_index"]}


def parse_args(argv=None):
    p = argparse.ArgumentParser(description="Generate synthetic City Business Permit training images.")
    p.add_argument("--city", choices=("digos", *sorted(CITY_LAYOUTS)), default="digos",
                   help="permit layout to generate (default: the original Digos template).")
    p.add_argument("--count", type=int, default=100,
                   help="base permits per run (each -> clean + scan = 2 files). Default 100.")
    p.add_argument("--out-dir", default=str(OUTPUT_DIR))
    p.add_argument("--seed", type=int, default=None, help="reproducible run (use a fresh out-dir).")
    p.add_argument("--clean-only", action="store_true", help="emit only the clean PNG.")
    p.add_argument("--scan-only", action="store_true", help="emit only the degraded JPG.")
    p.add_argument("--template", default=None, help="override the city's template image.")
    p.add_argument("--logo", default=None, help="override the City-seal PNG (digos only).")
    p.add_argument("--signature", default=None, help="override the blue-ink signature asset.")
    p.add_argument("--font-dir", default=str(FONT_DIR))
    p.add_argument("--dry-run", action="store_true",
                   help="validate assets/boxes/font + render one permit in memory; write nothing.")
    return p.parse_args(argv)


def _variants_from_args(args) -> tuple:
    if args.clean_only:
        return ("clean",)
    if args.scan_only:
        return ("scan",)
    return ("clean", "scan")


def _run_city_main(args, layout: CityLayout, font_dir: Path) -> int:
    """CLI path for a `CityLayout` (--city makati/manila)."""
    overrides = {}
    if args.template:
        overrides["template_path"] = Path(args.template)
    if args.signature and layout.image_assets:
        overrides["image_assets"] = {k: Path(args.signature) for k in layout.image_assets}
    if overrides:
        layout = replace(layout, **overrides)

    problems = validate_city_assets(layout, font_dir)
    if problems:
        for prob in problems:
            log(f"ERROR: {prob}")
        return 2

    if args.dry_run:
        from faker import Faker
        rng = random.Random(0)
        faker = Faker("en_PH")
        Faker.seed(0)
        record = layout.make_record(faker, rng)
        img = render_city_permit(layout, record, font_dir=font_dir)
        log(f"DRY RUN ok - rendered 1 {layout.name} permit in memory at {img.size}, wrote nothing.")
        log(f"fields: business_name={record.get('business_name', '')} "
            f"address={record.get('address', '')}")
        return 0

    summary = run_city_batch(layout, args.count, out_dir=Path(args.out_dir),
                             variants=_variants_from_args(args), seed=args.seed,
                             font_dir=font_dir)
    log(f"wrote {len(summary['files'])} files ({args.count} base {layout.name} permits) "
        f"-> {args.out_dir}")
    log(f"manifest -> {summary['manifest']} (next_index={summary['next_index']})")
    return 0


def main(argv=None) -> int:
    args = parse_args(argv)
    font_dir = Path(args.font_dir)

    if args.city != "digos":
        return _run_city_main(args, CITY_LAYOUTS[args.city], font_dir)

    assets = {
        SIGNATURE_KEYS[0]: Path(args.signature or SIGNATURE_PATH),
        SIGNATURE_KEYS[1]: Path(args.signature or SIGNATURE_PATH),
        LOGO_KEY: Path(args.logo or LOGO_PATH),
    }
    template = Path(args.template or TEMPLATE_PATH)

    problems = validate_assets(template, assets, font_dir)
    if problems:
        for prob in problems:
            log(f"ERROR: {prob}")
        return 2

    if args.dry_run:
        from faker import Faker
        rng = random.Random(0)
        faker = Faker("en_PH")
        Faker.seed(0)
        record = generate_record(faker, rng)
        img = render_permit(record, template_path=template, assets=assets, font_dir=font_dir)
        log(f"DRY RUN ok - rendered 1 permit in memory at {img.size}, wrote nothing.")
        log(f"fields: proprietor={record['name_of_proprietor']} "
            f"kind={record['kind_of_business']} issued={record['day_issued']} {record['month_issued']}")
        return 0

    summary = run_batch(args.count, out_dir=Path(args.out_dir), variants=_variants_from_args(args),
                        seed=args.seed, template_path=template, assets=assets, font_dir=font_dir)
    log(f"wrote {len(summary['files'])} files ({args.count} base permits) -> {args.out_dir}")
    log(f"manifest -> {summary['manifest']} (next_index={summary['next_index']})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
