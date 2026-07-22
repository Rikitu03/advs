"""Env-driven settings for the ADVS ML API.

Every tunable mirrors ADVS_System_Reference.md §9 (never invent thresholds);
model paths default to the canonical weight filenames under ``MODEL_DIR`` and
are meant to be (re)configured via env once training produces each model —
a missing file is reported by /health and yields 503 ``model_not_loaded`` on
its endpoints, it never crashes the service.
"""

from __future__ import annotations

from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict

PY_ROOT = Path(__file__).resolve().parents[1]


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=str(PY_ROOT / ".env.api"),
        env_file_encoding="utf-8",
        extra="ignore",
        protected_namespaces=(),
    )

    # --- auth (required for /v1/*; /health stays open) ---
    api_token: str = ""

    # --- model locations (configure once training finishes) ---
    model_dir: Path = PY_ROOT / "models"
    classifier_model_path: Path | None = None   # default: MODEL_DIR/resnet50_best.keras
    class_names_path: Path | None = None        # default: MODEL_DIR/class_names.json
    detector_model_path: Path | None = None     # default: MODEL_DIR/yolov8_document.pt
    siamese_model_path: Path | None = None      # default: MODEL_DIR/siamese_encoder.h5
    stamp_model_path: Path | None = None        # default: MODEL_DIR/efficientnet_feature_extractor.h5
    signature_threshold_path: Path | None = None  # default: MODEL_DIR/signature_threshold.txt
    stamp_threshold_path: Path | None = None      # default: MODEL_DIR/stamp_threshold.txt

    # --- §9 tunables ---
    classification_confidence_threshold: float = 0.70
    yolo_detection_confidence: float = 0.50
    stamp_similarity_threshold: float = 0.85
    # Empirical (EER) — set via env, or written by training to the threshold file.
    signature_distance_threshold: float | None = None
    pdf_dpi: int = 300
    max_pdf_pages: int = 2

    # --- runtime ---
    tesseract_cmd: str | None = None
    num_threads: int = 2  # HF free tier is CPU-limited; don't oversubscribe
    # Where PATCH /v1/config persists admin threshold overrides (JSON).
    threshold_store_path: Path | None = None

    # ------------------------------------------------------------------ paths
    @property
    def classifier_path(self) -> Path:
        return self.classifier_model_path or self.model_dir / "resnet50_best.keras"

    @property
    def classes_path(self) -> Path:
        return self.class_names_path or self.model_dir / "class_names.json"

    @property
    def detector_path(self) -> Path:
        return self.detector_model_path or self.model_dir / "yolov8_nano_moredata_best.pt"

    @property
    def siamese_path(self) -> Path:
        # The encoder, not the twin-tower model — the API only computes embeddings.
        return self.siamese_model_path or self.model_dir / "siamese_encoder.h5"

    @property
    def stamp_path(self) -> Path:
        # train_stamp.py's frozen EfficientNet-B0 feature extractor.
        return self.stamp_model_path or self.model_dir / "efficientnet_feature_extractor.h5"

    @property
    def store_path(self) -> Path:
        return self.threshold_store_path or PY_ROOT / ".thresholds.json"

    # ------------------------------------------------------------- thresholds
    def resolved_signature_distance_threshold(self) -> float | None:
        """Env value wins; otherwise read the training-produced threshold file.

        ``None`` means training has not produced an empirical threshold yet —
        signature verification then reports the distance without a match verdict.
        """
        if self.signature_distance_threshold is not None:
            return self.signature_distance_threshold
        return self._read_threshold_file(
            self.signature_threshold_path or self.model_dir / "signature_threshold.txt"
        )

    def resolved_stamp_similarity_threshold(self) -> float:
        """An explicitly set value (env, or an admin PATCH via /v1/config) wins;
        otherwise the training-produced file beats the §9 default."""
        if "stamp_similarity_threshold" in self.model_fields_set:
            return self.stamp_similarity_threshold
        from_file = self._read_threshold_file(
            self.stamp_threshold_path or self.model_dir / "stamp_threshold.txt"
        )
        return from_file if from_file is not None else self.stamp_similarity_threshold

    @staticmethod
    def _read_threshold_file(path: Path) -> float | None:
        try:
            return float(path.read_text(encoding="utf-8").strip())
        except (OSError, ValueError):
            return None
