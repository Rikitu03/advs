"""Load-once model registry (the guide's ``models = {}`` singleton, formalised).

Each model is loaded at startup ONLY if its configured weight file exists —
cold-loading per request would eat the free CPU quota, and missing weights
must degrade to a clean 503, not a crash. Heavy imports (tensorflow,
ultralytics/torch) stay inside the loaders so the app boots fast and tests
that never touch a model never pay for them.
"""

from __future__ import annotations

import logging
from typing import Any, Callable

from fastapi import HTTPException

from .compat import load_script
from .config import Settings

logger = logging.getLogger("advs.api.registry")

MODEL_NAMES = ("classifier", "detector", "siamese", "stamp", "rapid_detector",
               "trocr", "trocr_accurate")


class ModelRegistry:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings
        self._models: dict[str, Any] = {}
        self._status: dict[str, dict[str, Any]] = {}

    # ------------------------------------------------------------- lifecycle
    def load_all(self) -> None:
        loaders: dict[str, tuple[str, Callable[[], Any]]] = {
            "classifier": (str(self.settings.classifier_path), self._load_classifier),
            "detector": (str(self.settings.detector_path), self._load_detector),
            "siamese": (str(self.settings.siamese_path), self._load_siamese),
            "stamp": (str(self.settings.stamp_path), self._load_stamp),
            # A local snapshot directory (transformers save_pretrained()
            # layout), not a single file, but the same "configured path must
            # exist" gate as everything else — unset/missing means "not
            # loaded", never a network fetch. This is what keeps existing
            # tests (empty tmp model_dir) fast and offline.
            # Two TrOCR recognizers, routed per template by routers/ocr.py: the
            # fast one by default, the accurate one for the templates that
            # measurably need it (BIR). Baking only one is a supported setup —
            # the router falls back to whichever is loaded.
            "trocr": (str(self.settings.trocr_path), self._load_trocr),
            "trocr_accurate": (str(self.settings.trocr_accurate_path), self._load_trocr_accurate),
        }
        for name, (path, loader) in loaders.items():
            is_dir = name in ("trocr", "trocr_accurate")
            self._load(name, path, loader, is_dir=is_dir)

        # RapidOCR bundles its own PP-OCRv4 detector ONNX weights inside the
        # pip package — no local path to gate on. Always attempt; it's a
        # fast (~15MB), fully offline load, the same spirit as importing any
        # other installed dependency rather than a "trained weights" model.
        self._load_ungated("rapid_detector", self._load_rapid_detector)

    def clear(self) -> None:
        self._models.clear()
        self._status.clear()

    def _load(self, name: str, path: str, loader: Callable[[], Any], *, is_dir: bool = False) -> None:
        from pathlib import Path

        p = Path(path)
        exists = p.is_dir() if is_dir else p.is_file()
        if not exists:
            self._status[name] = {"loaded": False, "path": path, "error": "weights_not_found"}
            logger.info("model %s not loaded: no weights at %s", name, path)
            return
        try:
            self._models[name] = loader()
            self._status[name] = {"loaded": True, "path": path, "error": None}
            logger.info("model %s loaded from %s", name, path)
        except Exception as exc:  # fail-forward: a bad weight file must not kill boot
            self._status[name] = {"loaded": False, "path": path, "error": str(exc)}
            logger.exception("model %s failed to load from %s", name, path)

    def _load_ungated(self, name: str, loader: Callable[[], Any]) -> None:
        """For models with no configured weight path at all (e.g. bundled
        inside their own pip package) — still fail-forward on a load error,
        just without a path-existence gate to check first."""
        try:
            self._models[name] = loader()
            self._status[name] = {"loaded": True, "path": None, "error": None}
            logger.info("model %s loaded (no configured path)", name)
        except Exception as exc:
            self._status[name] = {"loaded": False, "path": None, "error": str(exc)}
            logger.exception("model %s failed to load", name)

    # --------------------------------------------------------------- access
    def status(self) -> dict[str, dict[str, Any]]:
        return self._status

    def get(self, name: str) -> Any | None:
        return self._models.get(name)

    def require(self, name: str) -> Any:
        model = self._models.get(name)
        if model is None:
            info = self._status.get(name, {"path": None, "error": "not_initialised"})
            raise HTTPException(
                status_code=503,
                detail={
                    "reason": "model_not_loaded",
                    "model": name,
                    "configured_path": info.get("path"),
                    "error": info.get("error"),
                },
            )
        return model

    # -------------------------------------------------------------- loaders
    def _load_classifier(self) -> dict[str, Any]:
        import tensorflow as tf

        predict_classifier = load_script("predict_classifier")
        return {
            "model": tf.keras.models.load_model(self.settings.classifier_path),
            "class_names": predict_classifier.load_class_names(self.settings.classes_path),
        }

    def _load_detector(self) -> Any:
        from ultralytics import YOLO

        return YOLO(str(self.settings.detector_path))

    def _load_siamese(self) -> Any:
        # SIAMESE_MODEL_PATH defaults to the encoder (siamese_encoder.h5), not
        # the twin-tower siamese_signature.h5 — the API only computes embeddings.
        import tensorflow as tf

        return tf.keras.models.load_model(self.settings.siamese_path)

    def _load_stamp(self) -> Any:
        import tensorflow as tf

        return tf.keras.models.load_model(self.settings.stamp_path)

    def _load_rapid_detector(self) -> Any:
        from rapidocr_onnxruntime import RapidOCR

        return RapidOCR()

    def _load_trocr(self) -> Any:
        # roi_field_ocr owns TrOCR loading for both layouts (torch snapshot vs
        # ONNX export) — one place decides which runtime a baked dir needs.
        return load_script("roi_field_ocr").load_trocr(self.settings.trocr_path)

    def _load_trocr_accurate(self) -> Any:
        return load_script("roi_field_ocr").load_trocr(self.settings.trocr_accurate_path)
