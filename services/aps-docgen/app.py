"""FastAPI front end for the affidavit merge service.

Drupal POSTs a .docx template plus a Job's resolved token values; this returns
the merged document. All the interesting logic is in `context.py` (the token
namespace collision) and `render.py` (the Jinja environment); this module is
transport, auth and error mapping only.

Run:
    uvicorn app:app --host 127.0.0.1 --port 8099

⚠ Bind to 127.0.0.1. Drupal and this service share a box, so nothing off-host
should reach it; the shared key is defence in depth, not the only wall.
"""

from __future__ import annotations

import hmac
import json
import logging
import os
from typing import Any

from fastapi import FastAPI, Form, Header, HTTPException, Request, UploadFile
from fastapi.responses import JSONResponse, Response

from render import RenderError, render_docx

logging.basicConfig(
    level=os.getenv("APS_DOCGEN_LOG_LEVEL", "INFO"),
    format="%(asctime)s %(levelname)s %(name)s %(message)s",
)
logger = logging.getLogger("aps_docgen")

app = FastAPI(title="APS Document Generation", version="1.0")

DOCX_MEDIA_TYPE = "application/vnd.openxmlformats-officedocument.wordprocessingml.document"

# A merged affidavit is a text document; anything approaching this size is a
# mistake or an attack, and rejecting early keeps a bad request from occupying
# a worker while it buffers.
MAX_TEMPLATE_BYTES = int(os.getenv("APS_DOCGEN_MAX_BYTES", str(25 * 1024 * 1024)))


def _require_key(supplied: str | None) -> None:
    """Check the shared key.

    A missing configured key is treated as a fault, not as "no auth required" —
    a misdeployed service that silently accepts everything is worse than one
    that refuses to work and says why.
    """
    expected = os.getenv("APS_DOCGEN_KEY", "")
    if not expected:
        logger.error("APS_DOCGEN_KEY is not set; refusing all render requests.")
        raise HTTPException(
            status_code=503,
            detail="Service is not configured: APS_DOCGEN_KEY is unset.",
        )
    # Constant-time: a plain == leaks the key a byte at a time under timing
    # analysis.
    if not supplied or not hmac.compare_digest(supplied, expected):
        raise HTTPException(status_code=401, detail="Invalid or missing X-APS-Key.")


def _parse_payload(
    raw: str,
) -> tuple[list[dict[str, Any]], dict[str, list[dict[str, Any]]], dict[str, list[Any]]]:
    """Read the `data` field into (tokens, tables, lists).

    Two accepted shapes, because the simple one is what Drupal already has:

        [{"name": "Client.Mail", "value": "..."}, ...]

        {"tokens": [...],
         "tables": {"Job_Fees": [{"Description": "..."}]},
         "lists":  {"Documents.Affidavit_Template": ["...", "..."]}}

    The bare list is `resolveTokensForJob()` output passed straight through;
    the object form adds the structured rows that `{%tr for %}` needs and the
    flat pairs cannot express, plus the one-column lists that render as one
    paragraph per item.

    `tables` and `lists` are both optional: a Drupal that has not been updated
    keeps working unchanged.
    """
    try:
        parsed = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise HTTPException(status_code=400, detail=f"`data` is not valid JSON: {exc}") from exc

    if isinstance(parsed, list):
        return parsed, {}, {}

    if isinstance(parsed, dict):
        tokens = parsed.get("tokens", [])
        tables = parsed.get("tables", {}) or {}
        lists = parsed.get("lists", {}) or {}
        if not isinstance(tokens, list):
            raise HTTPException(status_code=400, detail="`tokens` must be a list.")
        if not isinstance(tables, dict):
            raise HTTPException(status_code=400, detail="`tables` must be an object.")
        if not isinstance(lists, dict):
            raise HTTPException(status_code=400, detail="`lists` must be an object.")
        # A scalar where a list belongs would otherwise be iterated as
        # characters and produce one paragraph per letter.
        for name, items in lists.items():
            if not isinstance(items, (list, tuple)):
                raise HTTPException(
                    status_code=400,
                    detail=f"`lists.{name}` must be an array of values.",
                )
        return tokens, tables, lists

    raise HTTPException(
        status_code=400,
        detail="`data` must be a list of {name, value} pairs or an object with `tokens`.",
    )


@app.get("/health")
async def health() -> dict[str, Any]:
    """Liveness plus configuration state, for the Drupal config form's button.

    Reports whether the key is configured so a misdeploy is visible from the
    Drupal side without reading server logs. Never returns the key itself.
    """
    return {
        "status": "ok",
        "service": "aps-docgen",
        "key_configured": bool(os.getenv("APS_DOCGEN_KEY", "")),
        "max_template_bytes": MAX_TEMPLATE_BYTES,
    }


@app.post("/render")
async def render(
    file: UploadFile,
    data: str = Form(...),
    x_aps_key: str | None = Header(default=None),
    x_request_id: str | None = Header(default=None),
) -> Response:
    """Merge `data` into `file` and return the resulting .docx."""
    _require_key(x_aps_key)

    request_id = x_request_id or "-"
    template_bytes = await file.read()

    if not template_bytes:
        raise HTTPException(status_code=400, detail="No template file was uploaded.")
    if len(template_bytes) > MAX_TEMPLATE_BYTES:
        raise HTTPException(
            status_code=413,
            detail=f"Template exceeds the {MAX_TEMPLATE_BYTES} byte limit.",
        )

    tokens, tables, lists = _parse_payload(data)

    try:
        merged = render_docx(template_bytes, tokens, tables, lists)
    except RenderError as exc:
        # 422, not 500: the request was well-formed but the template or its
        # placeholders are wrong, and the message is written to be shown to
        # the staff member who authored it.
        logger.warning("render failed [req=%s] %s", request_id, exc)
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    except ValueError as exc:
        # build_context() rejects token names that would be shadowed — a data
        # contract violation on the Drupal side, not an authoring mistake.
        logger.error("payload rejected [req=%s] %s", request_id, exc)
        raise HTTPException(status_code=400, detail=str(exc)) from exc

    logger.info(
        "rendered [req=%s] template=%s in=%d out=%d tokens=%d tables=%d lists=%d",
        request_id,
        file.filename,
        len(template_bytes),
        len(merged),
        len(tokens),
        len(tables),
        len(lists),
    )

    return Response(
        content=merged,
        media_type=DOCX_MEDIA_TYPE,
        headers={
            "Content-Disposition": 'attachment; filename="merged.docx"',
            "X-Request-Id": request_id,
        },
    )


@app.exception_handler(Exception)
async def unhandled(request: Request, exc: Exception) -> JSONResponse:
    """Never leak a traceback to the caller; log it instead."""
    logger.exception("unhandled error on %s", request.url.path)
    return JSONResponse(
        status_code=500,
        content={"detail": "The document service failed unexpectedly."},
    )
