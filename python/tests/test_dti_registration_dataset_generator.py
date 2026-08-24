"""Unit + small-batch tests for scripts/dti_registration_dataset_generator.py."""
import importlib.util
import random as _random
import re
import sys
from datetime import datetime
from pathlib import Path

from faker import Faker
from PIL import Image

_SCRIPTS = Path(__file__).resolve().parents[1] / "scripts"
if str(_SCRIPTS) not in sys.path:
    sys.path.insert(0, str(_SCRIPTS))

_SCRIPT = _SCRIPTS / "dti_registration_dataset_generator.py"
_spec = importlib.util.spec_from_file_location("dti_registration_dataset_generator", _SCRIPT)
gen = importlib.util.module_from_spec(_spec)
sys.modules[_spec.name] = gen
_spec.loader.exec_module(gen)

FONT_DIR = Path(__file__).resolve().parents[1] / "data" / "fonts"


def _fresh_faker(seed=12345):
    Faker.seed(seed)
    return Faker("en_PH"), _random.Random(seed)


def _region_changed(before: Image.Image, after: Image.Image, box: dict) -> bool:
    rect = (box["x"], box["y"], box["x"] + box["w"], box["y"] + box["h"])
    return before.crop(rect).tobytes() != after.crop(rect).tobytes()


def test_field_boxes_cover_the_dti_template_fields():
    expected = {
        "business_name",
        "business_address",
        "owner_representative_name",
        "date_issued",
        "expiry_date",
        "certificate_no",
        "trn_no",
    }
    assert set(gen.FIELD_BOXES) == expected
    assert gen.TEXT_FIELD_KEYS == list(expected) or set(gen.TEXT_FIELD_KEYS) == expected


def test_output_dir_targets_the_dti_registration_class_folder():
    assert gen.OUTPUT_DIR.name == "dti_registration"
    assert gen.OUTPUT_DIR.parts[-4:] == ("data", "training", "classifier_data", "dti_registration")


def test_assets_and_boxes_validate_cleanly():
    assert gen.validate_assets(font_dir=FONT_DIR) == []
    with Image.open(gen.TEMPLATE_PATH) as image:
        assert gen.bir.boxes_out_of_bounds(image.size, gen.FIELD_BOXES) == []


def test_generate_record_matches_the_dti_fields_and_date_shape():
    faker, rng = _fresh_faker()
    record = gen.generate_record(faker, rng)
    assert set(record) == set(gen.TEXT_FIELD_KEYS)
    assert record["business_name"]
    assert record["business_address"]
    assert record["owner_representative_name"]
    assert re.fullmatch(r"\d{2}/\d{2}/\d{4}", record["date_issued"])
    assert re.fullmatch(r"\d{2}/\d{2}/\d{4}", record["expiry_date"])
    assert re.fullmatch(r"BN \d{7}", record["certificate_no"])
    assert re.fullmatch(r"DTI-\d{4}-\d{8}", record["trn_no"])

    issued = datetime.strptime(record["date_issued"], "%m/%d/%Y").date()
    expiry = datetime.strptime(record["expiry_date"], "%m/%d/%Y").date()
    assert expiry.year == issued.year + 5


def test_record_hash_is_stable_and_order_independent():
    a = {"business_name": "A", "certificate_no": "BN 1000000"}
    b = {"certificate_no": "BN 1000000", "business_name": "A"}
    assert gen.record_hash(a) == gen.record_hash(b)
    assert gen.record_hash(a) != gen.record_hash({"business_name": "B", "certificate_no": "BN 1000000"})


def test_manifest_roundtrip_and_increment(tmp_path):
    path = tmp_path / "_synthetic_manifest.json"
    manifest = gen.load_manifest(path)
    assert manifest["next_index"] == 1 and manifest["records"] == []
    record = {
        "business_name": "SAMPLE FOOD HOUSE",
        "business_address": "1 SAMPLE ST, BARANGAY WAWA, TAGUIG CITY",
        "owner_representative_name": "JUAN DELA CRUZ",
        "date_issued": "01/02/2026",
        "expiry_date": "01/02/2031",
        "certificate_no": "BN 1234567",
        "trn_no": "DTI-2026-12345678",
    }
    idx = gen.register_record(manifest, record, ["synthetic_dti_registration_00001_clean.png"])
    assert idx == 1 and manifest["next_index"] == 2
    gen.bir.save_manifest(path, manifest)

    reloaded = gen.load_manifest(path)
    assert reloaded["next_index"] == 2
    assert reloaded["used_certificate_nos"] == ["BN 1234567"]
    assert reloaded["used_trn_nos"] == ["DTI-2026-12345678"]


def test_unique_record_never_reuses_known_certificate_or_trn(tmp_path):
    faker, rng = _fresh_faker()
    manifest = gen.load_manifest(tmp_path / "m.json")
    faker2, rng2 = _fresh_faker()
    first = gen.generate_record(faker2, rng2)
    gen.register_record(manifest, first, ["x.png"])

    nxt = gen.generate_unique_record(faker, rng, manifest)
    assert nxt["certificate_no"] != first["certificate_no"]
    assert nxt["trn_no"] != first["trn_no"]
    assert gen.record_hash(nxt) != gen.record_hash(first)


def test_render_registration_fills_text_regions():
    faker, rng = _fresh_faker()
    record = gen.generate_record(faker, rng)
    blank = Image.open(gen.TEMPLATE_PATH).convert("RGB")
    out = gen.render_registration(record, font_dir=FONT_DIR, rng=rng)
    assert out.mode == "RGB" and out.size == blank.size

    for key in ("business_name", "owner_representative_name", "certificate_no", "trn_no"):
        assert _region_changed(blank, out, gen.FIELD_BOXES[key]), key


def test_run_batch_writes_variants_and_manifest(tmp_path):
    out = tmp_path / "dti_registration"
    summary = gen.run_batch(2, out_dir=out, seed=99, font_dir=FONT_DIR)
    pngs = sorted(out.glob("synthetic_dti_registration_*_clean.png"))
    jpgs = sorted(out.glob("synthetic_dti_registration_*_scan.jpg"))
    assert len(pngs) == 2 and len(jpgs) == 2
    assert (out / "_synthetic_manifest.json").exists()
    assert summary["count"] == 2 and summary["next_index"] == 3


def test_second_run_appends_without_duplicates(tmp_path):
    out = tmp_path / "dti_registration"
    gen.run_batch(2, out_dir=out, seed=1, font_dir=FONT_DIR)
    gen.run_batch(2, out_dir=out, seed=2, font_dir=FONT_DIR)
    manifest = gen.load_manifest(out / "_synthetic_manifest.json")
    assert manifest["next_index"] == 5
    assert len(manifest["used_certificate_nos"]) == len(set(manifest["used_certificate_nos"]))
    assert len(manifest["used_trn_nos"]) == len(set(manifest["used_trn_nos"]))
    assert sorted(path.name for path in out.glob("synthetic_dti_registration_0000*_clean.png")) == [
        "synthetic_dti_registration_00001_clean.png",
        "synthetic_dti_registration_00002_clean.png",
        "synthetic_dti_registration_00003_clean.png",
        "synthetic_dti_registration_00004_clean.png",
    ]


def test_dry_run_writes_nothing(tmp_path):
    out = tmp_path / "dti_registration"
    out.mkdir()
    rc = gen.main(["--dry-run", "--out-dir", str(out), "--font-dir", str(FONT_DIR)])
    assert rc == 0
    assert list(out.iterdir()) == []
