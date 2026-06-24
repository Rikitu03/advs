"""Unit + small-batch tests for scripts/bir_dataset_generator.py.

Pure logic (record generation, hashing, manifest dedup, font-fit, alpha keying)
is exercised in isolation; the batch test writes only into tmp_path, never the
real classifier folder. The module is loaded by path (repo convention).
"""
import importlib.util
import random as _random
import re
from pathlib import Path

from faker import Faker
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
    assert img.getextrema() != ((255, 255), (255, 255), (255, 255))


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
    monkeypatch.setattr(gen, "_build_augraphy_pipeline",
                        lambda: (_ for _ in ()).throw(RuntimeError("boom")))
    out = gen.degrade(clean)
    assert out.size == clean.size and out.mode == "RGB"


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
