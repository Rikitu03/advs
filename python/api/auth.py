"""Bearer-token auth for all /v1/* endpoints (/health stays open).

The token comes from the ``API_TOKEN`` env var (a Repository secret on a
Hugging Face Space). An unconfigured token fails closed with 503 rather than
running the service open to the internet.
"""

from __future__ import annotations

import secrets

from fastapi import Header, HTTPException, Request


async def verify_token(request: Request, authorization: str = Header(default="")) -> None:
    expected = request.app.state.settings.api_token
    if not expected:
        raise HTTPException(status_code=503, detail="API_TOKEN is not configured on the server.")

    supplied = authorization.removeprefix("Bearer ").strip()
    if not supplied or not secrets.compare_digest(supplied, expected):
        raise HTTPException(status_code=401, detail="Invalid or missing bearer token.")
