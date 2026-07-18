# ADVS ML API — container image for independent deployment.
# Build context is the python/ directory (see .dockerignore):
#   docker build -t advs-ml-api .
# On a Hugging Face Docker Space this file sits at the Space repo root and
# builds automatically on push (README.md carries the Space metadata header).

FROM python:3.12-slim

# tesseract-ocr  -> Stage 2 OCR engine (pytesseract shells out to it)
# poppler-utils  -> pdf2image PDF page rendering (§2: first 2 pages @ 300 DPI)
# libgl1/libglib -> OpenCV runtime deps
RUN apt-get update && apt-get install -y --no-install-recommends \
        tesseract-ocr poppler-utils libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

# HF Docker Spaces run as UID 1000; create the user up front so everything
# under /app is writable by the runtime user.
RUN useradd -m -u 1000 user

WORKDIR /app

# Torch CPU wheels first so ultralytics does not pull the CUDA build.
COPY requirements-api.txt .
RUN pip install --no-cache-dir torch torchvision --index-url https://download.pytorch.org/whl/cpu \
    && pip install --no-cache-dir -r requirements-api.txt

# Only what inference needs: the service, the forensics package, the reused
# pipeline scripts, and whatever trained weights exist under models/.
COPY --chown=user api/ api/
COPY --chown=user forensics/ forensics/
COPY --chown=user scripts/ scripts/
COPY --chown=user models/ models/

USER user
ENV HOME=/home/user \
    MODEL_DIR=/app/models \
    NUM_THREADS=2 \
    THRESHOLD_STORE_PATH=/home/user/thresholds.json

EXPOSE 7860
CMD ["uvicorn", "api.main:app", "--host", "0.0.0.0", "--port", "7860"]
