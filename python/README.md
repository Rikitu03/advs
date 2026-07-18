---
title: ADVS ML API
emoji: 📄
colorFrom: blue
colorTo: gray
sdk: docker
app_port: 7860
pinned: false
---

# ADVS ML API

Stateless FastAPI service wrapping the ADVS document-validation pipeline for
**independent deployment** (Hugging Face Docker Space or any container host).

Architecture: **Laravel (ADVS)** → HTTPS/JSON → **this service** →
ResNet-50 · PyTesseract · YOLOv8 · Siamese CNN · EfficientNet · Stage T forensics.

Laravel stays the orchestrator and the single owner of state: per-vendor
signature reference embeddings and issuer logo vectors live in Laravel's DB
and are **passed in each request**; the composite risk score (Stage 5) is
computed by Laravel's `RiskScoreService`, never here.

## Endpoints

| Endpoint | Stage | Status | Notes |
|---|---|---|---|
| `GET /health` | — | live, **no auth** | model availability report; keep-warm ping target |
| `GET /v1/config` | — | **live** | current/effective thresholds, overrides, and boot defaults |
| `PATCH /v1/config` | — | **live** | runtime threshold changes from the admin ML Models page (partial body; `null` resets a key) |
| `POST /v1/classify` | 3 | **live** | ResNet-50 → `{label, confidence, probabilities, passed_threshold}` |
| `POST /v1/ocr` | 2 | **live** | multipart `file` (+ `template=bir\|none`) → per-page `{text, words, fields, quality}` |
| `POST /v1/tamper` | T | **live** | original upload (+ optional JSON `context`) → forensic verdict |
| `POST /v1/detect` | 4 | 503 until weights | YOLOv8 boxes → `{detections, flags}` |
| `POST /v1/signature/embed` | 4a | 503 until weights | crop → 128-D embedding (registration enrollment) |
| `POST /v1/signature/verify` | 4a | 503 until weights | crop + `reference_embedding` (JSON array form field) |
| `POST /v1/stamp/embed` | 4b | 503 until weights | crop → issuer feature vector (EnrollReferenceJob seeding) |
| `POST /v1/stamp/verify` | 4b | 503 until weights | crop + `reference_vector`; omitted reference → `unreferenced_logo` |
| `POST /v1/validate` | all | **live (fail-forward)** | full pipeline, one round trip; unavailable stages report `{skipped, reason}` |

All `/v1/*` routes require `Authorization: Bearer <API_TOKEN>`. Interactive
docs at `/docs` once running.

## Configuration (env)

See `.env.api.example` for the full annotated surface. Highlights:

| Variable | Default | Purpose |
|---|---|---|
| `API_TOKEN` | — (**required**) | bearer token; a HF *Repository secret* on a Space |
| `MODEL_DIR` | `./models` (`/app/models` in Docker) | weight-file root |
| `CLASSIFIER_MODEL_PATH` | `MODEL_DIR/resnet50_best.keras` | trained ✔ |
| `DETECTOR_MODEL_PATH` | `MODEL_DIR/yolov8_document.pt` | configure after training |
| `SIAMESE_MODEL_PATH` | `MODEL_DIR/siamese_encoder.h5` | the encoder train_signature.py saves (the API only embeds) |
| `STAMP_MODEL_PATH` | `MODEL_DIR/efficientnet_feature_extractor.h5` | what train_stamp.py saves |
| `CLASSIFICATION_CONFIDENCE_THRESHOLD` | `0.70` | §9 |
| `YOLO_DETECTION_CONFIDENCE` | `0.50` | §9 |
| `STAMP_SIMILARITY_THRESHOLD` | `0.85` | §9 (training-produced `stamp_threshold.txt` wins) |
| `SIGNATURE_DISTANCE_THRESHOLD` | unset | empirical/EER; falls back to `signature_threshold.txt` |
| `PDF_DPI` / `MAX_PDF_PAGES` | `300` / `2` | §2 PDF handling |

A model whose weight file is missing is simply reported as not loaded by
`/health`; its endpoints return `503 {"reason": "model_not_loaded", ...}` and
`/v1/validate` marks that stage skipped. **Drop in the weights, set the path,
restart — no code changes.**

### Runtime thresholds (admin dashboard)

The §9 tunables (`classification_confidence_threshold`,
`yolo_detection_confidence`, `stamp_similarity_threshold`,
`signature_distance_threshold`, `pdf_dpi`, `max_pdf_pages`) are also
changeable at runtime — the admin dashboard's ML Models page calls:

```bash
curl -H "Authorization: Bearer <token>" http://.../v1/config
curl -X PATCH -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"stamp_similarity_threshold": 0.90}' http://.../v1/config
```

Precedence per key: **admin PATCH > env var > training-produced threshold
file (`signature_threshold.txt` / `stamp_threshold.txt`) > §9 default**.
PATCHing `null` resets a key. Overrides persist to `THRESHOLD_STORE_PATH`
(survives restarts, **not** a container rebuild) — Laravel's system settings
remain canonical and should re-push after a rebuild.

## Run locally

Always the repo ML venv (never bare `python` — see CLAUDE.md):

```powershell
cd python
env/Scripts/python.exe -m pip install -r requirements-api.txt
copy .env.api.example .env.api   # set API_TOKEN
env/Scripts/python.exe -m uvicorn api.main:app --port 7860
```

Smoke checks:

```bash
curl http://localhost:7860/health
curl -H "Authorization: Bearer <token>" -F "file=@sample_bir.jpg" http://localhost:7860/v1/classify
curl -H "Authorization: Bearer <token>" -F "file=@sample_bir.jpg" http://localhost:7860/v1/validate
```

Tests: `env/Scripts/python.exe -m pytest tests/test_api.py -v` (from `python/`).

## Deploy to a Hugging Face Space

1. Create a **Docker** Space (this README's YAML header is the Space config).
2. Push the contents of this `python/` directory to the Space repo, excluding
   everything in `.dockerignore` (`env/`, `data/`, `notebooks/`, `tests/`, …).
   Easiest path with the HF CLI:
   ```bash
   hf auth login
   hf upload <user>/advs-ml-api . . --repo-type=space \
     --exclude "env/*" --exclude "data/*" --exclude "notebooks/*" \
     --exclude "tests/*" --exclude "tmp/*" --exclude "json_data/*" \
     --exclude "backup_models/*" --exclude "models/tuning/*" \
     --exclude "**/__pycache__/*" --exclude "*.log" --exclude ".env.api"
   ```
3. Upload trained weights into the Space's `models/` (they are gitignored in
   the main repo; the Space carries its own copies — use `hf upload` or the
   Space UI; large `.h5`/`.keras`/`.pt` files ride Git LFS automatically).
4. Space **Settings → Repository secrets** → add `API_TOKEN`. Set any
   model-path/threshold overrides as Space *variables*.
5. Watch the build logs; first boot loads every model whose weights exist.

### Laravel integration (follow-up branch)

```
ML_API_URL=https://<user>-advs-ml-api.hf.space
ML_API_TOKEN=<same token>
```

Call from a queued job with a generous timeout (free Spaces sleep; a cold
start costs 30–60 s) and `retry(2, 5000)`. For a defense-day demo, either a
cron ping of `/health` every ~10 min keeps the Space warm, or temporarily
upgrade the Space hardware for the week.
