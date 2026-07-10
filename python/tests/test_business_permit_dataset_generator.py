"""Unit + small-batch tests for scripts/business_permit_dataset_generator.py.

Pure logic (record generation, ordinal day, hashing, manifest dedup, box config,
logo/signature compositing) is exercised in isolation; the batch test writes only
into tmp_path, never the real classifier folder. The module is loaded by path
(repo convention); its scripts dir is added to sys.path so its sibling import of
bir_dataset_generator resolves.
"""
import importlib.util
import random as _random
import re
import sys
from pathlib import Path

from faker import Faker
from PIL import Image, ImageDraw

_SCRIPTS = Path(__file__).resolve().parents[1] / "scripts"
if str(_SCRIPTS) not in sys.path:
    sys.path.insert(0, str(_SCRIPTS))

_SCRIPT = _SCRIPTS / "business_permit_dataset_generator.py"
_spec = importlib.util.spec_from_file_location("business_permit_dataset_generator", _SCRIPT)
gen = importlib.util.module_from_spec(_spec)
# Register before exec (importlib recipe): @dataclass resolves the module's
# string annotations through sys.modules[cls.__module__].
sys.modules[_spec.name] = gen
_spec.loader.exec_module(gen)

FONT_DIR = Path(__file__).resolve().parents[1] / "data" / "fonts"
TEMPLATE_SIZE = (1600, 1203)


def _fresh_faker(seed=12345):
    Faker.seed(seed)
    return Faker("en_PH"), _random.Random(seed)


# ----- config / boxes -------------------------------------------------------
def test_field_boxes_cover_annotated_fields_plus_logo():
    expected = {
        "name_of_proprietor", "trade_name", "business_location", "kind_of_business",
        "day_issued", "month_issued", "city_treasurer_name", "city_treasurer_signature",
        "city_administrator_name", "city_administrator_signature", "city_logo",
    }
    assert set(gen.FIELD_BOXES) == expected
    assert set(gen.IMAGE_FIELD_ASSETS) == {
        "city_treasurer_signature", "city_administrator_signature", "city_logo",
    }
    assert "city_logo" not in gen.TEXT_FIELD_KEYS
    assert "city_treasurer_signature" not in gen.TEXT_FIELD_KEYS
    assert "name_of_proprietor" in gen.TEXT_FIELD_KEYS


def test_all_boxes_fit_inside_the_template():
    assert gen.bir.boxes_out_of_bounds(TEMPLATE_SIZE, gen.FIELD_BOXES) == []


def test_load_field_boxes_reads_the_exported_json():
    # The annotator's export lives in python/json_data/ after the migration.
    assert gen.BOXES_JSON.exists(), f"expected exported boxes at {gen.BOXES_JSON}"
    boxes = gen.load_field_boxes()
    assert "name_of_proprietor" in boxes and "city_logo" in boxes


def test_output_dir_targets_the_canonical_business_permit_class_folder():
    # The classifier reads the class label from the folder name. The canonical
    # code in DocumentTypeSeeder is `business_permit` (LGU business permit); there
    # is no `business_registration` class on disk or in the DB taxonomy, so the
    # generator's default output must land in the real `business_permit` folder.
    assert gen.OUTPUT_DIR.name == "business_permit"
    assert gen.OUTPUT_DIR.parts[-4:] == ("data", "training", "classifier_data", "business_permit")


# ----- field values ---------------------------------------------------------
def test_ordinal_day_suffixes():
    assert gen.ordinal(1) == "1st"
    assert gen.ordinal(2) == "2nd"
    assert gen.ordinal(3) == "3rd"
    assert gen.ordinal(4) == "4th"
    assert gen.ordinal(11) == "11th"
    assert gen.ordinal(12) == "12th"
    assert gen.ordinal(13) == "13th"
    assert gen.ordinal(21) == "21st"
    assert gen.ordinal(24) == "24th"


def test_generate_record_has_only_text_fields():
    faker, rng = _fresh_faker()
    rec = gen.generate_record(faker, rng)
    assert set(rec) == set(gen.TEXT_FIELD_KEYS)
    assert rec["month_issued"] in gen.MONTHS_FULL
    assert re.fullmatch(r"\d{1,2}(?:st|nd|rd|th)", rec["day_issued"])
    assert rec["kind_of_business"] in gen.KIND_OF_BUSINESS_POOL
    assert rec["name_of_proprietor"] and rec["trade_name"] and rec["business_location"]


def test_record_hash_is_stable_and_order_independent():
    a = {"name_of_proprietor": "A", "trade_name": "B"}
    b = {"trade_name": "B", "name_of_proprietor": "A"}
    assert gen.record_hash(a) == gen.record_hash(b)
    assert gen.record_hash(a) != gen.record_hash({"name_of_proprietor": "A", "trade_name": "C"})


# ----- manifest dedup -------------------------------------------------------
def test_manifest_roundtrip_and_increment(tmp_path):
    path = tmp_path / "_synthetic_manifest.json"
    m = gen.load_manifest(path)
    assert m["next_index"] == 1 and m["records"] == []
    idx = gen.register_record(m, {"name_of_proprietor": "X"}, ["synthetic_permit_00001_clean.png"])
    assert idx == 1 and m["next_index"] == 2
    gen.bir.save_manifest(path, m)

    reloaded = gen.load_manifest(path)
    assert reloaded["next_index"] == 2
    assert len(reloaded["used_hashes"]) == 1


def test_unique_record_never_reuses_a_known_hash(tmp_path):
    faker, rng = _fresh_faker()
    m = gen.load_manifest(tmp_path / "m.json")
    faker2, rng2 = _fresh_faker()
    first = gen.generate_record(faker2, rng2)
    gen.register_record(m, first, ["x.png"])
    # Same seed would reproduce `first`; dedup must skip it and return a new one.
    nxt = gen.generate_unique_record(faker, rng, m)
    assert gen.record_hash(nxt) != gen.record_hash(first)


# ----- rendering ------------------------------------------------------------
def test_draw_text_in_box_centers_and_marks_pixels():
    box = {"x": 100, "y": 100, "w": 400, "h": 60}
    img = Image.new("RGB", TEMPLATE_SIZE, "white")
    draw = ImageDraw.Draw(img)
    gen.draw_text_in_box(draw, "JUAN DELA CRUZ", box, font_dir=FONT_DIR)
    crop = img.crop((box["x"], box["y"], box["x"] + box["w"], box["y"] + box["h"]))
    assert crop.getextrema() != ((255, 255), (255, 255), (255, 255))  # ink drawn


def test_paste_logo_composites_into_the_seal_box():
    base = Image.new("RGBA", TEMPLATE_SIZE, (255, 255, 255, 255))
    box = gen.FIELD_BOXES["city_logo"]
    gen.paste_logo(base, gen.LOGO_PATH, box)
    assert base.size == TEMPLATE_SIZE
    crop = base.crop((box["x"], box["y"], box["x"] + box["w"], box["y"] + box["h"]))
    assert crop.getextrema()[0] != (255, 255)  # something composited (not all white R)


def test_render_permit_fills_text_logo_and_signatures():
    faker, rng = _fresh_faker()
    rec = gen.generate_record(faker, rng)
    blank = Image.open(gen.TEMPLATE_PATH).convert("RGB")
    out = gen.render_permit(rec, font_dir=FONT_DIR)
    assert out.mode == "RGB" and out.size == blank.size

    def region_changed(key):
        b = gen.FIELD_BOXES[key]
        ca = blank.crop((b["x"], b["y"], b["x"] + b["w"], b["y"] + b["h"]))
        cb = out.crop((b["x"], b["y"], b["x"] + b["w"], b["y"] + b["h"]))
        return list(ca.getdata()) != list(cb.getdata())

    assert region_changed("name_of_proprietor")      # a text field was drawn
    assert region_changed("city_logo")               # crisp seal composited
    assert region_changed("city_treasurer_signature")
    assert region_changed("city_administrator_signature")


# ----- batch ----------------------------------------------------------------
def test_run_batch_writes_variants_and_manifest(tmp_path):
    out = tmp_path / "business_permit"
    summary = gen.run_batch(2, out_dir=out, seed=99, font_dir=FONT_DIR)
    pngs = sorted(out.glob("synthetic_permit_*_clean.png"))
    jpgs = sorted(out.glob("synthetic_permit_*_scan.jpg"))
    assert len(pngs) == 2 and len(jpgs) == 2
    assert (out / "_synthetic_manifest.json").exists()
    assert summary["count"] == 2 and summary["next_index"] == 3


def test_second_run_appends_without_duplicates(tmp_path):
    out = tmp_path / "business_permit"
    gen.run_batch(2, out_dir=out, seed=1, font_dir=FONT_DIR)
    gen.run_batch(2, out_dir=out, seed=2, font_dir=FONT_DIR)
    manifest = gen.load_manifest(out / "_synthetic_manifest.json")
    assert manifest["next_index"] == 5
    assert len(manifest["used_hashes"]) == len(set(manifest["used_hashes"]))  # no dup content
    assert sorted(p.name for p in out.glob("synthetic_permit_0000*_clean.png")) == [
        "synthetic_permit_00001_clean.png", "synthetic_permit_00002_clean.png",
        "synthetic_permit_00003_clean.png", "synthetic_permit_00004_clean.png",
    ]


def test_dry_run_writes_nothing(tmp_path):
    out = tmp_path / "business_permit"
    out.mkdir()
    rc = gen.main(["--dry-run", "--out-dir", str(out), "--font-dir", str(FONT_DIR)])
    assert rc == 0
    assert list(out.iterdir()) == []


# ----- city layouts (Makati / Manila) ---------------------------------------
def _amount(value: str) -> float:
    return float(value.replace(",", ""))


def _region_changed(before: Image.Image, after: Image.Image, box: dict) -> bool:
    rect = (box["x"], box["y"], box["x"] + box["w"], box["y"] + box["h"])
    return before.crop(rect).tobytes() != after.crop(rect).tobytes()


def test_city_layouts_registry_and_assets_exist():
    assert set(gen.CITY_LAYOUTS) == {"makati", "manila", "marikina", "taguig"}
    for layout in gen.CITY_LAYOUTS.values():
        assert layout.template_path.exists(), layout.template_path
        assert layout.boxes_json.exists(), layout.boxes_json
        assert gen.validate_city_assets(layout, FONT_DIR) == []


def test_city_boxes_fit_inside_their_templates():
    for layout in gen.CITY_LAYOUTS.values():
        boxes = layout.load_boxes()
        with Image.open(layout.template_path) as im:
            assert gen.bir.boxes_out_of_bounds(im.size, boxes) == []


def test_city_records_cover_exactly_the_text_boxes():
    faker, rng = _fresh_faker()
    for layout in gen.CITY_LAYOUTS.values():
        record = layout.make_record(faker, rng)
        assert set(record) == set(layout.text_keys(layout.load_boxes())), layout.name
        assert all(isinstance(v, str) and v for v in record.values()), layout.name


def test_makati_total_is_the_sum_of_the_fee_table():
    faker, rng = _fresh_faker()
    record = gen.generate_makati_record(faker, rng)
    fee_keys = list(gen.MAKATI_FEE_RANGES) + ["meat_inspection_fee", "fsi_fee"]
    assert abs(sum(_amount(record[k]) for k in fee_keys) - _amount(record["total"])) < 0.01
    assert record["expiry_date"] == f"DECEMBER 31, {record['tax_year']}"


def test_manila_total_and_amount_paid_are_consistent():
    faker, rng = _fresh_faker()
    record = gen.generate_manila_record(faker, rng)
    expected = _amount(record["business_tax"]) + _amount(record["fixed_fee"])
    assert abs(expected - _amount(record["total_fee"])) < 0.01
    assert record["amount_paid"] == record["total_fee"]


def _ink_bbox(img: Image.Image):
    from PIL import ImageOps
    return ImageOps.invert(img.convert("L")).getbbox()


def test_draw_text_in_box_nowrap_keeps_dates_on_one_line():
    box = {"x": 10, "y": 10, "w": 170, "h": 70}
    sizing = {"sizes": (28, 26, 22), "min_size": 16}
    wrapped = Image.new("RGB", (200, 100), "white")
    gen.draw_text_in_box(ImageDraw.Draw(wrapped), "January 2, 2026", box,
                         font_dir=FONT_DIR, **sizing)
    single = Image.new("RGB", (200, 100), "white")
    gen.draw_text_in_box(ImageDraw.Draw(single), "January 2, 2026", box,
                         font_dir=FONT_DIR, nowrap=True, **sizing)
    wb, sb = _ink_bbox(wrapped), _ink_bbox(single)
    assert wb and sb
    single_height = sb[3] - sb[1]
    assert single_height < 30                      # one line of <=22px text
    assert single_height < (wb[3] - wb[1])         # default sizing wraps to two


def test_marikina_total_and_dates_are_consistent():
    faker, rng = _fresh_faker()
    record = gen.generate_marikina_record(faker, rng)
    fee_total = sum(_amount(record[k]) for k in gen.MARIKINA_FEE_RANGES)
    assert abs(fee_total - _amount(record["total"])) < 0.01
    assert record["valid_until"] == f"DECEMBER 31, {gen.MARIKINA_FORM_YEAR}"
    assert record["deadline_of_renewal"] == f"January 20, {gen.MARIKINA_FORM_YEAR + 1}"
    assert record["permit_no"].startswith(f"{gen.MARIKINA_FORM_YEAR}-")
    assert str(gen.MARIKINA_FORM_YEAR) in record["date_issued"]


def test_taguig_fire_code_is_ten_percent_and_total_sums():
    faker, rng = _fresh_faker()
    record = gen.generate_taguig_record(faker, rng)
    subtotal = sum(_amount(record[k]) for k in gen.TAGUIG_FEE_RANGES)
    assert abs(_amount(record["fire_code"]) - round(0.10 * subtotal, 2)) < 0.01
    fee_total = subtotal + _amount(record["fire_code"])
    assert abs(fee_total - _amount(record["total"])) < 0.01
    assert record["date_issued"].endswith(f"/{gen.TAGUIG_FORM_YEAR}")
    assert record["valid_until"] == f"12/31/{gen.TAGUIG_FORM_YEAR}"


def test_render_marikina_fills_text_and_the_chief_signature():
    faker, rng = _fresh_faker()
    layout = gen.CITY_LAYOUTS["marikina"]
    boxes = layout.load_boxes()
    record = gen.generate_marikina_record(faker, rng)
    blank = Image.open(layout.template_path).convert("RGB")
    out = gen.render_city_permit(layout, record, font_dir=FONT_DIR)
    for key in ("business_name", "business_address", "business_tax",
                "total", "city_mayor_name", "acting_chief_signature"):
        assert _region_changed(blank, out, boxes[key]), key


def test_render_taguig_fills_text_and_the_bplo_head_signature():
    faker, rng = _fresh_faker()
    layout = gen.CITY_LAYOUTS["taguig"]
    boxes = layout.load_boxes()
    record = gen.generate_taguig_record(faker, rng)
    blank = Image.open(layout.template_path).convert("RGB")
    out = gen.render_city_permit(layout, record, font_dir=FONT_DIR)
    for key in ("name_of_entity", "contractors_management", "fire_code",
                "total", "bplo_head_signature"):
        assert _region_changed(blank, out, boxes[key]), key


def test_render_makati_fills_text_but_never_the_preprinted_regions():
    faker, rng = _fresh_faker()
    layout = gen.CITY_LAYOUTS["makati"]
    boxes = layout.load_boxes()
    record = gen.generate_makati_record(faker, rng)
    blank = Image.open(layout.template_path).convert("RGB")
    out = gen.render_city_permit(layout, record, font_dir=FONT_DIR)
    assert out.mode == "RGB" and out.size == blank.size
    assert _region_changed(blank, out, boxes["business_name"])
    assert _region_changed(blank, out, boxes["total"])
    # The OIC/Mayor names + signatures are printed on the template already.
    for key in sorted(layout.preprinted_keys):
        assert not _region_changed(blank, out, boxes[key]), key


def test_render_manila_fills_text_and_composites_the_secretary_signature():
    faker, rng = _fresh_faker()
    layout = gen.CITY_LAYOUTS["manila"]
    boxes = layout.load_boxes()
    record = gen.generate_manila_record(faker, rng)
    blank = Image.open(layout.template_path).convert("RGB")
    out = gen.render_city_permit(layout, record, font_dir=FONT_DIR)
    assert _region_changed(blank, out, boxes["name"])
    assert _region_changed(blank, out, boxes["kind_of_business"])
    assert _region_changed(blank, out, boxes["secretary_signature"])


def test_run_city_batch_uses_per_city_stems_and_manifest(tmp_path):
    layout = gen.CITY_LAYOUTS["manila"]
    out = tmp_path / "business_permit"
    summary = gen.run_city_batch(layout, 2, out_dir=out, seed=7, font_dir=FONT_DIR)
    assert len(sorted(out.glob("synthetic_permit_manila_*_clean.png"))) == 2
    assert len(sorted(out.glob("synthetic_permit_manila_*_scan.jpg"))) == 2
    assert (out / "_synthetic_manifest_manila.json").exists()
    assert summary["count"] == 2 and summary["next_index"] == 3
    # A second run appends after the first (no filename reuse, no dup content).
    gen.run_city_batch(layout, 1, out_dir=out, seed=8, font_dir=FONT_DIR)
    manifest = gen.load_manifest(out / "_synthetic_manifest_manila.json")
    assert manifest["next_index"] == 4
    assert len(manifest["used_hashes"]) == len(set(manifest["used_hashes"]))


def test_city_dry_run_writes_nothing(tmp_path):
    out = tmp_path / "business_permit"
    out.mkdir()
    rc = gen.main(["--city", "makati", "--dry-run",
                   "--out-dir", str(out), "--font-dir", str(FONT_DIR)])
    assert rc == 0
    assert list(out.iterdir()) == []
