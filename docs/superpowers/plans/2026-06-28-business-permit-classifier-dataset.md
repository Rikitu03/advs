# Business Permit Classifier Dataset Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate 100 synthetic City Business Permit documents (each as a clean "digital" PNG + an Augraphy-degraded "scan" JPG = 200 files) into the ResNet-50 classifier's canonical `business_permit` class folder, after correcting the generator's stale output target.

**Architecture:** The generator `python/scripts/business_permit_dataset_generator.py` is already fully implemented and unit-tested — it fills the blank Digos permit template, composites the City seal + two officer signatures, and emits clean + scan variants with a content-hash dedup manifest. The only defect is its default `OUTPUT_DIR`, which points at a **phantom** `business_registration` class folder that exists in neither the DB taxonomy (`DocumentTypeSeeder`) nor on disk. The real, canonical class folder (`business_permit`, currently empty) is what the classifier and `DocumentTypeSeeder` use. Task 1 repoints the generator (code + test, committed); Task 2 runs the batch to populate the folder (local, gitignored artifacts).

**Tech Stack:** Python 3.12 (the repo's ML venv), Pillow, Faker (`en_PH`), Augraphy (via the reused `bir_dataset_generator` helpers), pytest.

## Global Constraints

- **Interpreter:** ALWAYS use the repo's ML venv interpreter `python/env/Scripts/python.exe`. Bare `python` is MSYS2 (no wheels) and will fail; `py` is 3.14 (too new for the stack). Run all commands from the project root `c:\xampp\htdocs\projects\advs`.
- **Canonical class folder:** output target is `python/data/training/classifier_data/business_permit` — matches `DocumentTypeSeeder` (`code => 'business_permit'`) and the existing on-disk class folders (`bir_certificate`, `business_permit`, `fake`, `financial_statement`). There is NO `business_registration` class.
- **Batch size:** 100 base permits → 200 files (100 `*_clean.png` digital + 100 `*_scan.jpg` scan-like). This is the script's `--count 100` default; do not pass `--clean-only`/`--scan-only`.
- **Generated data is gitignored.** `.gitignore` lines 40–45 ignore everything under `python/data/training/**` except the directory scaffold + `.gitkeep`. The 200 images + `_synthetic_manifest.json` are LOCAL artifacts — do NOT attempt to `git add` them. Only the Task 1 source-code change is committed.
- **Do not duplicate machinery.** The field-agnostic helpers (text fitting, asset compositing, Augraphy degrade, manifest atomic-write) are imported from the sibling `bir_dataset_generator`; do not reimplement them here.
- **ASCII-only logging** (Windows cp1252 console safe) — preserve the existing `[permit-gen] ...` log style.
- **Current branch is `staging`** (not the `main` default), so commit directly on `staging`; no new branch required.

---

## File Structure

- `python/scripts/business_permit_dataset_generator.py` — **Modify.** Repoint `OUTPUT_DIR` (line 54) + the docstring (line 8) from the phantom `business_registration` to the canonical `business_permit`. No behavioral/logic change.
- `python/tests/test_business_permit_dataset_generator.py` — **Modify.** Add one regression test pinning `OUTPUT_DIR` to the canonical class folder; rename the three cosmetic `tmp_path` subfolders from `business_registration` → `business_permit` so the test file is self-consistent.
- `python/data/training/classifier_data/business_permit/` — **Populate (local, gitignored).** Receives 200 images + `_synthetic_manifest.json`.

---

### Task 1: Repoint the generator to the canonical `business_permit` class folder

**Files:**
- Modify: `python/scripts/business_permit_dataset_generator.py` (docstring line 8; `OUTPUT_DIR` line 54)
- Test: `python/tests/test_business_permit_dataset_generator.py` (add one test; rename 3 tmp folders)

**Interfaces:**
- Consumes: nothing (first task).
- Produces: `business_permit_dataset_generator.OUTPUT_DIR` (a `pathlib.Path`) now resolves to `.../python/data/training/classifier_data/business_permit`. Task 2's batch run relies on this corrected default.

- [ ] **Step 1: Write the failing regression test**

In `python/tests/test_business_permit_dataset_generator.py`, add this function immediately after `test_load_field_boxes_reads_the_exported_json` (right before the `# ----- field values ---` section comment):

```python
def test_output_dir_targets_the_canonical_business_permit_class_folder():
    # The classifier reads the class label from the folder name. The canonical
    # code in DocumentTypeSeeder is `business_permit` (LGU business permit); there
    # is no `business_registration` class on disk or in the DB taxonomy, so the
    # generator's default output must land in the real `business_permit` folder.
    assert gen.OUTPUT_DIR.name == "business_permit"
    assert gen.OUTPUT_DIR.parts[-4:] == ("data", "training", "classifier_data", "business_permit")
```

- [ ] **Step 2: Run the test to verify it fails**

Run:
```bash
python/env/Scripts/python.exe -m pytest "python/tests/test_business_permit_dataset_generator.py::test_output_dir_targets_the_canonical_business_permit_class_folder" -v
```
Expected: FAIL with `AssertionError: assert 'business_registration' == 'business_permit'` (the default still points at the phantom folder).

- [ ] **Step 3: Fix the `OUTPUT_DIR` constant**

In `python/scripts/business_permit_dataset_generator.py`, change the output target (line 54):

```python
OUTPUT_DIR = PY_ROOT / "data" / "training" / "classifier_data" / "business_permit"
```
(was `... / "classifier_data" / "business_registration"`)

- [ ] **Step 4: Fix the stale docstring reference**

In the same file's module docstring, change:

```python
ResNet-50 classifier's ``business_permit`` class folder. A per-folder JSON
```
(was `ResNet-50 classifier's ``business_registration`` class folder. A per-folder JSON`)

- [ ] **Step 5: Make the test file self-consistent (rename cosmetic tmp folders)**

Still in `python/tests/test_business_permit_dataset_generator.py`, replace **all three** occurrences of:

```python
    out = tmp_path / "business_registration"
```
with:
```python
    out = tmp_path / "business_permit"
```
(These are throwaway `tmp_path` subfolder names in `test_run_batch_writes_variants_and_manifest`, `test_second_run_appends_without_duplicates`, and `test_dry_run_writes_nothing` — cosmetic only, but renamed so no reference to the phantom class name remains.)

- [ ] **Step 6: Run the new test to verify it passes**

Run:
```bash
python/env/Scripts/python.exe -m pytest "python/tests/test_business_permit_dataset_generator.py::test_output_dir_targets_the_canonical_business_permit_class_folder" -v
```
Expected: PASS.

- [ ] **Step 7: Run the full generator test file to confirm no regressions**

Run:
```bash
python/env/Scripts/python.exe -m pytest "python/tests/test_business_permit_dataset_generator.py" -v
```
Expected: all tests PASS (the 13 existing tests + the 1 new = 14 passed).

- [ ] **Step 8: Commit**

```bash
git add python/scripts/business_permit_dataset_generator.py python/tests/test_business_permit_dataset_generator.py
git commit -m "$(cat <<'EOF'
fix(dataset): point business-permit generator at canonical business_permit class folder

The generator's default OUTPUT_DIR (and its docstring) pointed at a phantom
`business_registration` class that exists in neither DocumentTypeSeeder nor on
disk; the real classifier folder is `business_permit` (currently empty). Repoint
the default, add a regression test pinning OUTPUT_DIR, and drop the stale name
from the test file's tmp folders.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Generate and verify the 100-permit batch (200 files)

**Files:**
- Populate (local, gitignored): `python/data/training/classifier_data/business_permit/` — 100 `synthetic_permit_*_clean.png`, 100 `synthetic_permit_*_scan.jpg`, 1 `_synthetic_manifest.json`.

**Interfaces:**
- Consumes: the corrected `OUTPUT_DIR` default from Task 1; the script's existing `--count` / `--dry-run` CLI (`main()` in `business_permit_dataset_generator.py`).
- Produces: 200 verified classifier training images in the `business_permit` class folder + a dedup manifest with `next_index == 101`.

- [ ] **Step 1: Confirm the target folder is empty (clean baseline)**

Run:
```bash
find "python/data/training/classifier_data/business_permit" -type f
```
Expected: NO output (empty folder, no pre-existing `_synthetic_manifest.json`). This guarantees the run starts at index 1 and ends at `next_index=101`. (If files already exist, the run will safely APPEND and dedup; in that case expect cumulative counts and `next_index = prior + 100` instead.)

- [ ] **Step 2: Dry-run to validate assets, boxes, font, and one in-memory render**

Run:
```bash
python/env/Scripts/python.exe python/scripts/business_permit_dataset_generator.py --dry-run
```
Expected: exit code 0 with log lines like:
```
[permit-gen] DRY RUN ok - rendered 1 permit in memory at (1600, 1203), wrote nothing.
[permit-gen] fields: proprietor=... kind=... issued=... ...
```
(If instead you see `[permit-gen] ERROR: ...` for a missing template/logo/signature/font, STOP and resolve that asset before continuing.)

- [ ] **Step 3: Generate the full batch (100 base permits → 200 files)**

Run:
```bash
python/env/Scripts/python.exe python/scripts/business_permit_dataset_generator.py --count 100
```
Expected: exit code 0 with progress + summary logs:
```
[permit-gen] generated 25/100 permits
[permit-gen] generated 50/100 permits
[permit-gen] generated 75/100 permits
[permit-gen] generated 100/100 permits
[permit-gen] wrote 200 files (100 base permits) -> python/data/training/classifier_data/business_permit
[permit-gen] manifest -> python/data/training/classifier_data/business_permit/_synthetic_manifest.json (next_index=101)
```

- [ ] **Step 4: Verify file counts**

Run:
```bash
echo "clean PNGs:" $(find "python/data/training/classifier_data/business_permit" -name "*_clean.png" | wc -l)
echo "scan JPGs:"  $(find "python/data/training/classifier_data/business_permit" -name "*_scan.jpg"  | wc -l)
```
Expected:
```
clean PNGs: 100
scan JPGs: 100
```

- [ ] **Step 5: Verify manifest integrity, no duplicate content, and image validity**

Run:
```bash
python/env/Scripts/python.exe - <<'PY'
import json
from pathlib import Path
from PIL import Image

d = Path("python/data/training/classifier_data/business_permit")
m = json.loads((d / "_synthetic_manifest.json").read_text(encoding="utf-8"))

assert m["next_index"] == 101, f"next_index={m['next_index']} (expected 101)"
assert len(m["records"]) == 100, f"records={len(m['records'])} (expected 100)"
assert len(m["used_hashes"]) == len(set(m["used_hashes"])), "duplicate content hashes in manifest!"

cleans = sorted(d.glob("synthetic_permit_*_clean.png"))
scans = sorted(d.glob("synthetic_permit_*_scan.jpg"))
assert len(cleans) == 100 and len(scans) == 100, f"clean={len(cleans)} scan={len(scans)} (expected 100/100)"

# spot-check that first/last clean+scan images decode without corruption
for p in (cleans[0], cleans[-1], scans[0], scans[-1]):
    Image.open(p).verify()

print("OK: 100 clean + 100 scan, manifest next_index=101, 100 unique records, no dup hashes, sampled images valid")
PY
```
Expected final line:
```
OK: 100 clean + 100 scan, manifest next_index=101, 100 unique records, no dup hashes, sampled images valid
```

- [ ] **Step 6: Confirm the generated data is gitignored (nothing to commit)**

Run:
```bash
git status --short "python/data/training/classifier_data/business_permit/"
```
Expected: NO output — all 200 images + the manifest are ignored by `.gitignore` (lines 40–45). There is no Task 2 commit; the only version-controlled change in this plan was Task 1.

---

## Out of Scope / Follow-ups (not part of this plan)

These stale `business_registration` references exist elsewhere but are NOT required to deliver the 100 business-permit training images. Flagged for a future cleanup pass (each would need its own decision/sign-off):
- `python/scripts/train_classifier.py:9` (docstring example class list)
- `python/notebooks/01_resnet50_classifier.ipynb:55` (same docstring, copied)
- `python/.claude/skills/run-advs-training/make_fixtures.py:30` (smoke-test fixture class list)
- `python/data/README.md:20` and `AGENTS.md:149` (documentation)
- A matching **validation** set: `train_classifier.py` requires `python/data/validation/classifier_data/business_permit/` to be populated too before the classifier can train; generating that (e.g. a smaller `--count` into the validation folder via `--out-dir`) is a separate task.


10 samples first for approval
