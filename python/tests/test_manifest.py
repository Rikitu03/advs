"""Artifact manifest compatibility tests for the readiness contract."""

from __future__ import annotations

import json
import sys
from pathlib import Path

PY_ROOT = Path(__file__).resolve().parents[1]
if str(PY_ROOT) not in sys.path:
    sys.path.insert(0, str(PY_ROOT))

from api.manifest import ArtifactManifest, REQUIRED_MODELS, sha256_file  # noqa: E402


def test_manifest_accepts_loaded_artifacts_with_matching_checksums(tmp_path):
    paths = {}
    entries = {}
    statuses = {}
    for name in REQUIRED_MODELS:
        path = tmp_path / f"{name}.bin"
        path.write_bytes(f"artifact:{name}".encode())
        paths[name] = path
        statuses[name] = {"loaded": True, "path": str(path), "error": None}
        entries[name] = {
            "version": "2026.08.14",
            "sha256": sha256_file(path),
            "input_shape": [None, 512, 512, 3],
            "output_shape": [None, 128 if name == "siamese" else 4],
            "classes": ["bir_certificate", "business_permit", "dti_registration", "fake"],
            "trained_at": "2026-08-14T00:00:00Z",
            "metrics": {"accuracy": 0.95},
            "calibrated_threshold": 0.70,
        }

    manifest_path = tmp_path / "manifest.json"
    manifest_path.write_text(json.dumps({"schema_version": "1.0", "models": entries}))
    manifest = ArtifactManifest(manifest_path)

    assert manifest.readiness_errors(paths, statuses) == []
    report = manifest.report(paths, statuses)
    assert report["models"]["classifier"]["manifest_status"] == "compatible"
    assert report["models"]["siamese"]["output_shape"][-1] == 128
    assert report["models"]["classifier"]["threshold"] == 0.70


def test_manifest_detects_a_checksum_mismatch(tmp_path):
    paths = {}
    entries = {}
    statuses = {}
    for name in REQUIRED_MODELS:
        path = tmp_path / f"{name}.bin"
        path.write_bytes(name.encode())
        paths[name] = path
        statuses[name] = {"loaded": True, "path": str(path), "error": None}
        entries[name] = {"sha256": sha256_file(path)}

    manifest_path = tmp_path / "manifest.json"
    manifest_path.write_text(json.dumps({"schema_version": "1.0", "models": entries}))
    paths["classifier"].write_bytes(b"changed")

    manifest = ArtifactManifest(manifest_path)

    assert "classifier:artifact_incompatible" in manifest.readiness_errors(paths, statuses)
