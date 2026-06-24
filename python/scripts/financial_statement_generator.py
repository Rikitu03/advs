"""ADVS - synthetic Financial Statement generator (ResNet-50 classifier class).

Renders OCR-realistic, internally-consistent vendor Financial Statements entirely
from scratch with Pillow (no fixed template) and composites three PROCEDURALLY
generated marks so every document is visually unique:

  * a per-company LOGO (initials in a colour derived from the company name),
  * a dummy SIGNATURE of the reviewing Local Government Officer (ink squiggle),
  * an LGU SEAL OF APPROVAL (circular dry seal with arc text + star).

Each record carries a Statement of Financial Position (balance sheet that FOOTS:
Total Assets == Total Liabilities + Equity) and a Statement of Comprehensive
Income whose subtotals are arithmetically consistent. A CLEAN PNG and an
Augraphy-degraded SCAN JPG are emitted into the classifier's
`financial_statement` class folder. A per-folder JSON ledger
(`_synthetic_manifest.json`) records each company name + content hash + filenames
so reruns never duplicate data and filenames keep incrementing.

This mirrors scripts/bir_dataset_generator.py (manifest/dedup/degrade/CLI) so the
two classifier-data generators behave identically from the operator's side.

Usage (always the venv interpreter):
    python python/scripts/financial_statement_generator.py --dry-run
    python python/scripts/financial_statement_generator.py            # 200 base -> 400 files
    python python/scripts/financial_statement_generator.py --count 50 --clean-only
"""
from __future__ import annotations

import argparse
import colorsys
import hashlib
import json
import math
import random
import re
import sys
from datetime import datetime, timezone
from functools import lru_cache
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

PY_ROOT = Path(__file__).resolve().parents[1]            # .../python
FONT_DIR = PY_ROOT / "data" / "fonts"
OUTPUT_DIR = PY_ROOT / "data" / "training" / "classifier_data" / "financial_statement"

# Page geometry (portrait, ~100 DPI letter-ish). RGB white canvas.
PAGE_W = 1000
PAGE_H = 1300
MARGIN = 60
RIGHT_X = PAGE_W - MARGIN                                 # right edge for amounts
TEXT_COLOR = (25, 28, 38)                                # near-black ink
RULE_COLOR = (90, 95, 110)
MUTED_COLOR = (90, 95, 110)
SEAL_COLOR = (140, 24, 38)                               # official dark red
SIGNATURE_COLOR = (28, 42, 120)                          # blue ballpoint ink

# Pools for synthetic text values.
COMPANY_SUFFIXES = ["Inc.", "Corporation", "Trading", "Enterprises", "Co.",
                    "Ventures Inc.", "Holdings Corp.", "Industries Inc.",
                    "Construction Corp.", "Marketing", "Logistics Inc.",
                    "Manufacturing Corp.", "Services Co."]
LGU_TYPES = ["CITY", "MUNICIPALITY"]
LGU_NAMES = ["PASIG", "TAGUIG", "MAKATI", "QUEZON CITY", "MANDALUYONG",
             "ANTIPOLO", "CAINTA", "MARIKINA", "PASAY", "PARANAQUE",
             "VALENZUELA", "CALOOCAN", "MALOLOS", "CALAMBA", "BACOOR",
             "DASMARINAS", "SAN FERNANDO", "ILIGAN", "DAVAO", "CEBU"]
OFFICER_TITLES = ["City Treasurer", "Municipal Treasurer", "City Accountant",
                  "Municipal Accountant", "Local Government Officer",
                  "City Assessor", "Business Permits & Licensing Officer"]
PROVINCES = ["Metro Manila", "Rizal", "Cavite", "Laguna", "Bulacan",
             "Pampanga", "Cebu", "Davao del Sur", "Batangas", "Pangasinan"]
STATEMENT_TITLE = "STATEMENT OF FINANCIAL POSITION"


def log(msg: str) -> None:
    """ASCII-only status line (Windows cp1252 console safe)."""
    print(f"[fs-gen] {msg}", flush=True)


# ---------------------------------------------------------------------------
# Fonts (Courier Prime is vendored alongside the BIR generator).
# ---------------------------------------------------------------------------
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


# ---------------------------------------------------------------------------
# Internally-consistent synthetic financials. All amounts are whole pesos,
# rounded to thousands so the rendered numbers look like a typed statement.
# ---------------------------------------------------------------------------
def _split(rng: random.Random, total: int, n: int, round_to: int = 1000) -> list[int]:
    """Split `total` into `n` positive parts (rounded to `round_to`) that sum exactly."""
    if n <= 1:
        return [total]
    if total < round_to * n:                   # too small to round into n parts
        round_to = 1
    weights = [rng.uniform(0.6, 1.6) for _ in range(n)]
    s = sum(weights)
    parts = [max(round_to, int(round(total * w / s / round_to)) * round_to) for w in weights]
    parts[-1] += total - sum(parts)            # absorb rounding drift into the last part
    return parts


def generate_financials(rng: random.Random) -> dict[str, int]:
    """A balance sheet that FOOTS plus a consistent income statement (whole pesos)."""
    total_assets = rng.randint(2_000, 2_000_000) * 1000          # 2M .. 2B

    current_assets = int(round(total_assets * rng.uniform(0.30, 0.60) / 1000)) * 1000
    noncurrent_assets = total_assets - current_assets
    cash, receivables, inventories = _split(rng, current_assets, 3)
    ppe, other_nca = _split(rng, noncurrent_assets, 2)

    total_liabilities = int(round(total_assets * rng.uniform(0.25, 0.65) / 1000)) * 1000
    equity = total_assets - total_liabilities
    current_liabilities = int(round(total_liabilities * rng.uniform(0.40, 0.70) / 1000)) * 1000
    noncurrent_liabilities = total_liabilities - current_liabilities
    payables, short_term_loans = _split(rng, current_liabilities, 2)
    share_capital = int(round(equity * rng.uniform(0.40, 0.80) / 1000)) * 1000
    retained_earnings = equity - share_capital

    revenue = int(round(total_assets * rng.uniform(0.50, 1.60) / 1000)) * 1000
    cost_of_sales = int(round(revenue * rng.uniform(0.45, 0.70) / 1000)) * 1000
    gross_profit = revenue - cost_of_sales
    operating_expenses = int(round(gross_profit * rng.uniform(0.30, 0.70) / 1000)) * 1000
    operating_income = gross_profit - operating_expenses
    income_tax = int(round(max(0, operating_income) * 0.25 / 1000)) * 1000
    net_income = operating_income - income_tax

    return {
        "cash": cash, "receivables": receivables, "inventories": inventories,
        "current_assets": current_assets,
        "ppe": ppe, "other_noncurrent_assets": other_nca,
        "noncurrent_assets": noncurrent_assets,
        "total_assets": total_assets,
        "payables": payables, "short_term_loans": short_term_loans,
        "current_liabilities": current_liabilities,
        "long_term_debt": noncurrent_liabilities,
        "noncurrent_liabilities": noncurrent_liabilities,
        "total_liabilities": total_liabilities,
        "share_capital": share_capital, "retained_earnings": retained_earnings,
        "total_equity": equity,
        "total_liabilities_and_equity": total_liabilities + equity,
        "revenue": revenue, "cost_of_sales": cost_of_sales, "gross_profit": gross_profit,
        "operating_expenses": operating_expenses, "operating_income": operating_income,
        "income_tax": income_tax, "net_income": net_income,
    }


_SUFFIX_TOKENS = {"inc", "inc.", "ltd", "ltd.", "llc", "plc", "group", "sons",
                  "co", "co.", "corporation", "corp", "corp.", "limited", "and", "&"}


def _company_name(faker, rng: random.Random) -> str:
    """A plausible, unique-ish vendor company name (Title Case + a business suffix).

    Faker's company strings already carry their own suffix words; strip any trailing
    ones so we don't produce awkward doubles like 'Foods Limited Co.'."""
    tokens = re.split(r"\s*,\s*", faker.company())[0].split()
    while tokens and tokens[-1].lower() in _SUFFIX_TOKENS:
        tokens.pop()
    base = " ".join(tokens) or faker.company()
    return f"{base} {rng.choice(COMPANY_SUFFIXES)}"


def generate_record(faker, rng: random.Random) -> dict:
    """One synthetic Financial Statement record (data only; marks drawn at render)."""
    fiscal_year = rng.randint(2018, 2025)
    lgu_type = rng.choice(LGU_TYPES)
    lgu_name = rng.choice(LGU_NAMES)
    return {
        "company_name": _company_name(faker, rng),
        "company_address": faker.address().replace("\n", ", "),
        "statement_title": STATEMENT_TITLE,
        "period": f"As of December 31, {fiscal_year}",
        "fiscal_year": fiscal_year,
        "doc_no": f"FS-{fiscal_year}-{rng.randint(1, 99999):05d}",
        "officer_name": faker.name(),
        "officer_title": rng.choice(OFFICER_TITLES),
        "lgu_type": lgu_type,
        "lgu_name": lgu_name,
        "province": rng.choice(PROVINCES),
        "date_approved": f"{rng.choice(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'])} "
                         f"{rng.randint(1, 28):02d}, {fiscal_year + 1}",
        "financials": generate_financials(rng),
    }


def record_hash(record: dict) -> str:
    """Stable, order-independent SHA-1 of a record's data (dedup key)."""
    blob = json.dumps(record, sort_keys=True, ensure_ascii=False)
    return hashlib.sha1(blob.encode("utf-8")).hexdigest()


# ---------------------------------------------------------------------------
# Duplication ledger (per-folder JSON manifest) - identical contract to the BIR
# generator but keyed on company name instead of TIN.
# ---------------------------------------------------------------------------
def load_manifest(path: Path) -> dict:
    p = Path(path)
    if p.exists():
        data = json.loads(p.read_text(encoding="utf-8"))
        data.setdefault("version", 1)
        data.setdefault("records", [])
        data.setdefault("used_companies", [])
        data.setdefault("used_hashes", [])
        data.setdefault("next_index", len(data["records"]) + 1)
        return data
    return {"version": 1, "next_index": 1, "records": [],
            "used_companies": [], "used_hashes": []}


def save_manifest(path: Path, manifest: dict) -> None:
    """Atomic write (temp file then replace) so an interrupted run can't corrupt it."""
    p = Path(path)
    p.parent.mkdir(parents=True, exist_ok=True)
    tmp = p.with_suffix(p.suffix + ".tmp")
    tmp.write_text(json.dumps(manifest, indent=2, ensure_ascii=False), encoding="utf-8")
    tmp.replace(p)


def generate_unique_record(faker, rng: random.Random, manifest: dict,
                           max_tries: int = 1000) -> dict:
    """A record whose company name and content hash are absent from the manifest."""
    used_companies = set(manifest["used_companies"])
    used_hashes = set(manifest["used_hashes"])
    for _ in range(max_tries):
        rec = generate_record(faker, rng)
        if rec["company_name"] in used_companies or record_hash(rec) in used_hashes:
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
        "company_name": record["company_name"],
        "hash": h,
        "fields": record,
        "files": files,
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
    })
    manifest["used_companies"].append(record["company_name"])
    manifest["used_hashes"].append(h)
    manifest["next_index"] = idx + 1
    return idx


# ---------------------------------------------------------------------------
# Procedural marks: company logo, LGU seal, officer signature. Each is built as
# an RGBA image so it can be composited (with jitter) onto the page.
# ---------------------------------------------------------------------------
def _seeded(value: str) -> random.Random:
    """A deterministic RNG seeded by a string (stable colours/strokes per name)."""
    return random.Random(int(hashlib.sha1(value.encode("utf-8")).hexdigest(), 16))


def _initials(name: str, max_len: int = 3) -> str:
    words = [w for w in name.replace(".", " ").replace(",", " ").split() if w[:1].isalnum()]
    letters = "".join(w[0].upper() for w in words if w[0].isalpha())
    return (letters or name[:1].upper())[:max_len]


def make_logo(company_name: str, size: int = 110, font_dir: Path = FONT_DIR) -> Image.Image:
    """A unique RGBA company logo: initials over a colour derived from the name."""
    r = _seeded(company_name)
    hue = r.random()
    bg = tuple(int(c * 255) for c in colorsys.hsv_to_rgb(hue, r.uniform(0.45, 0.7), r.uniform(0.55, 0.8)))
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    shape = r.choice(["circle", "rounded", "hex"])
    pad = 4
    if shape == "circle":
        d.ellipse([pad, pad, size - pad, size - pad], fill=bg + (255,))
    elif shape == "rounded":
        d.rounded_rectangle([pad, pad, size - pad, size - pad], radius=size // 6, fill=bg + (255,))
    else:
        cx, cy, rad = size / 2, size / 2, size / 2 - pad
        pts = [(cx + rad * math.cos(math.radians(a - 90)), cy + rad * math.sin(math.radians(a - 90)))
               for a in range(0, 360, 60)]
        d.polygon(pts, fill=bg + (255,))
    text = _initials(company_name)
    font = load_font(int(size * 0.42), bold=True, font_dir=str(font_dir))
    tb = d.textbbox((0, 0), text, font=font)
    d.text(((size - (tb[2] - tb[0])) / 2 - tb[0], (size - (tb[3] - tb[1])) / 2 - tb[1]),
           text, fill=(255, 255, 255, 255), font=font)
    return img


def _arc_text(target: Image.Image, center: tuple[float, float], radius: float,
              text: str, font: ImageFont.FreeTypeFont, fill: tuple,
              start_deg: float, end_deg: float, *, flip: bool = False) -> None:
    """Render `text` along a circular arc by rotating each glyph to the tangent.

    Angles are degrees, 0 = top (12 o'clock), increasing clockwise. `flip` rotates
    each glyph 180 deg so a bottom arc reads upright."""
    cx, cy = center
    n = len(text)
    if n == 0:
        return
    step = (end_deg - start_deg) / (n - 1) if n > 1 else 0.0
    for i, ch in enumerate(text):
        ang = start_deg + i * step
        rad = math.radians(ang)
        x = cx + radius * math.sin(rad)
        y = cy - radius * math.cos(rad)
        gb = font.getbbox(ch)
        gw, gh = gb[2] - gb[0], gb[3] - gb[1]
        glyph = Image.new("RGBA", (gw + 6, gh + 6), (0, 0, 0, 0))
        ImageDraw.Draw(glyph).text((3 - gb[0], 3 - gb[1]), ch, font=font, fill=fill)
        glyph = glyph.rotate(-(ang + (180 if flip else 0)), expand=True, resample=Image.BICUBIC)
        target.alpha_composite(glyph, (int(x - glyph.width / 2), int(y - glyph.height / 2)))


def make_lgu_seal(record: dict, size: int = 260, font_dir: Path = FONT_DIR) -> Image.Image:
    """An RGBA circular LGU dry seal: ring rules, arc text, central star + year."""
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    c = (size / 2, size / 2)
    outer = size / 2 - 4
    color = SEAL_COLOR + (255,)
    d.ellipse([4, 4, size - 4, size - 4], outline=color, width=4)
    d.ellipse([22, 22, size - 22, size - 22], outline=color, width=2)

    top = f"REPUBLIC OF THE PHILIPPINES"
    bottom = f"{record['lgu_type']} OF {record['lgu_name']}"
    arc_font = load_font(max(12, size // 18), bold=True, font_dir=str(font_dir))
    _arc_text(img, c, outer - 22, top, arc_font, color, -70, 70)
    _arc_text(img, c, outer - 22, bottom, arc_font, color, 110, 250, flip=True)

    # Central five-point star.
    star_r = size * 0.16
    pts = []
    for k in range(10):
        rr = star_r if k % 2 == 0 else star_r * 0.42
        a = math.radians(k * 36 - 90)
        pts.append((c[0] + rr * math.cos(a), c[1] + rr * math.sin(a) - size * 0.04))
    d.polygon(pts, outline=color, width=3)

    seal_font = load_font(max(11, size // 20), bold=True, font_dir=str(font_dir))
    label = "SEAL OF APPROVAL"
    lb = d.textbbox((0, 0), label, font=seal_font)
    d.text((c[0] - (lb[2] - lb[0]) / 2, c[1] + size * 0.16), label, fill=color, font=seal_font)
    yb = d.textbbox((0, 0), str(record["fiscal_year"]), font=seal_font)
    d.text((c[0] - (yb[2] - yb[0]) / 2, c[1] + size * 0.24), str(record["fiscal_year"]),
           fill=color, font=seal_font)
    return img


def make_signature(seed_text: str, width: int = 230, height: int = 90) -> Image.Image:
    """A dummy ink SIGNATURE: layered smooth strokes derived from a name seed."""
    r = _seeded("sig:" + seed_text)
    img = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    ink = SIGNATURE_COLOR + (255,)
    baseline = height * 0.6
    for stroke in range(r.randint(2, 3)):
        x0 = r.uniform(8, 24)
        x1 = width - r.uniform(8, 28)
        amp = r.uniform(height * 0.18, height * 0.34)
        freq = r.uniform(2.2, 4.2)
        phase = r.uniform(0, math.pi)
        drift = r.uniform(-height * 0.12, height * 0.12)
        pts = []
        steps = 120
        for s in range(steps + 1):
            t = s / steps
            x = x0 + (x1 - x0) * t
            y = baseline + drift * t + amp * math.sin(freq * math.tau * t + phase) * (1 - 0.4 * t)
            pts.append((x, y))
        d.line(pts, fill=ink, width=r.choice([2, 3]), joint="curve")
    # A short flourish underline.
    if r.random() < 0.7:
        uy = baseline + r.uniform(8, 18)
        d.line([(12, uy), (width - r.uniform(20, 50), uy - r.uniform(-6, 6))], fill=ink, width=2)
    return img


# ---------------------------------------------------------------------------
# Page composition.
# ---------------------------------------------------------------------------
def _peso(amount: int, *, negate: bool = False) -> str:
    """Php-prefixed thousands-grouped amount; negatives shown in (parentheses)."""
    body = f"Php {abs(amount):,}"
    return f"({body})" if negate else body


def _right(draw: ImageDraw.ImageDraw, y: int, text: str, font, fill=TEXT_COLOR) -> None:
    w = draw.textbbox((0, 0), text, font=font)[2]
    draw.text((RIGHT_X - w, y), text, fill=fill, font=font)


def _center(draw: ImageDraw.ImageDraw, y: int, text: str, font, fill=TEXT_COLOR) -> int:
    w = draw.textbbox((0, 0), text, font=font)[2]
    draw.text(((PAGE_W - w) / 2, y), text, fill=fill, font=font)
    return y


def _line_item(draw, y: int, label: str, amount: int | None, *, indent: int = 0,
               bold: bool = False, negate: bool = False, font_dir: Path = FONT_DIR,
               rule_above: bool = False) -> int:
    font = load_font(15, bold=bold, font_dir=str(font_dir))
    if rule_above:
        draw.line([(MARGIN + 360, y - 4), (RIGHT_X, y - 4)], fill=RULE_COLOR, width=1)
    draw.text((MARGIN + indent, y), label, fill=TEXT_COLOR, font=font)
    if amount is not None:
        _right(draw, y, _peso(amount, negate=negate), font)
    return y + 24


def render_statement(record: dict, *, font_dir: Path = FONT_DIR,
                     rng: random.Random | None = None) -> Image.Image:
    """Render a single clean Financial Statement page (RGBA) from a record."""
    img = Image.new("RGBA", (PAGE_W, PAGE_H), (255, 255, 255, 255))
    d = ImageDraw.Draw(img)
    f = record["financials"]

    # --- Header: logo + company identity ---
    logo = make_logo(record["company_name"], font_dir=font_dir)
    img.alpha_composite(logo, (MARGIN, 50))
    name_font = load_font(26, bold=True, font_dir=str(font_dir))
    d.text((MARGIN + 130, 56), record["company_name"], fill=TEXT_COLOR, font=name_font)
    small = load_font(13, font_dir=str(font_dir))
    d.text((MARGIN + 130, 92), record["company_address"], fill=MUTED_COLOR, font=small)
    d.text((MARGIN + 130, 112), f"Document No.: {record['doc_no']}", fill=MUTED_COLOR, font=small)
    d.line([(MARGIN, 168), (RIGHT_X, 168)], fill=RULE_COLOR, width=2)

    _center(d, 184, record["statement_title"], load_font(20, bold=True, font_dir=str(font_dir)))
    _center(d, 212, record["period"], load_font(14, font_dir=str(font_dir)))
    _center(d, 232, "(All amounts in Philippine Peso)", load_font(12, font_dir=str(font_dir)),
            fill=MUTED_COLOR)

    y = 270
    sec = load_font(16, bold=True, font_dir=str(font_dir))
    d.text((MARGIN, y), "ASSETS", fill=TEXT_COLOR, font=sec); y += 26
    y = _line_item(d, y, "Current Assets", None, bold=True, font_dir=font_dir)
    y = _line_item(d, y, "Cash and Cash Equivalents", f["cash"], indent=24, font_dir=font_dir)
    y = _line_item(d, y, "Trade and Other Receivables", f["receivables"], indent=24, font_dir=font_dir)
    y = _line_item(d, y, "Inventories", f["inventories"], indent=24, font_dir=font_dir)
    y = _line_item(d, y, "Total Current Assets", f["current_assets"], indent=24, bold=True,
                   rule_above=True, font_dir=font_dir)
    y = _line_item(d, y, "Non-Current Assets", None, bold=True, font_dir=font_dir)
    y = _line_item(d, y, "Property, Plant and Equipment", f["ppe"], indent=24, font_dir=font_dir)
    y = _line_item(d, y, "Other Non-Current Assets", f["other_noncurrent_assets"], indent=24, font_dir=font_dir)
    y = _line_item(d, y, "Total Non-Current Assets", f["noncurrent_assets"], indent=24, bold=True,
                   rule_above=True, font_dir=font_dir)
    y = _line_item(d, y, "TOTAL ASSETS", f["total_assets"], bold=True, rule_above=True, font_dir=font_dir)
    d.line([(MARGIN + 360, y - 2), (RIGHT_X, y - 2)], fill=RULE_COLOR, width=1)
    y += 16

    d.text((MARGIN, y), "LIABILITIES AND EQUITY", fill=TEXT_COLOR, font=sec); y += 26
    y = _line_item(d, y, "Current Liabilities", None, bold=True, font_dir=font_dir)
    y = _line_item(d, y, "Trade and Other Payables", f["payables"], indent=24, font_dir=font_dir)
    y = _line_item(d, y, "Short-Term Loans Payable", f["short_term_loans"], indent=24, font_dir=font_dir)
    y = _line_item(d, y, "Total Current Liabilities", f["current_liabilities"], indent=24, bold=True,
                   rule_above=True, font_dir=font_dir)
    y = _line_item(d, y, "Non-Current Liabilities", None, bold=True, font_dir=font_dir)
    y = _line_item(d, y, "Long-Term Debt", f["long_term_debt"], indent=24, font_dir=font_dir)
    y = _line_item(d, y, "Total Liabilities", f["total_liabilities"], bold=True, rule_above=True, font_dir=font_dir)
    y = _line_item(d, y, "Equity", None, bold=True, font_dir=font_dir)
    y = _line_item(d, y, "Share Capital", f["share_capital"], indent=24, font_dir=font_dir)
    y = _line_item(d, y, "Retained Earnings", f["retained_earnings"], indent=24, font_dir=font_dir)
    y = _line_item(d, y, "Total Equity", f["total_equity"], indent=24, bold=True,
                   rule_above=True, font_dir=font_dir)
    y = _line_item(d, y, "TOTAL LIABILITIES AND EQUITY", f["total_liabilities_and_equity"],
                   bold=True, rule_above=True, font_dir=font_dir)
    d.line([(MARGIN + 360, y - 2), (RIGHT_X, y - 2)], fill=RULE_COLOR, width=1)
    y += 22

    _center(d, y, "STATEMENT OF COMPREHENSIVE INCOME", load_font(16, bold=True, font_dir=str(font_dir)))
    y += 30
    y = _line_item(d, y, "Revenue", f["revenue"], font_dir=font_dir)
    y = _line_item(d, y, "Cost of Sales", f["cost_of_sales"], negate=True, font_dir=font_dir)
    y = _line_item(d, y, "Gross Profit", f["gross_profit"], bold=True, rule_above=True, font_dir=font_dir)
    y = _line_item(d, y, "Operating Expenses", f["operating_expenses"], negate=True, font_dir=font_dir)
    y = _line_item(d, y, "Operating Income", f["operating_income"], bold=True, rule_above=True, font_dir=font_dir)
    y = _line_item(d, y, "Income Tax Expense", f["income_tax"], negate=True, font_dir=font_dir)
    y = _line_item(d, y, "NET INCOME", f["net_income"], bold=True, rule_above=True, font_dir=font_dir)

    # --- Approval block: signature over name + LGU seal ---
    ay = PAGE_H - 205
    d.line([(MARGIN, ay - 18), (RIGHT_X, ay - 18)], fill=RULE_COLOR, width=1)
    d.text((MARGIN, ay), "Reviewed and approved by the Local Government Unit:",
           fill=TEXT_COLOR, font=load_font(13, font_dir=str(font_dir)))

    sig = make_signature(record["officer_name"])
    sig_x, sig_y = MARGIN + 10, ay + 30
    img.alpha_composite(sig, (sig_x, sig_y))
    line_y = sig_y + sig.height - 6
    d.line([(MARGIN + 10, line_y), (MARGIN + 270, line_y)], fill=TEXT_COLOR, width=1)
    d.text((MARGIN + 10, line_y + 6), record["officer_name"].upper(),
           fill=TEXT_COLOR, font=load_font(14, bold=True, font_dir=str(font_dir)))
    d.text((MARGIN + 10, line_y + 28), record["officer_title"],
           fill=MUTED_COLOR, font=load_font(12, font_dir=str(font_dir)))
    d.text((MARGIN + 10, line_y + 46),
           f"{record['lgu_type'].title()} of {record['lgu_name'].title()}, {record['province']}",
           fill=MUTED_COLOR, font=load_font(12, font_dir=str(font_dir)))
    d.text((MARGIN + 10, line_y + 64), f"Date Approved: {record['date_approved']}",
           fill=MUTED_COLOR, font=load_font(12, font_dir=str(font_dir)))

    seal = make_lgu_seal(record, font_dir=font_dir)
    if rng is not None:
        seal = seal.rotate(rng.uniform(-10, 10), expand=True, resample=Image.BICUBIC)
        # Stamped marks are slightly translucent.
        alpha = seal.split()[3].point(lambda a: int(a * 0.85))
        seal.putalpha(alpha)
    # Anchor the seal to the bottom-right so rotation expansion never clips the page.
    seal_x = max(MARGIN, RIGHT_X - seal.width + 8)
    seal_y = max(ay - 40, PAGE_H - 26 - seal.height)
    img.alpha_composite(seal, (seal_x, seal_y))
    return img


# ---------------------------------------------------------------------------
# Scan/photocopy realism (Augraphy if present, else a numpy fallback that still
# genuinely degrades). Never let one bad frame abort the batch.
# ---------------------------------------------------------------------------
def _build_augraphy_pipeline():
    """A GENTLE scan/photocopy pipeline tuned for legibility (augraphy 8.x)."""
    from augraphy import (AugraphyPipeline, Brightness, Gamma, Geometric,
                          InkBleed, Jpeg, SubtleNoise)

    ink_phase = [InkBleed(intensity_range=(0.1, 0.2), kernel_size=(3, 3), severity=(0.1, 0.2))]
    paper_phase = []
    post_phase = [
        Brightness(brightness_range=(0.95, 1.08)),
        Gamma(gamma_range=(0.9, 1.1)),
        SubtleNoise(subtle_range=6),
        Geometric(rotate_range=(-1, 1)),
        Jpeg(quality_range=(80, 95)),
    ]
    return AugraphyPipeline(ink_phase=ink_phase, paper_phase=paper_phase, post_phase=post_phase)


def _numpy_degrade(src: Image.Image, rng: random.Random | None) -> Image.Image:
    """Lightweight scan look without augraphy: slight rotate, noise, brightness."""
    import numpy as np

    r = rng or random.Random()
    rotated = src.rotate(r.uniform(-1.0, 1.0), expand=False, resample=Image.BICUBIC, fillcolor=(255, 255, 255))
    arr = np.asarray(rotated).astype("int16")
    arr += np.random.default_rng(r.randint(0, 2**31)).integers(-7, 8, arr.shape, dtype="int16")
    arr = np.clip(arr * r.uniform(0.96, 1.05), 0, 255).astype("uint8")
    return Image.fromarray(arr).convert("RGB")


def degrade(image: Image.Image, rng: random.Random | None = None) -> Image.Image:
    """Scan/photocopy degradation; numpy fallback (then clean) on any failure."""
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
        log(f"WARN: augraphy unavailable/failed ({exc}); using numpy fallback degradation.")
        try:
            return _numpy_degrade(src, rng)
        except Exception as exc2:  # noqa: BLE001
            log(f"WARN: numpy degradation failed ({exc2}); using clean image as scan.")
            return src


# ---------------------------------------------------------------------------
# Validation, batch orchestration, CLI.
# ---------------------------------------------------------------------------
def validate_assets(font_dir: Path = FONT_DIR) -> list[str]:
    """Human-readable problems blocking generation (empty list = good to go)."""
    problems = []
    try:
        resolve_font(font_dir)
        resolve_font(font_dir, bold=True)
    except FileNotFoundError as exc:
        problems.append(str(exc))
    return problems


def run_batch(count: int, out_dir: Path = OUTPUT_DIR, *,
              variants: tuple[str, ...] = ("clean", "scan"), seed: int | None = None,
              font_dir: Path = FONT_DIR,
              manifest_name: str = "_synthetic_manifest.json") -> dict:
    """Generate `count` unique statements, each emitted in the requested variants,
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
        stem = f"synthetic_fs_{idx:05d}"
        clean = render_statement(record, font_dir=font_dir, rng=rng)
        files: list[str] = []
        if "clean" in variants:
            fp = out_dir / f"{stem}_clean.png"
            clean.convert("RGB").save(fp)
            files.append(fp.name)
        if "scan" in variants:
            fp = out_dir / f"{stem}_scan.jpg"
            degrade(clean.convert("RGB"), rng).save(fp, quality=85)
            files.append(fp.name)
        register_record(manifest, record, files)
        written.extend(files)
        if (n + 1) % 25 == 0:
            log(f"generated {n + 1}/{count} statements")

    save_manifest(manifest_path, manifest)
    return {"count": count, "files": written, "manifest": str(manifest_path),
            "next_index": manifest["next_index"]}


def parse_args(argv=None):
    p = argparse.ArgumentParser(description="Generate synthetic Financial Statement training images.")
    p.add_argument("--count", type=int, default=200,
                   help="base statements per run (each -> clean + scan = 2 files). Default 200.")
    p.add_argument("--out-dir", default=str(OUTPUT_DIR))
    p.add_argument("--seed", type=int, default=None, help="reproducible run (use a fresh out-dir).")
    p.add_argument("--clean-only", action="store_true", help="emit only the clean PNG.")
    p.add_argument("--scan-only", action="store_true", help="emit only the degraded JPG.")
    p.add_argument("--font-dir", default=str(FONT_DIR))
    p.add_argument("--dry-run", action="store_true",
                   help="validate fonts + render one statement in memory; write nothing.")
    return p.parse_args(argv)


def main(argv=None) -> int:
    args = parse_args(argv)
    font_dir = Path(args.font_dir)

    problems = validate_assets(font_dir)
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
        img = render_statement(record, font_dir=font_dir, rng=rng)
        f = record["financials"]
        assert f["total_assets"] == f["total_liabilities_and_equity"], "balance sheet must foot"
        log(f"DRY RUN ok - rendered 1 statement in memory at {img.size}, wrote nothing.")
        log(f"company={record['company_name']} | assets={f['total_assets']:,} "
            f"| officer={record['officer_name']} ({record['officer_title']})")
        return 0

    variants = ("clean", "scan")
    if args.clean_only:
        variants = ("clean",)
    elif args.scan_only:
        variants = ("scan",)

    summary = run_batch(args.count, out_dir=Path(args.out_dir), variants=variants,
                        seed=args.seed, font_dir=font_dir)
    log(f"wrote {len(summary['files'])} files ({args.count} base statements) -> {args.out_dir}")
    log(f"manifest -> {summary['manifest']} (next_index={summary['next_index']})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
