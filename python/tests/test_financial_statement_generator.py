"""Unit + small-batch tests for scripts/financial_statement_generator.py.

Pure logic (record/financials generation, hashing, manifest dedup, amount
splitting, peso formatting) is exercised in isolation; the rendering tests check
the procedural marks (logo, seal, signature) and full-page composition; the batch
test writes only into tmp_path, never the real classifier folder. The module is
loaded by path (repo convention, matching test_bir_dataset_generator.py).
"""
import importlib.util
import random as _random
import re
from pathlib import Path

from faker import Faker
from PIL import Image, ImageFont

_SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "financial_statement_generator.py"
_spec = importlib.util.spec_from_file_location("financial_statement_generator", _SCRIPT)
gen = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(gen)

FONT_DIR = Path(__file__).resolve().parents[1] / "data" / "fonts"


def _fresh_faker(seed: int = 12345):
    f = Faker("en_PH")
    Faker.seed(seed)
    return f, _random.Random(seed)


def test_courier_prime_regular_and_bold_are_vendored():
    for bold in (False, True):
        path = gen.resolve_font(FONT_DIR, bold=bold)
        assert path.exists()
        assert ImageFont.truetype(str(path), 14).size == 14


def test_split_parts_are_positive_and_sum_exactly():
    rng = _random.Random(1)
    for total in (1000, 250_000, 9_999_000):
        parts = gen._split(rng, total, 3)
        assert len(parts) == 3
        assert all(p > 0 for p in parts)
        assert sum(parts) == total


def test_financials_balance_sheet_foots_and_income_is_consistent():
    rng = _random.Random(7)
    for _ in range(50):
        f = gen.generate_financials(rng)
        assert f["current_assets"] + f["noncurrent_assets"] == f["total_assets"]
        assert f["cash"] + f["receivables"] + f["inventories"] == f["current_assets"]
        assert f["total_liabilities"] + f["total_equity"] == f["total_assets"]
        assert f["total_assets"] == f["total_liabilities_and_equity"]
        assert f["share_capital"] + f["retained_earnings"] == f["total_equity"]
        assert f["revenue"] - f["cost_of_sales"] == f["gross_profit"]
        assert f["gross_profit"] - f["operating_expenses"] == f["operating_income"]
        assert f["operating_income"] - f["income_tax"] == f["net_income"]


def test_generate_record_has_expected_shape():
    faker, rng = _fresh_faker()
    rec = gen.generate_record(faker, rng)
    for key in ("company_name", "company_address", "statement_title", "period",
                "fiscal_year", "doc_no", "officer_name", "officer_title",
                "lgu_type", "lgu_name", "province", "date_approved", "financials"):
        assert key in rec, f"missing {key}"
    assert re.fullmatch(r"FS-\d{4}-\d{5}", rec["doc_no"])
    assert rec["statement_title"] == gen.STATEMENT_TITLE
    assert rec["lgu_type"] in gen.LGU_TYPES
    assert rec["officer_title"] in gen.OFFICER_TITLES
    assert rec["company_name"] and rec["officer_name"]


def test_company_name_has_no_double_suffix():
    faker, rng = _fresh_faker()
    names = [gen.generate_record(faker, rng)["company_name"] for _ in range(40)]
    assert not any(re.search(r"\b(Inc|Ltd|Limited|Corp)\.?\s+(Inc|Co)\.?$", n) for n in names)


def test_record_hash_is_stable_and_order_independent():
    a = {"company_name": "ACME", "fiscal_year": 2024}
    b = {"fiscal_year": 2024, "company_name": "ACME"}
    assert gen.record_hash(a) == gen.record_hash(b)
    assert gen.record_hash(a) != gen.record_hash({"company_name": "OTHER", "fiscal_year": 2024})


def test_many_records_have_unique_company_names():
    faker, rng = _fresh_faker()
    names = {gen.generate_record(faker, rng)["company_name"] for _ in range(200)}
    assert len(names) > 180  # near-unique; dedup layer guarantees the rest


def test_manifest_roundtrip_and_increment(tmp_path):
    path = tmp_path / "_synthetic_manifest.json"
    m = gen.load_manifest(path)
    assert m["next_index"] == 1 and m["records"] == []
    idx = gen.register_record(m, {"company_name": "ACME Inc.", "fiscal_year": 2024},
                              ["synthetic_fs_00001_clean.png"])
    assert idx == 1 and m["next_index"] == 2
    gen.save_manifest(path, m)

    reloaded = gen.load_manifest(path)
    assert reloaded["next_index"] == 2
    assert reloaded["used_companies"] == ["ACME Inc."]
    assert len(reloaded["used_hashes"]) == 1


def test_unique_record_never_reuses_a_known_company(tmp_path):
    faker, rng = _fresh_faker()
    m = gen.load_manifest(tmp_path / "m.json")
    faker2, rng2 = _fresh_faker()
    first = gen.generate_record(faker2, rng2)
    gen.register_record(m, first, ["x.png"])
    nxt = gen.generate_unique_record(faker, rng, m)
    assert nxt["company_name"] != first["company_name"]


def test_peso_formats_thousands_and_parentheses():
    assert gen._peso(1234567) == "Php 1,234,567"
    assert gen._peso(5000, negate=True) == "(Php 5,000)"


def test_make_logo_is_rgba_sized_and_marked():
    logo = gen.make_logo("Quezon Trading Inc.", size=110, font_dir=FONT_DIR)
    assert logo.mode == "RGBA" and logo.size == (110, 110)
    assert logo.getextrema()[3][1] > 0  # some opaque pixels exist


def test_make_logo_is_deterministic_per_company():
    a = gen.make_logo("ACME Corp.", font_dir=FONT_DIR)
    b = gen.make_logo("ACME Corp.", font_dir=FONT_DIR)
    c = gen.make_logo("OTHER Corp.", font_dir=FONT_DIR)
    assert list(a.getdata()) == list(b.getdata())
    assert list(a.getdata()) != list(c.getdata())


def test_make_lgu_seal_renders_colored_ink():
    faker, rng = _fresh_faker()
    seal = gen.make_lgu_seal(gen.generate_record(faker, rng), font_dir=FONT_DIR)
    assert seal.mode == "RGBA"
    assert seal.getextrema()[3][1] > 0  # opaque seal ink present


def test_make_signature_has_ink_pixels():
    sig = gen.make_signature("Juan Dela Cruz")
    assert sig.mode == "RGBA"
    assert sig.getextrema()[3][1] > 0


def test_render_statement_size_mode_and_marks():
    faker, rng = _fresh_faker()
    rec = gen.generate_record(faker, rng)
    out = gen.render_statement(rec, font_dir=FONT_DIR, rng=rng)
    assert out.mode == "RGBA" and out.size == (gen.PAGE_W, gen.PAGE_H)
    # Not a blank page: many non-white pixels exist.
    rgb = out.convert("RGB")
    assert rgb.getextrema() != ((255, 255), (255, 255), (255, 255))


def test_degrade_returns_same_size_rgb_and_changes_pixels():
    from PIL import ImageDraw

    clean = Image.new("RGB", (300, 400), "white")
    dd = ImageDraw.Draw(clean)
    dd.rectangle((20, 20, 260, 80), outline="black")
    dd.text((30, 120), "FINANCIAL STATEMENT", fill=(0, 0, 0))
    out = gen.degrade(clean, _random.Random(7))
    assert out.mode == "RGB" and out.size == clean.size
    assert list(out.getdata()) != list(clean.getdata())


def test_degrade_falls_back_to_numpy_on_pipeline_error(monkeypatch):
    clean = Image.new("RGB", (80, 100), "white")
    monkeypatch.setattr(gen, "_build_augraphy_pipeline",
                        lambda: (_ for _ in ()).throw(RuntimeError("boom")))
    out = gen.degrade(clean, _random.Random(1))
    assert out.size == clean.size and out.mode == "RGB"


def test_run_batch_writes_variants_and_manifest(tmp_path):
    out = tmp_path / "financial_statement"
    summary = gen.run_batch(2, out_dir=out, seed=99, font_dir=FONT_DIR)
    pngs = sorted(out.glob("synthetic_fs_*_clean.png"))
    jpgs = sorted(out.glob("synthetic_fs_*_scan.jpg"))
    assert len(pngs) == 2 and len(jpgs) == 2
    assert (out / "_synthetic_manifest.json").exists()
    assert summary["count"] == 2 and summary["next_index"] == 3


def test_second_run_appends_without_duplicates(tmp_path):
    out = tmp_path / "financial_statement"
    gen.run_batch(2, out_dir=out, seed=1, font_dir=FONT_DIR)
    gen.run_batch(2, out_dir=out, seed=2, font_dir=FONT_DIR)
    manifest = gen.load_manifest(out / "_synthetic_manifest.json")
    assert manifest["next_index"] == 5
    assert len(manifest["used_companies"]) == len(set(manifest["used_companies"]))
    assert sorted(p.name for p in out.glob("synthetic_fs_0000*_clean.png")) == [
        "synthetic_fs_00001_clean.png", "synthetic_fs_00002_clean.png",
        "synthetic_fs_00003_clean.png", "synthetic_fs_00004_clean.png",
    ]


def test_dry_run_writes_nothing(tmp_path):
    out = tmp_path / "financial_statement"
    out.mkdir()
    rc = gen.main(["--dry-run", "--out-dir", str(out), "--font-dir", str(FONT_DIR)])
    assert rc == 0
    assert list(out.iterdir()) == []
