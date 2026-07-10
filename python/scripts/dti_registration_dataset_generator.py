"""ADVS - synthetic DTI Business Name Registration generator.

Fills the blank DTI certificate template with OCR-plausible synthetic field
values, then emits clean PNG and scan-degraded JPG variants into the
ResNet-50 classifier's ``dti_registration`` class folder. The DTI template
already contains the agency logo, watermark, and secretary signature, so this
generator only draws text fields.

Usage (always the venv interpreter):
    python/env/Scripts/python.exe python/scripts/dti_registration_dataset_generator.py --dry-run
    python/env/Scripts/python.exe python/scripts/dti_registration_dataset_generator.py --count 500
"""
from __future__ import annotations

import argparse
import hashlib
import json
import random
import sys
from datetime import date, datetime, timezone
from pathlib import Path

from PIL import Image, ImageDraw

SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

import bir_dataset_generator as bir  # noqa: E402

PY_ROOT = Path(__file__).resolve().parents[1]
TEMPLATE_PATH = PY_ROOT / "data" / "template" / "dti_registration" / "dti_template.png"
BOXES_JSON = PY_ROOT / "json_data" / "dti_registration.json"
FONT_DIR = PY_ROOT / "data" / "fonts"
OUTPUT_DIR = PY_ROOT / "data" / "training" / "classifier_data" / "dti_registration"

FIELD_BOXES: dict[str, dict[str, int]] = {}
TEXT_FIELD_KEYS = [
    "business_name",
    "business_address",
    "owner_representative_name",
    "date_issued",
    "expiry_date",
    "certificate_no",
    "trn_no",
]

PREFERRED_FONT_SIZES = (24, 22, 20, 18)
SMALL_FONT_SIZES = (18, 16, 14, 12)
MIN_FONT_SIZE = 10
NOWRAP_KEYS = {"date_issued", "expiry_date", "certificate_no", "trn_no"}
BOLD_BLACK_KEYS = {"business_name", "owner_representative_name"}
BOLD_BLACK_FILL = (0, 0, 0)  # pure black ink for prominent fields

BARANGAYS = [
    "BAGUMBAYAN", "BAMBANG", "CALZADA", "CENTRAL BICUTAN", "FORT BONIFACIO",
    "HAGONOY", "IBAYO TIPAS", "LOWER BICUTAN", "NAPINDAN", "PINAGSAMA",
    "SAN MIGUEL", "SANTA ANA", "TUKTUKAN", "USUSAN", "WAWA",
]
CITY_POOL = [
    "TAGUIG CITY", "PASIG CITY", "MAKATI CITY", "QUEZON CITY", "MANILA CITY",
    "PARANAQUE CITY", "PASAY CITY", "MANDALUYONG CITY", "SAN JUAN CITY",
]
BUSINESS_SUFFIXES = [
    "FOOD HOUSE", "SARI-SARI STORE", "BAKESHOP", "CANTEEN", "EATERY",
    "TRADING", "GENERAL MERCHANDISE", "MINI MART", "ONLINE SHOP",
    "CARINDERIA", "REFRESHMENT STAND", "CATERING SERVICES",
]


def log(message: str) -> None:
    """ASCII-only status line (Windows cp1252 console safe)."""
    print(f"[dti-gen] {message}", flush=True)


def load_field_boxes(path: Path = BOXES_JSON) -> dict[str, dict[str, int]]:
    """Read the annotator-exported DTI field boxes from JSON."""
    raw = json.loads(Path(path).read_text(encoding="utf-8"))
    return {
        key: {
            "x": int(value["x"]),
            "y": int(value["y"]),
            "w": int(value["w"]),
            "h": int(value["h"]),
        }
        for key, value in raw.items()
    }


def _add_years(value: date, years: int) -> date:
    """Add whole years, keeping Feb. 29 valid on non-leap target years."""
    try:
        return value.replace(year=value.year + years)
    except ValueError:
        return value.replace(month=2, day=28, year=value.year + years)


def _business_name(faker, rng: random.Random) -> str:
    owner = faker.last_name().upper()
    if rng.random() < 0.55:
        prefix = rng.choice([
            owner, faker.city().split()[0].upper(), faker.color_name().upper(),
            faker.first_name().upper(), "GOLDEN", "LUCKY", "SUNRISE", "MABUHAY",
        ])
        return f"{prefix} {rng.choice(BUSINESS_SUFFIXES)}"

    company = faker.company().upper()
    return company.replace(",", "").replace(".", "")


def _address(faker, rng: random.Random) -> str:
    return (
        f"{faker.building_number()} {faker.street_name().upper()}, "
        f"BARANGAY {rng.choice(BARANGAYS)}, {rng.choice(CITY_POOL)}"
    )


def _certificate_no(rng: random.Random) -> str:
    return f"BN {rng.randint(1_000_000, 9_999_999)}"


def _trn_no(issued: date, rng: random.Random) -> str:
    return f"DTI-{issued.year}-{rng.randint(10_000_000, 99_999_999)}"


def generate_record(faker, rng: random.Random) -> dict[str, str]:
    """One synthetic DTI registration record (text fields only)."""
    issued = faker.date_between(start_date="-4y", end_date="-1m")
    expiry = _add_years(issued, 5)

    return {
        "business_name": _business_name(faker, rng),
        "business_address": _address(faker, rng),
        "owner_representative_name": faker.name().upper(),
        "date_issued": issued.strftime("%m/%d/%Y"),
        "expiry_date": expiry.strftime("%m/%d/%Y"),
        "certificate_no": _certificate_no(rng),
        "trn_no": _trn_no(issued, rng),
    }


def record_hash(record: dict[str, str]) -> str:
    """Stable, order-independent SHA-1 of a record's fields."""
    blob = json.dumps(record, sort_keys=True, ensure_ascii=False)
    return hashlib.sha1(blob.encode("utf-8")).hexdigest()


def load_manifest(path: Path) -> dict:
    """Load the per-folder generation manifest, creating defaults if absent."""
    p = Path(path)
    if p.exists():
        data = json.loads(p.read_text(encoding="utf-8"))
        data.setdefault("version", 1)
        data.setdefault("records", [])
        data.setdefault("used_certificate_nos", [])
        data.setdefault("used_trn_nos", [])
        data.setdefault("used_hashes", [])
        data.setdefault("next_index", len(data["records"]) + 1)
        return data

    return {
        "version": 1,
        "next_index": 1,
        "records": [],
        "used_certificate_nos": [],
        "used_trn_nos": [],
        "used_hashes": [],
    }


def generate_unique_record(faker, rng: random.Random, manifest: dict,
                           max_tries: int = 1000) -> dict[str, str]:
    """A record whose certificate/TRN/hash are absent from the manifest."""
    used_certificate_nos = set(manifest["used_certificate_nos"])
    used_trn_nos = set(manifest["used_trn_nos"])
    used_hashes = set(manifest["used_hashes"])

    for _ in range(max_tries):
        record = generate_record(faker, rng)
        if record["certificate_no"] in used_certificate_nos:
            continue
        if record["trn_no"] in used_trn_nos:
            continue
        if record_hash(record) in used_hashes:
            continue
        return record

    raise RuntimeError(
        "Could not generate a unique DTI registration record in "
        f"{max_tries} tries (manifest saturated or seed too constrained)."
    )


def register_record(manifest: dict, record: dict[str, str], files: list[str]) -> int:
    """Append a record to the manifest, return its assigned index."""
    idx = manifest["next_index"]
    record_digest = record_hash(record)
    manifest["records"].append({
        "index": idx,
        "certificate_no": record["certificate_no"],
        "trn_no": record["trn_no"],
        "hash": record_digest,
        "fields": record,
        "files": files,
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
    })
    manifest["used_certificate_nos"].append(record["certificate_no"])
    manifest["used_trn_nos"].append(record["trn_no"])
    manifest["used_hashes"].append(record_digest)
    manifest["next_index"] = idx + 1
    return idx


def _single_line_size(draw: ImageDraw.ImageDraw, text: str, box_w: int,
                      font_dir: Path, sizes: tuple[int, ...]) -> int:
    candidates = list(sizes) + list(range(min(sizes) - 1, MIN_FONT_SIZE - 1, -1))
    for size in candidates:
        font = bir.load_font(size, font_dir=str(font_dir))
        if bir._text_size(draw, text, font)[0] <= box_w:
            return size
    return MIN_FONT_SIZE


def draw_text_in_box(draw: ImageDraw.ImageDraw, text: str, box: dict[str, int],
                     font_dir: Path = FONT_DIR, rng: random.Random | None = None,
                     *, nowrap: bool = False, bold: bool = False,
                     fill: tuple[int, int, int] | None = None) -> None:
    """Render text centered inside a DTI template box.

    Args:
        bold: Use CourierPrime-Bold instead of Regular.
        fill: Override ink colour (default: ``bir.TEXT_COLOR``).
    """
    if not text:
        return

    ink = fill if fill is not None else bir.TEXT_COLOR
    pad = 4
    x, y, w, h = box["x"], box["y"], box["w"], box["h"]
    sizes = SMALL_FONT_SIZES if nowrap else PREFERRED_FONT_SIZES

    if nowrap:
        size = _single_line_size(draw, text, w - 2 * pad, font_dir, sizes)
        lines = [text]
    else:
        size, lines = bir.fit_text(
            text,
            w - 2 * pad,
            h - 2 * pad,
            font_dir=font_dir,
            sizes=sizes,
            min_size=MIN_FONT_SIZE,
        )

    font = bir.load_font(size, bold=bold, font_dir=str(font_dir))
    line_h = bir._text_size(draw, "Ag", font)[1] + 2
    total_h = line_h * len(lines)
    jx, jy = (rng.randint(-1, 1), rng.randint(-1, 1)) if rng else (0, 0)
    ty = y + jy + max(pad, (h - total_h) // 2)

    for line in lines:
        line_w = bir._text_size(draw, line, font)[0]
        tx = x + jx + max(pad, (w - line_w) // 2)
        draw.text((tx, ty), line, fill=ink, font=font)
        ty += line_h


def render_registration(record: dict[str, str], *, boxes: dict[str, dict[str, int]] | None = None,
                        template_path: Path = TEMPLATE_PATH,
                        font_dir: Path = FONT_DIR,
                        rng: random.Random | None = None) -> Image.Image:
    """Render a single clean DTI registration image (RGB)."""
    boxes = boxes if boxes is not None else FIELD_BOXES
    base = Image.open(template_path).convert("RGBA")
    draw = ImageDraw.Draw(base)
    for key in TEXT_FIELD_KEYS:
        is_bold_black = key in BOLD_BLACK_KEYS
        draw_text_in_box(
            draw,
            record.get(key, ""),
            boxes[key],
            font_dir=font_dir,
            rng=rng,
            nowrap=key in NOWRAP_KEYS,
            bold=is_bold_black,
            fill=BOLD_BLACK_FILL if is_bold_black else None,
        )
    return base.convert("RGB")


def validate_assets(template_path: Path = TEMPLATE_PATH,
                    boxes: dict[str, dict[str, int]] | None = None,
                    font_dir: Path = FONT_DIR) -> list[str]:
    """Human-readable problems blocking generation (empty list = good)."""
    problems: list[str] = []
    boxes = boxes if boxes is not None else FIELD_BOXES

    if not Path(template_path).exists():
        problems.append(f"template missing: {template_path}")
    else:
        with Image.open(template_path) as image:
            bad = bir.boxes_out_of_bounds(image.size, boxes)
        if bad:
            problems.append(f"boxes outside template: {', '.join(bad)}")

    missing_keys = [key for key in TEXT_FIELD_KEYS if key not in boxes]
    if missing_keys:
        problems.append(f"boxes missing fields: {', '.join(missing_keys)}")

    try:
        bir.resolve_font(font_dir)
    except FileNotFoundError as exc:
        problems.append(str(exc))

    return problems


def run_batch(count: int, out_dir: Path = OUTPUT_DIR, *,
              variants: tuple[str, ...] = ("clean", "scan"),
              seed: int | None = None,
              boxes: dict[str, dict[str, int]] | None = None,
              template_path: Path = TEMPLATE_PATH,
              font_dir: Path = FONT_DIR,
              manifest_name: str = "_synthetic_manifest.json") -> dict:
    """Generate unique DTI registrations, appending to the folder manifest."""
    from faker import Faker

    out_dir = Path(out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    manifest_path = out_dir / manifest_name
    manifest = load_manifest(manifest_path)
    boxes = boxes if boxes is not None else FIELD_BOXES

    rng = random.Random(seed)
    faker = Faker("en_PH")
    if seed is not None:
        Faker.seed(seed)

    written: list[str] = []
    for n in range(count):
        record = generate_unique_record(faker, rng, manifest)
        idx = manifest["next_index"]
        stem = f"synthetic_dti_registration_{idx:05d}"
        clean = render_registration(
            record,
            boxes=boxes,
            template_path=template_path,
            font_dir=font_dir,
            rng=rng,
        )

        files: list[str] = []
        if "clean" in variants:
            clean_path = out_dir / f"{stem}_clean.png"
            clean.save(clean_path)
            files.append(clean_path.name)
        if "scan" in variants:
            scan_path = out_dir / f"{stem}_scan.jpg"
            bir.degrade(clean, rng).save(scan_path, quality=85)
            files.append(scan_path.name)

        register_record(manifest, record, files)
        written.extend(files)
        if (n + 1) % 25 == 0:
            log(f"generated {n + 1}/{count} registrations")

    bir.save_manifest(manifest_path, manifest)
    return {"count": count, "files": written, "manifest": str(manifest_path),
            "next_index": manifest["next_index"]}


def parse_args(argv=None):
    parser = argparse.ArgumentParser(description="Generate synthetic DTI registration training images.")
    parser.add_argument("--count", type=int, default=100,
                        help="base registrations per run (each -> clean + scan = 2 files).")
    parser.add_argument("--out-dir", default=str(OUTPUT_DIR))
    parser.add_argument("--seed", type=int, default=None, help="reproducible run (use a fresh out-dir).")
    parser.add_argument("--clean-only", action="store_true", help="emit only the clean PNG.")
    parser.add_argument("--scan-only", action="store_true", help="emit only the degraded JPG.")
    parser.add_argument("--template", default=str(TEMPLATE_PATH))
    parser.add_argument("--boxes-json", default=str(BOXES_JSON))
    parser.add_argument("--font-dir", default=str(FONT_DIR))
    parser.add_argument("--dry-run", action="store_true",
                        help="validate assets/boxes/font + render one registration in memory.")
    return parser.parse_args(argv)


def _variants_from_args(args) -> tuple[str, ...]:
    if args.clean_only:
        return ("clean",)
    if args.scan_only:
        return ("scan",)
    return ("clean", "scan")


def main(argv=None) -> int:
    args = parse_args(argv)
    template = Path(args.template)
    boxes = load_field_boxes(Path(args.boxes_json))
    font_dir = Path(args.font_dir)

    problems = validate_assets(template, boxes, font_dir)
    if problems:
        for problem in problems:
            log(f"ERROR: {problem}")
        return 2

    if args.dry_run:
        from faker import Faker
        rng = random.Random(0)
        faker = Faker("en_PH")
        Faker.seed(0)
        record = generate_record(faker, rng)
        image = render_registration(record, boxes=boxes, template_path=template,
                                    font_dir=font_dir, rng=rng)
        log(f"DRY RUN ok - rendered 1 DTI registration in memory at {image.size}, wrote nothing.")
        log(
            "fields: "
            f"certificate_no={record['certificate_no']} "
            f"trn_no={record['trn_no']} issued={record['date_issued']}"
        )
        return 0

    summary = run_batch(
        args.count,
        out_dir=Path(args.out_dir),
        variants=_variants_from_args(args),
        seed=args.seed,
        boxes=boxes,
        template_path=template,
        font_dir=font_dir,
    )
    log(f"wrote {len(summary['files'])} files ({args.count} base registrations) -> {args.out_dir}")
    log(f"manifest -> {summary['manifest']} (next_index={summary['next_index']})")
    return 0


FIELD_BOXES = load_field_boxes()


if __name__ == "__main__":
    raise SystemExit(main())
