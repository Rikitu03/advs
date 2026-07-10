"""ADVS - synthetic BIR Certificate of Registration (Form 2303) generator.

Fills the blank Form 2303 template with OCR-realistic synthetic field data and
composites the BIR dry seal + officer signature, then emits a CLEAN image and an
Augraphy-degraded SCAN image into the ResNet-50 classifier's `bir_certificate`
class folder. A per-folder JSON ledger (`_synthetic_manifest.json`) records every
record's TIN + content hash + filenames so reruns never duplicate data and
filenames keep incrementing.

Field VALUES are constrained to the OCR regexes in scripts/ocr_dryrun.py
(FIELD_SPECS) so generated documents read back the way the production OCR stage
expects. Bounding boxes are the calibrated pixel boxes from annotate_boxes.py
(IMAGE space of the 700x887 template).

Usage (always the venv interpreter):
    python/env/Scripts/python.exe python/scripts/bir_dataset_generator.py --dry-run
    python/env/Scripts/python.exe python/scripts/bir_dataset_generator.py            # 100 base -> 200 files
    python/env/Scripts/python.exe python/scripts/bir_dataset_generator.py --count 50 --clean-only
"""
from __future__ import annotations

import argparse
import hashlib
import json
import random
import string
import sys
from datetime import datetime, timezone
from functools import lru_cache
from pathlib import Path

from PIL import Image, ImageDraw, ImageEnhance, ImageFont

PY_ROOT = Path(__file__).resolve().parents[1]            # .../python
TEMPLATE_PATH = PY_ROOT / "data" / "template" / "bir_permit" / "BIR_PERMIT_TEMPLATE.png"
SEAL_PATH = PY_ROOT / "data" / "seal" / "BIR_SEAL.png"
SIGNATURE_PATH = PY_ROOT / "data" / "stamps" / "BIR_OFFICER_STAMP.png"
FONT_DIR = PY_ROOT / "data" / "fonts"
OUTPUT_DIR = PY_ROOT / "data" / "training" / "classifier_data" / "bir_certificate"

# Calibrated bounding boxes (annotate_boxes.py) in template IMAGE pixels.
FIELD_BOXES: dict[str, dict[str, int]] = {
    "form_no": {"x": 69, "y": 72, "w": 122, "h": 36},
    "ocn": {"x": 566, "y": 89, "w": 128, "h": 30},
    "tin": {"x": 18, "y": 200, "w": 171, "h": 28},
    "registered_name": {"x": 198, "y": 203, "w": 297, "h": 25},
    "registration_date": {"x": 502, "y": 204, "w": 184, "h": 24},
    "registered_address": {"x": 14, "y": 249, "w": 674, "h": 43},
    "revenue_region_no": {"x": 418, "y": 65, "w": 55, "h": 19},
    "rdo_code": {"x": 424, "y": 86, "w": 71, "h": 20},
    "line_of_business": {"x": 339, "y": 431, "w": 311, "h": 117},
    "trade_name": {"x": 51, "y": 431, "w": 241, "h": 113},
    "tax_types": {"x": 333, "y": 331, "w": 316, "h": 55},
    "revenue_district_officer": {"x": 295, "y": 773, "w": 221, "h": 43},
    "signature_over_name": {"x": 469, "y": 725, "w": 180, "h": 99},
    "dry_seal": {"x": 18, "y": 721, "w": 176, "h": 99},
    "date_issued": {"x": 554, "y": 799, "w": 114, "h": 23},
}

# The two non-text detection regions are filled with image assets, not text.
IMAGE_FIELD_ASSETS: dict[str, Path] = {
    "dry_seal": SEAL_PATH,
    "signature_over_name": SIGNATURE_PATH,
}
TEXT_FIELD_KEYS: list[str] = [k for k in FIELD_BOXES if k not in IMAGE_FIELD_ASSETS]

PREFERRED_FONT_SIZES = (12, 11)   # spec: size 12, drop to 11 to fit
MIN_FONT_SIZE = 8                 # only used when a value cannot fit at 11
WHITE_KEY_THRESHOLD = 235         # luminance >= this in an asset -> transparent
# The seal/signature are COLORED ink (orange seal, blue signature) at mid
# luminance, so a gentle ramp leaves them semi-transparent ("screened"). A steep
# gain drives colored ink to full opacity; the contrast/saturation lift deepens
# the ink and whitens the background so keying is clean and the stamp pops.
ASSET_INK_GAIN = 4.0              # opacity ramp steepness for keyed asset ink
ASSET_CONTRAST = 1.35            # pre-key contrast boost (deepens ink, whitens bg)
ASSET_SATURATION = 1.4           # pre-key saturation boost (richer orange/blue)
# Per-asset overrides. The officer signature is thin blue ink that the scan
# degradation washes out, so it gets near-binary opacity plus a darken so the
# deep blue survives Augraphy and pops in the final image; the bold orange seal
# already reads well and keeps the gentler defaults.
ASSET_ENHANCE: dict[str, dict] = {
    "signature_over_name": {"contrast": 1.6, "saturation": 1.9, "gain": 10.0, "darken": 0.7},
}
TEXT_COLOR = (25, 28, 38)         # near-black ink (not pure black)


def log(msg: str) -> None:
    """ASCII-only status line (Windows cp1252 console safe)."""
    print(f"[bir-gen] {msg}", flush=True)


def resolve_font(font_dir: Path = FONT_DIR, *, bold: bool = False) -> Path:
    """Path to the vendored Courier Prime face; fail loudly with how to get it."""
    name = "CourierPrime-Bold.ttf" if bold else "CourierPrime-Regular.ttf"
    path = Path(font_dir) / name
    if not path.exists():
        raise FileNotFoundError(
            f"Courier Prime not found at {path}. Vendor it into {font_dir} "
            "(Google Fonts OFL: ofl/courierprime/CourierPrime-Regular.ttf)."
        )
    return path


@lru_cache(maxsize=None)
def load_font(size: int, bold: bool = False, font_dir: str = str(FONT_DIR)) -> ImageFont.FreeTypeFont:
    """Cached TrueType face at a pixel size (font_dir is str so args stay hashable)."""
    return ImageFont.truetype(str(resolve_font(Path(font_dir), bold=bold)), size)


def boxes_out_of_bounds(template_size: tuple[int, int], boxes: dict | None = None) -> list[str]:
    """Keys whose box strays outside an (w, h) template - guards box calibration."""
    boxes = boxes if boxes is not None else FIELD_BOXES
    tw, th = template_size
    bad = []
    for key, b in boxes.items():
        if b["x"] < 0 or b["y"] < 0 or b["x"] + b["w"] > tw or b["y"] + b["h"] > th:
            bad.append(key)
    return bad


# ---------------------------------------------------------------------------
# Synthetic field values - constrained to the OCR FIELD_SPECS regexes so a
# generated form reads back the way the production OCR stage expects.
# ---------------------------------------------------------------------------
REVENUE_REGIONS = ["4A", "5", "6", "7", "7A", "8", "8B", "9", "9A", "10",
                   "11", "12", "13", "16", "19"]
TAX_TYPE_POOL = ["INCOME TAX", "VALUE-ADDED TAX", "PERCENTAGE TAX",
                 "WITHHOLDING TAX - COMPENSATION", "WITHHOLDING TAX - EXPANDED",
                 "REGISTRATION FEE"]
LINE_OF_BUSINESS_POOL = [
    "RETAIL SALE IN NON-SPECIALIZED STORES",
    "WHOLESALE OF OTHER HOUSEHOLD GOODS",
    "COMPUTER PROGRAMMING ACTIVITIES",
    "RESTAURANTS AND MOBILE FOOD SERVICE ACTIVITIES",
    "CONSTRUCTION OF RESIDENTIAL BUILDINGS",
    "FREIGHT TRANSPORT BY ROAD",
    "OTHER BUSINESS SUPPORT SERVICE ACTIVITIES",
    "MANUFACTURE OF BAKERY PRODUCTS",
]
MONTHS = ["JAN", "FEB", "MAR", "APR", "MAY", "JUN",
          "JUL", "AUG", "SEP", "OCT", "NOV", "DEC"]


def _tin(rng: random.Random) -> str:
    body = "-".join(f"{rng.randint(0, 999):03d}" for _ in range(3))
    branch = f"{rng.choice([0, 0, 0, rng.randint(1, 25)]):04d}"  # usually 0000 (head office)
    return f"{body}-{branch}"


def _ocn(rng: random.Random) -> str:
    head = rng.randint(1, 9)
    letters = "".join(rng.choice(string.ascii_uppercase) for _ in range(rng.randint(2, 3)))
    digits = "".join(str(rng.randint(0, 9)) for _ in range(rng.randint(9, 10)))
    return f"{head}{letters}{digits}"


def generate_record(faker, rng: random.Random) -> dict[str, str]:
    """One synthetic Form 2303 record (text fields only); image regions excluded."""
    is_company = rng.random() < 0.7
    name = (faker.company() if is_company else faker.name()).upper()
    reg = faker.date_between(start_date="-12y", end_date="-1y")
    return {
        "form_no": "2303",
        "ocn": _ocn(rng),
        "tin": _tin(rng),
        "registered_name": name,
        "registration_date": f"{reg.month}/{reg.day}/{reg.year}",
        "registered_address": faker.address().replace("\n", ", ").upper(),
        "revenue_region_no": rng.choice(REVENUE_REGIONS),
        "rdo_code": str(rng.randint(1, 213)),
        "line_of_business": rng.choice(LINE_OF_BUSINESS_POOL),
        "trade_name": (faker.company() if is_company else name.title()).upper(),
        "tax_types": ", ".join(sorted(rng.sample(TAX_TYPE_POOL, k=rng.randint(2, 4)))),
        "revenue_district_officer": faker.name().upper(),
        "date_issued": f"{rng.choice(MONTHS)} {reg.day:02d} {reg.year}",
    }


def record_hash(record: dict[str, str]) -> str:
    """Stable, order-independent SHA-1 of a record's fields (dedup key)."""
    blob = json.dumps(record, sort_keys=True, ensure_ascii=False)
    return hashlib.sha1(blob.encode("utf-8")).hexdigest()


# ---------------------------------------------------------------------------
# Duplication ledger: a per-folder JSON manifest of every generated record so
# reruns never duplicate data and filenames keep incrementing.
# ---------------------------------------------------------------------------
def load_manifest(path: Path) -> dict:
    p = Path(path)
    if p.exists():
        data = json.loads(p.read_text(encoding="utf-8"))
        data.setdefault("version", 1)
        data.setdefault("records", [])
        data.setdefault("used_tins", [])
        data.setdefault("used_hashes", [])
        data.setdefault("next_index", len(data["records"]) + 1)
        return data
    return {"version": 1, "next_index": 1, "records": [],
            "used_tins": [], "used_hashes": []}


def save_manifest(path: Path, manifest: dict) -> None:
    """Atomic write (temp file then replace) so an interrupted run can't corrupt it."""
    p = Path(path)
    p.parent.mkdir(parents=True, exist_ok=True)
    tmp = p.with_suffix(p.suffix + ".tmp")
    tmp.write_text(json.dumps(manifest, indent=2, ensure_ascii=False), encoding="utf-8")
    tmp.replace(p)


def generate_unique_record(faker, rng: random.Random, manifest: dict,
                           max_tries: int = 1000) -> dict:
    """A record whose TIN and content hash are absent from the manifest."""
    used_tins = set(manifest["used_tins"])
    used_hashes = set(manifest["used_hashes"])
    for _ in range(max_tries):
        rec = generate_record(faker, rng)
        if rec["tin"] in used_tins or record_hash(rec) in used_hashes:
            continue
        return rec
    raise RuntimeError(
        "Could not generate a unique record in "
        f"{max_tries} tries (manifest saturated or seed too constrained)."
    )


def register_record(manifest: dict, record: dict, files: list[str]) -> int:
    """Append a record to the manifest, return its assigned index, bump next_index."""
    idx = manifest["next_index"]
    h = record_hash(record)
    manifest["records"].append({
        "index": idx,
        "tin": record["tin"],
        "hash": h,
        "fields": record,
        "files": files,
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
    })
    manifest["used_tins"].append(record["tin"])
    manifest["used_hashes"].append(h)
    manifest["next_index"] = idx + 1
    return idx


# ---------------------------------------------------------------------------
# Text rendering: prefer size 12, drop to 11, then shrink/wrap to fit the box.
# ---------------------------------------------------------------------------
def _text_size(draw: ImageDraw.ImageDraw, text: str, font) -> tuple[int, int]:
    l, t, r, b = draw.textbbox((0, 0), text or " ", font=font)
    return r - l, b - t


def _wrap_to_width(draw, text: str, font, box_w: int) -> list[str]:
    words = text.split()
    if not words:
        return [""]
    lines, cur = [], words[0]
    for word in words[1:]:
        trial = f"{cur} {word}"
        if _text_size(draw, trial, font)[0] <= box_w:
            cur = trial
        else:
            lines.append(cur)
            cur = word
    lines.append(cur)
    return lines


def fit_text(text: str, box_w: int, box_h: int, font_dir: Path = FONT_DIR,
             sizes=PREFERRED_FONT_SIZES, min_size: int = MIN_FONT_SIZE) -> tuple[int, list[str]]:
    """Largest candidate size whose wrapped block fits (box_w, box_h).

    Tries the preferred sizes (12, 11) first, then shrinks toward min_size. Returns
    the smallest tried size + its wrapping if nothing fits (text then slightly
    overflows rather than vanishing - acceptable for a synthetic scan)."""
    scratch = ImageDraw.Draw(Image.new("RGB", (max(1, box_w), max(1, box_h))))
    candidate_sizes = list(sizes) + list(range(min(sizes) - 1, min_size - 1, -1))
    fallback = None
    for size in candidate_sizes:
        font = load_font(size, font_dir=str(font_dir))
        lines = _wrap_to_width(scratch, text, font, box_w)
        line_h = _text_size(scratch, "Ag", font)[1] + 2
        total_h = line_h * len(lines)
        widest = max((_text_size(scratch, ln, font)[0] for ln in lines), default=0)
        if widest <= box_w and total_h <= box_h:
            return size, lines
        fallback = (size, lines)
    return fallback


def draw_text_in_box(draw: ImageDraw.ImageDraw, text: str, box: dict,
                     font_dir: Path = FONT_DIR, rng: random.Random | None = None) -> None:
    """Render `text` top-left inside `box` with a small pad + optional 1px jitter."""
    if not text:
        return
    pad = 2
    x, y, w, h = box["x"], box["y"], box["w"], box["h"]
    size, lines = fit_text(text, w - 2 * pad, h - 2 * pad, font_dir=font_dir)
    font = load_font(size, font_dir=str(font_dir))
    line_h = _text_size(draw, "Ag", font)[1] + 2
    jx, jy = (rng.randint(-1, 1), rng.randint(-1, 1)) if rng else (0, 0)
    ty = y + pad + jy
    for ln in lines:
        draw.text((x + pad + jx, ty), ln, fill=TEXT_COLOR, font=font)
        ty += line_h


# ---------------------------------------------------------------------------
# Image regions: the seal + signature PNGs are ink on a white background (RGB,
# no alpha). Key near-white to transparent so only the ink composites.
# ---------------------------------------------------------------------------
def build_alpha(asset_rgb: Image.Image, threshold: int = WHITE_KEY_THRESHOLD,
                gain: float = ASSET_INK_GAIN) -> Image.Image:
    """L-mode alpha: white/near-white -> 0 (transparent), ink -> opaque.

    `gain` sets how fast luminance below `threshold` reaches full opacity; a high
    gain keeps mid-luminance COLORED ink (orange seal, blue signature) solid
    instead of screened."""
    import numpy as np

    arr = np.asarray(asset_rgb.convert("RGB")).astype(np.int16)
    lum = arr.mean(axis=2)
    alpha = np.clip((threshold - lum) * gain, 0, 255).astype("uint8")
    return Image.fromarray(alpha, mode="L")


def paste_asset(base: Image.Image, asset_path: Path, box: dict,
                rng: random.Random | None = None, *,
                contrast: float = ASSET_CONTRAST, saturation: float = ASSET_SATURATION,
                gain: float = ASSET_INK_GAIN, darken: float = 1.0) -> None:
    """Scale an asset to fit `box` (aspect-preserved, centered, light jitter) and
    alpha-composite it onto an RGBA `base` in place. Contrast/saturation/opacity
    are boosted first so the keyed stamp ink reads boldly rather than washed out;
    `darken` (<1.0) deepens the ink so thin strokes survive scan degradation. Pass
    overrides per asset (the signature needs more punch than the seal)."""
    asset = Image.open(asset_path).convert("RGB")
    asset = ImageEnhance.Contrast(asset).enhance(contrast)
    asset = ImageEnhance.Color(asset).enhance(saturation)
    alpha = build_alpha(asset, gain=gain)
    if darken != 1.0:
        # Deepen the ink AFTER keying so the darkened white background (now alpha 0)
        # stays invisible while the ink strokes become a darker, scan-proof colour.
        asset = ImageEnhance.Brightness(asset).enhance(darken)
    asset.putalpha(alpha)

    x, y, w, h = box["x"], box["y"], box["w"], box["h"]
    scale = min(w / asset.width, h / asset.height)
    if rng:
        scale *= rng.uniform(0.88, 1.0)
    nw, nh = max(1, int(asset.width * scale)), max(1, int(asset.height * scale))
    asset = asset.resize((nw, nh), Image.LANCZOS)
    if rng:
        asset = asset.rotate(rng.uniform(-3, 3), expand=True, resample=Image.BICUBIC)

    ox = max(x, x + (w - asset.width) // 2)
    oy = max(y, y + (h - asset.height) // 2)
    base.alpha_composite(asset, (ox, oy))


# ---------------------------------------------------------------------------
# Compose a full certificate: text fields, then the two image regions on top.
# ---------------------------------------------------------------------------
def render_certificate(record: dict, *, template_path: Path = TEMPLATE_PATH,
                       assets: dict = IMAGE_FIELD_ASSETS, font_dir: Path = FONT_DIR,
                       rng: random.Random | None = None) -> Image.Image:
    """Render a single clean Form 2303 image (RGB) from a field record."""
    base = Image.open(template_path).convert("RGBA")
    draw = ImageDraw.Draw(base)
    for key in TEXT_FIELD_KEYS:
        draw_text_in_box(draw, record.get(key, ""), FIELD_BOXES[key],
                         font_dir=font_dir, rng=rng)
    for key, asset_path in assets.items():
        paste_asset(base, asset_path, FIELD_BOXES[key], rng=rng,
                    **ASSET_ENHANCE.get(key, {}))
    return base.convert("RGB")


# ---------------------------------------------------------------------------
# Scan/photocopy realism (Augraphy). Imported lazily so the pure-logic tests and
# --dry-run do not pay the heavy import. Any pipeline failure falls back to the
# clean image so a single bad frame can't abort a 100-doc batch.
# ---------------------------------------------------------------------------
def _build_augraphy_pipeline():
    """A GENTLE scan/photocopy pipeline tuned for legibility (augraphy 8.2.6).

    Augraphy's defaults are aggressive - Jpeg quality drops to 25, Gamma to 0.5,
    InkBleed intensity to 0.7 - which shreds small Courier Prime text. Every range
    below is narrowed toward "lightly scanned": faint ink bleed, near-unity
    brightness/gamma, low noise, light JPEG, and a <=1 deg skew. The result reads
    as a scan but the field text stays clearly recognizable."""
    from augraphy import (AugraphyPipeline, Brightness, Gamma, Geometric,
                          InkBleed, Jpeg, SubtleNoise)

    ink_phase = [InkBleed(intensity_range=(0.1, 0.2), kernel_size=(3, 3),
                          severity=(0.1, 0.2))]
    paper_phase = []
    post_phase = [
        Brightness(brightness_range=(0.95, 1.08)),
        Gamma(gamma_range=(0.9, 1.1)),
        SubtleNoise(subtle_range=6),
        Geometric(rotate_range=(-1, 1)),
        Jpeg(quality_range=(80, 95)),
    ]
    return AugraphyPipeline(ink_phase=ink_phase, paper_phase=paper_phase,
                            post_phase=post_phase)


def degrade(image: Image.Image, rng: random.Random | None = None) -> Image.Image:
    """Augraphy scan/photocopy degradation; clean image on any failure.

    Some phases (e.g. Geometric rotation) pad the canvas, so the result is snapped
    back to the source dimensions to keep a uniform dataset."""
    src = image.convert("RGB")
    try:
        import numpy as np

        pipeline = _build_augraphy_pipeline()
        result = pipeline(np.asarray(src))
        out = result["output"] if isinstance(result, dict) else result
        out = np.asarray(out).astype("uint8")
        if out.ndim == 2:
            out = np.stack([out] * 3, axis=-1)
        scanned = Image.fromarray(out[:, :, :3]).convert("RGB")
        if scanned.size != src.size:
            scanned = scanned.resize(src.size, Image.LANCZOS)
        return scanned
    except Exception as exc:  # noqa: BLE001 - never let one frame kill the batch
        log(f"WARN: augraphy degradation failed ({exc}); using clean image as scan.")
        return src


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
            bad = boxes_out_of_bounds(im.size)
        if bad:
            problems.append(f"boxes outside template: {', '.join(bad)}")
    for key, path in assets.items():
        if not Path(path).exists():
            problems.append(f"{key} asset missing: {path}")
    try:
        resolve_font(font_dir)
    except FileNotFoundError as exc:
        problems.append(str(exc))
    return problems


def run_batch(count: int, out_dir: Path = OUTPUT_DIR, *,
              variants: tuple[str, ...] = ("clean", "scan"), seed: int | None = None,
              template_path: Path = TEMPLATE_PATH, assets: dict = IMAGE_FIELD_ASSETS,
              font_dir: Path = FONT_DIR,
              manifest_name: str = "_synthetic_manifest.json") -> dict:
    """Generate `count` unique base certificates, each emitted in the requested
    variants, appending to the per-folder manifest."""
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
        stem = f"synthetic_bir_{idx:05d}"
        clean = render_certificate(record, template_path=template_path,
                                   assets=assets, font_dir=font_dir, rng=rng)
        files: list[str] = []
        if "clean" in variants:
            fp = out_dir / f"{stem}_clean.png"
            clean.save(fp)
            files.append(fp.name)
        if "scan" in variants:
            fp = out_dir / f"{stem}_scan.jpg"
            degrade(clean, rng).save(fp, quality=85)
            files.append(fp.name)
        register_record(manifest, record, files)
        written.extend(files)
        if (n + 1) % 25 == 0:
            log(f"generated {n + 1}/{count} certificates")

    save_manifest(manifest_path, manifest)
    return {"count": count, "files": written, "manifest": str(manifest_path),
            "next_index": manifest["next_index"]}


def parse_args(argv=None):
    p = argparse.ArgumentParser(description="Generate synthetic BIR Form 2303 training images.")
    p.add_argument("--count", type=int, default=100,
                   help="base certificates per run (each -> clean + scan = 2 files). Default 100.")
    p.add_argument("--out-dir", default=str(OUTPUT_DIR))
    p.add_argument("--seed", type=int, default=None, help="reproducible run (use a fresh out-dir).")
    p.add_argument("--clean-only", action="store_true", help="emit only the clean PNG.")
    p.add_argument("--scan-only", action="store_true", help="emit only the degraded JPG.")
    p.add_argument("--template", default=str(TEMPLATE_PATH))
    p.add_argument("--seal", default=str(SEAL_PATH))
    p.add_argument("--signature", default=str(SIGNATURE_PATH))
    p.add_argument("--font-dir", default=str(FONT_DIR))
    p.add_argument("--dry-run", action="store_true",
                   help="validate assets/boxes/font + render one cert in memory; write nothing.")
    return p.parse_args(argv)


def main(argv=None) -> int:
    args = parse_args(argv)
    assets = {"dry_seal": Path(args.seal), "signature_over_name": Path(args.signature)}
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
        img = render_certificate(record, template_path=template, assets=assets, font_dir=font_dir)
        log(f"DRY RUN ok - rendered 1 certificate in memory at {img.size}, wrote nothing.")
        log(f"fields: TIN={record['tin']} OCN={record['ocn']} issued={record['date_issued']}")
        return 0

    variants = ("clean", "scan")
    if args.clean_only:
        variants = ("clean",)
    elif args.scan_only:
        variants = ("scan",)

    summary = run_batch(args.count, out_dir=Path(args.out_dir), variants=variants,
                        seed=args.seed, template_path=template, assets=assets, font_dir=font_dir)
    log(f"wrote {len(summary['files'])} files ({args.count} base certs) -> {args.out_dir}")
    log(f"manifest -> {summary['manifest']} (next_index={summary['next_index']})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
