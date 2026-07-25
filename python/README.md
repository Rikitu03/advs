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
| `TROCR_MODEL_PATH` | `MODEL_DIR/trocr-base-printed` | default/fast recognizer; local snapshot dir only (never a bare HF Hub id — no network fetch from inside a request-serving container); missing dir = fall back to the accurate one, or skip the ROI pass if neither is loaded |
| `TROCR_ACCURATE_MODEL_PATH` | `MODEL_DIR/trocr-large-printed` | recognizer for `TROCR_ACCURATE_TEMPLATES` |
| `TROCR_ACCURATE_TEMPLATES` | `bir` | comma-separated templates that need the accurate recognizer |
| `ROI_BUDGET_SECONDS` | `210` | per-page cap on the ROI+TrOCR pass (see below) — **not** a §9 parameter |
| `TROCR_MAX_NEW_TOKENS` | `64` | decode cap per field crop; 32 truncated a real address |
| `ROI_TESSERACT_CONFIDENCE_FLOOR` | `90` | skip TrOCR only when Tesseract's read is near-certain |
| `NUM_THREADS` | `min(4, cpu_count)` | 2 on the HF free tier, 4 on Oracle A1, without oversubscribing a dev box |

A model whose weight file is missing is simply reported as not loaded by
`/health`; its endpoints return `503 {"reason": "model_not_loaded", ...}` and
`/v1/validate` marks that stage skipped. **Drop in the weights, set the path,
restart — no code changes.**

### Stage 2 cost model — the ROI+TrOCR field-recognition pass

`scripts/roi_field_ocr.py` recognizes field VALUES from tight crops (RapidOCR
PP-OCRv4 detector → TrOCR), which is what makes Stage 2 accurate — and what
makes it expensive. It is CPU-bound and dominates `/v1/validate`, so it is
deliberately **bounded**, not best-effort:

- **Gated** — a field Tesseract already read at ≥ `ROI_TESSERACT_CONFIDENCE_FLOOR`
  is skipped. Keep that floor high (default 90): on a real BIR page Tesseract
  reported 68–78% on values it read *wrong* (`NORTHZ2N STAR FINANCE`,
  `GEMINI STREZT`, `CONSTRUCTION CF`), so a 60 floor gated out exactly the
  fields TrOCR exists to fix and dropped the page's text-validation score from
  1.0 to 0.778.
- **Budgeted** — `ROI_BUDGET_SECONDS` caps the whole pass; required fields go
  first, and anything unreached keeps its label+regex value (fail-forward, §5).
- **Cached decode** — `generation_kwargs()` passes `use_cache=True` explicitly,
  because `trocr-*-printed`'s own `generation_config.json` ships
  `"use_cache": false`. Measured 2.2× slower (103.9 s → 47.3 s over 3 crops)
  for byte-identical text. Do not drop that kwarg.

Measured on this repo's real samples (8-core dev box, `NUM_THREADS=4`,
budget unbounded, per-page `/v1/ocr` wall time):

| Document | ROI fields | trocr-base | trocr-large | values |
|---|---|---|---|---|
| BIR CoR | 11 crops | 94 s | 203 s | **differ** — base misread the issue year (`FEB 24 2025` on a 2023 certificate), `PHYILIS`/`PHYLLIS`, address, tax types |
| DTI | 7 crops | 15 s | 39 s | identical |
| Business Permit | 5 crops | 23 s | 37 s | identical |

Hence the per-template routing (`resolve_recognizer`): **base by default,
large for BIR only** (`TROCR_ACCURATE_TEMPLATES`). With that split the same
three documents run in **159 s / 17 s / 25 s**, BIR's values matching the
large-model reference exactly. For scale: before this pass was bounded, one
BIR page took **410 s** and blew Laravel's then-180 s `ML_API_TIMEOUT`, which
failed the whole call and blanked Stage 2 *and* Stage 3 in the officer
drill-down. `config/advs.php` now allows 300 s.

> The <60 s end-to-end target in CLAUDE.md §10 is **not** reachable for BIR
> with any TrOCR configuration measured here, **including ONNX** — see below.
> The remaining levers are a >=16GB build machine for a properly optimized
> ONNX export, or fewer ROI fields per page. Not a smaller budget.

#### ONNX export — attempted, not adopted (2026-07-25)

`scripts/export_trocr_onnx.py` exports a recognizer to ONNX (optionally INT8)
and `roi_field_ocr.load_trocr()` will load either layout transparently. It is
kept as a **build-machine tool**; it did not pay off on the 7.8 GB dev box.
Measured on the same 11 BIR field crops:

| runtime | per crop | output |
|---|---|---|
| torch `trocr-base` | 4.1 s | reference |
| ONNX fp32 | 16.8 s | faithful, **4× slower** |
| ONNX INT8 dynamic | 2.2 s | 1.9× faster, **7/11 crops corrupted** |

INT8 broke exactly what Stage 2 exists to get right — OCN `3U2907511725` →
`3029007511725`, `FEB 24 2025` → `FE9 24 2025`, trade name → garbage. fp32 was
slow because the export lacks transformer attention fusions (`--optimize O2`),
and on 7.8 GB RAM both `O2` **and** optimum's default decoder-merge die in
`onnx.save` → `SerializeToString` (optimum re-saves each 1.2 GB graph without
external-data streaming, needing ~2× the graph in RAM). `--no-post-process` is
what lets the export finish here, and it is also what leaves it unoptimized.
`trocr-large` — the recognizer BIR actually uses — never exported at all.

Retry on a ≥16 GB machine without `--no-post-process`, then re-run the field
comparison before adopting. `optimum` is an **optional** dependency: it is
imported only when a configured model path holds an ONNX export.

Each OCR page in the response carries a `roi` block —
`{attempted, recognized, skipped_over_budget, elapsed_s}` — so a slow or
truncated page is visible rather than silent. `null` means neither recognizer
is loaded, which is different from "ran and recognized nothing".

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

## Deploy to Oracle Cloud (Always Free Ampere A1)

Same image, same `/v1/*` contract, same `API_TOKEN` bearer auth — the only
difference from the Space deploy above is a self-managed VM instead of a
managed platform. Chosen specifically for the ROI+TrOCR field-recognition
pass (`roi_field_ocr.py`): the Always-Free **Ampere A1** shape gives a
persistent 4 OCPU / 24GB ARM (aarch64) VM with **no cold start / no sleep**,
unlike HF Spaces' free CPU tier.

1. **Provision the VM**: Oracle Cloud Console → Compute → Create Instance →
   shape `VM.Standard.A1.Flex` (Always Free eligible up to 4 OCPU/24GB),
   image Ubuntu 22.04 (aarch64/ARM). Note the public IP.
2. **Open the port at the cloud networking layer, not just the OS firewall**
   — Oracle blocks traffic at the instance's **Security List / Network
   Security Group** independently of `ufw`/`iptables` on the VM itself.
   Forgetting this is the most common first-deploy trap: add an ingress rule
   for TCP/7860 (or 443 if fronting with TLS) on the VCN's security list
   *and* `sudo ufw allow 7860/tcp` on the VM.
3. **Install Docker** on the VM (`curl -fsSL https://get.docker.com | sh`).
4. **Build natively on the VM** — this repo's `python/` directory, uploaded
   or `git clone`d onto the instance (real ARM hardware; avoids QEMU cross-
   arch emulation, which would make the TrOCR weight-baking step in the
   Dockerfile painfully slow):
   ```bash
   cd python
   docker build -t advs-ml-api .
   ```
5. **Run it** (a `systemd` unit is more durable across reboots than a bare
   `docker run`, but `--restart unless-stopped` covers container crashes and
   Docker-daemon restarts either way):
   ```bash
   docker run -d --name advs-ml-api -p 7860:7860 \
     --restart unless-stopped \
     -e API_TOKEN=<token> \
     advs-ml-api
   ```
   Trained `.h5`/`.keras`/`.pt` weights not already baked into the image can
   be bind-mounted (`-v /opt/advs-models:/app/models`) instead of rebuilding.
6. **TLS**: if the endpoint is reachable from the public internet, put nginx
   or Caddy in front for TLS termination (`API_TOKEN` bearer-auth stays the
   app-level guard regardless). Skip this for a VCN-internal/VPN-only setup.
7. **Verify**: `curl http://<vm-ip>:7860/health` — confirm `rapid_detector`
   and `trocr` (alongside the trained models) report `"loaded": true`.

### A risk worth knowing about upfront: Always Free reclamation

Oracle can reclaim an Always Free compute instance it considers idle —
documented threshold: average CPU, network, **and** memory utilization all
under ~20% for 7 consecutive days. A validation API that only sees traffic
when a vendor submits a document can plausibly look idle by that definition.
Mitigate with the same low-frequency keep-warm ping used for the HF Spaces
cold-start problem above, or accept the risk and watch for a reclamation
notice — make this a conscious choice, not a surprise.

### Laravel integration

Same as the HF Spaces case — only the URL changes:

```
ML_API_URL=http://<vm-ip>:7860        # or https://your-domain if TLS-fronted
ML_API_TOKEN=<same token>
```

No cold-start retry budget is needed here (the VM doesn't sleep), but keep a
reasonable request timeout regardless — ROI+TrOCR adds real per-field
inference time on top of Tesseract's existing OCR pass (see the module
docstring in `scripts/roi_field_ocr.py`); benchmark on the actual VM against
the <60s end-to-end target (CLAUDE.md §10) before relying on it.
