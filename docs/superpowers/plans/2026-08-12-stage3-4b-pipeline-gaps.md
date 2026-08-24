# Python Main Pipeline — Stage 3 / Stage 4b Gap Closure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the three spec-mandated behaviours missing from `POST /v1/validate` — the Stage 4b stamp tamper check, LGU city-scoped logo lookup, and the Stage 3 `fake`/type-mismatch signals — so every trained model actually influences a document's risk score.

**Architecture:** The Python ML API (`python/api`) runs the whole pipeline fail-forward in one request and returns per-stage components plus merged flags; Laravel's `MlPipelineService` projects those onto `ValidationResult` columns and `RiskScoreService` blends them. Each task here adds a stage output on the Python side and the minimal Laravel mapping that consumes it, so each task ships working software end to end.

**Tech Stack:** Python 3.12 · FastAPI · TensorFlow/Keras 2.16 · scikit-learn (LogisticRegression) · Ultralytics YOLOv8 · pytest — and PHP 8.2 · Laravel 12 · PHPUnit 11.

---

## Background — why these three

Verified against `ADVS_System_Reference.md` §5 and the current code:

1. **The Stage 4b tamper texture check never runs.** The spec says it runs "always … reference or not" (§5 Stage 4b, "Tamper check (always runs, reference or not)" and failure path "Tampering detected → … raised even before any reference exists"). Today `run_stamp_verify()` returns `unreferenced_logo` *before* embedding the crop, so nothing is analysed. `python/models/stamp_classifier.pkl` (genuine/forged, val accuracy 0.90, trained 2026-07-18) is loaded nowhere, and the `validation_results.stamp_tampered` column added by migration `2026_06_26_160504` has never been written.

2. **The OCR-detected city never reaches Stage 4b.** §5 Stage 2 says the detected city "is passed to Stage 4b, which uses it to look up the correct reference logo(s)"; §5 Stage 4b keys an `lgu` issuer by `(document type, detected city)` and requires a `City not identified` flag when OCR reads none. Today `validate.py` passes only the caller's `city` form field, and `MlPipelineService::validate()` can only resolve a reference for `national` issuers (the city does not exist until Stage 2 runs, inside the same call). Result: **every** LGU document — Business Permit, Sanitary Permit, Food Handler Certificate — reports `unreferenced_logo`, and `city_not_identified` does not exist anywhere in the codebase.

3. **A document the classifier calls `fake` scores as clean.** `class_names.json` is `["bir_certificate", "business_permit", "dti_registration", "fake"]`. `validate.py` flags only `low_classification_confidence`, so a document classified `fake` at 0.98 confidence produces no flag, and `RiskScoreService` receives `classification_confidence = 0.98` as an **authenticity** score → near-zero classification risk. A confident forgery detection currently *lowers* risk.

---

## Global Constraints

- **Interpreter:** always `python/env/Scripts/python.exe` (python.org 3.12.10). Never bare `python` (MSYS2 build, no wheels); never `py` (3.14, too new for TensorFlow).
- **Run every command from the project root** `c:\xampp\htdocs\projects\advs`.
- **Never invent thresholds or weights.** Defaults come from `ADVS_System_Reference.md` §9 (`CLASSIFICATION_CONFIDENCE_THRESHOLD=0.70`, `STAMP_SIMILARITY_THRESHOLD=0.85`, `MISSING_COMPONENT_PENALTY=15`, bands Low 0–30 / Medium 31–60 / High 61–100). Any new tunable must be justified as a model's own decision boundary, not a new §9 parameter.
- **The pipeline is fail-forward.** A stage that cannot run records `{"skipped": true, "reason": ...}` and contributes a flag. Never add an early `throw`/abort the spec does not call for.
- **Human-in-the-loop is mandatory.** These stages only produce flags and scores; nothing auto-approves or auto-rejects.
- **ASCII-only `print()`** in Python scripts (Windows cp1252 console).
- **Model weights are gitignored** (`python/models/`). Never `git add` anything under it.
- **`php artisan config:clear` before `php artisan test`** — a cached `bootstrap/cache/config.php` overrides phpunit's sqlite `:memory:` env and the suite then hits MySQL.
- **The FastAPI service has no `--reload`.** Python changes require restarting it: `python/env/Scripts/python.exe -m uvicorn api.main:app --port 7860` from `python/` (token `devtoken`, `/health` returns 200 after ~100s).
- **Run `vendor/bin/pint --dirty --format agent`** before committing any PHP change.

---

## File Structure

**Python — modified:**

| File | Responsibility after this plan |
|---|---|
| `python/api/config.py` | Adds the stamp-classifier weight path + its decision-boundary setting. |
| `python/api/registry.py` | Loads `stamp_classifier.pkl` once at startup, gated on the file existing. |
| `python/api/routers/stamp.py` | Always embeds the crop; runs the texture check before the reference gate; accepts a caller-supplied "why there is no reference" reason. |
| `python/api/routers/classify.py` | Adds `authenticity` (= 1 − P(fake)) to the Stage 3 result. |
| `python/api/routers/validate.py` | Owns issuer-reference resolution (national sentinel vs LGU city), city canonicalisation, and the new Stage 3 / Stage 4b flags. |
| `python/api/schemas.py` | Declares the new response fields. |
| `python/tests/test_api.py` | Contract tests for all of the above. |

**Laravel — modified:**

| File | Responsibility after this plan |
|---|---|
| `app/Services/Document/MlPipelineService.php` | Sends `issuer_scope` + the full city→vector reference map; persists `stamp_tampered`, `classification_authenticity`, and the `logo_references` row the API actually matched. |
| `app/Actions/ProcessDocumentAction.php` | Feeds `classification_authenticity` (not raw confidence) into the risk blend. |
| `app/Models/ValidationResult.php` | Fillable + cast for the new column. |
| `database/migrations/2026_08_12_000001_add_classification_authenticity_to_validation_results_table.php` | **Create** — the new column. |
| `tests/Feature/Document/MlPipelineServiceTest.php`, `tests/Feature/Document/ProcessDocumentActionTest.php` | Tests for the mapping and the risk input. |

---

## Task 1: Stage 4b tamper texture check always runs

**Files:**
- Modify: `python/api/config.py` (add settings field + property)
- Modify: `python/api/registry.py:22`, `python/api/registry.py:34-50` (register the classifier)
- Modify: `python/api/routers/stamp.py:75-101` (`run_stamp_verify`)
- Modify: `python/api/schemas.py:89-95` (`StampVerifyResponse`)
- Modify: `python/api/routers/validate.py:192-211` (Stage 4b block)
- Modify: `python/api/routers/stamp.py:160-171` (`/v1/stamp/verify` route passes the classifier)
- Modify: `app/Services/Document/MlPipelineService.php:219-242` (`mapStages` Stage 4b block)
- Test: `python/tests/test_api.py`, `tests/Feature/Document/MlPipelineServiceTest.php`

**Interfaces:**
- Consumes: `python/scripts/train_stamp.py`'s artefact — a pickled `sklearn.linear_model.LogisticRegression` fitted over 1280-D EfficientNet features with **class 1 = genuine wet ink, class 0 = reproduction/forgery**.
- Produces:
  - `run_tamper_check(classifier, vector: list[float], threshold: float) -> dict` returning `{"stamp_tampered": bool, "genuine_probability": float}`.
  - `run_stamp_verify(model, image, reference, settings, document_type=None, city=None, classifier=None, missing_reason="unreferenced_logo") -> dict` — the returned dict always carries `stamp_tampered` and `genuine_probability` keys (both `None` when the classifier is not loaded). Task 2 relies on the `missing_reason` parameter.
  - `Settings.stamp_classifier_path -> Path` and `Settings.stamp_tamper_threshold: float`.
  - Registry key `"stamp_classifier"`.
  - Flag string `stamp_tampered`.

- [ ] **Step 1: Write the failing test for the tamper verdict without a reference**

Add to `python/tests/test_api.py`, immediately after `class _StubEmbedderModel` (around line 486):

```python
class _StubStampClassifier:
    """sklearn-like binary classifier matching train_stamp.py's labelling:
    column 1 = P(genuine wet ink), column 0 = P(reproduction)."""

    def __init__(self, genuine_probability: float):
        self._genuine = genuine_probability

    def predict_proba(self, features):
        assert np.asarray(features).shape[0] == 1
        return np.array([[1.0 - self._genuine, self._genuine]])
```

Then add this test directly after `test_stamp_verify_without_reference_flags_unreferenced_logo`:

```python
def test_stamp_verify_runs_the_tamper_check_without_a_reference(tmp_path, jpeg_bytes):
    """§5 Stage 4b: 'Tamper check (always runs, reference or not)'. An issuer with
    no reference logo yet must still get the wet-ink-vs-reproduction verdict."""
    pytest.importorskip("tensorflow")  # efficientnet preprocess_input

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["stamp"] = _StubEmbedderModel()
        app.state.registry._models["stamp_classifier"] = _StubStampClassifier(0.10)

        body = client.post(
            "/v1/stamp/verify", headers=AUTH, files=_upload(jpeg_bytes),
            data={"document_type": "business_permit", "city": "Makati"},
        ).json()

    assert body["reason"] == "unreferenced_logo"      # unchanged
    assert body["similarity_score"] is None           # unchanged
    assert body["stamp_tampered"] is True
    assert body["genuine_probability"] == pytest.approx(0.10)
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py::test_stamp_verify_runs_the_tamper_check_without_a_reference -v`
Expected: FAIL with `KeyError: 'stamp_tampered'` (the response model has no such field).

- [ ] **Step 3: Add the classifier path and decision boundary to Settings**

In `python/api/config.py`, add this field right after `stamp_model_path` (line 38) — the `*_model_path` field / `*_path` property pairing is the convention every other weight follows:

```python
    stamp_classifier_model_path: Path | None = None  # default: MODEL_DIR/stamp_classifier.pkl
```

Add this tunable right after `stamp_similarity_threshold` (line 59):

```python
    # NOT a new §9 parameter. train_stamp.py fits a binary LogisticRegression
    # (class 1 = genuine wet ink, class 0 = photocopy/edit) whose own predict()
    # boundary is 0.50; this exposes that boundary so an operator can trade
    # false accepts against false rejects without retraining.
    stamp_tamper_threshold: float = 0.50
```

Add this property right after the `stamp_path` property (line 119):

```python
    @property
    def stamp_classifier_path(self) -> Path:
        # train_stamp.py's genuine/forged LogisticRegression over the 1280-D
        # EfficientNet features — the §5 Stage 4b texture check.
        return self.stamp_classifier_model_path or self.model_dir / "stamp_classifier.pkl"
```

- [ ] **Step 4: Register the classifier in the model registry**

In `python/api/registry.py`, change `MODEL_NAMES` (line 22) to:

```python
MODEL_NAMES = ("classifier", "detector", "siamese", "stamp", "stamp_classifier",
               "rapid_detector", "trocr", "trocr_accurate")
```

Add this entry to the `loaders` dict in `load_all()`, right after the `"stamp"` line (line 38):

```python
            "stamp_classifier": (str(self.settings.stamp_classifier_path),
                                 self._load_stamp_classifier),
```

Add this loader right after `_load_stamp` (line 141):

```python
    def _load_stamp_classifier(self) -> Any:
        # Trusted artefact: produced and consumed only by ADVS's own code
        # (train_stamp.py). Never unpickle a stamp_classifier.pkl from an
        # untrusted source.
        import pickle

        with open(self.settings.stamp_classifier_path, "rb") as fh:
            return pickle.load(fh)
```

- [ ] **Step 5: Always embed the crop and run the texture check**

In `python/api/routers/stamp.py`, replace `run_stamp_verify` (lines 75–101) with:

```python
def run_tamper_check(classifier, vector: list[float], threshold: float) -> dict:
    """EfficientNet texture verdict for one logo crop (§5 Stage 4b).

    train_stamp.py fits a LogisticRegression over the 1280-D feature vector with
    class 1 = genuine wet ink and class 0 = a photocopied/scanned/edited
    reproduction, so column 1 of predict_proba is the genuine probability.
    """
    import numpy as np

    probabilities = np.asarray(classifier.predict_proba(np.asarray([vector], dtype=np.float64)))
    genuine = float(probabilities[0][1])

    return {"stamp_tampered": genuine < threshold, "genuine_probability": round(genuine, 6)}


def run_stamp_verify(
    model,
    image: Image.Image,
    reference: list[float] | None,
    settings: Settings,
    document_type: str | None = None,
    city: str | None = None,
    classifier=None,
    missing_reason: str = "unreferenced_logo",
) -> dict:
    """Stage 4b: texture check first, issuer comparison second.

    The crop is ALWAYS embedded, reference or not — §5 Stage 4b runs the
    wet-ink-vs-reproduction check "even before any reference exists", so the
    fraud signal does not wait on an issuer being seeded. ``missing_reason``
    lets the caller say WHY there is no reference (no issuer seeded yet, or an
    LGU city that OCR could not read) without losing that check.
    """
    vector = embed_stamp(model, image)
    tamper = (
        run_tamper_check(classifier, vector, settings.stamp_tamper_threshold)
        if classifier is not None
        else {"stamp_tampered": None, "genuine_probability": None}
    )
    base = {"document_type": document_type, "city": city, **tamper}

    if reference is None:
        return {**base, "match": False, "reason": missing_reason,
                "similarity_score": None, "threshold": None}

    emb.require_same_length(reference, vector, "reference_vector")

    similarity = emb.cosine_similarity(vector, reference)
    threshold = settings.resolved_stamp_similarity_threshold()

    return {
        **base,
        "match": similarity >= threshold,
        "similarity_score": similarity,
        "threshold": threshold,
        "reason": None,
    }
```

- [ ] **Step 6: Declare the new response fields**

In `python/api/schemas.py`, replace `class StampVerifyResponse` (lines 89–95) with:

```python
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
```

- [ ] **Step 7: Pass the classifier from the /v1/stamp/verify route**

In `python/api/routers/stamp.py`, replace the body of the `stamp_verify` route (lines 168–171) with:

```python
    model = request.app.state.registry.require("stamp")
    reference = emb.parse_reference(reference_vector, "reference_vector") if reference_vector else None
    image = await _read_crop(file)

    return run_stamp_verify(
        model, image, reference, request.app.state.settings, document_type, city,
        classifier=request.app.state.registry.get("stamp_classifier"),
    )
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py::test_stamp_verify_runs_the_tamper_check_without_a_reference -v`
Expected: PASS.

- [ ] **Step 9: Fix the pre-existing test that no longer holds**

`test_stamp_verify_without_reference_flags_unreferenced_logo` previously never reached the embedder, so it lacks a TensorFlow guard. It now embeds. In `python/tests/test_api.py`, add the guard as the first line of that test's body and assert the null verdict:

```python
def test_stamp_verify_without_reference_flags_unreferenced_logo(tmp_path, jpeg_bytes):
    pytest.importorskip("tensorflow")  # the crop is now always embedded

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["stamp"] = _StubEmbedderModel()
        body = client.post(
            "/v1/stamp/verify", headers=AUTH, files=_upload(jpeg_bytes),
            data={"document_type": "business_permit", "city": "Makati"},
        ).json()

    assert body["match"] is False
    assert body["reason"] == "unreferenced_logo"
    assert body["similarity_score"] is None
    assert body["city"] == "Makati"
    # No stamp_classifier loaded -> the check could not run; not "clean".
    assert body["stamp_tampered"] is None
```

Then update `test_health_is_open_and_reports_missing_models` — the registry now reports one more model. Change the expected set to:

```python
    assert set(body["models"]) == {
        "classifier", "detector", "siamese", "stamp", "stamp_classifier",
        "rapid_detector", "trocr", "trocr_accurate",
    }
    # File/dir-gated models: an empty tmp model_dir means none of these are
    # configured, so all report "not loaded" the same way.
    for name in ("classifier", "detector", "siamese", "stamp", "stamp_classifier",
                 "trocr", "trocr_accurate"):
```

- [ ] **Step 10: Run the whole API test file to verify nothing else regressed**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -v`
Expected: all PASS.

- [ ] **Step 11: Write the failing test for the pipeline-level flag**

Add to `python/tests/test_api.py`, in the validate section after `test_validate_is_fail_forward_without_models`:

```python
class _StubBox:
    def __init__(self, cls: int, conf: float, xyxy: list[float]):
        self.cls = cls
        self.conf = conf
        # ultralytics hands back a tensor/array row, and run_detection calls
        # .tolist() on it — a bare list would not survive that.
        self.xyxy = [np.asarray(xyxy)]


class _StubResult:
    def __init__(self, names: dict[int, str], boxes: list[_StubBox]):
        self.names = names
        self.boxes = boxes


class _StubDetector:
    """Just enough of the ultralytics YOLO surface for run_detection."""

    names = {0: "signature", 1: "stamp"}

    def predict(self, source=None, conf=0.0, verbose=False):
        return [_StubResult(self.names, [_StubBox(1, 0.9, [5.0, 5.0, 60.0, 60.0])])]


def test_validate_flags_a_tampered_stamp(tmp_path, jpeg_bytes):
    """A reproduction detected on the crop must reach the officer as a flag even
    though the issuer has no reference logo yet (§5 Stage 4b)."""
    pytest.importorskip("tensorflow")

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["detector"] = _StubDetector()
        app.state.registry._models["stamp"] = _StubEmbedderModel()
        app.state.registry._models["stamp_classifier"] = _StubStampClassifier(0.05)

        body = client.post("/v1/validate", headers=AUTH, files=_upload(jpeg_bytes)).json()

    assert body["stages"]["stamp"]["stamp_tampered"] is True
    assert "stamp_tampered" in body["flags"]
    assert "unreferenced_logo" in body["flags"]
```

- [ ] **Step 12: Run it to verify it fails**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py::test_validate_flags_a_tampered_stamp -v`
Expected: FAIL — `assert None is True` (validate.py does not pass the classifier), or `KeyError: 'stamp_tampered'`.

- [ ] **Step 13: Wire the classifier and the flag into validate.py**

In `python/api/routers/validate.py`, replace the Stage 4b `else` branch body (lines 201–211) with:

```python
        else:
            try:
                stages["stamp"] = run_stamp_verify(
                    stamp_model, _crop(first_page, stamp_box["box"]),
                    logo_reference, settings, document_type, city,
                    classifier=registry.get("stamp_classifier"),
                )
                if stages["stamp"].get("reason"):
                    flags.append(stages["stamp"]["reason"])
                # A reproduction is a fraud signal in its own right, independent
                # of whether an issuer reference existed to compare against.
                if stages["stamp"].get("stamp_tampered") is True:
                    flags.append("stamp_tampered")
            except Exception as exc:
                logger.exception("stamp stage failed")
                stages["stamp"] = _skipped(f"error: {exc}")
```

- [ ] **Step 14: Run the test to verify it passes**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -v`
Expected: all PASS.

- [ ] **Step 15: Write the failing Laravel test for persisting the verdict**

Add to `tests/Feature/Document/MlPipelineServiceTest.php`, in the `mapStages()` section:

```php
    public function test_persists_the_stage_4b_tamper_verdict_without_an_issuer_reference(): void
    {
        $stages = [
            'stamp' => [
                'match' => false,
                'reason' => 'unreferenced_logo',
                'similarity_score' => null,
                'stamp_tampered' => true,
                'genuine_probability' => 0.05,
            ],
        ];

        $mapped = $this->service()->mapStages($stages, ['issuer_scope' => 'lgu']);

        $this->assertTrue($mapped['columns']['stamp_tampered']);
        $this->assertFalse($mapped['columns']['stamp_detected']);
        $this->assertContains('unreferenced_logo', $mapped['flags']);
    }

    public function test_leaves_the_tamper_verdict_untouched_when_the_classifier_did_not_run(): void
    {
        $stages = [
            'stamp' => [
                'match' => true, 'similarity_score' => 0.95, 'reason' => null,
                'stamp_tampered' => null, 'genuine_probability' => null,
            ],
        ];

        $mapped = $this->service()->mapStages($stages, ['issuer_scope' => 'national']);

        // Null is "could not run", not "clean" — never overwrite an earlier verdict.
        $this->assertArrayNotHasKey('stamp_tampered', $mapped['columns']);
    }
```

- [ ] **Step 16: Run them to verify they fail**

Run: `php artisan config:clear && php artisan test --compact --filter=MlPipelineServiceTest`
Expected: FAIL — `Undefined array key "stamp_tampered"` on the first test.

- [ ] **Step 17: Map the verdict onto the column**

In `app/Services/Document/MlPipelineService.php`, insert this immediately after `$issuerScope = $context['issuer_scope'] ?? null;` (line 221) — before the `if ($this->ran($stamp) …)` branch, so it applies whether or not a reference existed:

```php
        // §5 Stage 4b's texture check runs reference or not, so its verdict is
        // read before the reference branch below. Null means the classifier
        // could not run — leave the column alone rather than recording "clean".
        if ($this->ran($stamp) && ($stamp['stamp_tampered'] ?? null) !== null) {
            $columns['stamp_tampered'] = (bool) $stamp['stamp_tampered'];
        }
```

- [ ] **Step 18: Run the Laravel tests to verify they pass**

Run: `php artisan config:clear && php artisan test --compact --filter=MlPipelineServiceTest`
Expected: all PASS.

- [ ] **Step 19: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add python/api/config.py python/api/registry.py python/api/routers/stamp.py python/api/routers/validate.py python/api/schemas.py python/tests/test_api.py app/Services/Document/MlPipelineService.php tests/Feature/Document/MlPipelineServiceTest.php
git commit -m "Run the Stage 4b stamp tamper check with or without a reference"
```

---

## Task 2: LGU issuer logos resolved by the OCR-detected city

**Files:**
- Modify: `python/api/routers/validate.py:78-96` (form signature), `:126-153` (OCR block), `:192-211` (Stage 4b block); add module-level helpers
- Test: `python/tests/test_api.py`

**Interfaces:**
- Consumes: `run_stamp_verify(..., missing_reason=...)` from Task 1.
- Produces:
  - `canonical_city(value: str | None) -> str | None` — squish + title-case, the single canonical form shared with Laravel's `Str::title(Str::squish(...))`.
  - `parse_reference_map(raw: str | None) -> dict[str, list[float]]` — parses the `stamp_references` form field, keys canonicalised, `""` = the national sentinel.
  - `resolve_issuer_reference(references, single, issuer_scope, city) -> tuple[list[float] | None, str | None]` — returns `(vector, flag)`; flag is `"city_not_identified"` or `None`.
  - Two new `/v1/validate` form fields: `issuer_scope` (`"national"` | `"lgu"` | absent) and `stamp_references` (JSON object `{city: vector}`).
  - `stages["stamp"]["city"]` now reports the **resolved** city (caller-supplied, else OCR-detected) — Task 3's Laravel step reads it to pick the matched `logo_references` row.
  - Flag string `city_not_identified`.

- [ ] **Step 1: Write the failing tests for the pure resolution helpers**

Add to `python/tests/test_api.py`, in the validate section:

```python
def test_canonical_city_matches_the_form_laravel_stores():
    from api.routers.validate import canonical_city

    assert canonical_city("CITY OF DIGOS") == "City Of Digos"
    assert canonical_city("City of  Digos ") == "City Of Digos"
    assert canonical_city("") is None
    assert canonical_city(None) is None


def test_resolve_issuer_reference_uses_the_national_sentinel():
    """logo_references.city is '' for a national issuer (one logo agency-wide),
    so the city read off the page is irrelevant to the lookup (§5 Stage 4b)."""
    from api.routers.validate import resolve_issuer_reference

    references = {"": [1.0, 2.0], "Pasig": [3.0, 4.0]}

    vector, flag = resolve_issuer_reference(references, None, "national", "Pasig")

    assert vector == [1.0, 2.0]
    assert flag is None


def test_resolve_issuer_reference_scopes_an_lgu_issuer_by_city():
    from api.routers.validate import resolve_issuer_reference

    references = {"Pasig": [3.0, 4.0], "Quezon City": [5.0, 6.0]}

    assert resolve_issuer_reference(references, None, "lgu", "quezon  CITY")[0] == [5.0, 6.0]
    # An LGU city with no seeded reference yet is a miss, not a mis-scope.
    assert resolve_issuer_reference(references, None, "lgu", "Makati") == (None, None)


def test_resolve_issuer_reference_flags_an_unreadable_lgu_city():
    """§5 Stage 4b failure path: 'City not identified' — the lookup cannot be
    scoped, so verification is skipped rather than compared to a wrong city."""
    from api.routers.validate import resolve_issuer_reference

    assert resolve_issuer_reference({"Pasig": [3.0]}, None, "lgu", None) == (None, "city_not_identified")
```

- [ ] **Step 2: Run them to verify they fail**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -k "canonical_city or resolve_issuer_reference" -v`
Expected: FAIL with `ImportError: cannot import name 'canonical_city' from 'api.routers.validate'`.

- [ ] **Step 3: Implement the helpers**

In `python/api/routers/validate.py`, add these after `_parse_forensics` (line 57):

```python
def canonical_city(value: str | None) -> str | None:
    """The single canonical form of a city name, shared with Laravel.

    ``logo_references.city`` is half of the unique ``(document_type_id, city)``
    key and Laravel writes it as ``Str::title(Str::squish($city))``, so "CITY OF
    DIGOS" and "City of  Digos" must resolve to one issuer here too. Returns
    ``None`` for an empty read — note that ``''`` is NOT "no city", it is the
    national-issuer sentinel.
    """
    if value is None:
        return None
    squished = " ".join(str(value).split())

    return squished.title() if squished else None


def parse_reference_map(raw: str | None) -> dict[str, list[float]]:
    """Every reference logo the caller holds for this issuer, keyed by city.

    An ``lgu`` issuer's city is printed on the document, so it does not exist
    until Stage 2 runs INSIDE this call — the caller therefore sends the whole
    set and this service picks with the city it read. The ``''`` key is the
    national sentinel, exactly as stored in ``logo_references.city``.
    """
    if not raw:
        return {}
    try:
        parsed = json.loads(raw)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=f"Invalid stamp_references: {exc}") from exc
    if not isinstance(parsed, dict):
        raise HTTPException(status_code=422, detail="stamp_references must be a JSON object.")

    return {
        (canonical_city(key) or ""): [float(v) for v in value]
        for key, value in parsed.items()
        if isinstance(value, list) and value
    }


def resolve_issuer_reference(
    references: dict[str, list[float]],
    single: list[float] | None,
    issuer_scope: str | None,
    city: str | None,
) -> tuple[list[float] | None, str | None]:
    """The issuer's reference logo for this document (§5 Stage 4b), or why not.

    Returns ``(vector, flag)``. ``flag`` is ``city_not_identified`` when an LGU
    document's issuing city could not be read: the lookup cannot be scoped, so
    the comparison is skipped rather than run against another city's seal. A
    ``None`` vector with no flag simply means this issuer has no reference yet.
    """
    if issuer_scope == "lgu":
        key = canonical_city(city)
        if key is None:
            return None, "city_not_identified"

        return references.get(key), None

    # national (the '' sentinel) or an unscoped caller; `single` is the legacy
    # one-vector form of the same thing.
    return references.get("", single), None
```

- [ ] **Step 4: Run them to verify they pass**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -k "canonical_city or resolve_issuer_reference" -v`
Expected: all PASS.

- [ ] **Step 5: Write the failing test for the routing through /v1/validate**

Add to `python/tests/test_api.py` (it reuses `_StubDetector`, `_StubEmbedderModel` from Task 1):

```python
def test_validate_verifies_an_lgu_logo_against_the_matching_city_reference(tmp_path, jpeg_bytes):
    """The whole city→vector set travels with the request; this service picks
    the one for the document's city (§5 Stage 2 → Stage 4b)."""
    pytest.importorskip("tensorflow")

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["detector"] = _StubDetector()
        app.state.registry._models["stamp"] = _StubEmbedderModel()

        vector = client.post(
            "/v1/stamp/embed", headers=AUTH, files=_upload(jpeg_bytes)
        ).json()["vector"]

        body = client.post(
            "/v1/validate", headers=AUTH, files=_upload(jpeg_bytes),
            data={
                "issuer_scope": "lgu",
                "city": "PASIG  CITY",
                "stamp_references": json.dumps({
                    "Pasig City": vector,
                    "Makati": [-v for v in vector],
                }),
            },
        ).json()

    stamp = body["stages"]["stamp"]
    assert stamp["city"] == "Pasig City"
    assert stamp["match"] is True
    assert stamp["similarity_score"] == pytest.approx(1.0)
    assert "unreferenced_logo" not in body["flags"]


def test_validate_flags_an_lgu_document_with_no_readable_city(tmp_path, jpeg_bytes):
    """§5 Stage 4b: no city → 'City not identified'; the tamper check still ran."""
    pytest.importorskip("tensorflow")

    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["detector"] = _StubDetector()
        app.state.registry._models["stamp"] = _StubEmbedderModel()

        body = client.post(
            "/v1/validate", headers=AUTH, files=_upload(jpeg_bytes),
            data={"issuer_scope": "lgu",
                  "stamp_references": json.dumps({"Pasig City": [1.0, 2.0, 3.0]})},
        ).json()

    assert body["stages"]["stamp"]["reason"] == "city_not_identified"
    assert "city_not_identified" in body["flags"]
    assert "stamp_tampered" in body["stages"]["stamp"]  # the check still ran


def test_validate_rejects_a_malformed_reference_map(client, jpeg_bytes):
    response = client.post(
        "/v1/validate", headers=AUTH, files=_upload(jpeg_bytes),
        data={"stamp_references": "[1, 2, 3]"},
    )
    assert response.status_code == 422
```

- [ ] **Step 6: Run them to verify they fail**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -k "lgu or malformed_reference_map" -v`
Expected: FAIL — the extra form fields are ignored, so `stamp["city"]` is `None` and `reason` is `unreferenced_logo`.

- [ ] **Step 7: Accept the new form fields**

In `python/api/routers/validate.py`, add these two parameters to the `validate` signature, after `city` (line 84):

```python
    issuer_scope: str | None = Form(None),
    stamp_references: str | None = Form(None),
```

Then replace the reference-parsing lines (93–94) with:

```python
    sig_reference = emb.parse_reference(signature_reference, "signature_reference") if signature_reference else None
    single_logo_reference = emb.parse_reference(stamp_reference, "stamp_reference") if stamp_reference else None
    logo_references = parse_reference_map(stamp_references)
```

- [ ] **Step 8: Resolve the city from OCR and route Stage 4b through it**

In `python/api/routers/validate.py`, add this immediately after the Stage 2 `try/except` block (after line 153, before the `# ── Stage 4: detection` comment):

```python
        # §5 Stage 2 → 4b: the issuing city is printed on the document, so an
        # LGU issuer can only be scoped after OCR has read it. An explicit
        # caller value wins (the '' national sentinel canonicalises to None).
        detected_city = canonical_city(ocr_context.get("fields", {}).get("city_issued"))
        resolved_city = canonical_city(city) or detected_city
```

Then replace the Stage 4b block (lines 192–211, as left by Task 1) with:

```python
        # ── Stage 4b: issuer logo verify ───────────────────────────────────
        stamp_model = registry.get("stamp")
        stamp_box = _best_box(detections, ("stamp", "logo"))
        logo_reference, reference_flag = resolve_issuer_reference(
            logo_references, single_logo_reference, issuer_scope, resolved_city
        )
        if stamp_model is None:
            stages["stamp"] = _skipped("model_not_loaded")
        elif not stages.get("detection") or stages["detection"].get("skipped"):
            stages["stamp"] = _skipped("detection_unavailable")
        elif stamp_box is None:
            stages["stamp"] = _skipped("no_stamp_detected")
        else:
            try:
                stages["stamp"] = run_stamp_verify(
                    stamp_model, _crop(first_page, stamp_box["box"]),
                    logo_reference, settings, document_type, resolved_city,
                    classifier=registry.get("stamp_classifier"),
                    # Why there is no reference: an unseeded issuer, or an LGU
                    # city OCR could not read. Either way the texture check above
                    # still runs — only the comparison is skipped.
                    missing_reason=reference_flag or "unreferenced_logo",
                )
                if stages["stamp"].get("reason"):
                    flags.append(stages["stamp"]["reason"])
                if stages["stamp"].get("stamp_tampered") is True:
                    flags.append("stamp_tampered")
            except Exception as exc:
                logger.exception("stamp stage failed")
                stages["stamp"] = _skipped(f"error: {exc}")
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -v`
Expected: all PASS.

- [ ] **Step 10: Commit**

```bash
git add python/api/routers/validate.py python/tests/test_api.py
git commit -m "Scope Stage 4b logo lookup by the city OCR reads off the page"
```

---

## Task 3: Laravel sends every issuer reference and records the matched one

**Files:**
- Modify: `app/Services/Document/MlPipelineService.php:39-83` (`validate`), `:219-242` (`mapStages` Stage 4b), `:362-384` (`resolveLogoReference` → `resolveLogoReferences`)
- Test: `tests/Feature/Document/MlPipelineServiceTest.php`

**Interfaces:**
- Consumes: Task 2's `issuer_scope` + `stamp_references` form fields and the resolved `stages.stamp.city`.
- Produces:
  - `MlPipelineService::validate()` returns `context` as `array{issuer_scope: string|null, logo_reference_ids: array<string, int>}` — **the `logo_reference_id` key is replaced by `logo_reference_ids`** (city ⇒ row id).
  - `mapStages($stages, $context)` reads `$context['logo_reference_ids']`.

- [ ] **Step 1: Write the failing test for sending the whole reference set**

Add to `tests/Feature/Document/MlPipelineServiceTest.php`:

```php
    public function test_sends_every_city_reference_for_an_lgu_issuer(): void
    {
        Http::fake(['*/v1/validate' => Http::response(['stages' => [], 'flags' => []])]);

        $typeId = DB::table('document_types')->insertGetId([
            'name' => 'Business Permit', 'code' => 'business_permit',
            'issuer_scope' => 'lgu', 'is_required' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['Pasig City', [0.1, 0.2]], ['Makati', [0.3, 0.4]]] as [$city, $vector]) {
            DB::table('logo_references')->insert([
                'document_type_id' => $typeId, 'city' => $city, 'label' => $city,
                'feature_vector' => json_encode($vector),
                // NOT NULL with no default (migration 2026_06_26_160503).
                'reference_image_path' => 'refs/'.Str::slug($city).'.png',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->service()->validate($this->document(['document_type_id' => $typeId]));

        // multipartField() is this file's existing helper for reading one
        // form-data field out of the raw Guzzle body.
        Http::assertSent(function (Request $request) {
            $body = $request->body();

            // == not ===: the rows come back city-ascending off the
            // unique(document_type_id, city) index, and key order is not part
            // of the contract — the API looks the map up by key.
            return $this->multipartField($body, 'issuer_scope') === 'lgu'
                && json_decode($this->multipartField($body, 'stamp_references'), true)
                    == ['Pasig City' => [0.1, 0.2], 'Makati' => [0.3, 0.4]];
        });
    }

    public function test_logo_reference_id_follows_the_city_the_api_matched(): void
    {
        $stages = [
            'stamp' => ['match' => true, 'similarity_score' => 0.95, 'reason' => null,
                'city' => 'Pasig City', 'stamp_tampered' => false],
        ];

        $mapped = $this->service()->mapStages($stages, [
            'issuer_scope' => 'lgu',
            'logo_reference_ids' => ['Pasig City' => 7, 'Makati' => 9],
        ]);

        $this->assertSame(7, $mapped['columns']['logo_reference_id']);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan config:clear && php artisan test --compact --filter=MlPipelineServiceTest`
Expected: FAIL — `stamp_references` is not sent (undefined key), and `logo_reference_id` is `null`.

- [ ] **Step 3: Replace the single-reference lookup with the full set**

In `app/Services/Document/MlPipelineService.php`, replace `resolveLogoReference` (lines 362–384) with:

```php
    /**
     * Every reference logo this issuer has, keyed by city, plus each row's id.
     *
     * A `national` issuer has exactly one row under the `''` city sentinel; an
     * `lgu` issuer has one per city, and which one applies depends on the city
     * printed on the document — which does not exist until Stage 2 OCR runs,
     * inside the same API call. So the whole set travels with the request and
     * the API picks; {@see matchedLogoReferenceId()} then records which.
     *
     * @return array{0: string|null, 1: array<string, int>} [JSON {city: vector}, city => id]
     */
    private function resolveLogoReferences(?int $documentTypeId, ?string $issuerScope): array
    {
        if ($documentTypeId === null || $issuerScope === null) {
            return [null, []];
        }

        $vectors = [];
        $ids = [];

        $rows = DB::table('logo_references')
            ->where('document_type_id', $documentTypeId)
            ->whereNotNull('feature_vector')
            ->get(['id', 'city', 'feature_vector']);

        foreach ($rows as $row) {
            $vector = json_decode((string) $row->feature_vector, true);
            if (! is_array($vector) || $vector === []) {
                continue;
            }
            $vectors[$row->city] = $vector;
            $ids[$row->city] = (int) $row->id;
        }

        return [$vectors === [] ? null : json_encode($vectors, JSON_THROW_ON_ERROR), $ids];
    }

    /**
     * The `logo_references` row the API actually compared against: the `''`
     * sentinel row for a national issuer, or the row for the city Stage 2 read.
     *
     * @param  array<string, int>  $ids  city => logo_references.id
     */
    private function matchedLogoReferenceId(array $ids, ?string $issuerScope, ?string $city): ?int
    {
        if ($issuerScope === 'national') {
            return $ids[''] ?? null;
        }

        $key = Str::title(Str::squish((string) $city));

        return $key === '' ? null : ($ids[$key] ?? null);
    }
```

- [ ] **Step 4: Send the set and the issuer scope**

In `app/Services/Document/MlPipelineService.php`, replace lines 48–59 with:

```php
        [$stampReferences, $logoReferenceIds] = $this->resolveLogoReferences(
            $document->document_type_id, $issuerScope
        );

        $form = array_filter([
            'template' => $this->ocrTemplateFor($type['code'] ?? null),
            'document_type' => $type['code'] ?? null,
            // The API needs the scope to know whether to key the lookup by city.
            'issuer_scope' => $issuerScope,
            'city' => $city,
            'signature_reference' => $this->resolveSignatureReference($document),
            'stamp_references' => $stampReferences,
            'forensics' => json_encode($this->forensicsContext(), JSON_THROW_ON_ERROR),
        ], static fn ($value): bool => $value !== null);
```

Then replace the `context` in the return (line 81) with:

```php
            'context' => ['issuer_scope' => $issuerScope, 'logo_reference_ids' => $logoReferenceIds],
```

- [ ] **Step 5: Record which reference matched**

In `app/Services/Document/MlPipelineService.php`, inside the `mapStages` Stage 4b `if` branch, replace the `logo_reference_id` line (line 228) with:

```php
            $columns['logo_reference_id'] = $this->matchedLogoReferenceId(
                $context['logo_reference_ids'] ?? [], $issuerScope, $stamp['city'] ?? null
            );
```

- [ ] **Step 6: Update the pre-existing tests that used the old context shape**

In `tests/Feature/Document/MlPipelineServiceTest.php`, `test_maps_clean_stages_to_columns` passes `['issuer_scope' => 'national', 'logo_reference_id' => 42]`. Change only that call to the new shape:

```php
        $mapped = $this->service()->mapStages($stages, [
            'issuer_scope' => 'national',
            'logo_reference_ids' => ['' => 42],
        ]);
```

Do **not** add a `'city'` key to that fixture. `matchedLogoReferenceId()` short-circuits on `issuer_scope === 'national'` before it reads the city, so `$stamp['city'] ?? null` being absent is correct — and it matches reality: Task 2 canonicalises the `''` national sentinel to `None`, so the API reports `city: null` for a national issuer, not `''`.

Then convert every other `mapStages(...)` context argument the same way (`['' => N]` for `national`, `['<City>' => N]` for `lgu`):

```bash
grep -rn "logo_reference_id" tests/ app/
```

Also update `test_validate_sends_issuer_references_for_a_national_type` in the same file — the national vector now travels inside the `{"": [...]}` map, so amend the stale comment and assert the new field:

```php
            return str_contains($body, 'name="document_type"')
                && str_contains($body, 'bir_permit')
                && str_contains($body, '[0.1,0.2,0.3]')   // signature_reference
                && str_contains($body, '{"":[0.4,0.5]}')  // stamp_references, '' = national sentinel
                && $this->multipartField($body, 'issuer_scope') === 'national'
                && str_contains($body, 'name="forensics"');
```

- [ ] **Step 7: Run the Laravel tests to verify they pass**

Run: `php artisan config:clear && php artisan test --compact --filter="MlPipelineServiceTest|ProcessDocumentActionTest|EnrollReferenceJobTest"`
Expected: all PASS.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Document/MlPipelineService.php tests/Feature/Document/MlPipelineServiceTest.php
git commit -m "Send every issuer logo reference so LGU permits verify by city"
```

---

## Task 4: A document the classifier calls `fake` raises risk instead of lowering it

**Files:**
- Modify: `python/api/routers/classify.py:19-54` (`run_classification`)
- Modify: `python/api/schemas.py:26-31` (`ClassifyResponse`)
- Modify: `python/api/routers/validate.py:108-124` (Stage 3 block); add a module-level helper
- Create: `database/migrations/2026_08_12_000001_add_classification_authenticity_to_validation_results_table.php`
- Modify: `app/Models/ValidationResult.php` (fillable + casts)
- Modify: `app/Services/Document/MlPipelineService.php:161-166` (`mapStages` Stage 3 block)
- Modify: `app/Actions/ProcessDocumentAction.php:75-82` (risk components)
- Test: `python/tests/test_api.py`, `tests/Feature/Document/MlPipelineServiceTest.php`, `tests/Feature/Document/ProcessDocumentActionTest.php`

**Interfaces:**
- Consumes: `python/models/class_names.json` = `["bir_certificate", "business_permit", "dti_registration", "fake"]`; `document_types.code` uses those same strings for the three real types.
- Produces:
  - `run_classification(...)` result gains `"authenticity": float` = `1.0 - P(fake)` (equal to `confidence` when the model has no `fake` class).
  - `classification_flags(stage: dict, document_type: str | None, class_names: list[str]) -> list[str]` in `validate.py`.
  - Flag strings `classified_as_fake`, `document_type_mismatch`.
  - `validation_results.classification_authenticity` (nullable float).

- [ ] **Step 1: Write the failing test for the authenticity score**

Add to `python/tests/test_api.py`, in the classify section:

```python
def _classifier_entry(probabilities: list[float], class_names: list[str]) -> dict:
    class _StubModel:
        input_shape = (None, 64, 64, 3)

        def predict(self, batch, verbose=0):
            return np.array([probabilities])

    return {"model": _StubModel(), "class_names": class_names}


CLASSES = ["bir_certificate", "business_permit", "dti_registration", "fake"]


def test_classification_authenticity_discounts_the_fake_probability(tmp_path, jpeg_bytes):
    """A confident 'fake' must not reach the risk blend as high authenticity —
    the classification component is (1 - risk), so P(fake) is what matters."""
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["classifier"] = _classifier_entry(
            [0.01, 0.01, 0.00, 0.98], CLASSES
        )
        body = client.post("/v1/classify", headers=AUTH, files=_upload(jpeg_bytes)).json()

    assert body["label"] == "fake"
    assert body["confidence"] == pytest.approx(0.98)
    assert body["authenticity"] == pytest.approx(0.02)


def test_classification_authenticity_equals_confidence_without_a_fake_class(tmp_path, jpeg_bytes):
    app = create_app(_settings(tmp_path))
    with TestClient(app) as client:
        app.state.registry._models["classifier"] = _classifier_entry(
            [0.15, 0.85], ["bir_certificate", "business_permit"]
        )
        body = client.post("/v1/classify", headers=AUTH, files=_upload(jpeg_bytes)).json()

    assert body["authenticity"] == pytest.approx(1.0)
```

- [ ] **Step 2: Run them to verify they fail**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -k "authenticity" -v`
Expected: FAIL with `KeyError: 'authenticity'`.

- [ ] **Step 3: Compute authenticity in run_classification**

In `python/api/routers/classify.py`, replace the return statement (lines 45–54) with:

```python
    # The classifier's `fake` class IS the fraud signal, so the Stage 3
    # component the risk blend consumes is "probability this is a genuine
    # document of a known type" — not the winning class's confidence, which is
    # just as high for a confidently-detected forgery.
    fake_index = class_names.index("fake") if "fake" in class_names else None
    fake_probability = (
        float(probabilities[fake_index])
        if fake_index is not None and fake_index < len(probabilities)
        else 0.0
    )

    return {
        "label": label,
        "confidence": confidence,
        "authenticity": round(1.0 - fake_probability, 6),
        "probabilities": {
            class_names[i] if i < len(class_names) else str(i): float(score)
            for i, score in enumerate(probabilities)
        },
        "threshold": threshold,
        "passed_threshold": confidence >= threshold,
    }
```

In `python/api/schemas.py`, replace `class ClassifyResponse` (lines 26–31) with:

```python
class ClassifyResponse(BaseModel):
    label: str
    confidence: float
    # 1 - P(fake): the Stage 3 authenticity the risk blend consumes. Equals
    # confidence when the model has no `fake` class.
    authenticity: float
    probabilities: dict[str, float]
    threshold: float
    passed_threshold: bool
```

- [ ] **Step 4: Run them to verify they pass**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -k "authenticity" -v`
Expected: PASS.

- [ ] **Step 5: Write the failing tests for the Stage 3 flags**

Add to `python/tests/test_api.py`:

```python
def test_classification_flags_a_fake_verdict():
    from api.routers.validate import classification_flags

    stage = {"label": "fake", "confidence": 0.98, "passed_threshold": True}

    assert classification_flags(stage, "bir_certificate", CLASSES) == ["classified_as_fake"]


def test_classification_flags_a_confident_disagreement_with_the_declared_type():
    from api.routers.validate import classification_flags

    stage = {"label": "business_permit", "confidence": 0.93, "passed_threshold": True}

    assert classification_flags(stage, "bir_certificate", CLASSES) == ["document_type_mismatch"]


def test_classification_does_not_flag_a_type_the_model_never_learned():
    """sanitary_permit has no class, so the model CANNOT agree with it — that is
    an untrained type, not vendor misdeclaration."""
    from api.routers.validate import classification_flags

    stage = {"label": "business_permit", "confidence": 0.93, "passed_threshold": True}

    assert classification_flags(stage, "sanitary_permit", CLASSES) == []


def test_classification_does_not_flag_a_mismatch_it_is_unsure_about():
    from api.routers.validate import classification_flags

    stage = {"label": "business_permit", "confidence": 0.41, "passed_threshold": False}

    assert classification_flags(stage, "bir_certificate", CLASSES) == []
```

- [ ] **Step 6: Run them to verify they fail**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -k "classification_flags or classification_does_not_flag" -v`
Expected: FAIL with `ImportError: cannot import name 'classification_flags'`.

- [ ] **Step 7: Implement the flag helper and wire it in**

In `python/api/routers/validate.py`, add this after `resolve_issuer_reference`:

```python
def classification_flags(stage: dict, document_type: str | None,
                         class_names: list[str]) -> list[str]:
    """Stage 3 signals beyond the confidence gate (§5 Stage 3).

    ``classified_as_fake``     — the model's fraud class won the softmax. Raised
                                 at any confidence: a forgery verdict is news
                                 whether or not it cleared the type-confidence
                                 gate.
    ``document_type_mismatch`` — a CONFIDENT prediction disagrees with the type
                                 the vendor declared. Only meaningful for a
                                 declared type the model was trained on; a type
                                 outside ``class_names`` (sanitary permit, an
                                 ID, a contract) can never match, and flagging
                                 it would punish the vendor for a gap in the
                                 training corpus.
    """
    flags = []
    label = stage.get("label")

    if label == "fake":
        flags.append("classified_as_fake")
    elif (
        stage.get("passed_threshold")
        and document_type is not None
        and document_type in class_names
        and label != document_type
    ):
        flags.append("document_type_mismatch")

    return flags
```

Then in the Stage 3 block, replace lines 115–121 with:

```python
            try:
                stage = run_classification(
                    classifier, first_page, settings.classification_confidence_threshold
                )
                stages["classification"] = stage
                if not stage["passed_threshold"]:
                    flags.append("low_classification_confidence")
                flags.extend(classification_flags(
                    stage, document_type, classifier.get("class_names") or []
                ))
```

- [ ] **Step 8: Run them to verify they pass**

Run: `python\env\Scripts\python.exe -m pytest python/tests/test_api.py -v`
Expected: all PASS.

- [ ] **Step 9: Commit the Python half**

```bash
git add python/api/routers/classify.py python/api/routers/validate.py python/api/schemas.py python/tests/test_api.py
git commit -m "Flag fake and type-mismatched documents in Stage 3"
```

- [ ] **Step 10: Write the failing Laravel test for the risk input**

Add to `tests/Feature/Document/MlPipelineServiceTest.php`:

```php
    public function test_maps_classification_authenticity_separately_from_confidence(): void
    {
        $stages = [
            'classification' => ['label' => 'fake', 'confidence' => 0.98,
                'authenticity' => 0.02, 'passed_threshold' => true],
        ];

        $columns = $this->service()->mapStages($stages)['columns'];

        // The drill-down still shows what the model was confident ABOUT...
        $this->assertEqualsWithDelta(0.98, $columns['classification_confidence'], 1e-6);
        // ...while the risk blend gets 1 - P(fake).
        $this->assertEqualsWithDelta(0.02, $columns['classification_authenticity'], 1e-6);
    }
```

Add to `tests/Feature/Document/ProcessDocumentActionTest.php` (it uses that file's existing `document()`, `fakeMl()`, and `cleanStages()` helpers, so no new imports are needed):

```php
    public function test_a_document_the_classifier_calls_fake_outranks_a_genuine_one_on_risk(): void
    {
        $genuine = $this->document();
        $this->fakeMl($this->cleanStages());
        $clean = app(ProcessDocumentAction::class)->execute($genuine->fresh());

        $suspect = $this->document();
        $this->fakeMl($this->cleanStages(['classification' => [
            'label' => 'fake', 'confidence' => 0.98, 'authenticity' => 0.02,
            'passed_threshold' => true,
        ]]));
        $result = app(ProcessDocumentAction::class)->execute($suspect->fresh());

        // Both runs are equally CONFIDENT (0.95 vs 0.98) — only authenticity
        // moved. Before this change the forgery scored LOWER risk than the
        // genuine document, because confidence was feeding the blend.
        $this->assertEqualsWithDelta(0.02, $result->classification_authenticity, 1e-6);
        // 0.20 weight × (0.98 - 0.05) ≈ 18.6 points of separation.
        $this->assertGreaterThan($clean->document_risk_score + 15, $result->document_risk_score);
    }
```

- [ ] **Step 11: Run them to verify they fail**

Run: `php artisan config:clear && php artisan test --compact --filter="MlPipelineServiceTest|ProcessDocumentActionTest"`
Expected: FAIL — `Undefined array key "classification_authenticity"`.

- [ ] **Step 12: Create the migration**

Run: `php artisan make:migration add_classification_authenticity_to_validation_results_table --no-interaction`

Then put this in the generated file (rename it to `2026_08_12_000001_...` if the generated timestamp differs — the content is what matters):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 3 reports two different numbers and the pipeline needs both: the
 * winning class's `classification_confidence` (what the officer reads) and
 * `classification_authenticity` = 1 - P(fake) (what the risk blend consumes).
 * They diverge exactly when it matters — a document confidently classified
 * `fake` has high confidence and near-zero authenticity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validation_results', function (Blueprint $table) {
            $table->float('classification_authenticity')->nullable()->after('classification_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('validation_results', function (Blueprint $table) {
            $table->dropColumn('classification_authenticity');
        });
    }
};
```

- [ ] **Step 13: Add the column to the model**

In `app/Models/ValidationResult.php`, add `'classification_authenticity',` to `$fillable` immediately after `'classification_confidence',`, and add this to the `casts()` array next to the other float casts:

```php
            'classification_authenticity' => 'float',
```

- [ ] **Step 14: Map it and feed it to the risk blend**

In `app/Services/Document/MlPipelineService.php`, replace the Stage 3 block (lines 162–166) with:

```php
        $classification = $stages['classification'] ?? null;
        if ($this->ran($classification)) {
            $columns['classification_label'] = $classification['label'] ?? null;
            $columns['classification_confidence'] = $this->float($classification['confidence'] ?? null);
            // 1 - P(fake). Distinct from confidence: a document confidently
            // classified `fake` is 0.98 confident and 0.02 authentic.
            $columns['classification_authenticity'] = $this->float($classification['authenticity'] ?? null);
        }
```

In `app/Actions/ProcessDocumentAction.php`, replace the `'classification'` line in the risk components (line 77) with:

```php
            // Authenticity, not confidence — see MlPipelineService::mapStages().
            // Falls back for rows written before the API reported authenticity.
            'classification' => $result->classification_authenticity ?? $result->classification_confidence,
```

- [ ] **Step 15: Run the Laravel tests to verify they pass**

Run: `php artisan config:clear && php artisan test --compact --filter="MlPipelineServiceTest|ProcessDocumentActionTest|RiskScoreServiceTest"`
Expected: all PASS.

- [ ] **Step 16: Run the full suites**

Run: `php artisan config:clear && php artisan test --compact`
Run: `python\env\Scripts\python.exe -m pytest python/tests/ -q --tb=short`
Expected: both green. Fix anything that broke before committing.

- [ ] **Step 17: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/ValidationResult.php app/Services/Document/MlPipelineService.php app/Actions/ProcessDocumentAction.php tests/Feature/Document/MlPipelineServiceTest.php tests/Feature/Document/ProcessDocumentActionTest.php
git commit -m "Score a fake-classified document as high classification risk"
```

---

## Task 5: Live end-to-end verification against the running API

**Files:**
- Modify: `python/DEVELOPMENT_PHASES.md` (record the closed gaps)

**Interfaces:**
- Consumes: everything Tasks 1–4 produced, running against the real weights in `python/models/`.

This task has no new code — it proves the four preceding tasks work against real models rather than stubs, and records the result. Do not skip it: every test so far used stub models.

- [ ] **Step 1: Restart the ML API so it loads the new code and the classifier**

```bash
cd python && env/Scripts/python.exe -m uvicorn api.main:app --port 7860
```

Wait ~100s for startup (TrOCR is the slow load).

- [ ] **Step 2: Confirm the stamp classifier is now loaded**

```bash
curl -s http://127.0.0.1:7860/health
```

Expected: `models.stamp_classifier.loaded` is `true` with `path` ending `stamp_classifier.pkl`. If it is `false` with `weights_not_found`, the file is missing from `python/models/` — regenerate it with `env/Scripts/python.exe scripts/train_stamp.py` before continuing.

- [ ] **Step 3: Verify the tamper check runs on a real crop with no reference**

```bash
curl -s -X POST http://127.0.0.1:7860/v1/stamp/verify \
  -H "Authorization: Bearer devtoken" \
  -F "file=@python/data/seal/BIR_SEAL.png" \
  -F "document_type=bir_certificate"
```

Expected: `reason` is `unreferenced_logo`, and `stamp_tampered` / `genuine_probability` are non-null. Record the genuine probability — a real BIR seal should sit well above 0.50.

- [ ] **Step 4: Verify an LGU document routes by its OCR city**

Submit a real business permit through the vendor portal (or re-run one via `php artisan queue:work --queue=document-processing,mail,default` after re-queueing), then inspect the row:

```bash
php artisan tinker --execute 'dump(App\Models\ValidationResult::latest()->first()->only(["detected_city","logo_reference_id","stamp_tampered","classification_authenticity","flags"]));'
```

Expected: `detected_city` is the permit's city; `flags` contains `unreferenced_logo` **or** `city_not_identified` (not both), and no longer contains `unreferenced_logo` on a second submission for a city whose reference an officer has approved.

- [ ] **Step 5: Record the outcome in the phase doc**

In `python/DEVELOPMENT_PHASES.md`, update the "Where we are now" section: Phase 7 (EfficientNet) gains "Stage 4b texture check wired into the live pipeline (`stamp_classifier.pkl` loaded by the registry)"; note that LGU city-scoped logo lookup and the Stage 3 `fake`/mismatch flags are now live. Include the genuine-probability figure from Step 3.

- [ ] **Step 6: Commit**

```bash
git add python/DEVELOPMENT_PHASES.md
git commit -m "Record the Stage 3 and Stage 4b gaps as closed"
```

---

## Out of scope (deliberately)

- **Multi-page classification and detection.** `validate.py` still classifies and detects on page 1 only, and only page 1's OCR quality flags propagate (`max_pdf_pages = 2`). Worth its own plan.
- **ML tamper fusion (M7).** Stage T keeps its deterministic five-technique blend.
- **New document-type generators / classifier classes** (sanitary permit, FDA, food handler, government IDs). Until those classes exist, `document_type_mismatch` deliberately stays silent for them — that is why Task 4 Step 5's third test exists.
