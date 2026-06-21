# BIR Certificate Synthetic Dataset Generator — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `python/scripts/bir_dataset_generator.py`, a standalone CLI that fills the blank BIR Form 2303 template with OCR-realistic synthetic field data + composited dry seal and officer signature, emitting clean **and** scan-degraded labelled images into the ResNet-50 classifier's `bir_certificate` class folder, while a JSON ledger prevents duplicate data across runs.

**Architecture:** Pillow renders text into the 15 calibrated bounding boxes (Courier Prime, size 12→11 auto-fit with wrapping) and alpha-composites the two image regions (white keyed to transparency). Faker `en_PH` produces field values constrained to the OCR regexes in `ocr_dryrun.py`. Each base certificate is emitted as a clean PNG and an Augraphy-degraded JPG. A per-folder `_synthetic_manifest.json` records every record's TIN + content-hash + filenames so reruns never duplicate data and filenames keep incrementing. Pure logic (record generation, hashing, manifest, font-fit, alpha) is separated from I/O so it is unit-testable without writing the real dataset.

**Tech Stack:** Python 3.12 (venv at `python/env/`), Pillow 12.2, NumPy, Faker (`en_PH`), Augraphy 8.2.6, pytest 8.

## Global Constraints

- **Interpreter:** always `python/env/Scripts/python.exe` — never bare `python` (PATH resolves to an MSYS2 build with no stack).
- **Target script:** `python/scripts/bir_dataset_generator.py` (currently empty — fill it).
- **Output folder (flat):** `python/data/training/classifier_data/bir_certificate/`. It already holds real images — **never overwrite or collide**; all generated files use the `synthetic_bir_` prefix.
- **Batch size:** `--count` default **100 base certificates per run**. Both variants ⇒ **200 image files per run** (100 `*_clean.png` + 100 `*_scan.jpg`).
- **Duplication ledger:** `_synthetic_manifest.json` in the output folder tracks `used_tins`, `used_hashes`, `records`, and `next_index`. Reruns generate only novel records and continue filename numbering.
- **Font:** Courier Prime (SIL OFL), vendored + committed under `python/data/fonts/`. Prefer size **12**, then **11**; only shrink below 11 or wrap when a value cannot fit at 11.
- **Template:** `python/data/template/BIR_PERMIT_TEMPLATE.png` — 700×887 RGBA. Boxes are absolute pixels in that space.
- **Image assets (both RGB, white background → key white to alpha):** dry seal `python/data/seal/BIR_SEAL.png`; officer signature `python/data/stamps/BIR_OFFICER_STAMP.png`.
- **Field realism:** every generated value must satisfy the corresponding regex in `python/scripts/ocr_dryrun.py` `FIELD_SPECS` (`form_no`=2303, `tin`, `ocn`, `registration_date`, `date_issued`, `revenue_region_no`, `rdo_code`).
- **Tests:** pytest, loaded by path via `importlib.util` (repo convention — see `python/tests/test_ocr_dryrun.py`). Tests must **never write into the real data folder** — use `tmp_path`.
- **Console output:** ASCII only in `print()` (Windows cp1252 console mojibakes non-ASCII).
- **`.gitignore`:** `python/data/training/**` is ignored, so generated images + manifest are NOT committed (correct). `python/data/fonts/` is NOT ignored, so the font IS committed.

---

## File Structure

- **Create + commit:** `python/data/fonts/CourierPrime-Regular.ttf`, `python/data/fonts/CourierPrime-Bold.ttf`, `python/data/fonts/OFL.txt` — vendored font (one responsibility: the render typeface + its license).
- **Fill:** `python/scripts/bir_dataset_generator.py` — the generator. Single module, sectioned: constants/boxes → font → record generation → manifest → text rendering → asset compositing → certificate render → degradation → batch/CLI.
- **Create:** `python/tests/test_bir_dataset_generator.py` — pure-logic + small-batch tests (uses `tmp_path`, never the real folder).
- **Runtime output (gitignored):** `python/data/training/classifier_data/bir_certificate/synthetic_bir_NNNNN_clean.png`, `..._scan.jpg`, `_synthetic_manifest.json`.

**Module public API (names are fixed across tasks):**

```
Constants: PY_ROOT, TEMPLATE_PATH, SEAL_PATH, SIGNATURE_PATH, FONT_DIR, OUTPUT_DIR,
           FIELD_BOXES, IMAGE_FIELD_ASSETS, TEXT_FIELD_KEYS,
           PREFERRED_FONT_SIZES=(12,11), MIN_FONT_SIZE=8, WHITE_KEY_THRESHOLD=235, TEXT_COLOR
resolve_font(font_dir=FONT_DIR, *, bold=False) -> Path
load_font(size:int, bold=False, font_dir=str(FONT_DIR)) -> ImageFont.FreeTypeFont   # lru_cache
boxes_out_of_bounds(template_size:tuple[int,int], boxes=FIELD_BOXES) -> list[str]
generate_record(faker, rng) -> dict[str,str]
record_hash(record:dict[str,str]) -> str
load_manifest(path) -> dict ; save_manifest(path, manifest) -> None
generate_unique_record(faker, rng, manifest, max_tries=1000) -> dict
register_record(manifest, record, files:list[str]) -> int
fit_text(text, box_w, box_h, font_dir=FONT_DIR, sizes=PREFERRED_FONT_SIZES, min_size=MIN_FONT_SIZE) -> tuple[int,list[str]]
draw_text_in_box(draw, text, box, font_dir=FONT_DIR, rng=None) -> None
build_alpha(asset_rgb, threshold=WHITE_KEY_THRESHOLD) -> PIL.Image  # mode "L"
paste_asset(base, asset_path, box, rng=None) -> None
render_certificate(record, *, template_path=TEMPLATE_PATH, assets=IMAGE_FIELD_ASSETS, font_dir=FONT_DIR, rng=None) -> PIL.Image  # RGB
degrade(image, rng=None) -> PIL.Image  # RGB
validate_assets(template_path=TEMPLATE_PATH, assets=IMAGE_FIELD_ASSETS, font_dir=FONT_DIR) -> list[str]
run_batch(count, out_dir=OUTPUT_DIR, *, variants=("clean","scan"), seed=None, template_path=TEMPLATE_PATH, assets=IMAGE_FIELD_ASSETS, font_dir=FONT_DIR, manifest_name="_synthetic_manifest.json") -> dict
parse_args(argv=None) ; main(argv=None) -> int
```

---

### Task 1: Vendor the Courier Prime font + ready the test runner

**Files:**
- Create: `python/data/fonts/CourierPrime-Regular.ttf`, `python/data/fonts/CourierPrime-Bold.ttf`, `python/data/fonts/OFL.txt`
- Test: `python/tests/test_bir_dataset_generator.py` (first test only)

**Interfaces:**
- Produces: the committed font files every later rendering task depends on; a working `python/env/Scripts/python.exe -m pytest` invocation.

- [ ] **Step 1: Install pytest into the venv (dev tool; do not edit requirements.txt)**

Run:
```bash
python/env/Scripts/python.exe -m pip install pytest
```
Expected: ends with `Successfully installed ... pytest-8.x ...` (or "Requirement already satisfied").

- [ ] **Step 2: Fetch Courier Prime (SIL OFL) from the Google Fonts repo**

Run (PowerShell):
```powershell
$dir = "C:\xampp\htdocs\projects\advs\python\data\fonts"
New-Item -ItemType Directory -Force $dir | Out-Null
$base = "https://github.com/google/fonts/raw/main/ofl/courierprime"
Invoke-WebRequest "$base/CourierPrime-Regular.ttf" -OutFile "$dir\CourierPrime-Regular.ttf"
Invoke-WebRequest "$base/CourierPrime-Bold.ttf"    -OutFile "$dir\CourierPrime-Bold.ttf"
Invoke-WebRequest "$base/OFL.txt"                  -OutFile "$dir\OFL.txt"
Get-ChildItem $dir | Select-Object Name, Length
```
Expected: three files listed, each `.ttf` > 80 000 bytes.

- [ ] **Step 3: Write the failing test (font is present and loadable at size 12)**

Create `python/tests/test_bir_dataset_generator.py`:
```python
"""Unit + small-batch tests for scripts/bir_dataset_generator.py.

Pure logic (record generation, hashing, manifest dedup, font-fit, alpha keying)
is exercised in isolation; the batch test writes only into tmp_path, never the
real classifier folder. The module is loaded by path (repo convention).
"""
import importlib.util
import re
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

_SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "bir_dataset_generator.py"
_spec = importlib.util.spec_from_file_location("bir_dataset_generator", _SCRIPT)
gen = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(gen)

FONT_DIR = Path(__file__).resolve().parents[1] / "data" / "fonts"


def test_courier_prime_is_vendored_and_loadable():
    path = FONT_DIR / "CourierPrime-Regular.ttf"
    assert path.exists(), f"vendor Courier Prime into {FONT_DIR}"
    font = ImageFont.truetype(str(path), 12)
    assert font.size == 12
```

- [ ] **Step 4: Run the test — expect FAIL (module is empty / has no loadable content yet)**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -v
```
Expected: FAIL — the empty target script makes `exec_module` produce a module with nothing, but the import line itself succeeds; the test fails on the assertion only if the font is missing. If the font downloaded in Step 2, this single test PASSES. (The import of an empty module does not error.) Treat **PASS** here as success for Task 1.

- [ ] **Step 5: Commit**

```bash
git add python/data/fonts/CourierPrime-Regular.ttf python/data/fonts/CourierPrime-Bold.ttf python/data/fonts/OFL.txt python/tests/test_bir_dataset_generator.py
git commit -m "chore(ml): vendor Courier Prime (OFL) for BIR dataset generator"
```

---

### Task 2: Module skeleton — constants, boxes, font resolution

**Files:**
- Modify: `python/scripts/bir_dataset_generator.py` (create the header + constants + font helpers)
- Test: `python/tests/test_bir_dataset_generator.py`

**Interfaces:**
- Consumes: vendored font from Task 1.
- Produces: `FIELD_BOXES` (15 keys), `IMAGE_FIELD_ASSETS`, `TEXT_FIELD_KEYS`, `resolve_font`, `load_font`, `boxes_out_of_bounds`, `log`.

- [ ] **Step 1: Write the failing tests**

Append to `python/tests/test_bir_dataset_generator.py`:
```python
def test_field_boxes_cover_all_fifteen_regions():
    expected = {
        "form_no", "ocn", "tin", "registered_name", "registration_date",
        "registered_address", "revenue_region_no", "rdo_code", "line_of_business",
        "trade_name", "tax_types", "revenue_district_officer",
        "signature_over_name", "dry_seal", "date_issued",
    }
    assert set(gen.FIELD_BOXES) == expected
    assert set(gen.IMAGE_FIELD_ASSETS) == {"dry_seal", "signature_over_name"}
    assert "dry_seal" not in gen.TEXT_FIELD_KEYS
    assert "tin" in gen.TEXT_FIELD_KEYS


def test_all_boxes_fit_inside_the_template():
    assert gen.boxes_out_of_bounds((700, 887)) == []


def test_resolve_font_returns_the_vendored_ttf():
    assert gen.resolve_font(FONT_DIR).name == "CourierPrime-Regular.ttf"
    assert gen.load_font(12, font_dir=str(FONT_DIR)).size == 12
```

- [ ] **Step 2: Run — expect FAIL**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -v
```
Expected: FAIL with `AttributeError: module 'bir_dataset_generator' has no attribute 'FIELD_BOXES'`.

- [ ] **Step 3: Write the implementation**

Write `python/scripts/bir_dataset_generator.py`:
```python
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

from PIL import Image, ImageDraw, ImageFont

PY_ROOT = Path(__file__).resolve().parents[1]            # .../python
TEMPLATE_PATH = PY_ROOT / "data" / "template" / "BIR_PERMIT_TEMPLATE.png"
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
```

- [ ] **Step 4: Run — expect PASS**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -v
```
Expected: 4 passed.

- [ ] **Step 5: Commit**

```bash
git add python/scripts/bir_dataset_generator.py python/tests/test_bir_dataset_generator.py
git commit -m "feat(ml): scaffold BIR dataset generator (boxes, font resolution)"
```

---

### Task 3: Field-value generation + content hashing

**Files:**
- Modify: `python/scripts/bir_dataset_generator.py`
- Test: `python/tests/test_bir_dataset_generator.py`

**Interfaces:**
- Consumes: nothing new.
- Produces: `generate_record(faker, rng) -> dict[str,str]` (keys = `TEXT_FIELD_KEYS`), `record_hash(record) -> str`. Values satisfy the `ocr_dryrun.py` regexes.

- [ ] **Step 1: Write the failing tests**

Append to the test file:
```python
import random as _random
from faker import Faker


def _fresh_faker():
    f = Faker("en_PH")
    Faker.seed(12345)
    return f, _random.Random(12345)


def test_generated_values_match_ocr_regexes():
    faker, rng = _fresh_faker()
    rec = gen.generate_record(faker, rng)
    assert set(rec) == set(gen.TEXT_FIELD_KEYS)
    assert rec["form_no"] == "2303"
    assert re.fullmatch(r"\d{3}-\d{3}-\d{3}-\d{3,4}", rec["tin"])
    assert re.search(r"\d[A-Z]{1,3}\d{7,}", rec["ocn"])
    assert re.fullmatch(r"\d{1,2}/\d{1,2}/\d{2,4}", rec["registration_date"])
    assert re.fullmatch(r"\d{1,3}[A-Z]?", rec["revenue_region_no"])
    assert re.fullmatch(r"\d{1,3}", rec["rdo_code"])
    assert re.search(
        r"\b(?:JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)\.?\s+\d{1,2},?\s+(?:19|20)\d{2}\b",
        rec["date_issued"],
    )
    assert rec["registered_name"] and rec["registered_address"]


def test_record_hash_is_stable_and_order_independent():
    a = {"tin": "1", "form_no": "2303"}
    b = {"form_no": "2303", "tin": "1"}
    assert gen.record_hash(a) == gen.record_hash(b)
    assert gen.record_hash(a) != gen.record_hash({"tin": "2", "form_no": "2303"})


def test_many_records_have_unique_tins():
    faker, rng = _fresh_faker()
    tins = {gen.generate_record(faker, rng)["tin"] for _ in range(300)}
    assert len(tins) > 290  # near-unique; dedup layer (Task 4) guarantees the rest
```

- [ ] **Step 2: Run — expect FAIL**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "generated_values or record_hash or unique_tins" -v
```
Expected: FAIL with `AttributeError: ... has no attribute 'generate_record'`.

- [ ] **Step 3: Write the implementation**

Append to `python/scripts/bir_dataset_generator.py`:
```python
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
```

- [ ] **Step 4: Run — expect PASS**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "generated_values or record_hash or unique_tins" -v
```
Expected: 3 passed.

- [ ] **Step 5: Commit**

```bash
git add python/scripts/bir_dataset_generator.py python/tests/test_bir_dataset_generator.py
git commit -m "feat(ml): OCR-realistic field-value generation + content hashing"
```

---

### Task 4: Manifest ledger + duplication guard

**Files:**
- Modify: `python/scripts/bir_dataset_generator.py`
- Test: `python/tests/test_bir_dataset_generator.py`

**Interfaces:**
- Consumes: `generate_record`, `record_hash`.
- Produces: `load_manifest(path)`, `save_manifest(path, manifest)`, `generate_unique_record(faker, rng, manifest, max_tries=1000)`, `register_record(manifest, record, files) -> int`. Manifest dict shape: `{"version", "next_index", "records", "used_tins", "used_hashes"}`.

- [ ] **Step 1: Write the failing tests**

Append to the test file:
```python
def test_manifest_roundtrip_and_increment(tmp_path):
    path = tmp_path / "_synthetic_manifest.json"
    m = gen.load_manifest(path)
    assert m["next_index"] == 1 and m["records"] == []
    idx = gen.register_record(m, {"tin": "111-111-111-0000", "form_no": "2303"},
                              ["synthetic_bir_00001_clean.png"])
    assert idx == 1 and m["next_index"] == 2
    gen.save_manifest(path, m)

    reloaded = gen.load_manifest(path)
    assert reloaded["next_index"] == 2
    assert reloaded["used_tins"] == ["111-111-111-0000"]
    assert len(reloaded["used_hashes"]) == 1


def test_unique_record_never_reuses_a_known_tin(tmp_path):
    faker, rng = _fresh_faker()
    m = gen.load_manifest(tmp_path / "m.json")
    # Pre-load the manifest with the TIN the seeded generator will produce first.
    faker2, rng2 = _fresh_faker()
    first = gen.generate_record(faker2, rng2)
    gen.register_record(m, first, ["x.png"])
    # The same seed would reproduce `first`; dedup must skip it and return a new one.
    nxt = gen.generate_unique_record(faker, rng, m)
    assert nxt["tin"] != first["tin"]
```

- [ ] **Step 2: Run — expect FAIL**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "manifest_roundtrip or unique_record_never" -v
```
Expected: FAIL with `AttributeError: ... has no attribute 'load_manifest'`.

- [ ] **Step 3: Write the implementation**

Append to `python/scripts/bir_dataset_generator.py`:
```python
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
```

- [ ] **Step 4: Run — expect PASS**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "manifest_roundtrip or unique_record_never" -v
```
Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
git add python/scripts/bir_dataset_generator.py python/tests/test_bir_dataset_generator.py
git commit -m "feat(ml): manifest ledger + cross-run duplication guard"
```

---

### Task 5: Font-fit + text rendering into a box

**Files:**
- Modify: `python/scripts/bir_dataset_generator.py`
- Test: `python/tests/test_bir_dataset_generator.py`

**Interfaces:**
- Consumes: `load_font`, `FIELD_BOXES`.
- Produces: `fit_text(text, box_w, box_h, ...) -> (size, lines)` (size ∈ candidate set, block fits when possible) and `draw_text_in_box(draw, text, box, font_dir=FONT_DIR, rng=None)`.

- [ ] **Step 1: Write the failing tests**

Append to the test file:
```python
def test_fit_text_short_value_uses_preferred_size():
    size, lines = gen.fit_text("123-456-789-0000", 171, 28, font_dir=FONT_DIR)
    assert size in (12, 11)
    assert lines == ["123-456-789-0000"]


def test_fit_text_long_value_wraps_and_fits_a_tall_box():
    box = gen.FIELD_BOXES["registered_address"]   # 674 x 43
    text = "1234 EXAMPLE STREET, BARANGAY SAMPLE, QUEZON CITY, METRO MANILA, 1100"
    size, lines = gen.fit_text(text, box["w"] - 4, box["h"] - 4, font_dir=FONT_DIR)
    scratch = ImageDraw.Draw(Image.new("RGB", (box["w"], box["h"])))
    font = gen.load_font(size, font_dir=str(FONT_DIR))
    for ln in lines:
        l, t, r, b = scratch.textbbox((0, 0), ln, font=font)
        assert (r - l) <= box["w"] - 4


def test_draw_text_in_box_marks_pixels():
    img = Image.new("RGB", (700, 887), "white")
    draw = ImageDraw.Draw(img)
    gen.draw_text_in_box(draw, "HELLO 2303", gen.FIELD_BOXES["form_no"], font_dir=FONT_DIR)
    assert img.getcolors(maxcolors=1_000_000) is None or img.getextrema() != ((255, 255), (255, 255), (255, 255))
```

- [ ] **Step 2: Run — expect FAIL**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "fit_text or draw_text_in_box" -v
```
Expected: FAIL with `AttributeError: ... has no attribute 'fit_text'`.

- [ ] **Step 3: Write the implementation**

Append to `python/scripts/bir_dataset_generator.py`:
```python
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
```

- [ ] **Step 4: Run — expect PASS**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "fit_text or draw_text_in_box" -v
```
Expected: 3 passed.

- [ ] **Step 5: Commit**

```bash
git add python/scripts/bir_dataset_generator.py python/tests/test_bir_dataset_generator.py
git commit -m "feat(ml): Courier Prime box-fit text rendering (12->11, wrap)"
```

---

### Task 6: Asset compositing (white-keyed seal + signature)

**Files:**
- Modify: `python/scripts/bir_dataset_generator.py`
- Test: `python/tests/test_bir_dataset_generator.py`

**Interfaces:**
- Consumes: `FIELD_BOXES`, `IMAGE_FIELD_ASSETS`.
- Produces: `build_alpha(asset_rgb, threshold=WHITE_KEY_THRESHOLD) -> Image("L")` and `paste_asset(base, asset_path, box, rng=None)` (composites onto an RGBA `base` in place).

- [ ] **Step 1: Write the failing tests**

Append to the test file:
```python
def test_build_alpha_keys_white_transparent_and_ink_opaque():
    swatch = Image.new("RGB", (2, 1))
    swatch.putpixel((0, 0), (255, 255, 255))   # white
    swatch.putpixel((1, 0), (10, 10, 10))      # ink
    alpha = gen.build_alpha(swatch)
    assert alpha.mode == "L"
    assert alpha.getpixel((0, 0)) == 0          # white -> transparent
    assert alpha.getpixel((1, 0)) > 200         # ink -> opaque


def test_paste_asset_composites_into_the_box_region():
    base = Image.new("RGBA", (700, 887), (255, 255, 255, 255))
    before = base.copy()
    gen.paste_asset(base, gen.SEAL_PATH, gen.FIELD_BOXES["dry_seal"])
    assert base.size == (700, 887)
    box = gen.FIELD_BOXES["dry_seal"]
    crop = base.crop((box["x"], box["y"], box["x"] + box["w"], box["y"] + box["h"]))
    crop_before = before.crop((box["x"], box["y"], box["x"] + box["w"], box["y"] + box["h"]))
    assert list(crop.getdata()) != list(crop_before.getdata())
```

- [ ] **Step 2: Run — expect FAIL**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "build_alpha or paste_asset" -v
```
Expected: FAIL with `AttributeError: ... has no attribute 'build_alpha'`.

- [ ] **Step 3: Write the implementation**

Append to `python/scripts/bir_dataset_generator.py`:
```python
# ---------------------------------------------------------------------------
# Image regions: the seal + signature PNGs are ink on a white background (RGB,
# no alpha). Key near-white to transparent so only the ink composites.
# ---------------------------------------------------------------------------
def build_alpha(asset_rgb: Image.Image, threshold: int = WHITE_KEY_THRESHOLD) -> Image.Image:
    """L-mode alpha: white/near-white -> 0 (transparent), darker ink -> opaque."""
    import numpy as np

    arr = np.asarray(asset_rgb.convert("RGB")).astype(np.int16)
    lum = arr.mean(axis=2)
    alpha = np.clip((threshold - lum) * (255.0 / max(1, threshold)), 0, 255).astype("uint8")
    return Image.fromarray(alpha, mode="L")


def paste_asset(base: Image.Image, asset_path: Path, box: dict,
                rng: random.Random | None = None) -> None:
    """Scale an asset to fit `box` (aspect-preserved, centered, light jitter) and
    alpha-composite it onto an RGBA `base` in place."""
    asset = Image.open(asset_path).convert("RGB")
    asset.putalpha(build_alpha(asset))

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
```

- [ ] **Step 4: Run — expect PASS**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "build_alpha or paste_asset" -v
```
Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
git add python/scripts/bir_dataset_generator.py python/tests/test_bir_dataset_generator.py
git commit -m "feat(ml): white-keyed seal + signature compositing"
```

---

### Task 7: Render one complete clean certificate

**Files:**
- Modify: `python/scripts/bir_dataset_generator.py`
- Test: `python/tests/test_bir_dataset_generator.py`

**Interfaces:**
- Consumes: `generate_record`, `draw_text_in_box`, `paste_asset`, `TEXT_FIELD_KEYS`, `IMAGE_FIELD_ASSETS`.
- Produces: `render_certificate(record, *, template_path=TEMPLATE_PATH, assets=IMAGE_FIELD_ASSETS, font_dir=FONT_DIR, rng=None) -> Image` (RGB, template-sized).

- [ ] **Step 1: Write the failing test**

Append to the test file:
```python
def test_render_certificate_fills_text_and_image_regions():
    faker, rng = _fresh_faker()
    rec = gen.generate_record(faker, rng)
    blank = Image.open(gen.TEMPLATE_PATH).convert("RGB")
    out = gen.render_certificate(rec, font_dir=FONT_DIR)
    assert out.mode == "RGB" and out.size == blank.size

    def region_changed(key):
        b = gen.FIELD_BOXES[key]
        crop_a = blank.crop((b["x"], b["y"], b["x"] + b["w"], b["y"] + b["h"]))
        crop_b = out.crop((b["x"], b["y"], b["x"] + b["w"], b["y"] + b["h"]))
        return list(crop_a.getdata()) != list(crop_b.getdata())

    assert region_changed("tin")            # a text field was drawn
    assert region_changed("dry_seal")       # the seal was composited
    assert region_changed("signature_over_name")
```

- [ ] **Step 2: Run — expect FAIL**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "render_certificate" -v
```
Expected: FAIL with `AttributeError: ... has no attribute 'render_certificate'`.

- [ ] **Step 3: Write the implementation**

Append to `python/scripts/bir_dataset_generator.py`:
```python
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
        paste_asset(base, asset_path, FIELD_BOXES[key], rng=rng)
    return base.convert("RGB")
```

- [ ] **Step 4: Run — expect PASS**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "render_certificate" -v
```
Expected: 1 passed.

- [ ] **Step 5: Commit**

```bash
git add python/scripts/bir_dataset_generator.py python/tests/test_bir_dataset_generator.py
git commit -m "feat(ml): render complete clean BIR certificate"
```

---

### Task 8: Augraphy scan/photocopy degradation

**Files:**
- Modify: `python/scripts/bir_dataset_generator.py`
- Test: `python/tests/test_bir_dataset_generator.py`

**Interfaces:**
- Consumes: a clean `Image` from `render_certificate`.
- Produces: `degrade(image, rng=None) -> Image` (RGB, same size; falls back to the clean image if the Augraphy pipeline errors).

- [ ] **Step 1: Write the failing tests**

Append to the test file:
```python
def test_degrade_returns_same_size_rgb_and_changes_pixels():
    clean = Image.new("RGB", (240, 320), "white")
    d = ImageDraw.Draw(clean)
    d.rectangle((20, 20, 200, 80), outline="black")
    d.text((30, 100), "BIR 2303 SAMPLE", fill=(0, 0, 0))
    out = gen.degrade(clean, _random.Random(7))
    assert out.mode == "RGB" and out.size == clean.size
    assert list(out.getdata()) != list(clean.getdata())


def test_degrade_falls_back_to_clean_on_pipeline_error(monkeypatch):
    clean = Image.new("RGB", (64, 64), "white")
    monkeypatch.setattr(gen, "_build_augraphy_pipeline", lambda: (_ for _ in ()).throw(RuntimeError("boom")))
    out = gen.degrade(clean)
    assert out.size == clean.size and out.mode == "RGB"
```

- [ ] **Step 2: Run — expect FAIL**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "degrade" -v
```
Expected: FAIL with `AttributeError: ... has no attribute 'degrade'`.

- [ ] **Step 3: Write the implementation**

Append to `python/scripts/bir_dataset_generator.py`:
```python
# ---------------------------------------------------------------------------
# Scan/photocopy realism (Augraphy). Imported lazily so the pure-logic tests and
# --dry-run do not pay the heavy import. Any pipeline failure falls back to the
# clean image so a single bad frame can't abort a 100-doc batch.
# ---------------------------------------------------------------------------
def _build_augraphy_pipeline():
    """A modest ink/post pipeline. Verified import set on augraphy 8.2.6."""
    from augraphy import (AugraphyPipeline, Brightness, Gamma, Geometric,
                          InkBleed, Jpeg, SubtleNoise)

    ink_phase = [InkBleed()]
    paper_phase = []
    post_phase = [Brightness(), Gamma(), SubtleNoise(),
                  Geometric(rotate_range=(-2, 2)), Jpeg()]
    return AugraphyPipeline(ink_phase=ink_phase, paper_phase=paper_phase,
                            post_phase=post_phase)


def degrade(image: Image.Image, rng: random.Random | None = None) -> Image.Image:
    """Augraphy scan/photocopy degradation; clean image on any failure."""
    try:
        import numpy as np

        pipeline = _build_augraphy_pipeline()
        arr = np.asarray(image.convert("RGB"))
        result = pipeline(arr)
        out = result["output"] if isinstance(result, dict) else result
        out = np.asarray(out).astype("uint8")
        if out.ndim == 2:
            out = np.stack([out] * 3, axis=-1)
        return Image.fromarray(out[:, :, :3]).convert("RGB")
    except Exception as exc:  # noqa: BLE001 - never let one frame kill the batch
        log(f"WARN: augraphy degradation failed ({exc}); using clean image as scan.")
        return image.convert("RGB")
```

- [ ] **Step 4: Run — expect PASS**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "degrade" -v
```
Expected: 2 passed. (If `test_degrade_..._changes_pixels` fails because every default-`p` augmentation rolled "off", set explicit always-on probabilities: `Jpeg(p=1.0)` and `SubtleNoise(p=1.0)` in `_build_augraphy_pipeline`, then re-run.)

- [ ] **Step 5: Commit**

```bash
git add python/scripts/bir_dataset_generator.py python/tests/test_bir_dataset_generator.py
git commit -m "feat(ml): Augraphy scan degradation with clean-image fallback"
```

---

### Task 9: CLI, batch orchestration, `--dry-run`

**Files:**
- Modify: `python/scripts/bir_dataset_generator.py`
- Test: `python/tests/test_bir_dataset_generator.py`

**Interfaces:**
- Consumes: everything above.
- Produces: `validate_assets(...) -> list[str]`, `run_batch(count, out_dir=OUTPUT_DIR, *, variants=("clean","scan"), seed=None, ...) -> dict`, `parse_args(argv)`, `main(argv) -> int`.

- [ ] **Step 1: Write the failing tests**

Append to the test file:
```python
def test_run_batch_writes_variants_and_manifest(tmp_path):
    out = tmp_path / "bir_certificate"
    summary = gen.run_batch(2, out_dir=out, seed=99, font_dir=FONT_DIR)
    pngs = sorted(out.glob("synthetic_bir_*_clean.png"))
    jpgs = sorted(out.glob("synthetic_bir_*_scan.jpg"))
    assert len(pngs) == 2 and len(jpgs) == 2
    assert (out / "_synthetic_manifest.json").exists()
    assert summary["count"] == 2 and summary["next_index"] == 3


def test_second_run_appends_without_duplicates(tmp_path):
    out = tmp_path / "bir_certificate"
    gen.run_batch(2, out_dir=out, seed=1, font_dir=FONT_DIR)
    gen.run_batch(2, out_dir=out, seed=2, font_dir=FONT_DIR)
    manifest = gen.load_manifest(out / "_synthetic_manifest.json")
    assert manifest["next_index"] == 5
    assert len(manifest["used_tins"]) == len(set(manifest["used_tins"]))  # no dup TINs
    assert sorted(p.name for p in out.glob("synthetic_bir_0000*_clean.png")) == [
        "synthetic_bir_00001_clean.png", "synthetic_bir_00002_clean.png",
        "synthetic_bir_00003_clean.png", "synthetic_bir_00004_clean.png",
    ]


def test_dry_run_writes_nothing(tmp_path):
    out = tmp_path / "bir_certificate"
    out.mkdir()
    rc = gen.main(["--dry-run", "--out-dir", str(out), "--font-dir", str(FONT_DIR)])
    assert rc == 0
    assert list(out.iterdir()) == []
```

- [ ] **Step 2: Run — expect FAIL**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "run_batch or second_run or dry_run" -v
```
Expected: FAIL with `AttributeError: ... has no attribute 'run_batch'`.

- [ ] **Step 3: Write the implementation**

Append to `python/scripts/bir_dataset_generator.py`:
```python
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
        faker = Faker("en_PH"); Faker.seed(0)
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
```

- [ ] **Step 4: Run — expect PASS**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -k "run_batch or second_run or dry_run" -v
```
Expected: 3 passed.

- [ ] **Step 5: Run the whole test file + compile check**

Run:
```bash
python/env/Scripts/python.exe -m pytest python/tests/test_bir_dataset_generator.py -v
python/env/Scripts/python.exe -m py_compile python/scripts/bir_dataset_generator.py
```
Expected: all tests pass; compile is silent (exit 0).

- [ ] **Step 6: Commit**

```bash
git add python/scripts/bir_dataset_generator.py python/tests/test_bir_dataset_generator.py
git commit -m "feat(ml): CLI + batch orchestration + dry-run for BIR generator"
```

---

### Task 10: Generate the first real batch + eyeball QA

**Files:**
- Output (gitignored): `python/data/training/classifier_data/bir_certificate/synthetic_bir_*.{png,jpg}` + `_synthetic_manifest.json`

**Interfaces:**
- Consumes: the finished, tested generator.

- [ ] **Step 1: Dry-run against the real assets**

Run:
```bash
python/env/Scripts/python.exe python/scripts/bir_dataset_generator.py --dry-run
```
Expected: `[bir-gen] DRY RUN ok - rendered 1 certificate in memory at (700, 887), wrote nothing.` (exit 0).

- [ ] **Step 2: Generate a small visual sample (10) and inspect**

Run:
```bash
python/env/Scripts/python.exe python/scripts/bir_dataset_generator.py --count 10
```
Expected: `[bir-gen] wrote 20 files (10 base certs) -> ...bir_certificate`.

Open 2–3 `synthetic_bir_000NN_clean.png` and `_scan.jpg` and confirm: text sits inside each box (no overflow into neighbours), the seal + signature land in their regions, the scan variant looks plausibly photocopied. If any field overflows, adjust that box's `pad`/size handling in `fit_text` and re-run a fresh `--seed` sample into a tmp dir.

- [ ] **Step 3: Generate the full 100-base batch**

Run:
```bash
python/env/Scripts/python.exe python/scripts/bir_dataset_generator.py --count 100
```
Expected: `[bir-gen] wrote 200 files (100 base certs) -> ...` and a manifest `next_index` of 111 (10 from Step 2 + 100). Total in folder: 220 synthetic files + the pre-existing real images.

- [ ] **Step 4: Verify counts + manifest integrity**

Run (PowerShell):
```powershell
$dir = "C:\xampp\htdocs\projects\advs\python\data\training\classifier_data\bir_certificate"
"clean: " + (Get-ChildItem "$dir\synthetic_bir_*_clean.png").Count
"scan:  " + (Get-ChildItem "$dir\synthetic_bir_*_scan.jpg").Count
$m = Get-Content "$dir\_synthetic_manifest.json" -Raw | ConvertFrom-Json
"records: " + $m.records.Count + "  unique TINs: " + ($m.used_tins | Sort-Object -Unique).Count
```
Expected: `clean: 110`, `scan: 110`, `records: 110`, `unique TINs: 110` (no duplicates).

- [ ] **Step 5: Confirm the classifier sees the data (no commit — data is gitignored)**

Run:
```bash
python/env/Scripts/python.exe python/scripts/train_classifier.py --dry-run
```
Expected: exit 0 (the `bir_certificate` class now has the real + synthetic images; layout still valid). No `git add` of the images/manifest — `python/data/training/**` is gitignored by design.

---

## Self-Review

**1. Spec coverage**
- Fill `python/scripts/bir_dataset_generator.py` → Tasks 2–9. ✓
- Output to `python/data/training/classifier_data/bir_certificate` → `OUTPUT_DIR`, Tasks 9–10. ✓
- Courier Prime, size 11/12 to fit box → Task 1 (vendor) + Task 5 (`PREFERRED_FONT_SIZES=(12,11)`, fit/wrap). ✓
- `dry_seal` ← `BIR_SEAL.png`, `signature_over_name` ← `BIR_OFFICER_STAMP.png` → `IMAGE_FIELD_ASSETS`, Task 6. ✓
- All 15 boxes consumed → `FIELD_BOXES` (13 text + 2 image), Tasks 2/5/6. ✓
- "100 per run" + dedup JSON ledger → `--count` default 100, manifest (Task 4), cross-run test (Task 9). ✓
- Both clean + degraded → Task 7 (clean) + Task 8 (Augraphy) + Task 9 (variants). ✓
- "Ask if unclear" → resolved up front (font source, count+ledger, realism). ✓

**2. Placeholder scan:** No TBD/TODO; every code step contains full, runnable code and real test bodies. ✓

**3. Type consistency:** `FIELD_BOXES` dict-of-dicts (`x/y/w/h`) used identically in `boxes_out_of_bounds`, `draw_text_in_box`, `paste_asset`, `render_certificate`. `fit_text` returns `(size, lines)` consumed by `draw_text_in_box`. Manifest keys (`next_index`, `used_tins`, `used_hashes`, `records`) consistent across `load/save/register/generate_unique`. `render_certificate` → RGB `Image` consumed by `degrade`. `main`/`run_batch`/`parse_args` signatures match the test calls (`gen.main([...])`, `gen.run_batch(2, out_dir=..., seed=..., font_dir=...)`). ✓

**Applicable skills:** `advs-system-reference` (classifier training-data domain) + `run-advs-training` (data layout, venv interpreter) drove this plan; `superpowers:test-driven-development` governs execution. The user-suggested `/laravel-best-practices` and `/analyzing-data` do **not** apply (pure Python; no Laravel or warehouse SQL).
