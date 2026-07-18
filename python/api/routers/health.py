"""GET /health — open (no auth): the keep-warm ping target and model report."""

from __future__ import annotations

from fastapi import APIRouter, Request

from .. import __version__
from ..schemas import HealthResponse

router = APIRouter()


@router.get("/health", response_model=HealthResponse)
async def health(request: Request) -> dict:
    return {
        "status": "ok",
        "service": "advs-ml-api",
        "version": __version__,
        "models": request.app.state.registry.status(),
    }
