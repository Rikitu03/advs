"""GET /health — open (no auth): the keep-warm ping target and model report."""

from __future__ import annotations

from fastapi import APIRouter, Request, Response, status

from .. import __version__
from ..schemas import HealthResponse, ReadinessResponse

router = APIRouter()


@router.get("/health", response_model=HealthResponse)
async def health(request: Request) -> dict:
    return {
        "status": "ok",
        "service": "advs-ml-api",
        "version": __version__,
        "models": request.app.state.registry.status(),
    }


@router.get("/ready", response_model=ReadinessResponse)
async def ready(request: Request, response: Response) -> dict:
    registry = request.app.state.registry
    errors = registry.readiness_errors()
    if errors:
        response.status_code = status.HTTP_503_SERVICE_UNAVAILABLE

    return {
        "status": "ready" if not errors else "not_ready",
        "service": "advs-ml-api",
        "version": __version__,
        "errors": errors,
        "artifacts": registry.artifact_report(),
    }
