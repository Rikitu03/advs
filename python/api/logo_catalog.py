"""Curated issuer-logo catalog shared by validation and direct stamp checks."""

from __future__ import annotations

import json
import logging
from pathlib import Path, PurePosixPath
from typing import Any

from PIL import Image, UnidentifiedImageError

from . import embedding as emb
from .compat import load_script

logger = logging.getLogger("advs.api.logo_catalog")

CATALOG_DIR = Path(__file__).resolve().parents[1] / "logo_and_seals"
MANIFEST_PATH = CATALOG_DIR / "manifest.json"

_manifest_cache: dict[tuple[str, int, int], dict[str, Any]] = {}
_vector_cache: dict[tuple[int, str, int, int], list[float]] = {}


class CatalogError(ValueError):
    """The curated manifest is malformed or points outside its catalog root."""


def canonical_city(value: str | None) -> str | None:
    if value is None:
        return None
    squished = " ".join(str(value).split())
    if not squished:
        return None
    permit_city = load_script("ocr_dryrun").canonical_business_permit_city(squished)
    return permit_city if permit_city is not None else squished.title()


def clear_caches() -> None:
    _manifest_cache.clear()
    _vector_cache.clear()


def load_manifest(manifest_path: Path | None = None) -> dict[str, Any]:
    path = (manifest_path or MANIFEST_PATH).resolve()
    try:
        stat = path.stat()
    except OSError as exc:
        raise CatalogError(f"Issuer-logo manifest is unavailable: {path}") from exc

    cache_key = (str(path), stat.st_mtime_ns, stat.st_size)
    if cache_key in _manifest_cache:
        return _manifest_cache[cache_key]

    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        raise CatalogError(f"Issuer-logo manifest is unreadable: {exc}") from exc

    if not isinstance(payload, dict) or not isinstance(payload.get("references"), list):
        raise CatalogError("Issuer-logo manifest must contain a references array.")

    version = payload.get("schema_version")
    if not isinstance(version, str) or not version.strip():
        raise CatalogError("Issuer-logo manifest requires a schema_version.")

    root = path.parent.resolve()
    references: list[dict[str, Any]] = []
    keys: set[str] = set()
    for index, raw in enumerate(payload["references"]):
        if not isinstance(raw, dict):
            raise CatalogError(f"Reference {index} must be an object.")

        key = raw.get("key")
        document_type = raw.get("document_type")
        issuer_scope = raw.get("issuer_scope")
        label = raw.get("label")
        relative_path = raw.get("path")
        if not all(isinstance(value, str) and value.strip()
                   for value in (key, document_type, issuer_scope, label, relative_path)):
            raise CatalogError(f"Reference {index} has incomplete metadata.")
        if issuer_scope not in ("national", "lgu"):
            raise CatalogError(f"Reference {key} has an invalid issuer_scope.")
        if key in keys:
            raise CatalogError(f"Reference key {key!r} is duplicated.")

        relative = PurePosixPath(relative_path)
        if relative.is_absolute() or ".." in relative.parts:
            raise CatalogError(f"Reference {key} has an unsafe path.")
        absolute = (root / Path(*relative.parts)).resolve()
        if absolute != root and root not in absolute.parents:
            raise CatalogError(f"Reference {key} resolves outside the catalog root.")

        city = canonical_city(raw.get("city")) if issuer_scope == "lgu" else None
        if issuer_scope == "lgu" and city is None:
            raise CatalogError(f"LGU reference {key} requires a canonical city.")

        keys.add(key)
        references.append({
            "key": key,
            "document_type": document_type.strip().lower(),
            "issuer_scope": issuer_scope,
            "city": city,
            "label": label.strip(),
            "path": absolute,
        })

    catalog = {"schema_version": version, "references": references}
    _manifest_cache.clear()
    _manifest_cache[cache_key] = catalog
    return catalog


def resolve_candidates(
    document_type: str | None,
    issuer_scope: str | None,
    city: str | None,
    manifest_path: Path | None = None,
) -> list[dict[str, Any]]:
    if not document_type:
        return []

    normalized_type = document_type.strip().lower()
    normalized_scope = issuer_scope.strip().lower() if issuer_scope else None
    normalized_city = canonical_city(city)
    references = [
        reference for reference in load_manifest(manifest_path)["references"]
        if reference["document_type"] == normalized_type
    ]

    if normalized_scope in ("national", "lgu"):
        references = [reference for reference in references
                      if reference["issuer_scope"] == normalized_scope]
    elif normalized_city is not None:
        references = [reference for reference in references
                      if reference["issuer_scope"] == "lgu"]
    else:
        references = [reference for reference in references
                      if reference["issuer_scope"] == "national"]

    if any(reference["issuer_scope"] == "lgu" for reference in references):
        if normalized_city is None:
            return []
        references = [reference for reference in references
                      if canonical_city(reference["city"]) == normalized_city]

    return references


def _efficientnet_preprocess(batch):
    from tensorflow.keras.applications.efficientnet import preprocess_input

    return preprocess_input(batch)


def embed_stamp(model, image: Image.Image) -> list[float]:
    return emb.embed(model, image, _efficientnet_preprocess)


def embed_candidates(model, candidates: list[dict[str, Any]]) -> list[dict[str, Any]]:
    embedded: list[dict[str, Any]] = []
    for candidate in candidates:
        path = candidate["path"]
        try:
            stat = path.stat()
            cache_key = (id(model), str(path), stat.st_mtime_ns, stat.st_size)
            vector = _vector_cache.get(cache_key)
            if vector is None:
                with Image.open(path) as image:
                    image.load()
                    vector = embed_stamp(model, image.convert("RGB"))
                _vector_cache[cache_key] = vector
        except (OSError, UnidentifiedImageError, ValueError) as exc:
            logger.warning("skipping issuer reference %s: %s", candidate["key"], exc)
            continue

        embedded.append({
            "key": candidate["key"],
            "label": candidate["label"],
            "source": "curated",
            "city": candidate["city"],
            "vector": vector,
        })

    return embedded
