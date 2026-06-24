# Registered-Name Positional + Fuzzy Header Extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `registered_name` extraction in `python/scripts/ocr_dryrun.py` read the value from the **NAME column's word boxes** (positional), and tolerate up to 4 OCR character-typos in the `TIN | NAME | REGISTRATION DATE` header caption (so `REGISTRAUION` still identifies the header row).

**Architecture:** Add two small fuzzy-matching primitives (`_levenshtein`, `_fuzzy_token_eq`), give the existing box-finder `_find_phrase` an optional `max_typos` parameter, add a positional extractor `_positional_registered_name` wired into `_positional_fields` (so it overrides the text result whenever word boxes exist), and make the text-only fallback `_extract_registered_name` detect its header row fuzzily. The positional path is the new primary; the fuzzy text path is the fallback for callers without word boxes.

**Tech Stack:** Python 3.12, OpenCV + PyTesseract (already wired), stdlib `re`. No new dependencies. Tests are plain assert-based and run via the test file's built-in runner.

## Global Constraints

- Python interpreter is the project venv ONLY: `python/env/Scripts/python.exe` (python.org 3.12). Never use bare `python` (MSYS2, no wheels) or `py` (3.14).
- **No new dependencies.** pytest is NOT installed; tests run via the file's `__main__` runner: `env/Scripts/python.exe tests/test_ocr_dryrun.py` (prints `PASS/FAIL` per test + an `N/M passed` summary; exit 0 = all pass, exit 1 = a failure).
- All paths below are relative to `c:/xampp/htdocs/projects/advs`. Run test commands from `c:/xampp/htdocs/projects/advs/python`.
- Changes are confined to the dry-run harness (`scripts/ocr_dryrun.py`) and its test (`tests/test_ocr_dryrun.py`). Do NOT touch production `ocr_runner.py` or any §9 path.
- Every existing test in `tests/test_ocr_dryrun.py` must still pass after each task (run the whole file, not just the new test).
- Match existing code style: PEP-type-hinted functions, PHPDoc-style docstrings explaining *why*, no inline `# obvious` comments.

---

## File Structure

- `python/scripts/ocr_dryrun.py` — add `_levenshtein`, `_fuzzy_token_eq` (after `_norm_token`, ~line 325); extend `_find_phrase` (~line 513); add `_positional_registered_name` + wire into `_positional_fields` (~line 551–577); make `_extract_registered_name` header match fuzzy via a new `_line_is_name_header` (~line 430–454).
- `python/tests/test_ocr_dryrun.py` — append new tests for each piece. Reuses the existing `_WORDS` real-OCR box fixture (`tests/fixtures/bir1_words.json`) and a small synthetic header word-list helper for the typo case.

---

### Task 1: Fuzzy-matching primitives (`_levenshtein`, `_fuzzy_token_eq`)

**Files:**
- Modify: `python/scripts/ocr_dryrun.py` (insert after `_norm_token`, currently lines 324–325)
- Test: `python/tests/test_ocr_dryrun.py` (append)

**Interfaces:**
- Produces:
  - `_levenshtein(a: str, b: str) -> int` — classic edit distance.
  - `_fuzzy_token_eq(a: str, b: str, max_typos: int = 0) -> bool` — normalises both tokens (via existing `_norm_token`), returns `True` if within `max_typos` edits; tolerance is capped at `min(len(a), len(b)) - 1` so short captions can't match unrelated tokens; `max_typos=0` means exact.

- [ ] **Step 1: Write the failing tests**

Append to `python/tests/test_ocr_dryrun.py`:

```python
# --- fuzzy caption matching primitives ----------------------------------------

def test_levenshtein_counts_single_substitution() -> None:
    assert ocr_dryrun._levenshtein("REGISTRATION", "REGISTRAUION") == 1


def test_levenshtein_zero_for_identical() -> None:
    assert ocr_dryrun._levenshtein("NAME", "NAME") == 0


def test_fuzzy_token_eq_tolerates_long_caption_typo() -> None:
    # REGISTRATION read as REGISTRAUION is one substitution -> within a 4 budget.
    assert ocr_dryrun._fuzzy_token_eq("REGISTRAUION", "REGISTRATION", 4) is True


def test_fuzzy_token_eq_exact_when_budget_zero() -> None:
    assert ocr_dryrun._fuzzy_token_eq("NAME", "NAME", 0) is True
    assert ocr_dryrun._fuzzy_token_eq("NAME", "NAVE", 0) is False


def test_fuzzy_token_eq_short_token_needs_low_budget() -> None:
    # With a 1-typo budget NAME must NOT match the unrelated DATE (distance 2).
    assert ocr_dryrun._fuzzy_token_eq("DATE", "NAME", 1) is False


def test_fuzzy_token_eq_caps_tolerance_below_token_length() -> None:
    # A 4 budget on a 3-letter caption is capped to 2, so TIN can't match FOR.
    assert ocr_dryrun._fuzzy_token_eq("TIN", "FOR", 4) is False
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: the run aborts with `AttributeError: module 'ocr_dryrun' has no attribute '_levenshtein'` (the built-in runner only catches `AssertionError`, so a missing symbol surfaces as a traceback and a non-zero exit).

- [ ] **Step 3: Add the primitives**

In `python/scripts/ocr_dryrun.py`, immediately after the `_norm_token` function (currently ends at line 325), insert:

```python
def _levenshtein(a: str, b: str) -> int:
    """Character-level edit distance (substitutions/insertions/deletions)."""
    if a == b:
        return 0
    if not a:
        return len(b)
    if not b:
        return len(a)
    prev = list(range(len(b) + 1))
    for i, ca in enumerate(a, 1):
        cur = [i]
        for j, cb in enumerate(b, 1):
            cur.append(min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (ca != cb)))
        prev = cur
    return prev[-1]


def _fuzzy_token_eq(a: str, b: str, max_typos: int = 0) -> bool:
    """True if two tokens are equal within ``max_typos`` character edits.

    Both sides are normalised with ``_norm_token`` first. The tolerance is capped
    at one less than the shorter token's length, so a short caption ("NAME",
    "TIN", "DATE") can never fuzzy-match a wholly different token even with a large
    budget - long captions get the full 1-4 budget, short ones are called with a
    small one. ``max_typos=0`` means exact match (the default).
    """
    a, b = _norm_token(a), _norm_token(b)
    if not a or not b:
        return False
    if a == b:
        return True
    if max_typos < 1:
        return False
    tol = min(max_typos, min(len(a), len(b)) - 1)
    if tol < 1:
        return False
    return _levenshtein(a, b) <= tol
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: all tests `PASS`, summary ends `N/N passed`, exit 0. (The six new tests now pass; every previously passing test still passes.)

- [ ] **Step 5: Commit**

```bash
git add python/tests/test_ocr_dryrun.py python/scripts/ocr_dryrun.py
git commit -m "feat(ocr): add fuzzy token-match primitives for BIR caption matching

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Optional fuzzy matching in `_find_phrase`

**Files:**
- Modify: `python/scripts/ocr_dryrun.py` — `_find_phrase` (currently lines 513–526)
- Test: `python/tests/test_ocr_dryrun.py` (append)

**Interfaces:**
- Consumes: `_fuzzy_token_eq(a, b, max_typos)` from Task 1.
- Produces: `_find_phrase(words: list[dict], phrase: str, max_typos: int = 0)` — unchanged return `(left, top, right, bottom)` tuple or `None`; with `max_typos=0` (default) behaviour is identical to before (exact); `max_typos>0` matches each caption token fuzzily.

- [ ] **Step 1: Write the failing tests**

Append to `python/tests/test_ocr_dryrun.py`:

```python
# --- fuzzy phrase finder over word boxes --------------------------------------

def _word(text: str, left: int, top: int, width: int = 80, height: int = 20) -> dict:
    return {"text": text, "conf": 90, "left": left, "top": top,
            "width": width, "height": height}


def test_find_phrase_exact_by_default() -> None:
    words = [_word("TRADE", 10, 10, 50), _word("NAME", 70, 10, 50)]
    assert ocr_dryrun._find_phrase(words, "TRADE NAME") == (10, 10, 120, 30)
    assert ocr_dryrun._find_phrase(words, "TRADE NAMEX") is None  # exact: no match


def test_find_phrase_fuzzy_tolerates_caption_typo() -> None:
    words = [_word("REGISTRAUION", 100, 50, 180), _word("DATE", 290, 50, 60)]
    assert ocr_dryrun._find_phrase(words, "REGISTRATION DATE", max_typos=4) == (100, 50, 350, 70)
    assert ocr_dryrun._find_phrase(words, "REGISTRATION DATE") is None  # exact fails
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: `FAIL  test_find_phrase_fuzzy_tolerates_caption_typo` (current `_find_phrase` ignores the `max_typos` kwarg — actually raises `TypeError: _find_phrase() got an unexpected keyword argument 'max_typos'`, aborting the run with a traceback and exit 1).

- [ ] **Step 3: Extend `_find_phrase`**

Replace the existing `_find_phrase` (lines 513–526) with:

```python
def _find_phrase(words: list[dict], phrase: str, max_typos: int = 0):
    """Box (left, top, right, bottom) of the first consecutive run of words whose
    normalised text matches the phrase tokens; None if absent. ``max_typos`` > 0
    allows fuzzy per-token matching (tolerates OCR typos in a caption); the
    default 0 is an exact match, preserving the original behaviour."""
    tokens = [_norm_token(t) for t in phrase.split()]
    for i in range(len(words) - len(tokens) + 1):
        seg = words[i:i + len(tokens)]
        if all(_fuzzy_token_eq(w["text"], tok, max_typos)
               for w, tok in zip(seg, tokens)):
            return (
                min(w["left"] for w in seg),
                min(w["top"] for w in seg),
                max(w["left"] + w["width"] for w in seg),
                max(w["top"] + w["height"] for w in seg),
            )
    return None
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: all `PASS`, exit 0. The existing positional tests (`test_positional_trade_name_is_left_column`, `..._line_of_business...`, `..._tax_types`, `..._district_officer...`) still pass because the default `max_typos=0` keeps exact matching.

- [ ] **Step 5: Commit**

```bash
git add python/tests/test_ocr_dryrun.py python/scripts/ocr_dryrun.py
git commit -m "feat(ocr): make _find_phrase tolerate OCR caption typos via max_typos

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Positional `registered_name` extraction (primary path)

**Files:**
- Modify: `python/scripts/ocr_dryrun.py` — add `_positional_registered_name` just before `_positional_fields` (before line 551); add one line inside `_positional_fields` (before its `return`, line 577)
- Test: `python/tests/test_ocr_dryrun.py` (append)

**Interfaces:**
- Consumes: `_find_phrase(words, phrase, max_typos)` (Task 2), and existing `_words_in_box`, `_join_clean`, `_TIN_TOKEN`, `_DATE_TOKEN`.
- Produces: `_positional_registered_name(words: list[dict]) -> str | None`. Wired so `_positional_fields(words)["registered_name"]` is present whenever the NAME + REGISTRATION DATE header captions are found, and `refine_fields_with_positions` therefore overrides the text-derived `registered_name` with this value.

**Geometry reference (real `bir1` boxes, the Otsu+2x pixel space the fixture is in):** header captions sit at `top≈378` (`TIN` left 31/right 73, `NAME` left 398, `REGISTRATION` left 1008, `DATE` left 1199); the name value row is `top≈412` (`009-028-463-000` right 355, `CENTER` left 409 … `AND` right 932, `06/01/2015` left 1036); the wrap `PROFESSIONAL DEVT.` is `top≈440`; `REGISTERED ADDRESS` is `top≈470`. The band therefore runs `x∈[NAME.left-40, DATE-column-start]`, `y∈[header_bottom+6, just above REGISTERED ADDRESS]`, with any flanking TIN/date stripped by regex.

- [ ] **Step 1: Write the failing tests**

Append to `python/tests/test_ocr_dryrun.py`:

```python
# --- positional registered_name (middle column of the header table) -----------

def _name_header_words(registration_token: str = "REGISTRATION") -> list:
    """Synthetic TIN | NAME | REGISTRATION DATE header with a two-row name value,
    plus the REGISTERED ADDRESS caption that bounds the band below. Mirrors the
    bir2.jpg layout; ``registration_token`` lets a test inject the OCR typo."""
    return [
        _word("TIN", 30, 100, 40), _word("NAME", 400, 100, 70),
        _word(registration_token, 1000, 100, 180), _word("DATE", 1200, 100, 60),
        _word("000-132-541-000", 100, 140, 250),
        _word("MINING", 410, 140, 110), _word("AND", 530, 140, 60),
        _word("PETROLEUM", 600, 140, 150), _word("SERVICES", 760, 140, 130),
        _word("08/12/1998", 1040, 140, 160),
        _word("CORPORATION", 410, 175, 200),
        _word("REGISTERED", 400, 215, 200), _word("ADDRESS", 610, 215, 130),
    ]


def test_positional_registered_name_reads_middle_column() -> None:
    name = ocr_dryrun._positional_fields(_WORDS)["registered_name"].upper()
    assert "CENTER FOR LOCAL GOVERNANCE" in name
    assert "PROFESSIONAL DEVT" in name
    assert "009-028-463-000" not in name   # flanking TIN column excluded
    assert "06/01/2015" not in name        # flanking date column excluded
    assert "REGISTERED ADDRESS" not in name  # next section not pulled in


def test_positional_registered_name_tolerates_header_typo() -> None:
    name = ocr_dryrun._positional_registered_name(
        _name_header_words(registration_token="REGISTRAUION")
    ).upper()
    assert "MINING AND PETROLEUM SERVICES" in name
    assert "CORPORATION" in name
    assert "000-132-541-000" not in name
    assert "08/12/1998" not in name


def test_positional_registered_name_none_without_header() -> None:
    # No NAME/REGISTRATION DATE captions -> nothing to anchor on -> None.
    assert ocr_dryrun._positional_registered_name(
        [_word("PUROK", 100, 100, 80), _word("ORIENTAL", 190, 100, 120)]
    ) is None
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: the run aborts with `AttributeError: module 'ocr_dryrun' has no attribute '_positional_registered_name'` (and `_positional_fields(...)["registered_name"]` would `KeyError`), exit 1.

- [ ] **Step 3: Add the positional extractor**

In `python/scripts/ocr_dryrun.py`, insert this function immediately before `def _positional_fields(` (before line 551):

```python
def _positional_registered_name(words: list[dict]) -> str | None:
    """Read the registrant name from the TIN | NAME | REGISTRATION DATE header
    table using word boxes: the name sits in the middle column, below the NAME
    caption and between the TIN (left) and REGISTRATION DATE (right) columns.

    A plain text scan returns the neighbouring caption because the three columns
    flatten into one line; reading the column band beneath the NAME caption keeps
    only the name. Fuzzy caption matching tolerates an OCR typo in the long
    REGISTRATION caption (e.g. REGISTRAUION). The band stops just above the
    REGISTERED ADDRESS caption so the name's wrap line is kept but the next
    section is not, and any TIN/date that bleeds into the band is stripped.
    """
    name_cap = _find_phrase(words, "NAME", max_typos=1)
    date_cap = _find_phrase(words, "REGISTRATION DATE", max_typos=4)
    if not (name_cap and date_cap):
        return None
    if abs(name_cap[1] - date_cap[1]) > 40:  # captions must share the header row
        return None
    x0 = name_cap[0] - 40                     # left of NAME caption, past TIN column
    x1 = date_cap[0] - 30                     # right edge = start of the DATE column
    header_bottom = max(name_cap[3], date_cap[3])
    addr_cap = _find_phrase(words, "REGISTERED ADDRESS", max_typos=4)
    y0 = header_bottom + 6
    y1 = addr_cap[1] - 5 if addr_cap and addr_cap[1] > header_bottom else header_bottom + 80
    value = _join_clean(_words_in_box(words, x0, x1, y0, y1))
    if value:
        value = _TIN_TOKEN.sub(" ", value)
        value = _DATE_TOKEN.sub(" ", value)
        value = re.sub(r"\s+", " ", value).strip(" :|-")
    return value or None
```

- [ ] **Step 4: Wire it into `_positional_fields`**

In `_positional_fields`, change the final return block (currently line 577):

```python
    return {k: v for k, v in out.items() if v}
```

to:

```python
    out["registered_name"] = _positional_registered_name(words)

    return {k: v for k, v in out.items() if v}
```

(The dict-comprehension already drops `None`/empty values, so a missed header leaves the text-derived value in place.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: all `PASS`, exit 0. In particular the real-fixture `test_positional_registered_name_reads_middle_column` returns `CENTER FOR LOCAL GOVERNANCE AND PROFESSIONAL DEVT`, and the synthetic-typo test returns `MINING AND PETROLEUM SERVICES CORPORATION`.

- [ ] **Step 6: Commit**

```bash
git add python/tests/test_ocr_dryrun.py python/scripts/ocr_dryrun.py
git commit -m "feat(ocr): extract registered_name positionally from the NAME column

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: Fuzzy header match in the text-only fallback `_extract_registered_name`

**Files:**
- Modify: `python/scripts/ocr_dryrun.py` — add `_line_is_name_header` before `_extract_registered_name` (before line 430); change the header-row test inside `_extract_registered_name` (lines 438–440)
- Test: `python/tests/test_ocr_dryrun.py` (append)

**Interfaces:**
- Consumes: `_fuzzy_token_eq` (Task 1).
- Produces: `_line_is_name_header(line: str) -> bool`. `_extract_registered_name` now detects the header row fuzzily, so the text-only path (callers with no word boxes) also tolerates the `REGISTRAUION`-type typo.

- [ ] **Step 1: Write the failing test**

Append to `python/tests/test_ocr_dryrun.py`:

```python
# --- text-only fallback tolerates the header typo too -------------------------

def test_extract_registered_name_text_fallback_tolerates_typo() -> None:
    text = (
        "TIN NAME REGISTRAUION DATE\n"
        "000-132-541-000 MINING AND PETROLEUM SERVICES 08/12/1998\n"
        "CORPORATION\n"
        "REGISTERED ADDRESS\n"
    )
    val = ocr_dryrun.extract_fields(text, {})["registered_name"]["value"]
    assert val is not None                      # header found despite REGISTRAUION
    name = val.upper()
    assert "MINING AND PETROLEUM SERVICES" in name
    assert "CORPORATION" in name
    assert "000-132-541-000" not in name        # flanking TIN stripped
    assert "08/12/1998" not in name             # flanking date stripped


def test_extract_registered_name_text_fallback_still_matches_clean_header() -> None:
    # Regression: the existing clean-header path must keep working.
    name = ocr_dryrun.extract_fields(SAMPLE_OCR_TEXT, {})["registered_name"]["value"].upper()
    assert "GOVERNANCE" in name
    assert "REGISTRATION DATE" not in name
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: `FAIL  test_extract_registered_name_text_fallback_tolerates_typo` with an `AssertionError` on `assert val is not None` — the current exact substring check `"REGISTRATION DATE" in upper` does not match `REGISTRAUION DATE`, so the field is `None`. Exit 1. (`..._still_matches_clean_header` passes already.)

- [ ] **Step 3: Add `_line_is_name_header` and use it**

In `python/scripts/ocr_dryrun.py`, insert immediately before `def _extract_registered_name(` (before line 430):

```python
def _line_is_name_header(line: str) -> bool:
    """True if a line is the 'TIN | NAME | REGISTRATION DATE' header row, matched
    fuzzily so an OCR typo in the long caption (e.g. REGISTRAUION) still flags it.
    NAME/DATE use a 1-typo budget (short, only checked for presence); the long
    REGISTRATION caption gets the full 4."""
    toks = line.split()
    return (
        any(_fuzzy_token_eq(t, "NAME", 1) for t in toks)
        and any(_fuzzy_token_eq(t, "REGISTRATION", 4) for t in toks)
        and any(_fuzzy_token_eq(t, "DATE", 1) for t in toks)
    )
```

Then inside `_extract_registered_name`, replace these two lines (currently 439–440):

```python
        upper = line.upper()
        if "NAME" in upper and "REGISTRATION DATE" in upper:  # the caption row
```

with:

```python
        if _line_is_name_header(line):  # the TIN|NAME|REGISTRATION DATE caption row
```

(The removed `upper` local is unused elsewhere in the loop — the break condition on line 443 uses `cont.upper()`, not `upper`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: all `PASS`, exit 0. Both new tests pass; the original `test_registered_name_reads_value_row_not_column_header` (clean header) still passes.

- [ ] **Step 5: Commit**

```bash
git add python/tests/test_ocr_dryrun.py python/scripts/ocr_dryrun.py
git commit -m "feat(ocr): fuzzy header-row match in registered_name text fallback

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: End-to-end verification on the real bir2.jpg sample

**Files:** none (verification only)

**Interfaces:** Consumes the full default run of `ocr_dryrun.py` against the bundled bir2.jpg, which previously reported `Registered Name = MISSING`.

- [ ] **Step 1: Run the full harness on bir2.jpg**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe scripts/ocr_dryrun.py --input data/training/classifier_data/bir_certificate/bir2.jpg`
Expected: in the "Structured fields" table, the `* Registered Name` row now shows a value containing `MINING AND PETROLEUM SERVICES` with status `ok` (no longer `MISSING`), and the `missing_required_fields` flag no longer lists `registered_name`.

- [ ] **Step 2: Confirm the full test suite is green**

Run: `cd c:/xampp/htdocs/projects/advs/python && env/Scripts/python.exe tests/test_ocr_dryrun.py`
Expected: summary line `N/N passed`, exit 0.

- [ ] **Step 3 (only if Step 1 shows the field still MISSING): diagnose, do not guess**

If the field is still missing on bir2.jpg, the live OCR boxes differ from the `bir1` geometry. Add `--save-preprocessed /tmp/bir2_pre.png` and inspect, then widen the band tolerances in `_positional_registered_name` (the `name_cap[0]-40` / `header_bottom+80` constants) to match bir2's scale — then re-run Task 3's tests to confirm no regression. Commit any tuning with message `fix(ocr): tune registered_name band for bir2 geometry`.

---

## Notes for the implementer

- The text file `tests/test_ocr_dryrun.py` defines `_word(...)` in Task 2's test block; Tasks 3 reuse it. If executing tasks out of order, ensure `_word` exists before running Task 3's tests.
- `python/tests/` is currently untracked (`?? python/tests/` in git status) — the first `git add python/tests/test_ocr_dryrun.py` will start tracking it.
- Do not add `pytest` to `requirements.txt`; the `__main__` runner is the sanctioned path for this harness.
- This work is intentionally scoped to the dry-run harness. Porting any of it into production `ocr_runner.py` is a separate, sign-off-gated effort (see the standby note in project memory).
