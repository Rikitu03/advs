"""Keras embedding helpers shared by the signature (Siamese) and stamp
(EfficientNet) verifiers. Untested until training produces those weights —
the routers gate every call behind ``registry.require``.
"""

from __future__ import annotations

import json

from fastapi import HTTPException
from PIL import Image


def parse_reference(raw: str, field: str) -> list[float]:
    try:
        vector = json.loads(raw)
        if not isinstance(vector, list) or not vector:
            raise ValueError("expected a non-empty JSON array of numbers")
        return [float(v) for v in vector]
    except (ValueError, TypeError) as exc:
        raise HTTPException(status_code=422, detail=f"Invalid {field}: {exc}") from exc


def keras_input_size(model) -> tuple[int, int]:
    shape = model.input_shape
    if isinstance(shape, list):  # twin-tower models expose one shape per input
        shape = shape[0]
    if len(shape) < 3 or shape[1] is None or shape[2] is None:
        raise ValueError(f"Unexpected model input shape: {shape}")
    return int(shape[1]), int(shape[2])


def embed(model, image: Image.Image, preprocess) -> list[float]:
    """Run one crop through a Keras feature extractor -> flat float vector."""
    import numpy as np

    batch = np.expand_dims(
        np.asarray(image.convert("RGB").resize(keras_input_size(model)), dtype=np.float32),
        axis=0,
    )
    batch = preprocess(batch)
    output = model.predict(batch, verbose=0)
    return [float(v) for v in np.asarray(output)[0].ravel()]


def euclidean(a: list[float], b: list[float]) -> float:
    import numpy as np

    return float(np.linalg.norm(np.asarray(a, dtype=np.float64) - np.asarray(b, dtype=np.float64)))


def cosine_similarity(a: list[float], b: list[float]) -> float:
    import numpy as np

    va = np.asarray(a, dtype=np.float64)
    vb = np.asarray(b, dtype=np.float64)
    denom = float(np.linalg.norm(va) * np.linalg.norm(vb))
    if denom == 0.0:
        return 0.0
    return float(np.dot(va, vb) / denom)


def mean_pairwise_cosine(vectors: list[list[float]]) -> float | None:
    """Mean cosine similarity over every unordered pair of embeddings.

    The registration consistency gate: three same-session signatures should embed
    close together, so a low mean signals mixed/dissimilar samples. ``None`` when
    fewer than two vectors are given (no pair to compare)."""
    if len(vectors) < 2:
        return None
    total = 0.0
    pairs = 0
    for i in range(len(vectors)):
        for j in range(i + 1, len(vectors)):
            total += cosine_similarity(vectors[i], vectors[j])
            pairs += 1
    return total / pairs


def centroid(vectors: list[list[float]]) -> list[float]:
    """The unit-normalised mean of the embeddings — the single reference vector the
    document pipeline verifies against (§5 Stage 4a). The Siamese encoder emits
    L2-normalised vectors, so the mean is re-normalised back onto the unit sphere to
    keep it comparable to future query embeddings under Euclidean distance."""
    import numpy as np

    if not vectors:
        raise ValueError("centroid requires at least one vector")
    mean = np.asarray(vectors, dtype=np.float64).mean(axis=0)
    norm = float(np.linalg.norm(mean))
    if norm == 0.0:
        return [float(v) for v in mean]
    return [float(v) for v in (mean / norm)]


def require_same_length(reference: list[float], embedding: list[float], field: str) -> None:
    if len(reference) != len(embedding):
        raise HTTPException(
            status_code=422,
            detail=f"{field} length {len(reference)} does not match model output length {len(embedding)}.",
        )
