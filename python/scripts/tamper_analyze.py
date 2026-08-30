"""ADVS — Document tampering forensics runner (pipeline Stage T).

The production entry point Laravel's ``TamperDetectionService`` invokes. It reads
a JSON payload, runs the five forensic techniques in ``python/forensics`` over
the ORIGINAL upload (and, for PDFs, the full-DPI page image — never the binarized
Stage-1 output), blends their authenticity scores into one verdict, and writes a
JSON result.

I/O contract (matches the other Stage runners — see CLAUDE.md §6):
    --input  <json payload path>   --output <json result path>
    exit 0 on success, 2 on a structural/usage error, 1 on an unexpected failure.

Input payload:
    {
      "original_path": "/abs/path/upload.jpg",   # required: the original file (metadata)
      "page_images":   ["/abs/path/page1.png"],  # optional: full-DPI raster pages
                                                  #   (defaults to [original_path] for images)
      "ocr_words":     [{"text","conf","bbox":[x,y,w,h]}, ...],  # optional (font)
      "ocr_text":      "....",                    # optional (cross-reference)
      "fields":        {"tin": "...", ...},       # optional extracted fields
      "issue_date":    "2019-01-14",              # optional printed issue date (metadata)
      "weights":       {"ela": 0.25, ...},        # optional blend override
      "tamper_threshold": 0.50,                   # optional gate override
      "hard_confidence": 0.80                     # optional hard-flag gate
    }

Output result: the ``aggregate()`` dict — ``tamper_score`` (0 clean .. 1 tampered),
``tamper_authenticity``, ``tamper_confidence``, ``tamper_passed``, per-technique
breakdown under ``techniques``, and a merged ``flags`` list.

Forensics are fail-forward: a technique that cannot run is recorded as skipped
and excluded from the blend — it never aborts the document.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

PY_ROOT = Path(__file__).resolve().parents[1]
if str(PY_ROOT) not in sys.path:
    sys.path.insert(0, str(PY_ROOT))

from forensics import DEFAULT_HARD_CONFIDENCE, DEFAULT_TAMPER_THRESHOLD, aggregate  # noqa: E402
from forensics import copy_move, cross_reference, ela, font_consistency, metadata  # noqa: E402


def log(msg: str) -> None:
    print(f"[tamper-analyze] {msg}", flush=True)


def run(payload: dict) -> dict:
    """Run all five techniques over the payload and return the blended verdict."""
    original_path = payload.get("original_path")
    page_images = payload.get("page_images") or ([original_path] if original_path else [])
    page = page_images[0] if page_images else None

    techniques = {
        "metadata": metadata.analyze(original_path, issue_date=payload.get("issue_date")),
        "ela": ela.analyze(page),
        "copy_move": copy_move.analyze(page),
        "font": font_consistency.analyze(payload.get("ocr_words")),
        "cross_reference": cross_reference.analyze(payload.get("ocr_text"), payload.get("fields")),
    }
    return aggregate(
        techniques,
        weights=payload.get("weights"),
        tamper_threshold=float(payload.get("tamper_threshold", DEFAULT_TAMPER_THRESHOLD)),
        hard_confidence=float(payload.get("hard_confidence", DEFAULT_HARD_CONFIDENCE)),
    )


def parse_args(argv: list[str]) -> argparse.Namespace:
    ap = argparse.ArgumentParser(description="ADVS document-tampering forensics (Stage T).")
    ap.add_argument("--input", required=True, help="Path to the JSON payload.")
    ap.add_argument("--output", required=True, help="Path to write the JSON result.")
    return ap.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv if argv is not None else sys.argv[1:])

    input_path = Path(args.input)
    if not input_path.is_file():
        log(f"STRUCTURE ERROR: input payload not found: {input_path}")
        return 2
    try:
        payload = json.loads(input_path.read_text(encoding="utf-8"))
    except (ValueError, OSError) as exc:
        log(f"STRUCTURE ERROR: could not read payload: {exc}")
        return 2

    try:
        result = run(payload)
        Path(args.output).write_text(json.dumps(result, indent=2), encoding="utf-8")
        log(f"tamper_score={result['tamper_score']} authenticity={result['tamper_authenticity']} "
            f"passed={result['tamper_passed']} flags={len(result['flags'])}")
        return 0
    except Exception as exc:  # noqa: BLE001
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
