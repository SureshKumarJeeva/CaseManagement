"""Merge a Job's token values into a .docx template.

Thin by design: `context.py` holds the hard part (see the collision note
there), and docxtpl holds the OOXML work. What lives here is the Jinja
environment — specifically the two settings that decide whether an authoring
mistake produces a small gap or a failed affidavit.
"""

from __future__ import annotations

import io
import logging
import zipfile
from collections.abc import Mapping, Sequence
from typing import Any

from docxtpl import DocxTemplate
from jinja2 import ChainableUndefined, Environment

from context import build_context

logger = logging.getLogger("aps_docgen")


class RenderError(Exception):
    """A template could not be merged. Message is safe to show a user."""


# The one part every .docx must contain. This is the OOXML format itself
# (ECMA-376), not a docxtpl detail, so checking it cannot drift when the
# library is upgraded.
_DOCUMENT_PART = "word/document.xml"


def assert_is_docx(template_bytes: bytes) -> None:
    """Reject anything that is not a readable .docx, before docxtpl sees it.

    WHY THIS EXISTS: ``DocxTemplate(...)`` does not parse the file — the load
    is deferred, so a corrupt or mis-named upload survives construction and
    fails later inside ``render()``. There it is indistinguishable from a
    template with a bad placeholder, and the author gets told to fix
    ``{% for %}`` tags in a document that is perfectly fine.

    Checking the container ourselves keeps the two failures separate and does
    not depend on any docxtpl internal.
    """
    try:
        with zipfile.ZipFile(io.BytesIO(template_bytes)) as archive:
            names = set(archive.namelist())
    except zipfile.BadZipFile as exc:
        raise RenderError(
            "The template is not a Word .docx file — it is not a valid "
            "document container. A .doc, .rtf or PDF renamed to .docx will "
            "fail this way."
        ) from exc

    if _DOCUMENT_PART not in names:
        raise RenderError(
            "The template is a zip archive but not a Word document: it has no "
            f"{_DOCUMENT_PART} part."
        )


# What line() puts between two values. One constant, and deliberately on this
# side rather than baked into the template text the picker generates — see
# join_line().
LINE_SEPARATOR = " - "


def join_line(*parts: Any, sep: str = LINE_SEPARATOR) -> str:
    """Join several field values onto one line, dropping the empty ones.

    WHY THIS IS NOT JUST "{{ a }} - {{ b }}" IN THE TEMPLATE: the separator is
    only wanted when there is something on both sides of it, and that is a
    property of the ROW, not of the template. An attendance note with no
    employee recorded renders " - No response at the door", and one with no
    note renders "Jason Ryan - ". A template cannot express "unless it is
    empty" without an {% if %} per gap, which is exactly the syntax the token
    picker exists to spare the author.

    Missing tokens arrive as ChainableUndefined and stringify to "", so a
    mistyped name costs its own slot and nothing else.
    """
    rendered = [text for text in (str(part).strip() for part in parts) if text]
    return sep.join(rendered)


def build_environment() -> Environment:
    """The Jinja environment docxtpl renders with.

    ``ChainableUndefined`` is the important choice. With Jinja's default
    ``Undefined``, ``{{ Client.Mail }}`` raises as soon as ``Client`` is
    missing, which would fail the entire affidavit over one mistyped token.
    ``ChainableUndefined`` lets the lookup chain keep going and render empty,
    so a bad token leaves a gap the author can see and fix while every other
    token still merges.

    ⚠ This is a deliberate trade: silent gaps over hard failures. It is the
    right way round here because these documents are reviewed and edited by
    staff before they go anywhere near a court.

    ``line()`` is registered as a global so a template can call it directly.
    The Library form's token picker generates the call, so an author never
    types it — but it is part of the template contract now, which is why it
    lives here beside the environment rather than being passed per render.
    """
    environment = Environment(undefined=ChainableUndefined, autoescape=False)
    environment.globals["line"] = join_line
    return environment


def render_docx(
    template_bytes: bytes,
    tokens: Sequence[Mapping[str, Any]],
    tables: Mapping[str, Sequence[Mapping[str, Any]]] | None = None,
    lists: Mapping[str, Sequence[Any]] | None = None,
) -> bytes:
    """Render `template_bytes` with the Job's values and return the merged docx.

    ``lists`` are one-column lists keyed by dotted token name; each renders as
    one paragraph per item. See :func:`context.build_context`.

    :raises RenderError: the file is not a readable .docx, or the template
        contains Jinja that cannot be compiled (an unclosed ``{% for %}``,
        typically). Both are author-fixable, so the message says which.
    """
    # Validate the container first — see assert_is_docx() for why this cannot
    # be left to docxtpl.
    assert_is_docx(template_bytes)

    try:
        template = DocxTemplate(io.BytesIO(template_bytes))
    except Exception as exc:  # noqa: BLE001 - surfaced as a clean message
        raise RenderError(
            "The template could not be opened as a Word .docx file. "
            f"({exc.__class__.__name__})"
        ) from exc

    context = build_context(tokens, tables, lists)

    try:
        template.render(context, build_environment())
    except RenderError:
        raise
    except Exception as exc:  # noqa: BLE001 - surfaced as a clean message
        raise RenderError(
            "The template could not be merged. This usually means a "
            "placeholder is malformed — an unclosed {% for %} or a stray "
            f"{{{{ brace. ({exc.__class__.__name__}: {exc})"
        ) from exc

    out = io.BytesIO()
    try:
        template.save(out)
    except Exception as exc:  # noqa: BLE001
        raise RenderError(f"The merged document could not be written. ({exc})") from exc

    return out.getvalue()


def find_placeholders(template_bytes: bytes) -> set[str]:
    """List the Jinja variables a template actually references.

    Useful for a future "which tokens does this template use?" panel on the
    Library form, and for checking a template before it is put into service.

    ⚠ Returns ROOT names only. A template containing
    ``{{ Attendance_Notes.Employee_First_Name }}`` reports ``Attendance_Notes``,
    because that is what Jinja resolves from the context — the rest is
    attribute access. Do not read the result as the full list of tokens used.

    ⚠ ``init_docx()`` is required. ``DocxTemplate.__init__`` only stores the
    file handle and sets ``self.docx = None`` — nothing is parsed until this
    call, which is also why the container has to be validated separately.
    """
    assert_is_docx(template_bytes)

    template = DocxTemplate(io.BytesIO(template_bytes))
    template.init_docx()
    return set(template.get_undeclared_template_variables(build_environment()))
