"""Build the Jinja2 render context from Drupal's flat token list.

The whole difficulty of this service is in one fact about the incoming data:

    every parent name is BOTH a scalar and a namespace

`DocuSignTokenMapper::resolveTokensForJob()` emits a flat list of dotted names,
and for all 14 parents it emits the parent *and* its children:

    {"name": "Client",      "value": "Australian Financial Security Authority"}
    {"name": "Client.Mail", "value": "or.notices@afsa.gov.au"}

A template author will legitimately write both ``{{ Client }}`` and
``{{ Client.Mail }}``. A flat dict cannot serve the second (Jinja resolves the
dot by attribute lookup, not by key), and a nested dict cannot serve the first
(``{{ Client }}`` would render a dict repr). So one object has to be both a
string and a namespace: that object is `Value`.
"""

from __future__ import annotations

import html
from collections.abc import Iterator, Mapping, Sequence
from typing import Any
from xml.sax.saxutils import escape

# The character docxtpl's `resolve_listing()` turns into a real new <w:p>,
# carrying the source paragraph's <w:pPr> with it — so a token sitting in a
# numbered or bulleted paragraph produces one correctly numbered item per
# entry. "\n" would only give a soft line break inside the one paragraph,
# which does not number.
#
# ⚠ resolve_listing() runs over the whole rendered XML unconditionally, not
# only over docxtpl's own Listing objects, which is why a plain str carrying
# this character is enough and no special context type is needed.
PARAGRAPH_BREAK = "\a"

# Parent names that also carry children, discovered from the payload rather
# than hard-coded — the Job content type changes and this must not need
# editing when it does.


class Value(str):
    """A token value that is simultaneously a scalar, a namespace and a table.

    - ``{{ Client }}``            -> the scalar, via ``str`` itself
    - ``{{ Client.Mail }}``       -> a child, via ``__getattr__``
    - ``{% for f in Job_Fees %}`` -> table rows, via ``__iter__``

    WHY A ``str`` SUBCLASS AND NOT A CUSTOM CLASS: docxtpl inserts the rendered
    result straight into the OOXML, and every Jinja filter an author might
    reach for (``|upper``, ``|trim``, ``|replace``) is a string operation. A
    wrapper object would force ``{{ Client }}`` to become ``{{ Client.value }}``
    everywhere and break every filter. Subclassing ``str`` keeps all of that
    working for free.

    ⚠ SAFETY OF ``__getattr__``: it is only consulted when normal attribute
    lookup fails, so a child whose name collides with a real ``str`` method
    would be shadowed by that method — ``{{ Parties.Title }}`` would render
    ``<built-in method title>``. That cannot happen with this data because
    ``formatVariableName()`` on the Drupal side Ucfirsts every segment while
    every ``str`` method is lowercase. It is load-bearing, so
    :func:`build_context` asserts it rather than trusting it.
    """

    __slots__ = ("_children", "_rows")

    def __new__(
        cls,
        text: str = "",
        children: Mapping[str, "Value"] | None = None,
        rows: Sequence[Mapping[str, "Value"]] | None = None,
    ) -> "Value":
        obj = super().__new__(cls, text if text is not None else "")
        obj._children = dict(children) if children else {}
        obj._rows = list(rows) if rows else []
        return obj

    def __getattr__(self, name: str) -> "Value":
        # Reached only when normal lookup fails. Unknown children resolve to an
        # empty Value rather than raising, so a typo in a template leaves a gap
        # in the affidavit instead of failing the whole generation.
        if name.startswith("_"):
            raise AttributeError(name)
        return self._children.get(name, Value(""))

    def __iter__(self) -> Iterator[Mapping[str, "Value"]]:
        # ⚠ Deliberately NOT str's per-character iteration. A template that
        # loops over a scalar is an authoring mistake; yielding nothing drops
        # the row quietly, whereas yielding characters would explode a table
        # into one row per letter.
        return iter(self._rows)

    # ⚠ __len__ is deliberately NOT defined. Defining it would make truthiness
    # follow the row count, so `{% if Client %}` would be False for a populated
    # client that simply has no table rows. Truthiness must stay the string's.

    @property
    def rows(self) -> list[Mapping[str, "Value"]]:
        """Explicit row access, and the way to get a meaningful count.

        ``{{ Job_Fees|length }}`` measures the *string*, which is nearly always
        the wrong number; ``{{ Job_Fees.rows|length }}`` is the row count. Safe
        as a property because incoming child names are always capitalised.
        """
        return self._rows

    @property
    def has_rows(self) -> bool:
        return bool(self._rows)


def _coerce(value: Any) -> str:
    """Flatten an incoming JSON value to the text that belongs in the document."""
    if value is None:
        return ""
    if isinstance(value, bool):
        # Drupal booleans arrive as real JSON booleans from structured payloads;
        # "True"/"False" is never what belongs in an affidavit.
        return "Yes" if value else "No"
    if isinstance(value, (int, float)):
        return str(value)
    if isinstance(value, (list, tuple)):
        # A list reaching a scalar slot means multi-value data the author
        # referenced without a loop. Comma-join it, matching what the Drupal
        # resolver already does for its flat pairs, so both paths look alike.
        return ", ".join(_coerce(item) for item in value if item is not None)
    return _for_xml(str(value))


def _for_xml(text: str) -> str:
    """Make one value safe to drop straight into the document's XML.

    A .docx is XML, docxtpl splices rendered values into it verbatim, and the
    environment renders with ``autoescape=False`` (it has to — the template
    body *is* markup, so escaping it would destroy the document). So an
    unescaped ``&`` or ``<`` in a value is not a rendering nicety, it is data
    loss: a client called "Smith & Sons <Pty> Ltd" merged as "Smith  Sons ",
    silently, with no error anywhere.

    Entities are decoded first because these values arrive from Drupal fields
    that have been through ``strip_tags()`` on HTML, so "&amp;" is as likely as
    a raw "&"; without the decode it would come out as the literal "&amp;".
    Decoding then re-escaping normalises both to the same correct output.

    ⚠ Control characters are untouched by both steps, so PARAGRAPH_BREAK
    survives this and is still there for docxtpl's resolve_listing().
    """
    return escape(html.unescape(text))


def _assert_no_str_shadowing(names: list[str]) -> None:
    """Guard the one property that makes `Value.__getattr__` safe.

    If Drupal ever stops Ucfirst-ing a segment, a child called ``title`` or
    ``count`` would be silently shadowed by the ``str`` method of that name and
    the token would render as a method repr. Fail loudly at build time instead.

    Only CHILD and ROW-KEY names are checked. Top-level names are plain context
    dict keys, resolved by Jinja without touching attribute lookup, so a
    top-level ``Title`` is never at risk.
    """
    shadowed = sorted({n for n in names if hasattr(str, n) or n in ("rows", "has_rows")})
    if shadowed:
        raise ValueError(
            "Token name segment(s) collide with str/Value attributes and would "
            f"be shadowed: {', '.join(shadowed)}. Token names must be "
            "capitalised (Drupal's formatVariableName() does this)."
        )


def build_context(
    tokens: Sequence[Mapping[str, Any]],
    tables: Mapping[str, Sequence[Mapping[str, Any]]] | None = None,
    lists: Mapping[str, Sequence[Any]] | None = None,
) -> dict[str, Value]:
    """Turn Drupal's payload into a render context.

    :param tokens: flat pairs, ``[{"name": "Client.Mail", "value": "..."}, ...]``
        exactly as ``resolveTokensForJob()`` returns them. Dotted names nest.
    :param tables: optional structured rows keyed by the parent token name,
        ``{"Job_Fees": [{"Description": "Delivery Fee", "Amount": "35.00"}]}``.
        This is what makes ``{%tr for %}`` dynamic rows possible; the flat
        pairs alone cannot express them, because the Drupal resolver flattens
        multi-value fields to parallel comma-joined strings.
    :param lists: optional one-column lists keyed by the FULL dotted name,
        ``{"Documents.Affidavit_Template": ["Test Affidavit 5", "QCAT - ..."]}``.
        Each becomes one paragraph per item, so a plain
        ``{{ Documents.Affidavit_Template }}`` lists every document's template
        without the author writing a loop. These OVERRIDE the flat pair of the
        same name — deliberately, because that pair is what is wrong: Drupal's
        legacy resolver renders the parent field once and hands the same string
        to every child of it, so ``Documents.Document_Title`` arrives as every
        document's whole line run together and ``Documents.Affidavit_Template``
        arrives empty.
    """
    scalars: dict[str, str] = {}
    children: dict[str, dict[str, str]] = {}
    # Only names reached by attribute lookup — children and row keys. Top-level
    # names are dict keys and cannot be shadowed.
    attribute_names: list[str] = []

    for token in tokens:
        name = str(token.get("name", "")).strip()
        if not name:
            continue
        text = _coerce(token.get("value"))

        head, _, tail = name.partition(".")
        if tail:
            # Only one level of nesting is ever emitted (the mapper walks
            # references exactly one level deep), so a further dot would be
            # data we do not understand — keep it as a literal child key
            # rather than silently dropping it.
            attribute_names.append(tail)
            children.setdefault(head, {})[tail] = text
        else:
            scalars[head] = text

    # Overlay the one-column lists. Applied after the flat pairs so they win,
    # and an empty list writes nothing rather than blanking a name that the
    # flat pairs happened to fill.
    for name, items in (lists or {}).items():
        name = str(name).strip()
        if not name:
            continue
        rendered = [
            text
            for text in (_coerce(item) for item in items)
            if text.strip()
        ]
        if not rendered:
            continue
        joined = PARAGRAPH_BREAK.join(rendered)

        head, _, tail = name.partition(".")
        if tail:
            attribute_names.append(tail)
            children.setdefault(head, {})[tail] = joined
        else:
            scalars[head] = joined

    table_rows: dict[str, list[dict[str, Value]]] = {}
    for parent, rows in (tables or {}).items():
        parent = str(parent).strip()
        if not parent:
            continue
        built_rows: list[dict[str, Value]] = []
        for row in rows:
            built = {}
            for key, cell in row.items():
                key = str(key).strip()
                if not key:
                    continue
                attribute_names.append(key)
                built[key] = Value(_coerce(cell))
            built_rows.append(built)
        table_rows[parent] = built_rows

    _assert_no_str_shadowing(attribute_names)

    context: dict[str, Value] = {}
    for name in set(scalars) | set(children) | set(table_rows):
        context[name] = Value(
            text=scalars.get(name, ""),
            children={k: Value(v) for k, v in children.get(name, {}).items()},
            rows=table_rows.get(name),
        )

    return context
