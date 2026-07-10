"""Regression tests for scripts/train_classifier.py.

These keep the classifier training contract honest without importing TensorFlow:
the train and validation folder sets must match exactly before training starts.
"""

from __future__ import annotations

import importlib.util
from pathlib import Path

import pytest

_SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "train_classifier.py"
_spec = importlib.util.spec_from_file_location("train_classifier", _SCRIPT)
train_classifier = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(train_classifier)


def _make_class_dir(root: Path, name: str) -> None:
    (root / name).mkdir(parents=True, exist_ok=True)


def test_validate_structure_accepts_matching_class_folders(tmp_path: Path) -> None:
    train_dir = tmp_path / "training" / "classifier_data"
    val_dir = tmp_path / "validation" / "classifier_data"

    for name in ["alpha", "beta", "fake"]:
        _make_class_dir(train_dir, name)
        _make_class_dir(val_dir, name)

    summary = train_classifier.validate_structure(train_dir, val_dir)

    assert summary == {"num_classes": 3, "classes": ["alpha", "beta", "fake"]}


def test_validate_structure_rejects_mismatched_validation_folders(tmp_path: Path) -> None:
    train_dir = tmp_path / "training" / "classifier_data"
    val_dir = tmp_path / "validation" / "classifier_data"

    for name in ["alpha", "beta", "fake"]:
        _make_class_dir(train_dir, name)
    for name in ["alpha", "fake"]:
        _make_class_dir(val_dir, name)

    with pytest.raises(train_classifier.TrainError, match="must match exactly"):
        train_classifier.validate_structure(train_dir, val_dir)