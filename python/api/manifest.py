"""Model artifact manifest loading and compatibility checks.

The manifest is deployment metadata, not a model loader. It gives callers a
stable provenance report and lets ``/ready`` reject missing, stale, or
incompatible artifacts before the queue starts sending documents.
"""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any

MANIFEST_SCHEMA_VERSION = "1.0"
REQUIRED_MODELS = ("classifier", "detector", "siamese", "stamp", "stamp_classifier")


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


class ArtifactManifest:
    """Parsed model manifest plus deployability diagnostics."""

    def __init__(self, path: Path) -> None:
        self.path = path
        self.data: dict[str, Any] = {}
        self.error: str | None = None
        self._load()

    def _load(self) -> None:
        try:
            parsed = json.loads(self.path.read_text(encoding="utf-8"))
        except FileNotFoundError:
            self.error = "manifest_not_found"
            return
        except (OSError, ValueError) as exc:
            self.error = f"manifest_unreadable: {exc}"
            return

        if not isinstance(parsed, dict) or not isinstance(parsed.get("models"), dict):
            self.error = "manifest_invalid_shape"
            return
        if str(parsed.get("schema_version")) != MANIFEST_SCHEMA_VERSION:
            self.error = "manifest_schema_incompatible"
            return
        self.data = parsed

    @property
    def schema_version(self) -> str | None:
        value = self.data.get("schema_version")
        return str(value) if value is not None else None

    def entry(self, name: str) -> dict[str, Any] | None:
        entry = self.data.get("models", {}).get(name)
        return entry if isinstance(entry, dict) else None

    def report(self, model_paths: dict[str, Path], statuses: dict[str, dict[str, Any]]) -> dict:
        models: dict[str, dict[str, Any]] = {}
        for name, status in statuses.items():
            entry = self.entry(name)
            models[name] = {
                **status,
                "version": entry.get("version") if entry else None,
                "sha256": entry.get("sha256") if entry else None,
                "input_shape": entry.get("input_shape") if entry else None,
                "output_shape": entry.get("output_shape") if entry else None,
                "classes": entry.get("classes") if entry else None,
                "trained_at": entry.get("trained_at") if entry else None,
                "metrics": entry.get("metrics") if entry else None,
                "calibrated_threshold": entry.get("calibrated_threshold") if entry else None,
                "threshold": entry.get("calibrated_threshold") if entry else None,
                "manifest_compatible": self._entry_compatible(name, model_paths.get(name), entry),
                "manifest_status": self._entry_status(name, model_paths.get(name), entry),
            }

        return {
            "schema_version": self.schema_version,
            "path": str(self.path),
            "error": self.error,
            "models": models,
        }

    def readiness_errors(
        self,
        model_paths: dict[str, Path],
        statuses: dict[str, dict[str, Any]],
    ) -> list[str]:
        errors: list[str] = []
        if self.error:
            errors.append(self.error)

        for name in REQUIRED_MODELS:
            if not statuses.get(name, {}).get("loaded"):
                errors.append(f"{name}:model_not_loaded")
            entry = self.entry(name)
            if entry is None:
                errors.append(f"{name}:manifest_entry_missing")
            elif not self._entry_compatible(name, model_paths.get(name), entry):
                errors.append(f"{name}:artifact_incompatible")
        return errors

    def output_dimension(self, name: str) -> int | None:
        entry = self.entry(name)
        shape = entry.get("output_shape") if entry else None
        if isinstance(shape, list) and shape:
            try:
                return int(shape[-1])
            except (TypeError, ValueError):
                return None
        return None

    def _entry_compatible(
        self,
        name: str,
        path: Path | None,
        entry: dict[str, Any] | None,
    ) -> bool:
        if self.error or entry is None or path is None or not path.is_file():
            return False
        expected = entry.get("sha256")
        if not isinstance(expected, str) or len(expected) != 64:
            return False
        try:
            return sha256_file(path) == expected.lower()
        except OSError:
            return False

    def _entry_status(
        self,
        name: str,
        path: Path | None,
        entry: dict[str, Any] | None,
    ) -> str:
        if self.error:
            return self.error
        if entry is None:
            return "entry_missing"
        if path is None or not path.exists():
            return "artifact_missing"
        return "compatible" if self._entry_compatible(name, path, entry) else "checksum_mismatch"
