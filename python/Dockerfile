# ADVS ML API — container image for independent deployment.
# Build context is the python/ directory (see .dockerignore):
#   docker build -t advs-ml-api .
# On a Hugging Face Docker Space this file sits at the Space repo root and
# builds automatically on push (README.md carries the Space metadata header).
# Also deployable to an Oracle Cloud Always-Free Ampere A1 (aarch64) VM — see
# README.md's "Deploy to Oracle Cloud" section. Build NATIVELY on the target
# architecture (the ARM VM itself, or the HF builder) rather than cross-
# compiling: this image bakes multi-GB model weights at build time (below),
# and QEMU-emulated cross-arch builds make that painfully slow.

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

# Bake the TrOCR recognizer into the image at build time (the ROI+TrOCR
# field-recognition fallback, python/scripts/roi_field_ocr.py) so the running
# container never stalls a request on a multi-GB first-use download. RapidOCR's
# PP-OCRv4 detector needs no such step — its ~8MB ONNX weights already ship
# inside the rapidocr-onnxruntime pip package installed above.
#
# Swap recognizers at build time without touching code:
#   docker build --build-arg TROCR_MODEL_ID=microsoft/trocr-base-printed .
# base is ~3x faster per crop than large on CPU; see README.md for the measured
# latency/accuracy trade-off on the real BIR / permit / DTI samples.
#
# TWO recognizers, routed per OCR template (api/routers/ocr.resolve_recognizer):
# base handles DTI / Business Permit — benchmarked identical to large there and
# ~3x faster — while BIR gets large, where base misreads values including the
# issue year. Baked to fixed directories so the ENV paths below stay correct
# and the later `COPY models/` cannot shadow them with a host snapshot.
#
# To ship a single-recognizer image (smaller, BIR loses accuracy), point both
# build args at the same id — the router falls back to whichever is loaded.
ARG TROCR_MODEL_ID=microsoft/trocr-base-printed
ARG TROCR_ACCURATE_MODEL_ID=microsoft/trocr-large-printed
RUN python -c "import os; from transformers import TrOCRProcessor, VisionEncoderDecoderModel;\
 [ (TrOCRProcessor.from_pretrained(src).save_pretrained(dest), VisionEncoderDecoderModel.from_pretrained(src).save_pretrained(dest)) for src, dest in ((os.environ['TROCR_MODEL_ID'], '/app/trocr'), (os.environ['TROCR_ACCURATE_MODEL_ID'], '/app/trocr-accurate')) ]"

# Only what inference needs: the service, the forensics package, the reused
# pipeline scripts, and whatever trained weights exist under models/.
COPY --chown=user api/ api/
COPY --chown=user forensics/ forensics/
COPY --chown=user scripts/ scripts/
COPY --chown=user models/ models/
RUN chown -R user:user /app/models

USER user
ENV HOME=/home/user \
    MODEL_DIR=/app/models \
    TROCR_MODEL_PATH=/app/trocr \
    TROCR_ACCURATE_MODEL_PATH=/app/trocr-accurate \
    THRESHOLD_STORE_PATH=/home/user/thresholds.json

EXPOSE 7860
CMD ["uvicorn", "api.main:app", "--host", "0.0.0.0", "--port", "7860"]
