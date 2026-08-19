"""ADVS ResNet-50 classifier inference CLI.

Loads the saved classifier model from ``python/models/resnet50_classifier.keras``
and predicts the class for a single input image.

Usage:
    python scripts/predict_classifier.py --image /path/to/document.jpg
    python scripts/predict_classifier.py --image /path/to/document.jpg --model /custom/model.keras

Output:
    JSON written to stdout with the predicted label, confidence, and class
    probabilities.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

import numpy as np
from PIL import Image

PY_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_MODEL_PATH = PY_ROOT / "models" / "resnet50_best.keras"
DEFAULT_CLASS_NAMES_PATH = PY_ROOT / "models" / "class_names.json"


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Predict a document class using the ADVS classifier.")
    parser.add_argument("--image", required=True, help="Path to the input image.")
    parser.add_argument(
        "--model",
        default=str(DEFAULT_MODEL_PATH),
        help="Path to the saved Keras model (.keras or .h5).",
    )
    parser.add_argument(
        "--class-names",
        default=str(DEFAULT_CLASS_NAMES_PATH),
        help="Path to the JSON file containing class names in training order.",
    )
    return parser.parse_args(argv)


def load_class_names(path: Path) -> list[str]:
    if not path.is_file():
        raise FileNotFoundError(f"Class name mapping not found: {path}")

    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, list) or not all(isinstance(item, str) for item in data):
        raise ValueError(f"Invalid class name mapping in {path}; expected a JSON list of strings.")
    return data


def load_image(path: Path, target_size: tuple[int, int]) -> np.ndarray:
    if not path.is_file():
        raise FileNotFoundError(f"Image not found: {path}")

    with Image.open(path) as image:
        image = image.convert("RGB").resize(target_size)
        array = np.asarray(image, dtype=np.float32)

    return np.expand_dims(array, axis=0)


def predict(image_path: Path, model_path: Path, class_names_path: Path) -> dict:
    import tensorflow as tf

    if not model_path.is_file():
        raise FileNotFoundError(f"Model not found: {model_path}")

    model = tf.keras.models.load_model(model_path)
    class_names = load_class_names(class_names_path)

    input_shape = model.input_shape
    if len(input_shape) < 3 or input_shape[1] is None or input_shape[2] is None:
        raise ValueError(f"Unexpected model input shape: {input_shape}")

    # Raw 0-255 input: the saved graph embeds resnet50.preprocess_input
    # (train_classifier.py), so preprocessing again here would corrupt it.
    target_size = (int(input_shape[1]), int(input_shape[2]))
    batch = load_image(image_path, target_size)

    probabilities = model.predict(batch, verbose=0)[0]
    predicted_index = int(np.argmax(probabilities))
    predicted_label = class_names[predicted_index] if predicted_index < len(class_names) else str(predicted_index)

    return {
        "image": str(image_path),
        "model": str(model_path),
        "label": predicted_label,
        "confidence": float(probabilities[predicted_index]),
        "probabilities": {
            class_names[index] if index < len(class_names) else str(index): float(score)
            for index, score in enumerate(probabilities)
        },
    }


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv if argv is not None else sys.argv[1:])

    image_path = Path(args.image)
    model_path = Path(args.model)
    class_names_path = Path(args.class_names)

    try:
        result = predict(image_path, model_path, class_names_path)
    except Exception as exc:  # noqa: BLE001
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1

    print(json.dumps(result, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())