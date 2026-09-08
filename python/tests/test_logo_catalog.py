from __future__ import annotations

import json
import sys
from pathlib import Path

import pytest
from PIL import Image

PY_ROOT = Path(__file__).resolve().parents[1]
if str(PY_ROOT) not in sys.path:
    sys.path.insert(0, str(PY_ROOT))

from api import logo_catalog  # noqa: E402
from api.routers import stamp  # noqa: E402


def _manifest(root: Path, references: list[dict]) -> Path:
    path = root / "manifest.json"
    path.write_text(json.dumps({"schema_version": "test", "references": references}), encoding="utf-8")
    return path


def _reference(key: str, path: str, *, city: str | None = None) -> dict:
    return {
        "key": key,
        "document_type": "business_permit" if city else "bir_certificate",
        "issuer_scope": "lgu" if city else "national",
        "city": city,
        "label": key.replace("-", " ").title(),
        "path": path,
    }


def test_manifest_resolves_ordered_national_and_canonical_lgu_candidates(tmp_path: Path) -> None:
    manifest = _manifest(tmp_path, [
        _reference("bir-one", "bir-one.png"),
        _reference("bir-two", "bir-two.png"),
        _reference("makati", "makati.png", city="Makati"),
    ])

    national = logo_catalog.resolve_candidates("bir_certificate", "national", None, manifest)
    lgu = logo_catalog.resolve_candidates("business_permit", "lgu", "CITY OF MAKATI", manifest)

    assert [candidate["key"] for candidate in national] == ["bir-one", "bir-two"]
    assert [candidate["key"] for candidate in lgu] == ["makati"]


def test_manifest_rejects_paths_outside_the_catalog_root(tmp_path: Path) -> None:
    manifest = _manifest(tmp_path, [_reference("unsafe", "../outside.png")])

    with pytest.raises(logo_catalog.CatalogError, match="unsafe path"):
        logo_catalog.load_manifest(manifest)


def test_embedding_cache_uses_model_identity_and_file_timestamp(tmp_path: Path, monkeypatch) -> None:
    image_path = tmp_path / "reference.png"
    Image.new("RGB", (12, 12), "white").save(image_path)
    candidate = {
        "key": "reference",
        "label": "Reference",
        "city": None,
        "path": image_path,
    }
    calls = []

    def fake_embed(model, image):
        calls.append((model, image.size))
        return [float(len(calls)), 0.0]

    monkeypatch.setattr(logo_catalog, "embed_stamp", fake_embed)
    logo_catalog.clear_caches()
    model = object()

    first = logo_catalog.embed_candidates(model, [candidate])
    second = logo_catalog.embed_candidates(model, [candidate])
    Image.new("RGB", (14, 14), "black").save(image_path)
    third = logo_catalog.embed_candidates(model, [candidate])

    assert first[0]["vector"] == second[0]["vector"] == [1.0, 0.0]
    assert third[0]["vector"] == [2.0, 0.0]
    assert len(calls) == 2


def test_embedding_skips_missing_or_corrupt_assets_individually(tmp_path: Path, monkeypatch) -> None:
    valid_path = tmp_path / "valid.png"
    corrupt_path = tmp_path / "corrupt.png"
    Image.new("RGB", (8, 8), "white").save(valid_path)
    corrupt_path.write_text("not an image", encoding="utf-8")
    monkeypatch.setattr(logo_catalog, "embed_stamp", lambda model, image: [1.0, 0.0])
    logo_catalog.clear_caches()

    embedded = logo_catalog.embed_candidates(object(), [
        {"key": "missing", "label": "Missing", "city": None, "path": tmp_path / "missing.png"},
        {"key": "corrupt", "label": "Corrupt", "city": None, "path": corrupt_path},
        {"key": "valid", "label": "Valid", "city": None, "path": valid_path},
    ])

    assert [candidate["key"] for candidate in embedded] == ["valid"]


class _Settings:
    stamp_tamper_threshold = 0.5

    @staticmethod
    def resolved_stamp_similarity_threshold() -> float:
        return 0.85


def test_best_candidate_drives_legacy_fields_and_preserves_order(monkeypatch) -> None:
    monkeypatch.setattr(stamp, "embed_stamp", lambda model, image: [1.0, 0.0])

    result = stamp.run_stamp_verify(
        object(),
        Image.new("RGB", (8, 8), "white"),
        None,
        _Settings(),
        document_type="bir_certificate",
        reference_candidates=[
            {"key": "weak", "label": "Weak", "source": "curated", "city": None, "vector": [0.5, 0.5]},
            {"key": "best", "label": "Best", "source": "curated", "city": None, "vector": [1.0, 0.0]},
        ],
    )

    assert result["match"] is True
    assert result["similarity_score"] == pytest.approx(1.0)
    assert result["reference_source"] == "curated"
    assert result["best_reference_key"] == "best"
    assert [match["key"] for match in result["reference_matches"]] == ["weak", "best"]


def test_single_database_vector_remains_a_legacy_fallback(monkeypatch) -> None:
    monkeypatch.setattr(stamp, "embed_stamp", lambda model, image: [1.0, 0.0])

    result = stamp.run_stamp_verify(
        object(),
        Image.new("RGB", (8, 8), "white"),
        [1.0, 0.0],
        _Settings(),
    )

    assert result["match"] is True
    assert result["reference_source"] == "enrolled"
    assert result["best_reference_key"] == "enrolled-reference"
    assert result["reference_matches"][0]["key"] == "enrolled-reference"
