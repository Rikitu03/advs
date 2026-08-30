"""ADVS ML API application factory.

Local run (always the repo ML venv, never bare ``python``):
    cd python
    env/Scripts/python.exe -m uvicorn api.main:app --port 7860

Deployment: see python/README.md (Hugging Face Docker Space runbook).
"""

from __future__ import annotations

import logging
import os
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI

from . import __version__
from .auth import verify_token
from .config import Settings
from .registry import ModelRegistry
from .routers import classify, detect, health, ocr, signature, stamp, tamper, validate
from .routers import config as config_router
from .thresholds import ThresholdManager

logger = logging.getLogger("advs.api")


def create_app(settings: Settings | None = None) -> FastAPI:
    settings = settings or Settings()
    registry = ModelRegistry(settings)

    @asynccontextmanager
    async def lifespan(app: FastAPI):
        _limit_threads(settings.num_threads)
        registry.load_all()
        logger.info("startup complete; models: %s",
                    {k: v["loaded"] for k, v in registry.status().items()})
        yield
        registry.clear()

    app = FastAPI(
        title="ADVS ML API",
        description="Stateless document-validation pipeline service for ADVS "
                    "(classify / OCR / detect / verify / forensics). "
                    "Risk scoring stays in the Laravel orchestrator.",
        version=__version__,
        lifespan=lifespan,
    )
    app.state.settings = settings
    app.state.registry = registry
    # Applies any persisted admin overrides (PATCH /v1/config) on top of env.
    app.state.thresholds = ThresholdManager(settings, settings.store_path)

    app.include_router(health.router)
    protected = [Depends(verify_token)]
    for module in (config_router, classify, ocr, tamper, detect, signature, stamp, validate):
        app.include_router(module.router, dependencies=protected)

    return app


def _limit_threads(count: int) -> None:
    """Best-effort CPU-thread cap; ``count`` defaults to ``min(4, cpu_count)``
    (see Settings.num_threads) so the free tier stays at 2 while the Oracle A1
    deploy target gets its 4 — TrOCR field recognition is thread-bound
    (measured 15.8s/crop at 2 threads vs 10.3s at 8).

    Env vars must be set before TF/torch initialise their thread pools, which
    happens on first model load inside the registry, i.e. after this runs.
    """
    for var in ("OMP_NUM_THREADS", "TF_NUM_INTRAOP_THREADS", "TF_NUM_INTEROP_THREADS"):
        os.environ.setdefault(var, str(count))
    try:
        import torch

        torch.set_num_threads(count)
    except ImportError:
        pass


app = create_app()
