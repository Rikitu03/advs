"""Runtime-tunable thresholds — the surface the admin dashboard's ML Models
page talks to (GET/PATCH /v1/config).

§9 thresholds are operator-configurable, so they must be changeable without a
redeploy. Updates are validated, applied to the live Settings object (every
stage reads thresholds from it per request), and persisted to a small JSON
store so a process restart keeps them. Precedence per key:

    admin override (PATCH) > env var > training-produced threshold file > default

PATCHing a key to ``null`` resets it — the env/default value returns, and for
signature/stamp the training threshold file becomes the fallback again.

The store file is local state: on an ephemeral host (a rebuilt HF Space) it
resets to env defaults, so Laravel should treat its own settings as canonical
and re-push after a rebuild (a changed /health boot is the signal).
"""

from __future__ import annotations

import json
import logging
import os
import tempfile
from pathlib import Path
from typing import Any

from pydantic import BaseModel, ConfigDict, Field

from .config import Settings

logger = logging.getLogger("advs.api.thresholds")

MUTABLE_KEYS = (
    "classification_confidence_threshold",
    "yolo_detection_confidence",
    "stamp_similarity_threshold",
    "signature_distance_threshold",
    "pdf_dpi",
    "max_pdf_pages",
)


class ThresholdUpdate(BaseModel):
    """PATCH /v1/config body — partial; ``null`` resets a key."""

    model_config = ConfigDict(extra="forbid")

    classification_confidence_threshold: float | None = Field(None, ge=0.0, le=1.0)
    yolo_detection_confidence: float | None = Field(None, ge=0.0, le=1.0)
    stamp_similarity_threshold: float | None = Field(None, ge=0.0, le=1.0)
    signature_distance_threshold: float | None = Field(None, ge=0.0)
    pdf_dpi: int | None = Field(None, ge=72, le=600)
    max_pdf_pages: int | None = Field(None, ge=1, le=10)


class ThresholdManager:
    """Owns the mutable threshold state on top of the boot-time Settings."""

    def __init__(self, settings: Settings, store_path: Path) -> None:
        self.settings = settings
        self.store_path = store_path
        # Boot snapshot: what env/defaults produced, and which of the mutable
        # keys were explicitly set (env/kwargs) — resets restore exactly this.
        self._defaults: dict[str, Any] = {key: getattr(settings, key) for key in MUTABLE_KEYS}
        self._boot_fields_set = set(settings.model_fields_set) & set(MUTABLE_KEYS)
        self._overrides: dict[str, Any] = {}
        self._load_store()

    # ------------------------------------------------------------ mutation
    def apply(self, update: ThresholdUpdate) -> dict:
        for key in update.model_fields_set:
            value = getattr(update, key)
            if value is None:
                self._reset(key)
            else:
                setattr(self.settings, key, value)
                self._overrides[key] = value
        self._save_store()
        logger.info("thresholds updated: %s", sorted(update.model_fields_set))
        return self.snapshot()

    def _reset(self, key: str) -> None:
        self._overrides.pop(key, None)
        setattr(self.settings, key, self._defaults[key])
        if key not in self._boot_fields_set:
            # Assignment marks the field "explicitly set", which would keep an
            # env-style precedence over the training threshold file — undo it
            # so the file fallback applies again, as it did at boot.
            self.settings.__pydantic_fields_set__.discard(key)

    # ------------------------------------------------------------ reporting
    def snapshot(self) -> dict:
        return {
            "thresholds": {key: getattr(self.settings, key) for key in MUTABLE_KEYS},
            "effective": {
                # What verify actually uses once file fallbacks are resolved.
                "signature_distance_threshold": self.settings.resolved_signature_distance_threshold(),
                "stamp_similarity_threshold": self.settings.resolved_stamp_similarity_threshold(),
            },
            "overrides": dict(self._overrides),
            "defaults": dict(self._defaults),
        }

    # ---------------------------------------------------------- persistence
    def _load_store(self) -> None:
        try:
            stored = json.loads(self.store_path.read_text(encoding="utf-8"))
        except FileNotFoundError:
            return
        except (OSError, ValueError) as exc:
            logger.warning("ignoring unreadable threshold store %s: %s", self.store_path, exc)
            return

        if not isinstance(stored, dict):
            logger.warning("ignoring malformed threshold store %s", self.store_path)
            return

        valid = {key: value for key, value in stored.items() if key in MUTABLE_KEYS}
        try:
            update = ThresholdUpdate(**valid)
        except ValueError as exc:
            logger.warning("ignoring invalid stored thresholds: %s", exc)
            return

        for key in update.model_fields_set:
            value = getattr(update, key)
            if value is not None:
                setattr(self.settings, key, value)
                self._overrides[key] = value

    def _save_store(self) -> None:
        payload = json.dumps(self._overrides, indent=2)
        self.store_path.parent.mkdir(parents=True, exist_ok=True)
        fd, tmp_name = tempfile.mkstemp(
            dir=str(self.store_path.parent), prefix=self.store_path.name, suffix=".tmp"
        )
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as handle:
                handle.write(payload)
            os.replace(tmp_name, self.store_path)
        except OSError as exc:
            logger.error("could not persist thresholds to %s: %s", self.store_path, exc)
            try:
                os.unlink(tmp_name)
            except OSError:
                pass
