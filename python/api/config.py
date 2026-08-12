"""Env-driven settings for the ADVS ML API.

Every tunable mirrors ADVS_System_Reference.md §9 (never invent thresholds);
model paths default to the canonical weight filenames under ``MODEL_DIR`` and
are meant to be (re)configured via env once training produces each model —
a missing file is reported by /health and yields 503 ``model_not_loaded`` on
its endpoints, it never crashes the service.
"""

from __future__ import annotations

import os
from pathlib import Path

from pydantic import Field
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
    stamp_classifier_model_path: Path | None = None  # default: MODEL_DIR/stamp_classifier.pkl
    signature_threshold_path: Path | None = None  # default: MODEL_DIR/signature_threshold.txt
    stamp_threshold_path: Path | None = None      # default: MODEL_DIR/stamp_threshold.txt
    # ROI+TrOCR field-recognition fallback (Stage 2 augmentation, see
    # roi_field_ocr.py). Same convention as every other model: a LOCAL
    # snapshot directory (transformers' save_pretrained() layout), gated on
    # existing — never a bare HF Hub id, so a missing/unbaked model degrades
    # to "not loaded" with zero network calls, instead of every request (or
    # every test that builds the app) silently trying to fetch ~2.3GB.
    # Two recognizers, routed per template (see routers/ocr.resolve_recognizer).
    # Benchmarked on the real BIR / permit / DTI samples: base and large agree
    # exactly on DTI and Business Permit while base is ~3x faster, but on BIR
    # base misreads values including the issue YEAR — so BIR alone pays for the
    # accurate model. Either path missing simply falls back to the other.
    trocr_model_path: Path | None = None            # default: MODEL_DIR/trocr-base-printed
    trocr_accurate_model_path: Path | None = None   # default: MODEL_DIR/trocr-large-printed
    trocr_accurate_templates: str = "bir"           # comma-separated template names

    # --- §9 tunables ---
    classification_confidence_threshold: float = 0.70
    yolo_detection_confidence: float = 0.50
    stamp_similarity_threshold: float = 0.85
    # NOT a new §9 parameter. train_stamp.py fits a binary LogisticRegression
    # (class 1 = genuine wet ink, class 0 = photocopy/edit) whose own predict()
    # boundary is 0.50; this exposes that boundary so an operator can trade
    # false accepts against false rejects without retraining.
    stamp_tamper_threshold: float = 0.50
    # Empirical (EER) — set via env, or written by training to the threshold file.
    signature_distance_threshold: float | None = None
    pdf_dpi: int = 300
    max_pdf_pages: int = 2

    # --- Stage 2 ROI+TrOCR pass (roi_field_ocr.py) ---
    # Not §9 parameters — these bound the *cost* of the field-recognition pass,
    # they do not affect any score or threshold. Each field crop is ~10-30s of
    # CPU inference, so an ungated BIR page (13 fields) measured 409s against
    # Laravel's 180s ML_API_TIMEOUT; the budget makes the stage bounded and
    # fail-forward instead of taking the whole /v1/validate call down.
    # Per page; fields the budget cannot reach keep their label+regex value.
    # 210 = the measured worst case (BIR, 11 crops on the accurate recognizer,
    # 186s) plus headroom. DTI/Business Permit never approach it (8s / 3s on
    # the fast recognizer) — this is a ceiling, not a spend.
    roi_budget_seconds: float = 210.0
    # Decode cap per field crop. 64, not 32: a real BIR registered_address
    # decodes to 70 chars (~34 tokens) and a 32-token cap truncated it to a
    # wrong-but-plausible value. Generation stops at EOS, so short fields
    # never pay for this headroom.
    trocr_max_new_tokens: int = 64
    # Skip TrOCR on a field Tesseract already read at or above this per-word
    # confidence. Deliberately HIGH: measured on a real BIR page, Tesseract
    # reports 68-78% on values it read wrong ("NORTHZ2N STAR FINANCE",
    # "GEMINI STREZT", "CONSTRUCTION CF"), so a low floor gates out exactly
    # the fields TrOCR exists to fix. Only a near-certain read is worth
    # skipping; the time budget, not this gate, is what bounds the pass.
    roi_tesseract_confidence_floor: float = 90.0

    # --- runtime ---
    tesseract_cmd: str | None = None
    # CPU thread cap. Auto-sizes to the host: 2 on the HF free tier (2 vCPU),
    # 4 on the Oracle A1 deploy target (4 OCPU), without oversubscribing a
    # bigger dev box. Measured: 1 TrOCR crop 15.8s @2 threads vs 10.3s @8.
    num_threads: int = Field(default_factory=lambda: min(4, os.cpu_count() or 2))
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
    def stamp_classifier_path(self) -> Path:
        # train_stamp.py's genuine/forged LogisticRegression over the 1280-D
        # EfficientNet features — the §5 Stage 4b texture check.
        return self.stamp_classifier_model_path or self.model_dir / "stamp_classifier.pkl"

    @property
    def store_path(self) -> Path:
        return self.threshold_store_path or PY_ROOT / ".thresholds.json"

    @property
    def trocr_path(self) -> Path:
        # A local directory (baked into the Docker image at build time, or
        # downloaded once via generate_roi_config.py's sibling bake step) —
        # never a bare HF Hub id; see the field comment above for why.
        return self.trocr_model_path or self.model_dir / "trocr-base-printed"

    @property
    def trocr_accurate_path(self) -> Path:
        return self.trocr_accurate_model_path or self.model_dir / "trocr-large-printed"

    def accurate_templates(self) -> tuple[str, ...]:
        return tuple(
            name.strip() for name in self.trocr_accurate_templates.split(",") if name.strip()
        )

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
