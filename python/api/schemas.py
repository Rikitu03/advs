"""Response models for the stable, model-backed endpoints.

OCR, tamper, and validate responses pass through the dict shapes produced by
the reused pipeline modules (ocr_dryrun quality report, forensics.aggregate
verdict) — re-declaring those here would just drift from the source of truth.
"""

from __future__ import annotations

from pydantic import BaseModel, Field


class ModelStatus(BaseModel):
    loaded: bool
    path: str | None = None
    error: str | None = None


class ReadinessResponse(BaseModel):
    status: str
    service: str
    version: str
    errors: list[str]
    artifacts: dict


class HealthResponse(BaseModel):
    status: str
    service: str
    version: str
    models: dict[str, ModelStatus]


class ClassifyResponse(BaseModel):
    label: str
    confidence: float
    # 1 - P(fake): the Stage 3 authenticity the risk blend consumes. Equals
    # confidence when the model has no `fake` class.
    authenticity: float
    probabilities: dict[str, float]
    threshold: float
    passed_threshold: bool


class Detection(BaseModel):
    label: str
    confidence: float
    box: list[float]  # [x1, y1, x2, y2] in source-image pixels


class DetectResponse(BaseModel):
    detections: list[Detection]
    flags: list[str]


class SignatureEmbedResponse(BaseModel):
    embedding: list[float]


class SignatureVerifyResponse(BaseModel):
    # match is None when no empirical SIGNATURE_DISTANCE_THRESHOLD exists yet
    # (§9: it is determined during model validation, never invented).
    match: bool | None
    distance: float
    similarity: float  # provisional 1/(1+distance) mapping until EER calibration
    threshold: float | None
    embedding: list[float]


class SignatureSample(BaseModel):
    box: list[float]  # [x1, y1, x2, y2] in source-image pixels
    confidence: float
    embedding: list[float]


class EnrollForensics(BaseModel):
    # Lenient registration gate: hard_flag is set only by an image-editor metadata
    # signature or a copy-move clone ("placed on top"); soft signals are ignored.
    hard_flag: bool
    reasons: list[str]
    techniques: dict


class SignatureEnrollResponse(BaseModel):
    signatures: list[SignatureSample]
    count: int
    consistency: float | None  # mean pairwise cosine; None when < 2 signatures
    centroid: list[float] | None  # unit-norm reference vector; None when 0 signatures
    forensics: EnrollForensics


class StampEmbedResponse(BaseModel):
    vector: list[float]
    # Base64 PNG of the region actually embedded, returned only when the caller
    # passed a `box`. EnrollReferenceJob persists it as the issuer's
    # reference_image_path, so the stored crop is provably the embedded pixels.
    crop_png_base64: str | None = None


class StampReferenceMatch(BaseModel):
    key: str
    label: str
    source: str
    city: str | None = None
    similarity_score: float
    match: bool


class StampVerifyResponse(BaseModel):
    match: bool
    similarity_score: float | None = None
    threshold: float | None = None
    reason: str | None = None
    document_type: str | None = None
    city: str | None = None
    # §5 Stage 4b texture check — runs reference or not. Both are None when
    # stamp_classifier.pkl is not loaded (the check could not run at all).
    stamp_tampered: bool | None = None
    genuine_probability: float | None = None
    reference_source: str | None = None
    best_reference_key: str | None = None
    reference_matches: list[StampReferenceMatch] = Field(default_factory=list)
