"""ADVS - synthetic City Business Permit generator (Digos layout).

The business-permit analogue of ``bir_dataset_generator.py``. It fills the blank
City of Digos Business Permit template with OCR-realistic synthetic field data,
composites a crisp City seal (``python/logo/logo_digos.png``) and two officer
signatures, then emits a CLEAN image and an Augraphy-degraded SCAN image into the
ResNet-50 classifier's ``business_permit`` class folder. A per-folder JSON
ledger (``_synthetic_manifest.json``) records every record's content hash so
reruns never duplicate data and filenames keep incrementing.

Bounding boxes are the calibrated pixel boxes exported by
``business_permit_annotator.py`` -> ``python/json_data/business_permit_boxes.json``
(IMAGE space of the 1600x1203 template). The non-text regions (the two signature
boxes + the City seal) are filled with image assets, not text.

The heavy, field-agnostic machinery (text fitting, alpha keying, asset
compositing, Augraphy scan degradation, manifest atomic-write) is REUSED from
``bir_dataset_generator.py`` so both generators stay consistent and the tricky
code lives in one place. Only the permit-specific config + field values + the
alpha-aware logo composite live here.

Usage (always the venv interpreter):
    python/env/Scripts/python.exe python/scripts/business_permit_dataset_generator.py --dry-run
    python/env/Scripts/python.exe python/scripts/business_permit_dataset_generator.py            # 100 base -> 200 files
    python/env/Scripts/python.exe python/scripts/business_permit_dataset_generator.py --count 50 --clean-only
"""
from __future__ import annotations

import argparse
import hashlib
import json
import random
import sys
from datetime import datetime, timezone
from pathlib import Path

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
BOXES_JSON = PY_ROOT / "json_data" / "business_permit_boxes.json"
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
                           max_tries: int = 1000) -> dict:
    """A record whose content hash is absent from the manifest."""
    used_hashes = set(manifest["used_hashes"])
    for _ in range(max_tries):
        rec = generate_record(faker, rng)
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
                     *, center: bool = True) -> None:
    """Render `text` inside `box`, horizontally + vertically centered by default,
    shrinking the face to fit. Reuses the BIR generator's wrapping/fitting."""
    if not text:
        return
    pad = 4
    x, y, w, h = box["x"], box["y"], box["w"], box["h"]
    size, lines = bir.fit_text(text, w - 2 * pad, h - 2 * pad, font_dir=font_dir,
                               sizes=PREFERRED_FONT_SIZES, min_size=MIN_FONT_SIZE)
    font = bir.load_font(size, font_dir=str(font_dir))
    line_h = bir._text_size(draw, "Ag", font)[1] + 2
    total_h = line_h * len(lines)
    jx, jy = (rng.randint(-1, 1), rng.randint(-1, 1)) if rng else (0, 0)
    ty = y + jy + (max(0, (h - total_h) // 2) if center else pad)
    for ln in lines:
        line_w = bir._text_size(draw, ln, font)[0]
        tx = x + jx + ((w - line_w) // 2 if center else pad)
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
    p.add_argument("--count", type=int, default=100,
                   help="base permits per run (each -> clean + scan = 2 files). Default 100.")
    p.add_argument("--out-dir", default=str(OUTPUT_DIR))
    p.add_argument("--seed", type=int, default=None, help="reproducible run (use a fresh out-dir).")
    p.add_argument("--clean-only", action="store_true", help="emit only the clean PNG.")
    p.add_argument("--scan-only", action="store_true", help="emit only the degraded JPG.")
    p.add_argument("--template", default=str(TEMPLATE_PATH))
    p.add_argument("--logo", default=str(LOGO_PATH))
    p.add_argument("--signature", default=str(SIGNATURE_PATH))
    p.add_argument("--font-dir", default=str(FONT_DIR))
    p.add_argument("--dry-run", action="store_true",
                   help="validate assets/boxes/font + render one permit in memory; write nothing.")
    return p.parse_args(argv)


def main(argv=None) -> int:
    args = parse_args(argv)
    assets = {
        SIGNATURE_KEYS[0]: Path(args.signature),
        SIGNATURE_KEYS[1]: Path(args.signature),
        LOGO_KEY: Path(args.logo),
    }
    font_dir = Path(args.font_dir)
    template = Path(args.template)

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

    variants = ("clean", "scan")
    if args.clean_only:
        variants = ("clean",)
    elif args.scan_only:
        variants = ("scan",)

    summary = run_batch(args.count, out_dir=Path(args.out_dir), variants=variants,
                        seed=args.seed, template_path=template, assets=assets, font_dir=font_dir)
    log(f"wrote {len(summary['files'])} files ({args.count} base permits) -> {args.out_dir}")
    log(f"manifest -> {summary['manifest']} (next_index={summary['next_index']})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
