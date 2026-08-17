# ADVS ML Pipeline → Hugging Face Spaces Deployment Guide

Architecture: **Laravel 11 (ADVS)** → HTTPS/JSON → **FastAPI on HF Space** → ResNet-50 → PyTesseract → YOLOv8 → Siamese CNN → EfficientNet

---

## 1. Repo structure

```
advs-ml-api/
├── README.md              # HF Space metadata header (required)
├── Dockerfile
├── requirements.txt
├── main.py
├── auth.py
├── models/
│   ├── classifier.py       # ResNet-50 (document type)
│   ├── ocr.py               # PyTesseract
│   ├── detector.py           # YOLOv8 (signature/stamp regions)
│   ├── signature_verify.py   # Siamese CNN
│   └── stamp_verify.py       # EfficientNet
└── weights/                  # .pt / .pth files
```

## 2. HF Space README header

Every Space needs this YAML block at the top of README.md:

```yaml
---
title: ADVS ML API
emoji: 📄
colorFrom: blue
colorTo: gray
sdk: docker
app_port: 7860
pinned: false
---
```

## 3. Dockerfile

```dockerfile
FROM python:3.10-slim

RUN apt-get update && apt-get install -y \
    tesseract-ocr libtesseract-dev libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .
EXPOSE 7860
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "7860"]
```

## 4. requirements.txt

```
fastapi
uvicorn[standard]
torch --index-url https://download.pytorch.org/whl/cpu
torchvision --index-url https://download.pytorch.org/whl/cpu
ultralytics
pytesseract
pillow
python-multipart
numpy
python-dotenv
```

## 5. main.py — key design decisions

**Load all 5 models once at startup, not per request.** Cold-loading 5 models on every call will eat your free CPU quota fast.

```python
from fastapi import FastAPI, UploadFile, Depends, HTTPException
from contextlib import asynccontextmanager
from auth import verify_token
import torch

models = {}

@asynccontextmanager
async def lifespan(app: FastAPI):
    torch.set_num_threads(2)  # free tier is CPU-limited, don't oversubscribe
    models["classifier"] = load_resnet50()
    models["detector"] = load_yolov8()
    models["sig_verify"] = load_siamese()
    models["stamp_verify"] = load_efficientnet()
    yield
    models.clear()

app = FastAPI(lifespan=lifespan)

@app.post("/validate", dependencies=[Depends(verify_token)])
async def validate_document(file: UploadFile):
    # Run the full pipeline in one round trip instead of 5 separate
    # HTTP calls from Laravel — much cheaper over the network.
    image = await load_image(file)
    doc_type = classify(models["classifier"], image)
    text = extract_text(image)                    # pytesseract, no GPU/model load
    regions = detect_regions(models["detector"], image)
    sig_score = verify_signature(models["sig_verify"], regions)
    stamp_score = verify_stamp(models["stamp_verify"], regions)
    return {
        "document_type": doc_type,
        "extracted_text": text,
        "signature_match": sig_score,
        "stamp_match": stamp_score,
    }
```

`auth.py` — simple bearer token check:

```python
import os
from fastapi import Header, HTTPException

def verify_token(authorization: str = Header(...)):
    token = authorization.replace("Bearer ", "")
    if token != os.environ.get("API_TOKEN"):
        raise HTTPException(status_code=401, detail="Invalid token")
```

## 6. Memory/performance notes for your specific 5 models

- ResNet-50 (~100MB) + YOLOv8n/s (~10–40MB) + a Siamese CNN and EfficientNet-B0 (~20MB) all fit comfortably under 16GB. PyTesseract isn't a loaded model — it shells out to the tesseract binary, so it adds negligible RAM.
- Use `yolov8n` or `yolov8s`, not larger variants — no accuracy need to justify the extra load time on CPU.
- **Free Spaces sleep after inactivity.** First request after sleep costs 30–60s cold start. Plan for this in Laravel (see below) — don't assume instant response during your defense demo.

## 7. Secrets

In the Space: **Settings → Repository secrets → `API_TOKEN`**. Read it in Python via `os.environ["API_TOKEN"]`. Never hardcode it in the repo.

## 8. Push to HF

```bash
huggingface-cli login          # paste your HF token once
git clone https://huggingface.co/spaces/<your-username>/advs-ml-api
cd advs-ml-api
# copy in your files
git add .
git commit -m "Initial ADVS ML API"
git push
```

Space builds automatically on push; watch the build logs in the Space UI.

## 9. Laravel integration

`.env`:
```
ML_API_URL=https://<your-username>-advs-ml-api.hf.space
ML_API_TOKEN=your-token-here
```

`config/services.php`:
```php
'ml_pipeline' => [
    'url' => env('ML_API_URL'),
    'token' => env('ML_API_TOKEN'),
],
```

`app/Services/MLPipelineService.php`:
```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\UploadedFile;

class MLPipelineService
{
    public function validateDocument(UploadedFile $file): array
    {
        $response = Http::withToken(config('services.ml_pipeline.token'))
            ->timeout(90) // account for cold starts
            ->retry(2, 5000)
            ->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
            ->post(config('services.ml_pipeline.url') . '/validate');

        if ($response->failed()) {
            throw new \RuntimeException('ML pipeline error: ' . $response->body());
        }

        return $response->json();
    }
}
```

**Important:** because cold starts + 5-model inference can take 10–30s+, don't call this synchronously inside a web request. Dispatch it as a **queued Job**, write the result to the document's DB row when done, and have the frontend poll or use Livewire/broadcasting to reflect the status change. This also protects your app if the Space is asleep when a vendor uploads a document.

## 10. Testing checklist

- `curl` each endpoint directly against the Space URL with a sample document image
- Time a cold-start request vs. a warm one
- Test with actual BIR/vendor documents from your 7-document-type checklist, not just clean samples
- Free tier has limited concurrency — test what happens with 2–3 simultaneous uploads

## 11. Defense-day consideration

Free CPU Basic is fine for development and most demos. If your panel defense needs zero cold-start delay, either:
- Set up a scheduled ping (cron job hitting a `/health` endpoint every ~10 min) to keep it warm, or
- Temporarily upgrade to a paid persistent Space for defense week only, then downgrade after.

Given your cost-consciousness, the ping-keep-alive approach is free and usually sufficient for a live demo.
