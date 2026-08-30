"""ADVS - generates python/api/ocr_roi_config.json (dev-time tool, not run in prod).

The OCR templates in ``ocr_dryrun.py`` (bir, dti, business_permit) already
have hand-calibrated field bounding boxes, exported by ``annotate_boxes.py`` /
``business_permit_annotator.py`` against the exact template images used to
synthesize training data:
    json_data/bounding_boxes.json                   (bir, 700x887 template)
    json_data/dti_registration.json                 (dti, 1200x1691 template)
    json_data/business_permit_boxes_digos.json       (business_permit/digos, 1600x1203)
    json_data/business_permit_boxes_Makati.json      (business_permit/makati)
    json_data/business_permit_boxes_Manila.json      (business_permit/manila)
    json_data/business_permit_boxes_Marikina.json    (business_permit/marikina)
    json_data/business_permit_boxes_Taguig.json      (business_permit/taguig)

This script reuses that existing calibration instead of re-deriving it: it
reads each JSON (pixel boxes in TEMPLATE image space), opens the matching
template PNG to get its true pixel size, and converts each box to a fraction
of page width/height. Fractions travel across scan resolutions; the API's ROI
matcher (``roi_field_ocr.py``) only ever needs this static JSON at runtime -
it does not open template images or import the dataset generators.

Only fields present in ``ocr_dryrun.py``'s FIELD_SPECS/BUSINESS_PERMIT_FIELD_SPECS/
DTI_FIELD_SPECS are kept; image-asset boxes (signatures, seals) and
generator-only boxes (fees, OR numbers, etc.) are dropped since ROI+TrOCR
only recognizes text fields ocr_dryrun.py actually extracts.

bir and dti use every calibrated field 1:1 by key already (a codebase
convention: "field keys mirror ocr_dryrun.py so the synthetic data and the
template never drift"). business_permit is different per city: each
``CityLayout`` in business_permit_dataset_generator.py has its own field
vocabulary (e.g. Manila's "name"/"address" vs. Digos's generic
"name_of_proprietor"/"business_location"), so each city gets an explicit
KEY_MAP translating its own box names to the BUSINESS_PERMIT_FIELD_SPECS
keys ocr_dryrun.py actually extracts (name_of_proprietor, trade_name,
business_location, kind_of_business, date_issued). ``city_issued`` has no
per-city box anywhere - it is boilerplate "CITY OF <NAME>" header text,
already handled by the existing global regex, not something ROI+TrOCR needs
to help with.

A city field with no honest semantic match is left unmapped rather than
guessed - Manila's "date" box, for instance, sits next to or_no/amount_paid
(the OR/payment date, not the permit's issue date) and is deliberately NOT
mapped to date_issued: a confidently-wrong ROI value is worse than falling
back to today's label+regex extraction for that field.

Run from the venv whenever a template's calibrated boxes change:
    python/env/Scripts/python.exe python/scripts/generate_roi_config.py
"""
from __future__ import annotations

import json
from pathlib import Path

from PIL import Image

PY_ROOT = Path(__file__).resolve().parents[1]
OUTPUT_PATH = PY_ROOT / "api" / "ocr_roi_config.json"
TEMPLATE_DIR = PY_ROOT / "data" / "template"
BOXES_DIR = PY_ROOT / "json_data"

# --------------------------------------------------------------------------- #
# bir / dti: calibrated box keys already match FIELD_SPECS/DTI_FIELD_SPECS
# 1:1. Keep everything except image assets / non-extracted annotations.
# --------------------------------------------------------------------------- #
_DROP_KEYS = {
    "signature_over_name", "dry_seal",  # bir: image assets
}


def _to_fraction(box: dict, width: int, height: int) -> dict:
    return {
        "x": box["x"] / width,
        "y": box["y"] / height,
        "w": box["w"] / width,
        "h": box["h"] / height,
    }


def _union(boxes: list[dict]) -> dict:
    x1 = min(b["x"] for b in boxes)
    y1 = min(b["y"] for b in boxes)
    x2 = max(b["x"] + b["w"] for b in boxes)
    y2 = max(b["y"] + b["h"] for b in boxes)
    return {"x": x1, "y": y1, "w": x2 - x1, "h": y2 - y1}


def build_template_roi(
    name: str,
    boxes_json: Path,
    template_png: Path,
    *,
    key_map: dict[str, str] | None = None,
    combine: dict[str, list[str]] | None = None,
) -> dict[str, dict]:
    """Convert one template's calibrated pixel boxes to fractional ROIs.

    Without ``key_map``: keep every box whose key isn't in ``_DROP_KEYS``
    (bir/dti - the calibrated keys already ARE the target keys). With
    ``key_map``: only boxes present as a source key are kept, renamed to
    their mapped target key (business_permit, per city).

    ``combine`` unions several raw boxes into one synthetic raw key (e.g. a
    split day/month issue date) before drop-filtering / key-mapping runs, so
    the combined key can itself appear in ``key_map``.
    """
    raw_boxes: dict[str, dict] = json.loads(boxes_json.read_text(encoding="utf-8"))
    combine = combine or {}

    combined_keys = {k for group in combine.values() for k in group}
    for target_key, source_keys in combine.items():
        missing = [k for k in source_keys if k not in raw_boxes]
        if missing:
            raise KeyError(f"{name}: combine sources missing from {boxes_json.name}: {missing}")
        raw_boxes[target_key] = _union([raw_boxes[k] for k in source_keys])

    if key_map is None:
        kept = {k: v for k, v in raw_boxes.items() if k not in _DROP_KEYS and k not in combined_keys}
    else:
        missing = [k for k in key_map if k not in raw_boxes]
        if missing:
            raise KeyError(f"{name}: key_map sources missing from {boxes_json.name}: {missing}")
        kept = {target: raw_boxes[source] for source, target in key_map.items()}

    with Image.open(template_png) as im:
        width, height = im.size

    return {key: _to_fraction(box, width, height) for key, box in kept.items()}


# --------------------------------------------------------------------------- #
# business_permit: per-city boxes_json/template + explicit KEY_MAP (own box
# name -> BUSINESS_PERMIT_FIELD_SPECS key) + optional combine for a split
# issue date. See module docstring for the "no honest match -> leave it out"
# rule this table follows.
# --------------------------------------------------------------------------- #
_CITY_LAYOUTS: dict[str, dict] = {
    "digos": {
        "boxes_json": BOXES_DIR / "business_permit_boxes_digos.json",
        "template": TEMPLATE_DIR / "business_permits" / "Business Permit (Digos).png",
        "combine": {"date_issued": ["day_issued", "month_issued"]},
        "key_map": {
            "name_of_proprietor": "name_of_proprietor",
            "trade_name": "trade_name",
            "business_location": "business_location",
            "kind_of_business": "kind_of_business",
            "date_issued": "date_issued",
        },
    },
    "makati": {
        "boxes_json": BOXES_DIR / "business_permit_boxes_Makati.json",
        "template": TEMPLATE_DIR / "business_permits" / "Business Permit (Makati).png",
        "combine": {"date_issued": ["issued_day", "issued_month_year"]},
        "key_map": {
            "business_name": "trade_name",
            "address": "business_location",
            "date_issued": "date_issued",
            # No distinct proprietor-name or kind-of-business box on this
            # layout at all (see business_permit_boxes_Makati.json) - those
            # two fields fall back to label+regex, same as today.
        },
    },
    "manila": {
        "boxes_json": BOXES_DIR / "business_permit_boxes_Manila.json",
        "template": TEMPLATE_DIR / "business_permits" / "Business Permit (Manila).png",
        "key_map": {
            "name": "name_of_proprietor",
            "business_name": "trade_name",
            "address": "business_location",
            "kind_of_business": "kind_of_business",
            # "date" sits beside or_no/amount_paid - the OR/payment date, not
            # the permit's issue date. Not mapped to date_issued on purpose.
        },
    },
    "marikina": {
        "boxes_json": BOXES_DIR / "business_permit_boxes_Marikina.json",
        "template": TEMPLATE_DIR / "business_permits" / "Business Permit (Marikina).png",
        "key_map": {
            "business_name": "trade_name",
            "business_owner_name": "name_of_proprietor",
            "business_address": "business_location",
            "nature_of_business": "kind_of_business",
            "date_issued": "date_issued",
        },
    },
    "taguig": {
        "boxes_json": BOXES_DIR / "business_permit_boxes_Taguig.json",
        "template": TEMPLATE_DIR / "business_permits" / "Business Permit (Taguig).png",
        "key_map": {
            "name_of_entity": "name_of_proprietor",
            "trade_name": "trade_name",
            "business_location": "business_location",
            "nature_of_business": "kind_of_business",
            "date_issued": "date_issued",
        },
    },
}


def build_business_permit_roi() -> dict[str, dict[str, dict]]:
    return {
        city: build_template_roi(
            f"business_permit/{city}",
            layout["boxes_json"],
            layout["template"],
            key_map=layout["key_map"],
            combine=layout.get("combine"),
        )
        for city, layout in _CITY_LAYOUTS.items()
    }


def main() -> int:
    roi_config = {
        "bir": build_template_roi(
            "bir",
            PY_ROOT / "json_data" / "bounding_boxes.json",
            PY_ROOT / "data" / "template" / "bir_permit" / "BIR_PERMIT_TEMPLATE.png",
        ),
        "dti": build_template_roi(
            "dti",
            PY_ROOT / "json_data" / "dti_registration.json",
            PY_ROOT / "data" / "template" / "dti_registration" / "dti_template.png",
        ),
        "business_permit": build_business_permit_roi(),
    }

    OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT_PATH.write_text(json.dumps(roi_config, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    permit_summary = ", ".join(
        f"{city}={len(fields)}" for city, fields in sorted(roi_config["business_permit"].items())
    )
    print(f"wrote {OUTPUT_PATH} "
          f"(bir={len(roi_config['bir'])} fields, dti={len(roi_config['dti'])} fields, "
          f"business_permit: {permit_summary})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
