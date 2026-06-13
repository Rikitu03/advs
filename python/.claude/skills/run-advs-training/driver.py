"""Smoke harness for the ADVS per-model training scripts.

Runs each of the four training scripts and reports PASS/FAIL. Two modes:

    --dry-run  (default)  validate the data scaffold + script wiring with NO heavy
                          imports and NO training. Safe on any machine; needs only
                          the data scaffold to exist (run make_fixtures.py first).
    --smoke               tiny 1-epoch CPU run per model. Needs the full ML stack
                          (pip install -r python/requirements.txt) and fixtures.

Usage (from anywhere; uses the same interpreter that runs this file):
    python python/.claude/skills/run-advs-training/driver.py
    python python/.claude/skills/run-advs-training/driver.py --smoke
    python python/.claude/skills/run-advs-training/driver.py --only classifier,stamp

This is the committed interaction harness referenced by SKILL.md.
"""

from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path

PY_ROOT = Path(__file__).resolve().parents[3]
SCRIPTS = {
    "classifier": PY_ROOT / "scripts" / "train_classifier.py",
    "detector": PY_ROOT / "scripts" / "train_detector.py",
    "signature": PY_ROOT / "scripts" / "train_signature.py",
    "stamp": PY_ROOT / "scripts" / "train_stamp.py",
}


def run_one(name: str, script: Path, mode_flag: str) -> bool:
    print("\n" + "#" * 70)
    print(f"#  {name}  ({mode_flag})")
    print("#" * 70, flush=True)
    proc = subprocess.run([sys.executable, str(script), mode_flag])
    ok = proc.returncode == 0
    print(f"--> {name}: {'PASS' if ok else 'FAIL'} (exit {proc.returncode})", flush=True)
    return ok


def main() -> int:
    ap = argparse.ArgumentParser(description="Drive the ADVS training scripts.")
    ap.add_argument("--smoke", action="store_true", help="Tiny real run (needs ML stack).")
    ap.add_argument("--only", default="", help="Comma list subset of: " + ",".join(SCRIPTS))
    args = ap.parse_args()

    mode_flag = "--smoke" if args.smoke else "--dry-run"
    names = [n.strip() for n in args.only.split(",") if n.strip()] or list(SCRIPTS)
    bad = [n for n in names if n not in SCRIPTS]
    if bad:
        print(f"Unknown model(s): {bad}. Valid: {list(SCRIPTS)}")
        return 2

    results = {name: run_one(name, SCRIPTS[name], mode_flag) for name in names}

    print("\n" + "=" * 70)
    print(f"  SUMMARY ({mode_flag})")
    for name, ok in results.items():
        print(f"    {name:<11} {'PASS' if ok else 'FAIL'}")
    passed = sum(results.values())
    print(f"  {passed}/{len(results)} passed")
    print("=" * 70)
    return 0 if passed == len(results) else 1


if __name__ == "__main__":
    raise SystemExit(main())
