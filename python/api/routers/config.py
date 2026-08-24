"""GET/PATCH /v1/config — runtime threshold management for the admin dashboard.

The ML Models page reads the current values with GET and applies operator
changes with PATCH (partial body; ``null`` resets a key to its env/default —
see ``api.thresholds`` for validation, precedence, and persistence).
"""

from __future__ import annotations

from fastapi import APIRouter, Request

from ..thresholds import ThresholdUpdate

router = APIRouter()


@router.get("/v1/config")
async def get_config(request: Request) -> dict:
    return request.app.state.thresholds.snapshot()


@router.patch("/v1/config")
async def patch_config(request: Request, update: ThresholdUpdate) -> dict:
    return request.app.state.thresholds.apply(update)
